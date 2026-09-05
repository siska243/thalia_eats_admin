# Sous-projet C-back — jetons agents, pré-commandes, lien de paiement : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettre à un client de pré-commander depuis un assistant conversationnel, avec un jeton scopé qui ne peut écrire qu'à un seul endroit, et de payer via un lien signé valide 12 heures — sans qu'aucun prix ne vienne jamais de l'appelant.

**Architecture:** Un jeton Sanctum porteur d'abilities restreintes et d'une expiration ouvre l'accès aux endpoints livrés par le sous-projet A. Une table `precommandes` immuable fige le chiffrage calculé par `QuotationService`. Un lien signé temporaire mène au paiement FlexPay ; au webhook, la pré-commande devient une `Commande` au statut 2, indiscernable d'une commande payée normalement.

**Tech Stack:** PHP 8.1, Laravel 12, Sanctum 4, MySQL 8, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-09-05-c-back-precommandes-design.md`

## Global Constraints

- **PHP `^8.1`** per `composer.json`. Le runtime local est 8.3 et **ne détectera pas** les violations : pas de `readonly class` (8.2+), pas de constantes typées (8.3+). Les propriétés `readonly` et les propriétés promues sont autorisées.
- **Application en production, tolérance zéro régression.** Aucune route existante renommée, aucun champ de réponse modifié, aucune forme `ApiResponse` changée.
- **Toutes les réponses passent par `App\Wrappers\ApiResponse`.** Signatures exactes : `GET_DATA($data)` → 200 et renvoie `$data` **sans enveloppe** ; `SUCCESS_DATA($data, $title, $message)` → 201 ; `BAD_REQUEST($errors, $title, $message)` → 400, **erreurs en premier** ; `NOT_FOUND($title, $message)` → 404 ; `NOT_AUTHORIZED($title, $message)` → 401.
- **Messages utilisateur en français**, consommés tels quels par les toasts des clients.
- **Les identifiants publics sont chiffrés** par `App\Wrappers\Cipher` : `Cipher::Encrypt($id)`, `Cipher::Decrypt($uid)`. Aucun identifiant brut ne quitte le serveur.
- **Fautes de frappe historiques à conserver verbatim** : `refernce`, `Payement`, `DelivreryPrice`, `delivrery_prices`, `payements`. Jamais « corrigées ».
- **La table des statuts s'appelle `status`** (singulier). Statuts : 1 en-attente, 2 en-cours, 3 livrer, 4 annuler, 5 en-attente-paiement.
- **Formatage** : `./vendor/bin/pint` **uniquement sur ses propres fichiers, par chemin explicite**. Un lancement nu reformate ~180 fichiers préexistants.
- **Aucun nouveau seeder** — `script-run.sh` lance `db:seed --force` en production.
- **Aucun secret en clair.** Les nouvelles valeurs passent par `.env`.
- **Le scheduler ne tourne pas** en production : aucune correction ne peut reposer sur un job planifié.
- **Rien ne se supprime, rien ne se modifie** : aucun endpoint de mise à jour ou de suppression de pré-commande, pour personne.

---

## Structure des fichiers

| Fichier | Responsabilité |
|---|---|
| `app/Enums/TokenAbility.php` | Les cinq abilities, en un seul endroit |
| `app/Http/Controllers/Api/AssistantTokenController.php` | Émission, liste et révocation des jetons agents |
| `app/Models/Precommande.php` | Le modèle, ses relations et son scope de validité |
| `app/Models/PrecommandeProduct.php` | Les lignes figées |
| `app/Services/PrecommandeService.php` | Création : chiffrage figé, refus, référence |
| `app/Http/Controllers/Api/PrecommandeController.php` | Création et lecture |
| `app/Http/Requests/PrecommandeRequest.php` | Validation de la création |
| `app/Http/Controllers/PaiementPrecommandeController.php` | La route signée, hors `/api` |
| `app/Http/Resources/PrecommandeResource.php` | Sérialisation |

Découpage par responsabilité : le service porte la règle, le contrôleur porte le HTTP, la ressource porte la forme.

---

## Task 1: Les abilities et leur application

Aucun `tokenCan()` n'existe dans le projet et les 82 jetons de production portent `["*"]`. Cette tâche introduit le vocabulaire et l'applique aux routes livrées en A, sans changer ce que peut un jeton existant.

**Files:**
- Create: `app/Enums/TokenAbility.php`
- Modify: `app/Http/Kernel.php` (ajout de deux alias)
- Modify: `config/sanctum.php` (expiration par défaut)
- Modify: `routes/api.php` (ajout d'abilities au groupe créé en A)
- Modify: `.env.example`
- Test: `tests/Feature/Api/TokenAbilityTest.php`

**Interfaces:**
- Consumes: les trois endpoints livrés en A.
- Produces: `App\Enums\TokenAbility` — enum `string` avec les cas `CatalogueLire = 'catalogue:lire'`, `DevisCalculer = 'devis:calculer'`, `PrecommandeCreer = 'precommande:creer'`, `PrecommandeLire = 'precommande:lire'`, `CommandeLire = 'commande:lire'`, plus `public static function agent(): array` renvoyant les cinq valeurs `string`.

- [ ] **Step 1: Écrire les tests**

Créer `tests/Feature/Api/TokenAbilityTest.php` :

```php
<?php

namespace Tests\Feature\Api;

use App\Enums\TokenAbility;
use App\Models\Town;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TokenAbilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_jeton_etoile_conserve_tous_ses_pouvoirs(): void
    {
        // Les 82 jetons de production portent ["*"] : ils ne doivent rien perdre.
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->getJson('/api/products/search?q=poulet')->assertStatus(200);
    }

    public function test_un_jeton_agent_peut_chercher_dans_le_catalogue(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());

        $this->getJson('/api/products/search?q=poulet')->assertStatus(200);
    }

    public function test_un_jeton_sans_l_ability_catalogue_est_refuse(): void
    {
        Sanctum::actingAs(User::factory()->create(), [TokenAbility::CommandeLire->value]);

        $this->getJson('/api/products/search?q=poulet')->assertStatus(403);
    }

    public function test_un_jeton_sans_l_ability_devis_ne_peut_pas_chiffrer(): void
    {
        Sanctum::actingAs(User::factory()->create(), [TokenAbility::CatalogueLire->value]);
        $town = Town::factory()->create();

        $this->postJson('/api/quote', [
            'town' => $town->slug,
            'products' => [['uid' => 'peu-importe', 'quantity' => 1]],
        ])->assertStatus(403);
    }

    public function test_l_enum_expose_exactement_cinq_abilities(): void
    {
        $this->assertCount(5, TokenAbility::agent());
        $this->assertContains('precommande:creer', TokenAbility::agent());
        $this->assertNotContains('commande:annuler', TokenAbility::agent());
    }
}
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `php artisan test tests/Feature/Api/TokenAbilityTest.php`
Expected: FAIL — `Class "App\Enums\TokenAbility" not found`.

- [ ] **Step 3: Écrire l'enum**

Créer `app/Enums/TokenAbility.php` :

```php
<?php

namespace App\Enums;

/**
 * Les abilities qu'un jeton confié à un assistant peut porter.
 *
 * Volontairement absentes, et qui ne doivent jamais y entrer : annuler une
 * commande, en modifier une en cours, changer une adresse de livraison,
 * déclencher un paiement, émettre un jeton. Un agent ne s'auto-délivre pas
 * de pouvoirs.
 */
enum TokenAbility: string
{
    case CatalogueLire = 'catalogue:lire';

    case DevisCalculer = 'devis:calculer';

    case PrecommandeCreer = 'precommande:creer';

    case PrecommandeLire = 'precommande:lire';

    case CommandeLire = 'commande:lire';

    /**
     * @return array<int, string>
     */
    public static function agent(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
```

- [ ] **Step 4: Enregistrer les middlewares de Sanctum**

Dans `app/Http/Kernel.php`, repérer le tableau d'alias qui contient `'signed' => \App\Http\Middleware\ValidateSignature::class` (vers la ligne 68) et y ajouter, en gardant l'ordre alphabétique du tableau :

```php
        'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
        'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
```

`abilities` exige **toutes** les abilités listées ; `ability` en exige **au moins une**. On utilise `ability` partout ici : une seule suffit par route.

- [ ] **Step 5: Appliquer aux routes livrées en A**

Dans `routes/api.php`, le groupe créé par le sous-projet A devient :

```php
Route::middleware('auth:sanctum')->group(function () {
    Route::middleware('ability:catalogue:lire')->group(function () {
        Route::get('/products/search', [\App\Http\Controllers\Api\ProductSearchController::class, 'index']);
    });

    Route::middleware('ability:devis:calculer')->group(function () {
        Route::post('/quote', [\App\Http\Controllers\Api\QuotationController::class, 'quote']);
        Route::post('/budget-suggestions', [\App\Http\Controllers\Api\QuotationController::class, 'budgetSuggestions']);
    });
});
```

Ne modifier **aucune autre ligne** de ce fichier.

- [ ] **Step 6: Fixer une expiration par défaut**

Dans `config/sanctum.php`, remplacer `'expiration' => null,` par :

```php
    'expiration' => env('SANCTUM_EXPIRATION'),
```

et ajouter à `.env.example` :

```
# Duree de vie par defaut d'un jeton, en minutes. Vide = jamais.
# Les jetons d'assistant portent leur propre expiration, independamment.
SANCTUM_EXPIRATION=
```

Laisser vide : les jetons applicatifs existants ne doivent pas se mettre à expirer du jour au lendemain. Ce sont les jetons d'assistant qui portent une expiration explicite, posée à leur création en tâche 2.

- [ ] **Step 7: Lancer les tests pour vérifier qu'ils passent**

Run: `php artisan test tests/Feature/Api/TokenAbilityTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 8: Lancer la suite complète**

Run: `php artisan test`
Expected: aucune régression. `tests/Feature/ExampleTest.php` échoue toujours en 404 — préexistant, la route `/` est commentée dans `routes/web.php`, hors périmètre.

- [ ] **Step 9: Formater et committer**

```bash
./vendor/bin/pint app/Enums/TokenAbility.php tests/Feature/Api/TokenAbilityTest.php
git diff routes/api.php
git add app/Enums/TokenAbility.php app/Http/Kernel.php config/sanctum.php routes/api.php .env.example tests/Feature/Api/TokenAbilityTest.php
git commit -m "feat: abilities de jeton et application aux endpoints de devis

Les 82 jetons de production portent [\"*\"] : ils conservent tous leurs
pouvoirs. Seuls les jetons emis avec des abilities etroites sont
restreints, ce qui rend cette bascule sans regression.

L'enum TokenAbility tient le vocabulaire en un seul endroit, et son test
verifie qu'aucune ability d'ecriture destructive n'y entre par megarde."
```

Vérifier avant de committer que `git diff routes/api.php` ne montre que le remaniement du groupe, sans reformatage des lignes voisines.

---

## Task 2: Émission et révocation des jetons d'assistant

Le geste du client : il nomme la connexion, obtient un jeton **affiché une seule fois**, et peut le révoquer. Un jeton d'assistant ne doit pas pouvoir en émettre un autre — sans quoi la restriction d'abilities serait contournable en une requête.

**Files:**
- Create: `app/Http/Middleware/EnsureNotAgentToken.php`
- Create: `app/Http/Controllers/Api/AssistantTokenController.php`
- Create: `app/Http/Requests/AssistantTokenRequest.php`
- Modify: `app/Http/Kernel.php` (un alias de plus)
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/AssistantTokenTest.php`

**Interfaces:**
- Consumes: `App\Enums\TokenAbility::agent()` (tâche 1).
- Produces: trois routes — `POST /api/user/assistants`, `GET /api/user/assistants`, `DELETE /api/user/assistants/{uid}` — et le middleware aliasé `assistant.emetteur`.

- [ ] **Step 1: Écrire les tests**

Créer `tests/Feature/Api/AssistantTokenTest.php` :

```php
<?php

namespace Tests\Feature\Api;

use App\Enums\TokenAbility;
use App\Models\User;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssistantTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_l_endpoint_exige_une_authentification(): void
    {
        $this->postJson('/api/user/assistants', ['name' => 'mon Claude'])->assertStatus(401);
    }

    public function test_il_emet_un_jeton_affiche_une_seule_fois(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/user/assistants', ['name' => 'mon Claude']);

        $response->assertStatus(201);
        $this->assertNotEmpty($response->json('data.token'));

        // Le jeton en clair ne doit jamais reapparaitre dans la liste.
        $liste = $this->getJson('/api/user/assistants');
        $liste->assertStatus(200);
        $this->assertSame('mon Claude', $liste->json('0.name'));
        $this->assertArrayNotHasKey('token', $liste->json('0'));
    }

    public function test_le_jeton_emis_porte_les_abilities_agent_et_une_expiration(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/user/assistants', ['name' => 'mon Claude'])->assertStatus(201);

        $token = $user->tokens()->where('name', 'mon Claude')->first();

        $this->assertSame(TokenAbility::agent(), $token->abilities);
        $this->assertNotNull($token->expires_at);
        $this->assertTrue($token->expires_at->greaterThan(now()->addDays(89)));
    }

    public function test_un_jeton_agent_ne_peut_pas_en_emettre_un_autre(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());

        $this->postJson('/api/user/assistants', ['name' => 'un autre'])->assertStatus(403);
        $this->getJson('/api/user/assistants')->assertStatus(403);
    }

    public function test_il_revoque_un_jeton(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/user/assistants', ['name' => 'mon Claude'])->assertStatus(201);
        $token = $user->tokens()->where('name', 'mon Claude')->first();

        $this->deleteJson('/api/user/assistants/'.Cipher::Encrypt($token->id))->assertStatus(200);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
    }

    public function test_on_ne_revoque_pas_le_jeton_d_un_autre(): void
    {
        $victime = User::factory()->create();
        $token = $victime->createToken('sa connexion', TokenAbility::agent());

        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->deleteJson('/api/user/assistants/'.Cipher::Encrypt($token->accessToken->id))
            ->assertStatus(404);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_un_nom_est_obligatoire(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson('/api/user/assistants', [])->assertStatus(422);
    }
}
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `php artisan test tests/Feature/Api/AssistantTokenTest.php`
Expected: FAIL — 404, les routes n'existent pas.

- [ ] **Step 3: Écrire le middleware**

Créer `app/Http/Middleware/EnsureNotAgentToken.php` :

```php
<?php

namespace App\Http\Middleware;

use App\Wrappers\ApiResponse;
use Closure;
use Illuminate\Http\Request;

/**
 * Refuse la requête lorsqu'elle est portée par un jeton d'assistant.
 *
 * Un jeton applicatif porte l'ability « * » ; un jeton d'assistant porte une
 * liste étroite. Sans ce garde, un assistant pourrait s'émettre un second
 * jeton et contourner en une requête la restriction d'abilities.
 */
class EnsureNotAgentToken
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->tokenCan('*')) {
            return ApiResponse::BAD_REQUEST(
                'jeton_assistant',
                'Oups',
                'Cette action ne peut pas être effectuée depuis un assistant.'
            )->setStatusCode(403);
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Aliaser le middleware**

Dans `app/Http/Kernel.php`, à côté des alias ajoutés en tâche 1 :

```php
        'assistant.emetteur' => \App\Http\Middleware\EnsureNotAgentToken::class,
```

- [ ] **Step 5: Écrire la validation**

Créer `app/Http/Requests/AssistantTokenRequest.php` :

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssistantTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'jours' => ['nullable', 'integer', 'min:1', 'max:365'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Veuillez nommer cette connexion.',
            'name.max' => 'Le nom ne peut pas dépasser 60 caractères.',
            'jours.max' => 'Une connexion ne peut pas durer plus de 365 jours.',
        ];
    }
}
```

- [ ] **Step 6: Écrire le contrôleur**

Créer `app/Http/Controllers/Api/AssistantTokenController.php` :

```php
<?php

namespace App\Http\Controllers\Api;

use App\Enums\TokenAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssistantTokenRequest;
use App\Wrappers\ApiResponse;
use App\Wrappers\Cipher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantTokenController extends Controller
{
    private const JOURS_PAR_DEFAUT = 90;

    public function store(AssistantTokenRequest $request): JsonResponse
    {
        $jours = (int) ($request->input('jours') ?: self::JOURS_PAR_DEFAUT);

        $token = $request->user()->createToken(
            $request->input('name'),
            TokenAbility::agent(),
            now()->addDays($jours),
        );

        // Le jeton en clair n'est renvoyé qu'ici, une seule fois. Il n'est
        // stocké nulle part en clair et ne peut pas être réaffiché.
        return ApiResponse::SUCCESS_DATA(
            [
                'uid' => Cipher::Encrypt($token->accessToken->id),
                'name' => $token->accessToken->name,
                'token' => $token->plainTextToken,
                'expires_at' => $token->accessToken->expires_at,
            ],
            'Connexion créée',
            'Copiez ce jeton maintenant : il ne sera plus jamais affiché.'
        );
    }

    public function index(Request $request): JsonResponse
    {
        $connexions = $request->user()->tokens()
            ->whereJsonDoesntContain('abilities', '*')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($token) => [
                'uid' => Cipher::Encrypt($token->id),
                'name' => $token->name,
                'last_used_at' => $token->last_used_at,
                'expires_at' => $token->expires_at,
                'created_at' => $token->created_at,
            ])
            ->values();

        return ApiResponse::GET_DATA($connexions);
    }

    public function destroy(Request $request, string $uid): JsonResponse
    {
        $id = Cipher::Decrypt($uid);

        if ($id === false || ! ctype_digit((string) $id)) {
            return ApiResponse::NOT_FOUND('Oups', 'Cette connexion est introuvable');
        }

        // Restreint aux jetons de l'utilisateur courant : révoquer celui d'un
        // autre doit être indiscernable d'un identifiant inexistant.
        $token = $request->user()->tokens()->where('id', (int) $id)->first();

        if (! $token) {
            return ApiResponse::NOT_FOUND('Oups', 'Cette connexion est introuvable');
        }

        $token->delete();

        return ApiResponse::GET_DATA([
            'message' => 'La connexion a été révoquée.',
        ]);
    }
}
```

- [ ] **Step 7: Déclarer les routes**

Dans `routes/api.php`, à l'intérieur du groupe `Route::middleware('auth:sanctum')->prefix('/user')` existant, **sans modifier une ligne existante** :

```php
    Route::middleware('assistant.emetteur')->prefix('/assistants')
        ->controller(\App\Http\Controllers\Api\AssistantTokenController::class)
        ->group(function () {
            Route::post('/', 'store');
            Route::get('/', 'index');
            Route::delete('/{uid}', 'destroy');
        });
```

- [ ] **Step 8: Lancer les tests pour vérifier qu'ils passent**

Run: `php artisan test tests/Feature/Api/AssistantTokenTest.php`
Expected: PASS, 7 tests.

Si `whereJsonDoesntContain` échoue sur cette version de MySQL, remplacer par un filtre en PHP après `get()` — et le signaler dans le rapport.

- [ ] **Step 9: Formater et committer**

```bash
./vendor/bin/pint app/Http/Middleware/EnsureNotAgentToken.php app/Http/Controllers/Api/AssistantTokenController.php app/Http/Requests/AssistantTokenRequest.php tests/Feature/Api/AssistantTokenTest.php
git diff routes/api.php
git add app/Http/Middleware/EnsureNotAgentToken.php app/Http/Controllers/Api/AssistantTokenController.php app/Http/Requests/AssistantTokenRequest.php app/Http/Kernel.php routes/api.php tests/Feature/Api/AssistantTokenTest.php
git commit -m "feat: emission et revocation des jetons d'assistant

Le jeton en clair n'est renvoye qu'a la creation. Il porte les abilities
agent et expire a 90 jours par defaut.

Un jeton d'assistant ne peut pas en emettre un autre : sans ce garde, la
restriction d'abilities serait contournable en une requete. Revoquer le
jeton d'un autre utilisateur renvoie 404, indiscernable d'un identifiant
inexistant."
```

---

## Task 3: Les limiteurs de débit, comptés par jeton

Le limiteur `api` actuel compte **par utilisateur**. Un assistant partagerait donc le quota de l'application mobile du même client, qu'il pourrait épuiser — le client verrait son app se bloquer sans comprendre.

**Files:**
- Modify: `app/Providers/RouteServiceProvider.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/RateLimitTest.php`

**Interfaces:**
- Consumes: les routes des tâches 1 et 2.
- Produces: les limiteurs nommés `agent-lecture`, `agent-devis`, `agent-ecriture`, `assistants`, `lien-paiement`, applicables via `throttle:<nom>`.

- [ ] **Step 1: Écrire les tests**

Créer `tests/Feature/Api/RateLimitTest.php` :

```php
<?php

namespace Tests\Feature\Api;

use App\Enums\TokenAbility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('agent-devis');
    }

    public function test_le_limiteur_de_devis_declenche_au_seuil(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());

        // 20 par minute : la 21e doit etre refusee.
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/budget-suggestions', [])->assertStatus(422);
        }

        $this->postJson('/api/budget-suggestions', [])->assertStatus(429);
    }

    public function test_un_assistant_n_entame_pas_le_quota_de_l_utilisateur(): void
    {
        $user = User::factory()->create();

        // L'assistant consomme son quota de devis...
        Sanctum::actingAs($user, TokenAbility::agent());
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/budget-suggestions', []);
        }
        $this->postJson('/api/budget-suggestions', [])->assertStatus(429);

        // ...et le meme utilisateur, depuis son application, passe toujours.
        Sanctum::actingAs($user, ['*']);
        $this->postJson('/api/budget-suggestions', [])->assertStatus(422);
    }

    public function test_l_emission_de_jetons_est_severement_limitee(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/user/assistants', ['name' => 'connexion '.$i])->assertStatus(201);
        }

        $this->postJson('/api/user/assistants', ['name' => 'de trop'])->assertStatus(429);
    }
}
```

> **Note :** `Sanctum::actingAs()` crée un `TransientToken` sans identifiant en base. La clé du limiteur doit donc retomber proprement sur l'utilisateur lorsque le jeton n'a pas d'`id` — c'est exactement ce que fait `cleDeLimitation()` ci-dessous, et le deuxième test échouerait si ce n'était pas le cas. Si `Sanctum::actingAs` ne permet pas de distinguer les deux quotas dans votre version, le signaler plutôt que d'affaiblir le test : il faudra alors émettre de vrais jetons via l'endpoint de la tâche 2 et authentifier par en-tête `Authorization`.

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `php artisan test tests/Feature/Api/RateLimitTest.php`
Expected: FAIL — les limiteurs n'existent pas, aucune requête n'est refusée.

- [ ] **Step 3: Définir les limiteurs**

Dans `app/Providers/RouteServiceProvider.php`, ajouter dans `boot()` **après** le limiteur `api` existant, qui reste inchangé :

```php
        RateLimiter::for('agent-lecture', fn (Request $request) => Limit::perMinute(60)->by(self::cleDeLimitation($request)));

        RateLimiter::for('agent-devis', fn (Request $request) => Limit::perMinute(20)->by(self::cleDeLimitation($request)));

        RateLimiter::for('agent-ecriture', fn (Request $request) => [
            Limit::perMinute(10)->by(self::cleDeLimitation($request)),
            Limit::perHour(60)->by(self::cleDeLimitation($request)),
        ]);

        RateLimiter::for('assistants', fn (Request $request) => Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()));

        // La signature n'identifie pas l'appelant : on compte par IP.
        RateLimiter::for('lien-paiement', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
```

et la méthode privée, dans la même classe :

```php
    /**
     * Compter par JETON et non par utilisateur : sinon un assistant bavard
     * épuise le quota de l'application mobile du même client, qui se retrouve
     * bloqué dans son app sans comprendre pourquoi.
     *
     * Retombe sur l'utilisateur lorsque le jeton n'a pas d'identifiant en base
     * (authentification de session, ou Sanctum::actingAs en test), puis sur
     * l'adresse IP.
     */
    private static function cleDeLimitation(Request $request): string
    {
        $token = $request->user()?->currentAccessToken();

        if ($token !== null && isset($token->id)) {
            return 'token:'.$token->id;
        }

        if ($request->user() !== null) {
            return 'user:'.$request->user()->id;
        }

        return 'ip:'.$request->ip();
    }
```

- [ ] **Step 4: Appliquer les limiteurs aux routes**

Dans `routes/api.php`, compléter les groupes créés en tâches 1 et 2 :

```php
Route::middleware('auth:sanctum')->group(function () {
    Route::middleware(['ability:catalogue:lire', 'throttle:agent-lecture'])->group(function () {
        Route::get('/products/search', [\App\Http\Controllers\Api\ProductSearchController::class, 'index']);
    });

    Route::middleware(['ability:devis:calculer', 'throttle:agent-devis'])->group(function () {
        Route::post('/quote', [\App\Http\Controllers\Api\QuotationController::class, 'quote']);
        Route::post('/budget-suggestions', [\App\Http\Controllers\Api\QuotationController::class, 'budgetSuggestions']);
    });
});
```

et sur le groupe des assistants, ajouter `'throttle:assistants'` à la liste de middlewares aux côtés de `'assistant.emetteur'`.

- [ ] **Step 5: Lancer les tests pour vérifier qu'ils passent**

Run: `php artisan test tests/Feature/Api/RateLimitTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 6: Lancer la suite complète**

Run: `php artisan test`
Expected: aucune régression. Les limiteurs peuvent faire échouer des tests voisins qui enchaînent beaucoup de requêtes sur un même endpoint — si c'est le cas, ajouter `RateLimiter::clear()` dans le `setUp()` du test concerné plutôt que de relâcher un seuil.

- [ ] **Step 7: Formater et committer**

```bash
./vendor/bin/pint app/Providers/RouteServiceProvider.php tests/Feature/Api/RateLimitTest.php
git diff routes/api.php
git add app/Providers/RouteServiceProvider.php routes/api.php tests/Feature/Api/RateLimitTest.php
git commit -m "feat: limiteurs de debit comptes par jeton

Le limiteur api existant compte par utilisateur : un assistant partageait
donc le quota de l'application mobile du meme client, qu'il pouvait
epuiser. Les nouveaux limiteurs comptent par jeton, avec repli sur
l'utilisateur puis sur l'IP.

Les seuils suivent le cout reel : 60/min en lecture, 20/min pour un devis
qui declenche jusqu'a 50 chiffrages, 5/min pour l'emission de jetons."
```

---

## Task 4: Le schéma de la pré-commande

Deux tables, deux modèles, deux factories. Aucun endpoint encore — cette tâche pose les fondations et prouve que le prix figé est réellement figé.

**Point de conception à ne pas rater :** les références de commande sont des **entiers nus** (1038, 1039, 1040) et `PayementController::webhook` cherche `Commande::where('refernce', $reference)`. Une référence de pré-commande doit donc être **impossible à confondre** avec une référence de commande, sinon le webhook les mélangerait. D'où le préfixe `P-`.

**Files:**
- Create: `database/migrations/2026_09_05_170000_create_precommandes_table.php`
- Create: `app/Models/Precommande.php`
- Create: `app/Models/PrecommandeProduct.php`
- Create: `database/factories/PrecommandeFactory.php`
- Test: `tests/Feature/Models/PrecommandeTest.php`

**Interfaces:**
- Consumes: les factories du sous-projet A (`Product`, `Restaurant`, `Town`, `Currency`, `DelivreryPrice`, `User`).
- Produces:
  - `App\Models\Precommande` — relations `user()`, `restaurant()`, `town()`, `currency()`, `products()` (`HasMany` vers `PrecommandeProduct`), `commande()`. Constantes `STATUT_EN_ATTENTE = 'en_attente'`, `STATUT_PAYEE = 'payee'`, `STATUT_EXPIREE = 'expiree'`. Méthodes `estValide(): bool` et `estExpiree(): bool`. Scope `scopeValides()`.
  - `App\Models\PrecommandeProduct` — relations `precommande()`, `product()`.
  - `Precommande::factory()` avec les états `->expiree()` et `->payee()`.

- [ ] **Step 1: Écrire les tests**

Créer `tests/Feature/Models/PrecommandeTest.php` :

```php
<?php

namespace Tests\Feature\Models;

use App\Models\Precommande;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrecommandeTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_precommande_fraiche_est_valide(): void
    {
        $precommande = Precommande::factory()->create();

        $this->assertTrue($precommande->estValide());
        $this->assertFalse($precommande->estExpiree());
    }

    public function test_une_precommande_de_plus_de_douze_heures_est_expiree(): void
    {
        $precommande = Precommande::factory()->expiree()->create();

        $this->assertFalse($precommande->estValide());
        $this->assertTrue($precommande->estExpiree());
    }

    public function test_une_precommande_payee_n_est_plus_valide(): void
    {
        // Elle n'est pas expirée, mais elle ne peut plus servir a payer.
        $precommande = Precommande::factory()->payee()->create();

        $this->assertFalse($precommande->estValide());
        $this->assertFalse($precommande->estExpiree());
    }

    public function test_le_scope_valides_ecarte_expirees_et_payees(): void
    {
        Precommande::factory()->create();
        Precommande::factory()->expiree()->create();
        Precommande::factory()->payee()->create();

        $this->assertSame(1, Precommande::query()->valides()->count());
    }

    public function test_le_prix_de_la_ligne_est_fige_meme_si_le_produit_change(): void
    {
        $precommande = Precommande::factory()->create();
        $produit = Product::factory()->create(['price' => 1000]);

        $precommande->products()->create([
            'product_id' => $produit->id,
            'quantity' => 2,
            'price' => 1000,
        ]);

        $produit->update(['price' => 9999]);

        $ligne = $precommande->products()->first();

        $this->assertSame(1000.0, (float) $ligne->price);
        $this->assertSame(9999.0, (float) $ligne->product->price);
    }

    public function test_la_reference_ne_peut_pas_etre_confondue_avec_une_commande(): void
    {
        // commandes.refernce est un entier nu ; le webhook cherche dessus.
        $precommande = Precommande::factory()->create();

        $this->assertStringStartsWith('P-', $precommande->refernce);
        $this->assertFalse(ctype_digit($precommande->refernce));
    }
}
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `php artisan test tests/Feature/Models/PrecommandeTest.php`
Expected: FAIL — `Class "App\Models\Precommande" not found`.

- [ ] **Step 3: Écrire la migration**

Créer `database/migrations/2026_09_05_170000_create_precommandes_table.php` :

```php
<?php

use App\Models\Commande;
use App\Models\Currency;
use App\Models\DelivreryPrice;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Town;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('precommandes', function (Blueprint $table) {
            $table->id();
            $table->string('refernce')->unique();

            $table->foreignIdFor(User::class, 'user_id');
            $table->foreignIdFor(Restaurant::class, 'restaurant_id');
            $table->foreignIdFor(Town::class, 'town_id');

            $table->string('adresse_delivery');
            $table->string('street')->nullable();
            $table->string('number_street')->nullable();
            $table->string('reference_adresse')->nullable();
            $table->float('lat')->nullable();
            $table->float('long')->nullable();

            $table->string('recipient_name');
            $table->string('recipient_phone');

            $table->float('sous_total');
            $table->float('frais_livraison');
            $table->float('service_price');
            $table->float('total');
            $table->foreignIdFor(Currency::class, 'currency_id');
            $table->foreignIdFor(DelivreryPrice::class, 'delivrery_price_id')->nullable();

            $table->timestamp('expires_at');
            $table->string('status')->default('en_attente');
            $table->string('reference_paiement')->nullable();
            $table->foreignIdFor(Commande::class, 'commande_id')->nullable();
            $table->timestamp('paied_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('precommande_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('precommande_id')->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Product::class, 'product_id');
            $table->float('quantity');
            $table->float('price');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('precommande_products');
        Schema::dropIfExists('precommandes');
    }
};
```

`quantity` est un `float` et non un entier : le moteur de quotation, aligné sur `calculePrice.js`, ne coerce pas la quantité. La stocker en entier réintroduirait la troncature que le sous-projet A a précisément retirée.

- [ ] **Step 4: Écrire les modèles**

Créer `app/Models/Precommande.php` :

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Precommande extends Model
{
    use HasFactory;

    public const STATUT_EN_ATTENTE = 'en_attente';

    public const STATUT_PAYEE = 'payee';

    public const STATUT_EXPIREE = 'expiree';

    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'paied_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class, 'restaurant_id');
    }

    public function town(): BelongsTo
    {
        return $this->belongsTo(Town::class, 'town_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class, 'commande_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(PrecommandeProduct::class);
    }

    /**
     * Expiration paresseuse : le scheduler ne tourne pas en production, donc
     * aucun balayage ne peut être le mécanisme. On dérive de expires_at.
     */
    public function estExpiree(): bool
    {
        return $this->status === self::STATUT_EN_ATTENTE
            && $this->expires_at->isPast();
    }

    public function estValide(): bool
    {
        return $this->status === self::STATUT_EN_ATTENTE
            && $this->expires_at->isFuture();
    }

    /**
     * @param  Builder<Precommande>  $query
     */
    public function scopeValides(Builder $query): void
    {
        $query->where('status', self::STATUT_EN_ATTENTE)->where('expires_at', '>', now());
    }
}
```

Créer `app/Models/PrecommandeProduct.php` :

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrecommandeProduct extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function precommande(): BelongsTo
    {
        return $this->belongsTo(Precommande::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
```

- [ ] **Step 5: Écrire la factory**

Créer `database/factories/PrecommandeFactory.php` :

```php
<?php

namespace Database\Factories;

use App\Models\Currency;
use App\Models\Precommande;
use App\Models\Restaurant;
use App\Models\Town;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PrecommandeFactory extends Factory
{
    protected $model = Precommande::class;

    public function definition(): array
    {
        return [
            // Prefixe P- : commandes.refernce est un entier nu et le webhook
            // cherche dessus. Les deux espaces de references ne doivent pas
            // pouvoir se croiser.
            'refernce' => 'P-'.Str::upper(Str::random(10)),
            'user_id' => User::factory(),
            'restaurant_id' => Restaurant::factory(),
            'town_id' => Town::factory(),
            'adresse_delivery' => 'Avenue Test',
            'street' => 'Rue Test',
            'number_street' => '12',
            'reference_adresse' => 'En face du marche',
            'lat' => null,
            'long' => null,
            'recipient_name' => 'Destinataire Test',
            'recipient_phone' => '+243810000000',
            'sous_total' => 3000.0,
            'frais_livraison' => 2000.0,
            'service_price' => 500.0,
            'total' => 5500.0,
            'currency_id' => Currency::factory(),
            'delivrery_price_id' => null,
            'expires_at' => now()->addHours(12),
            'status' => Precommande::STATUT_EN_ATTENTE,
        ];
    }

    public function expiree(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }

    public function payee(): static
    {
        return $this->state(fn () => [
            'status' => Precommande::STATUT_PAYEE,
            'paied_at' => now(),
        ]);
    }
}
```

- [ ] **Step 6: Lancer les tests pour vérifier qu'ils passent**

Run: `php artisan test tests/Feature/Models/PrecommandeTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 7: Régénérer le dump de schéma**

Les deux nouvelles tables doivent entrer dans le dump, sans quoi un clone neuf ne les aura pas.

```bash
php artisan migrate --env=testing
php artisan schema:dump
php artisan db:wipe --force --env=testing && php artisan migrate --force --env=testing
php artisan test tests/Feature/Models/PrecommandeTest.php
```

Expected: le rejeu depuis zéro fonctionne et les tests passent.

- [ ] **Step 8: Formater et committer**

```bash
./vendor/bin/pint app/Models/Precommande.php app/Models/PrecommandeProduct.php database/factories/PrecommandeFactory.php database/migrations/2026_09_05_170000_create_precommandes_table.php tests/Feature/Models/PrecommandeTest.php
git add app/Models/Precommande.php app/Models/PrecommandeProduct.php database/factories/PrecommandeFactory.php database/migrations/2026_09_05_170000_create_precommandes_table.php database/schema/mysql-schema.sql tests/Feature/Models/PrecommandeTest.php
git commit -m "feat: schema de la precommande

Deux tables, avec le chiffrage fige sur l'entete et le prix fige sur
chaque ligne : si le restaurant change son tarif, la ligne garde le sien.

La reference porte un prefixe P- parce que commandes.refernce est un
entier nu et que le webhook de paiement cherche dessus : les deux espaces
de references ne doivent pas pouvoir se croiser.

L'expiration est derivee de expires_at et non ecrite par un processus :
le scheduler ne tourne pas en production."
```

---

## Task 5: La création d'une pré-commande

Le seul endroit où un assistant peut écrire. Il ne prend **aucun prix** : le serveur chiffre lui-même avec `QuotationService`, exactement comme `/api/quote`. Il est donc structurellement impossible à un agent de dicter un montant.

**Files:**
- Create: `config/precommande.php`
- Create: `app/Exceptions/PrecommandeRefusee.php`
- Create: `app/Services/PrecommandeService.php`
- Create: `app/Http/Requests/PrecommandeRequest.php`
- Create: `app/Http/Resources/PrecommandeResource.php`
- Create: `app/Http/Controllers/Api/PrecommandeController.php`
- Modify: `routes/api.php`
- Modify: `.env.example`
- Test: `tests/Feature/Api/PrecommandeCreationTest.php`

**Interfaces:**
- Consumes: `App\Services\QuotationService::quote(array $lines, Town $town, ?int $expected_restaurant_id = null): Quotation` (sous-projet A) ; `App\Models\Precommande` (tâche 4) ; `App\Enums\TokenAbility` (tâche 1).
- Produces:
  - `App\Exceptions\PrecommandeRefusee` — `public readonly string $raison`, plus `static pour(string $raison): self`.
  - `App\Services\PrecommandeService::creer(User $user, array $lines, Town $town, array $adresse, array $destinataire): Precommande` où `$lines` est `array<int, array{product: Product, quantity: int|float}>`, `$adresse` a les clés `adresse`, `street`, `number_street`, `reference`, et `$destinataire` les clés `name`, `phone`. Lève `PrecommandeRefusee`.
  - `App\Http\Controllers\Api\PrecommandeController::store()`, et sa méthode `protected resoudreLignes(array $products): array` réutilisée en tâche 6.
  - `App\Http\Resources\PrecommandeResource`.

- [ ] **Step 1: Écrire les tests**

Créer `tests/Feature/Api/PrecommandeCreationTest.php` :

```php
<?php

namespace Tests\Feature\Api;

use App\Enums\TokenAbility;
use App\Models\Currency;
use App\Models\DelivreryPrice;
use App\Models\Precommande;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Town;
use App\Models\User;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrecommandeCreationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Town, 1: Currency, 2: Product}
     */
    private function contexte(float $prix = 1500): array
    {
        $town = Town::factory()->create();
        $currency = Currency::factory()->create();
        $restaurant = Restaurant::factory()->create(['town_id' => $town->id]);

        DelivreryPrice::factory()->create([
            'town_id' => $town->id, 'currency_id' => $currency->id,
            'interval_pricing' => 0, 'interval_max_price' => 100000,
            'frais' => 2000, 'service_price' => 500,
        ]);

        $product = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'currency_id' => $currency->id,
            'price' => $prix,
        ]);

        return [$town, $currency, $product];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Town $town, Product $product, int $quantite = 2): array
    {
        return [
            'town' => $town->slug,
            'products' => [['uid' => Cipher::Encrypt($product->id), 'quantity' => $quantite]],
            'adresse' => [
                'adresse' => 'Avenue Test',
                'street' => 'Rue Test',
                'number_street' => '12',
                'reference' => 'En face du marche',
            ],
            'destinataire' => [
                'name' => 'Amie du client',
                'phone' => '+243810000000',
            ],
        ];
    }

    public function test_l_endpoint_exige_l_ability_de_creation(): void
    {
        Sanctum::actingAs(User::factory()->create(), [TokenAbility::CatalogueLire->value]);
        [$town, , $product] = $this->contexte();

        $this->postJson('/api/precommandes', $this->payload($town, $product))->assertStatus(403);
    }

    public function test_il_cree_une_precommande_chiffree_par_le_serveur(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());
        [$town, , $product] = $this->contexte();

        $response = $this->postJson('/api/precommandes', $this->payload($town, $product));

        $response->assertStatus(201)->assertJson([
            'data' => [
                'sous_total' => 3000,
                'frais_livraison' => 2000,
                'service_price' => 500,
                'total' => 5500,
            ],
        ]);

        $this->assertNotEmpty($response->json('data.lien_paiement'));
        $this->assertNotEmpty($response->json('data.expires_at'));
    }

    public function test_un_prix_envoye_par_l_appelant_est_ignore(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());
        [$town, , $product] = $this->contexte();

        $payload = $this->payload($town, $product);
        $payload['total'] = 1;
        $payload['total_price'] = 1;
        $payload['pricing'] = ['frais_livraison' => 0, 'service_price' => 0];

        $this->postJson('/api/precommandes', $payload)->assertStatus(201)
            ->assertJson(['data' => ['total' => 5500]]);

        $this->assertSame(5500.0, (float) Precommande::query()->first()->total);
    }

    public function test_le_destinataire_peut_etre_quelqu_un_d_autre(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());
        [$town, , $product] = $this->contexte();

        $this->postJson('/api/precommandes', $this->payload($town, $product))->assertStatus(201);

        $precommande = Precommande::query()->first();

        $this->assertSame('Amie du client', $precommande->recipient_name);
        $this->assertSame('+243810000000', $precommande->recipient_phone);
    }

    public function test_le_destinataire_est_obligatoire(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());
        [$town, , $product] = $this->contexte();

        $payload = $this->payload($town, $product);
        unset($payload['destinataire']);

        $this->postJson('/api/precommandes', $payload)->assertStatus(422);
    }

    public function test_une_town_sans_tarif_actif_est_refusee(): void
    {
        // Le moteur tolère le zéro pour rester fidèle au web ; une pré-commande
        // créée par une machine ne doit pas promettre une livraison gratuite.
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());

        $town = Town::factory()->create();
        $currency = Currency::factory()->create();
        $product = Product::factory()->create(['currency_id' => $currency->id]);

        $this->postJson('/api/precommandes', $this->payload($town, $product))
            ->assertStatus(400)
            ->assertJson(['error' => 'aucun_tarif_livraison']);
    }

    public function test_un_panier_multi_restaurants_est_refuse(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());
        [$town, $currency, $product] = $this->contexte();

        $autre = Product::factory()->create(['currency_id' => $currency->id]);

        $payload = $this->payload($town, $product);
        $payload['products'][] = ['uid' => Cipher::Encrypt($autre->id), 'quantity' => 1];

        $this->postJson('/api/precommandes', $payload)
            ->assertStatus(400)
            ->assertJson(['error' => 'multi_restaurant']);
    }

    public function test_le_prix_reste_fige_quand_le_produit_change(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());
        [$town, , $product] = $this->contexte();

        $this->postJson('/api/precommandes', $this->payload($town, $product))->assertStatus(201);

        $product->update(['price' => 9999]);

        $precommande = Precommande::query()->with('products')->first();

        $this->assertSame(5500.0, (float) $precommande->total);
        $this->assertSame(1500.0, (float) $precommande->products->first()->price);
    }

    public function test_la_precommande_expire_dans_douze_heures(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());
        [$town, , $product] = $this->contexte();

        $this->postJson('/api/precommandes', $this->payload($town, $product))->assertStatus(201);

        $precommande = Precommande::query()->first();

        $this->assertTrue($precommande->expires_at->between(now()->addHours(11), now()->addHours(13)));
    }

    public function test_le_panier_est_borne(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());
        [$town, , $product] = $this->contexte();

        $payload = $this->payload($town, $product);
        $payload['products'] = array_fill(0, 101, ['uid' => Cipher::Encrypt($product->id), 'quantity' => 1]);

        $this->postJson('/api/precommandes', $payload)->assertStatus(422);
    }
}
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `php artisan test tests/Feature/Api/PrecommandeCreationTest.php`
Expected: FAIL — 404, la route n'existe pas.

- [ ] **Step 3: Écrire la configuration**

Créer `config/precommande.php` :

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Durée de validité
    |--------------------------------------------------------------------------
    |
    | Une pré-commande fige son prix pour cette durée. C'est donc la mesure de
    | l'exposition commerciale : pendant ce laps, Thalia honore le devis même
    | si un tarif produit ou une tranche de livraison a changé entre-temps.
    |
    */

    'validite_heures' => (int) env('PRECOMMANDE_VALIDITE_HEURES', 12),

    /*
    |--------------------------------------------------------------------------
    | Visibilité après expiration
    |--------------------------------------------------------------------------
    |
    | Une pré-commande expirée n'est JAMAIS supprimée. Elle sort simplement des
    | listes au bout de ce délai, pour qu'un assistant puisse encore dire
    | « ta commande d'hier a expiré, je te la refais ? ».
    |
    */

    'visibilite_jours' => (int) env('PRECOMMANDE_VISIBILITE_JOURS', 30),

];
```

et à `.env.example` :

```
PRECOMMANDE_VALIDITE_HEURES=12
PRECOMMANDE_VISIBILITE_JOURS=30
```

- [ ] **Step 4: Écrire l'exception de refus**

Créer `app/Exceptions/PrecommandeRefusee.php` :

```php
<?php

namespace App\Exceptions;

use Exception;

/**
 * Refus métier de créer une pré-commande. La raison est destinée à un agent :
 * elle doit être stable et exploitable, pas jolie.
 */
class PrecommandeRefusee extends Exception
{
    public const AUCUN_TARIF_LIVRAISON = 'aucun_tarif_livraison';

    public function __construct(public readonly string $raison)
    {
        parent::__construct($raison);
    }

    public static function pour(string $raison): self
    {
        return new self($raison);
    }
}
```

- [ ] **Step 5: Écrire le service**

Créer `app/Services/PrecommandeService.php` :

```php
<?php

namespace App\Services;

use App\Exceptions\PrecommandeRefusee;
use App\Models\Precommande;
use App\Models\Town;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PrecommandeService
{
    public function __construct(private readonly QuotationService $quotations) {}

    /**
     * @param  array<int, array{product: \App\Models\Product, quantity: int|float}>  $lines
     * @param  array{adresse: string, street: ?string, number_street: ?string, reference: ?string}  $adresse
     * @param  array{name: string, phone: string}  $destinataire
     *
     * @throws PrecommandeRefusee
     */
    public function creer(User $user, array $lines, Town $town, array $adresse, array $destinataire): Precommande
    {
        $quotation = $this->quotations->quote($lines, $town);

        if (! $quotation->disponible) {
            throw PrecommandeRefusee::pour((string) $quotation->raison);
        }

        // Le moteur tolère l'absence de tranche pour rester fidèle au client
        // web, qui facture alors 0 de livraison. Une pré-commande créée par une
        // machine ne doit pas promettre une livraison gratuite par accident.
        if ($quotation->bracket === null) {
            throw PrecommandeRefusee::pour(PrecommandeRefusee::AUCUN_TARIF_LIVRAISON);
        }

        $premier = $lines[array_key_first($lines)]['product'];

        return DB::transaction(function () use ($user, $lines, $town, $adresse, $destinataire, $quotation, $premier) {
            $precommande = Precommande::query()->create([
                'refernce' => $this->reference(),
                'user_id' => $user->id,
                'restaurant_id' => $premier->restaurant_id,
                'town_id' => $town->id,
                'adresse_delivery' => $adresse['adresse'],
                'street' => $adresse['street'] ?? null,
                'number_street' => $adresse['number_street'] ?? null,
                'reference_adresse' => $adresse['reference'] ?? null,
                'recipient_name' => $destinataire['name'],
                'recipient_phone' => $destinataire['phone'],
                'sous_total' => $quotation->sous_total,
                'frais_livraison' => $quotation->frais_livraison,
                'service_price' => $quotation->service_price,
                'total' => $quotation->total,
                'currency_id' => $quotation->currency?->id,
                'delivrery_price_id' => $quotation->bracket->id,
                'expires_at' => now()->addHours((int) config('precommande.validite_heures')),
                'status' => Precommande::STATUT_EN_ATTENTE,
            ]);

            foreach ($lines as $line) {
                $precommande->products()->create([
                    'product_id' => $line['product']->id,
                    'quantity' => $line['quantity'],
                    // Le prix du devis, pas celui du produit au moment du paiement.
                    'price' => $line['product']->price,
                ]);
            }

            return $precommande->load('products');
        });
    }

    /**
     * Préfixe P- : commandes.refernce est un entier nu et le webhook de
     * paiement cherche dessus. Les deux espaces ne doivent pas se croiser.
     */
    private function reference(): string
    {
        do {
            $reference = 'P-'.Str::upper(Str::random(12));
        } while (Precommande::query()->where('refernce', $reference)->exists());

        return $reference;
    }
}
```

- [ ] **Step 6: Écrire la validation**

Créer `app/Http/Requests/PrecommandeRequest.php` :

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PrecommandeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'town' => ['required', 'string'],
            'products' => ['required', 'array', 'min:1', 'max:100'],
            'products.*.uid' => ['required', 'string'],
            'products.*.quantity' => ['required', 'numeric', 'min:1'],

            'adresse' => ['required', 'array'],
            'adresse.adresse' => ['required', 'string', 'max:255'],
            'adresse.street' => ['nullable', 'string', 'max:255'],
            'adresse.number_street' => ['nullable', 'string', 'max:50'],
            'adresse.reference' => ['nullable', 'string', 'max:255'],

            'destinataire' => ['required', 'array'],
            'destinataire.name' => ['required', 'string', 'max:120'],
            'destinataire.phone' => ['required', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'town.required' => 'La ville de livraison est obligatoire.',
            'products.required' => 'Veuillez indiquer au moins un produit.',
            'products.max' => 'Une commande ne peut pas dépasser 100 produits différents.',
            'adresse.adresse.required' => 'L\'adresse de livraison est obligatoire.',
            'destinataire.required' => 'Veuillez indiquer qui doit être livré.',
            'destinataire.name.required' => 'Le nom de la personne à livrer est obligatoire.',
            'destinataire.phone.required' => 'Le numéro que le livreur appellera est obligatoire.',
        ];
    }
}
```

Noter qu'aucune règle n'accepte un prix : `total`, `total_price` et `pricing` envoyés par l'appelant ne sont simplement jamais lus.

- [ ] **Step 7: Écrire la ressource**

Créer `app/Http/Resources/PrecommandeResource.php` :

```php
<?php

namespace App\Http\Resources;

use App\Models\Precommande;
use App\Wrappers\Cipher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Precommande $resource
 */
class PrecommandeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uid' => Cipher::Encrypt($this->resource->id),
            'reference' => $this->resource->refernce,
            'statut' => $this->resource->estExpiree() ? Precommande::STATUT_EXPIREE : $this->resource->status,
            'sous_total' => $this->resource->sous_total,
            'frais_livraison' => $this->resource->frais_livraison,
            'service_price' => $this->resource->service_price,
            'total' => $this->resource->total,
            'currency' => $this->resource->currency ? [
                'code' => $this->resource->currency->code,
                'slug' => $this->resource->currency->slug,
            ] : null,
            'restaurant' => $this->resource->restaurant ? [
                'name' => $this->resource->restaurant->name,
                'slug' => $this->resource->restaurant->slug,
            ] : null,
            'adresse' => $this->resource->adresse_delivery,
            'destinataire' => [
                'name' => $this->resource->recipient_name,
                'phone' => $this->resource->recipient_phone,
            ],
            'produits' => $this->whenLoaded('products', fn () => $this->resource->products->map(fn ($ligne) => [
                'uid' => Cipher::Encrypt($ligne->product_id),
                'title' => $ligne->product?->title,
                'quantity' => $ligne->quantity,
                'price' => $ligne->price,
            ])->values()),
            'expires_at' => $this->resource->expires_at,
            'created_at' => $this->resource->created_at,
        ];
    }
}
```

- [ ] **Step 8: Écrire le contrôleur**

Créer `app/Http/Controllers/Api/PrecommandeController.php` :

```php
<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PrecommandeRefusee;
use App\Http\Controllers\Controller;
use App\Http\Requests\PrecommandeRequest;
use App\Http\Resources\PrecommandeResource;
use App\Models\Product;
use App\Models\Town;
use App\Services\PrecommandeService;
use App\Wrappers\ApiResponse;
use App\Wrappers\Cipher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\URL;

class PrecommandeController extends Controller
{
    public function __construct(private readonly PrecommandeService $precommandes) {}

    public function store(PrecommandeRequest $request): JsonResponse
    {
        $town = Town::query()->where('slug', $request->input('town'))->first();

        if (! $town) {
            return ApiResponse::NOT_FOUND('Oups', 'Cette ville de livraison est introuvable');
        }

        try {
            $lines = $this->resoudreLignes($request->input('products'));
        } catch (ModelNotFoundException) {
            return ApiResponse::BAD_REQUEST(
                'produit_introuvable',
                'Oups',
                'Un des produits demandés est introuvable ou n\'est plus disponible'
            );
        }

        try {
            $precommande = $this->precommandes->creer(
                $request->user(),
                $lines,
                $town,
                $request->input('adresse'),
                $request->input('destinataire'),
            );
        } catch (PrecommandeRefusee $e) {
            return ApiResponse::BAD_REQUEST(
                $e->raison,
                'Oups',
                $this->messageDeRefus($e->raison)
            );
        }

        return ApiResponse::SUCCESS_DATA(
            array_merge(
                (new PrecommandeResource($precommande))->toArray($request),
                ['lien_paiement' => $this->lienDePaiement($precommande)],
            ),
            'Pré-commande créée',
            'Votre pré-commande est valable '.config('precommande.validite_heures').' heures.'
        );
    }

    protected function lienDePaiement(\App\Models\Precommande $precommande): string
    {
        return URL::temporarySignedRoute(
            'precommande.paiement',
            $precommande->expires_at,
            ['uid' => Cipher::Encrypt($precommande->id)],
        );
    }

    private function messageDeRefus(string $raison): string
    {
        return match ($raison) {
            PrecommandeRefusee::AUCUN_TARIF_LIVRAISON => 'Nous ne livrons pas encore dans cette zone.',
            'multi_restaurant' => 'Une commande ne peut contenir que des plats d\'un seul restaurant.',
            'devises_melangees' => 'Tous les plats doivent être dans la même devise.',
            'panier_vide' => 'Veuillez indiquer au moins un produit.',
            'quantite_invalide' => 'La quantité doit être au moins égale à 1.',
            default => 'Cette commande ne peut pas être créée.',
        };
    }

    /**
     * @param  array<int, array{uid: string, quantity: int|float}>  $products
     * @return array<int, array{product: Product, quantity: int|float}>
     *
     * @throws ModelNotFoundException
     */
    protected function resoudreLignes(array $products): array
    {
        $ids = [];

        foreach ($products as $entry) {
            $id = Cipher::Decrypt($entry['uid']);

            if ($id === false || $id === '' || ! ctype_digit((string) $id)) {
                throw new ModelNotFoundException;
            }

            $ids[$entry['uid']] = (int) $id;
        }

        $trouves = Product::query()
            ->with('currency')
            ->whereIn('id', array_values($ids))
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $lines = [];

        foreach ($products as $entry) {
            $product = $trouves->get($ids[$entry['uid']]);

            if (! $product) {
                throw new ModelNotFoundException;
            }

            $lines[] = ['product' => $product, 'quantity' => $entry['quantity']];
        }

        return $lines;
    }
}
```

- [ ] **Step 9: Déclarer la route**

Dans `routes/api.php`, dans le groupe `auth:sanctum` créé en tâche 1 :

```php
    Route::middleware(['ability:precommande:creer', 'throttle:agent-ecriture'])->group(function () {
        Route::post('/precommandes', [\App\Http\Controllers\Api\PrecommandeController::class, 'store']);
    });
```

- [ ] **Step 10: Lancer les tests**

Run: `php artisan test tests/Feature/Api/PrecommandeCreationTest.php`
Expected: FAIL sur les tests qui vérifient `lien_paiement` — la route nommée `precommande.paiement` n'existe pas encore, elle arrive en tâche 7. C'est attendu.

Pour cette tâche, la définition de route minimale suivante est ajoutée à `routes/web.php` et sera étoffée en tâche 7 :

```php
Route::get('/paiement/precommande/{uid}', function () {
    abort(501);
})->name('precommande.paiement')->middleware('signed');
```

Relancer : Expected PASS, 10 tests.

- [ ] **Step 11: Formater et committer**

```bash
./vendor/bin/pint config/precommande.php app/Exceptions/PrecommandeRefusee.php app/Services/PrecommandeService.php app/Http/Requests/PrecommandeRequest.php app/Http/Resources/PrecommandeResource.php app/Http/Controllers/Api/PrecommandeController.php tests/Feature/Api/PrecommandeCreationTest.php
git diff routes/api.php routes/web.php
git add config/precommande.php app/Exceptions app/Services/PrecommandeService.php app/Http/Requests/PrecommandeRequest.php app/Http/Resources/PrecommandeResource.php app/Http/Controllers/Api/PrecommandeController.php routes/api.php routes/web.php .env.example tests/Feature/Api/PrecommandeCreationTest.php
git commit -m "feat: creation d'une precommande, sans aucun prix en entree

Le seul endroit ou un assistant peut ecrire. Aucune regle de validation
n'accepte de prix : total, total_price et pricing envoyes par l'appelant
ne sont jamais lus. Le serveur chiffre avec QuotationService et fige le
resultat sur l'entete et sur chaque ligne.

Une town sans tarif de livraison actif est refusee : le moteur tolere le
zero pour rester fidele au client web, mais une precommande creee par une
machine ne doit pas promettre une livraison gratuite par accident."
```

---

## Task 6: Lecture des pré-commandes et adresses récentes

Trois endpoints de lecture. Le dernier alimente la question que l'assistant doit poser : « on livre au même endroit que la dernière fois ? ». Il lit les **commandes passées**, pas `user_adresses`, qui est vide (0 ligne).

**Files:**
- Modify: `app/Http/Controllers/Api/PrecommandeController.php` (deux méthodes)
- Create: `app/Http/Controllers/Api/AdresseRecenteController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/PrecommandeLectureTest.php`

**Interfaces:**
- Consumes: `Precommande` et son scope `valides()` (tâche 4), `PrecommandeResource` et `lienDePaiement()` (tâche 5).
- Produces: `GET /api/precommandes`, `GET /api/precommandes/{uid}`, `GET /api/user/adresses-recentes`.

- [ ] **Step 1: Écrire les tests**

Créer `tests/Feature/Api/PrecommandeLectureTest.php` :

```php
<?php

namespace Tests\Feature\Api;

use App\Enums\TokenAbility;
use App\Models\Commande;
use App\Models\Precommande;
use App\Models\Town;
use App\Models\User;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrecommandeLectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_il_liste_les_siennes_uniquement(): void
    {
        $moi = User::factory()->create();
        Sanctum::actingAs($moi, TokenAbility::agent());

        Precommande::factory()->create(['user_id' => $moi->id]);
        Precommande::factory()->create();

        $response = $this->getJson('/api/precommandes');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_une_precommande_expiree_reste_visible_mais_marquee(): void
    {
        $moi = User::factory()->create();
        Sanctum::actingAs($moi, TokenAbility::agent());

        Precommande::factory()->expiree()->create(['user_id' => $moi->id]);

        $response = $this->getJson('/api/precommandes');

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('expiree', $response->json('data.0.statut'));
    }

    public function test_une_precommande_expiree_n_a_plus_de_lien(): void
    {
        $moi = User::factory()->create();
        Sanctum::actingAs($moi, TokenAbility::agent());

        $p = Precommande::factory()->expiree()->create(['user_id' => $moi->id]);

        $response = $this->getJson('/api/precommandes/'.Cipher::Encrypt($p->id));

        $response->assertStatus(200);
        $this->assertNull($response->json('data.lien_paiement'));
    }

    public function test_une_precommande_valide_porte_son_lien(): void
    {
        $moi = User::factory()->create();
        Sanctum::actingAs($moi, TokenAbility::agent());

        $p = Precommande::factory()->create(['user_id' => $moi->id]);

        $response = $this->getJson('/api/precommandes/'.Cipher::Encrypt($p->id));

        $this->assertNotEmpty($response->json('data.lien_paiement'));
    }

    public function test_on_ne_lit_pas_la_precommande_d_un_autre(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());

        $autre = Precommande::factory()->create();

        $this->getJson('/api/precommandes/'.Cipher::Encrypt($autre->id))->assertStatus(404);
    }

    public function test_les_adresses_recentes_viennent_des_commandes_passees(): void
    {
        $moi = User::factory()->create();
        Sanctum::actingAs($moi, TokenAbility::agent());

        $town = Town::factory()->create();

        Commande::query()->create([
            'refernce' => '9001', 'user_id' => $moi->id, 'status_id' => 3,
            'town_id' => $town->id, 'adresse_delivery' => 'Avenue Patrice',
            'street' => 'Rue A', 'number_street' => '1',
        ]);
        Commande::query()->create([
            'refernce' => '9002', 'user_id' => $moi->id, 'status_id' => 3,
            'town_id' => $town->id, 'adresse_delivery' => 'Avenue Patrice',
            'street' => 'Rue A', 'number_street' => '1',
        ]);
        Commande::query()->create([
            'refernce' => '9003', 'user_id' => $moi->id, 'status_id' => 3,
            'town_id' => $town->id, 'adresse_delivery' => 'Avenue Kasavubu',
            'street' => 'Rue B', 'number_street' => '2',
        ]);

        $response = $this->getJson('/api/user/adresses-recentes');

        $response->assertStatus(200);
        // Deux adresses distinctes, la plus recente d'abord.
        $this->assertCount(2, $response->json());
        $this->assertSame('Avenue Kasavubu', $response->json('0.adresse'));
    }

    public function test_les_adresses_d_un_autre_ne_fuient_pas(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());

        $autre = User::factory()->create();
        $town = Town::factory()->create();

        Commande::query()->create([
            'refernce' => '9004', 'user_id' => $autre->id, 'status_id' => 3,
            'town_id' => $town->id, 'adresse_delivery' => 'Chez quelqu un d autre',
        ]);

        $this->assertCount(0, $this->getJson('/api/user/adresses-recentes')->json());
    }
}
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `php artisan test tests/Feature/Api/PrecommandeLectureTest.php`
Expected: FAIL — 404 sur les trois routes.

- [ ] **Step 3: Ajouter les méthodes de lecture au contrôleur**

Dans `app/Http/Controllers/Api/PrecommandeController.php`, ajouter après `store()` :

```php
    public function index(Request $request): JsonResponse
    {
        $precommandes = Precommande::query()
            ->with(['products.product', 'restaurant', 'currency'])
            ->where('user_id', $request->user()->id)
            ->where('created_at', '>=', now()->subDays((int) config('precommande.visibilite_jours')))
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::GET_DATA([
            'data' => PrecommandeResource::collection($precommandes),
        ]);
    }

    public function show(Request $request, string $uid): JsonResponse
    {
        $precommande = $this->sienne($request, $uid);

        if (! $precommande) {
            return ApiResponse::NOT_FOUND('Oups', 'Cette pré-commande est introuvable');
        }

        return ApiResponse::GET_DATA([
            'data' => array_merge(
                (new PrecommandeResource($precommande))->toArray($request),
                // Un lien n'a de sens que sur une pré-commande encore payable.
                ['lien_paiement' => $precommande->estValide() ? $this->lienDePaiement($precommande) : null],
            ),
        ]);
    }

    /**
     * Restreint aux pré-commandes de l'utilisateur courant : celle d'un autre
     * doit être indiscernable d'un identifiant inexistant.
     */
    protected function sienne(Request $request, string $uid): ?Precommande
    {
        $id = Cipher::Decrypt($uid);

        if ($id === false || ! ctype_digit((string) $id)) {
            return null;
        }

        return Precommande::query()
            ->with(['products.product', 'restaurant', 'currency'])
            ->where('user_id', $request->user()->id)
            ->find((int) $id);
    }
```

et les imports manquants en tête de fichier :

```php
use App\Models\Precommande;
use Illuminate\Http\Request;
```

- [ ] **Step 4: Écrire le contrôleur des adresses**

Créer `app/Http/Controllers/Api/AdresseRecenteController.php` :

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Wrappers\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Les adresses que l'assistant propose au client.
 *
 * Elles viennent des commandes passées, pas de user_adresses : cette table
 * existe mais est vide, l'adresse ayant toujours été saisie par commande.
 */
class AdresseRecenteController extends Controller
{
    private const MAX = 10;

    public function index(Request $request): JsonResponse
    {
        $adresses = Commande::query()
            ->with('town')
            ->where('user_id', $request->user()->id)
            ->whereNotNull('adresse_delivery')
            ->orderByDesc('created_at')
            ->get(['adresse_delivery', 'street', 'number_street', 'reference_adresse', 'town_id', 'created_at'])
            ->unique(fn (Commande $c) => $c->adresse_delivery.'|'.$c->street.'|'.$c->number_street.'|'.$c->town_id)
            ->take(self::MAX)
            ->map(fn (Commande $c) => [
                'adresse' => $c->adresse_delivery,
                'street' => $c->street,
                'number_street' => $c->number_street,
                'reference' => $c->reference_adresse,
                'town' => $c->town?->slug,
                'town_title' => $c->town?->title,
                'derniere_utilisation' => $c->created_at,
            ])
            ->values();

        return ApiResponse::GET_DATA($adresses);
    }
}
```

- [ ] **Step 5: Déclarer les routes**

Dans `routes/api.php`, dans le groupe `auth:sanctum` de la tâche 1 :

```php
    Route::middleware(['ability:precommande:lire', 'throttle:agent-lecture'])->group(function () {
        Route::get('/precommandes', [\App\Http\Controllers\Api\PrecommandeController::class, 'index']);
        Route::get('/precommandes/{uid}', [\App\Http\Controllers\Api\PrecommandeController::class, 'show']);
        Route::get('/user/adresses-recentes', [\App\Http\Controllers\Api\AdresseRecenteController::class, 'index']);
    });
```

- [ ] **Step 6: Lancer les tests**

Run: `php artisan test tests/Feature/Api/PrecommandeLectureTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 7: Formater et committer**

```bash
./vendor/bin/pint app/Http/Controllers/Api/PrecommandeController.php app/Http/Controllers/Api/AdresseRecenteController.php tests/Feature/Api/PrecommandeLectureTest.php
git diff routes/api.php
git add app/Http/Controllers/Api/PrecommandeController.php app/Http/Controllers/Api/AdresseRecenteController.php routes/api.php tests/Feature/Api/PrecommandeLectureTest.php
git commit -m "feat: lecture des precommandes et adresses recentes

Une precommande expiree reste lisible mais perd son lien : l'assistant
peut dire « ta commande d'hier a expire, je te la refais ? » plutot que
« je ne trouve rien ».

Les adresses proposees viennent des commandes passees et non de
user_adresses, qui existe mais est vide."
```

---

## Task 7: Le lien de paiement signé

Le lien mène à une page Thalia, pas directement à FlexPay : le paiement mobile money exige le numéro du payeur, que la conversation n'a pas de raison de connaître — et une page permet de montrer le récapitulatif avant de débiter.

**Files:**
- Create: `app/Http/Controllers/PaiementPrecommandeController.php`
- Create: `resources/views/precommande/paiement.blade.php`
- Create: `resources/views/precommande/indisponible.blade.php`
- Modify: `routes/web.php`
- Modify: `routes/api.php` (le chemin applicatif)
- Test: `tests/Feature/Api/LienPaiementTest.php`

**Interfaces:**
- Consumes: `Precommande::estValide()` (tâche 4), `lienDePaiement()` (tâche 5), `App\Wrappers\FlexPay::sendData(array $data, string $method)`.
- Produces: la route nommée `precommande.paiement` (GET, signée) et `precommande.paiement.initier` (POST), plus `POST /api/precommandes/{uid}/paiement` pour l'application.

- [ ] **Step 1: Écrire les tests**

Créer `tests/Feature/Api/LienPaiementTest.php` :

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Precommande;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class LienPaiementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response([
            'code' => 0, 'orderNumber' => 'TEST-ORDER-1', 'message' => 'Transaction initiee',
        ], 200)]);
    }

    private function lien(Precommande $p, ?\DateTimeInterface $expiration = null): string
    {
        return URL::temporarySignedRoute(
            'precommande.paiement',
            $expiration ?: $p->expires_at,
            ['uid' => Cipher::Encrypt($p->id)],
        );
    }

    public function test_un_lien_valide_affiche_le_recapitulatif(): void
    {
        $p = Precommande::factory()->create();

        $this->get($this->lien($p))->assertStatus(200)->assertSee($p->refernce);
    }

    public function test_un_lien_sans_signature_est_refuse(): void
    {
        $p = Precommande::factory()->create();

        $this->get('/paiement/precommande/'.Cipher::Encrypt($p->id))->assertStatus(403);
    }

    public function test_un_lien_dont_la_signature_est_alteree_est_refuse(): void
    {
        $p = Precommande::factory()->create();

        $this->get($this->lien($p).'X')->assertStatus(403);
    }

    public function test_un_lien_vers_une_precommande_expiree_ne_paie_rien(): void
    {
        $p = Precommande::factory()->expiree()->create();

        // Signature encore valable, mais la pré-commande ne l'est plus :
        // la signature seule ne suffit jamais.
        $this->get($this->lien($p, now()->addHour()))
            ->assertStatus(410)
            ->assertSee('expir', false);
    }

    public function test_un_lien_rejoue_apres_paiement_ne_paie_rien(): void
    {
        $p = Precommande::factory()->payee()->create();

        $this->get($this->lien($p, now()->addHour()))->assertStatus(410);
    }

    public function test_l_initiation_enregistre_la_reference_de_paiement(): void
    {
        $p = Precommande::factory()->create();

        $this->post(route('precommande.paiement.initier', ['uid' => Cipher::Encrypt($p->id)]), [
            'phone' => '+243810000000',
        ])->assertRedirect();

        $this->assertSame('TEST-ORDER-1', $p->fresh()->reference_paiement);
    }

    public function test_l_initiation_envoie_le_total_fige_a_flexpay(): void
    {
        $p = Precommande::factory()->create(['total' => 5500]);

        $this->post(route('precommande.paiement.initier', ['uid' => Cipher::Encrypt($p->id)]), [
            'phone' => '+243810000000',
        ]);

        Http::assertSent(fn ($request) => (float) $request['amount'] === 5500.0);
    }

    public function test_l_initiation_sur_une_precommande_expiree_est_refusee(): void
    {
        $p = Precommande::factory()->expiree()->create();

        $this->post(route('precommande.paiement.initier', ['uid' => Cipher::Encrypt($p->id)]), [
            'phone' => '+243810000000',
        ])->assertStatus(410);

        Http::assertNothingSent();
    }
}
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `php artisan test tests/Feature/Api/LienPaiementTest.php`
Expected: FAIL — la route renvoie 501 (le bouchon posé en tâche 5).

- [ ] **Step 3: Écrire le contrôleur**

Créer `app/Http/Controllers/PaiementPrecommandeController.php` :

```php
<?php

namespace App\Http\Controllers;

use App\Models\Precommande;
use App\Wrappers\Cipher;
use App\Wrappers\FlexPay;
use App\Wrappers\LibPhoneNumber;
use Illuminate\Http\Request;

/**
 * Le lien signé mène ici, pas directement à FlexPay : le paiement mobile money
 * exige le numéro du payeur, que la conversation n'a pas de raison de connaître,
 * et une page permet de montrer le récapitulatif avant de débiter.
 */
class PaiementPrecommandeController extends Controller
{
    public function show(string $uid)
    {
        $precommande = $this->trouver($uid);

        if (! $precommande) {
            abort(404);
        }

        // La signature autorise l'accès ; elle ne dit rien de l'état. Une
        // pré-commande déjà payée ou expirée ne doit rien initier.
        if (! $precommande->estValide()) {
            return response()->view('precommande.indisponible', [
                'precommande' => $precommande,
            ], 410);
        }

        return view('precommande.paiement', [
            'precommande' => $precommande->load(['products.product', 'restaurant', 'currency']),
            'uid' => $uid,
        ]);
    }

    public function initier(Request $request, string $uid)
    {
        $precommande = $this->trouver($uid);

        if (! $precommande) {
            abort(404);
        }

        if (! $precommande->estValide()) {
            return response()->view('precommande.indisponible', [
                'precommande' => $precommande,
            ], 410);
        }

        $phone = (string) $request->input('phone');

        if (! (new LibPhoneNumber($phone))->checkValidationNumber()) {
            return back()->withErrors(['phone' => 'Numéro de téléphone invalide.']);
        }

        $result = FlexPay::sendData([
            // Le total figé, jamais un montant venu de la requête.
            'amount' => (float) $precommande->total,
            'phone' => $phone,
            'name' => $precommande->recipient_name,
            'email' => $precommande->user?->email,
            'currency' => $precommande->currency?->code ?: 'CDF',
            'reference' => $precommande->refernce,
            'callback_url' => config('flexpay.callback_url'),
            'approve_url' => config('app.url'),
            'cancel_url' => config('app.url'),
            'decline_url' => config('app.url'),
            'language' => 'fr',
            'description' => 'Paiement pré-commande Thalia Eats',
        ], 'mobile');

        if (! empty($result['code']) && $result['code'] != 0) {
            return back()->withErrors(['phone' => $result['message'] ?? 'Le paiement n\'a pas pu être initié.']);
        }

        $precommande->reference_paiement = $result['orderNumber'] ?? null;
        $precommande->save();

        return redirect()->away(config('app.url'));
    }

    private function trouver(string $uid): ?Precommande
    {
        $id = Cipher::Decrypt($uid);

        if ($id === false || ! ctype_digit((string) $id)) {
            return null;
        }

        return Precommande::query()->with('user')->find((int) $id);
    }
}
```

- [ ] **Step 4: Écrire les vues**

Créer `resources/views/precommande/paiement.blade.php` :

```blade
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payer votre pré-commande</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 24px; background: #faf9f7; color: #1a1a1a; }
        .carte { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 24px; }
        .ligne { display: flex; justify-content: space-between; padding: 6px 0; }
        .total { font-weight: 700; border-top: 1px solid #e5e5e5; margin-top: 12px; padding-top: 12px; }
        input, button { width: 100%; padding: 12px; font-size: 16px; border-radius: 8px; box-sizing: border-box; }
        input { border: 1px solid #ccc; margin-bottom: 12px; }
        button { border: 0; background: #1a1a1a; color: #fff; }
        .erreur { color: #b00020; margin-bottom: 12px; }
    </style>
</head>
<body>
<div class="carte">
    <h1>Votre pré-commande</h1>
    <p>Référence {{ $precommande->refernce }} — {{ $precommande->restaurant?->name }}</p>

    @foreach ($precommande->products as $ligne)
        <div class="ligne">
            <span>{{ $ligne->product?->title }} × {{ $ligne->quantity }}</span>
            <span>{{ $ligne->price }} {{ $precommande->currency?->code }}</span>
        </div>
    @endforeach

    <div class="ligne"><span>Livraison</span><span>{{ $precommande->frais_livraison }}</span></div>
    <div class="ligne"><span>Service</span><span>{{ $precommande->service_price }}</span></div>
    <div class="ligne total"><span>Total</span><span>{{ $precommande->total }} {{ $precommande->currency?->code }}</span></div>

    <p>Livraison à {{ $precommande->adresse_delivery }}, pour {{ $precommande->recipient_name }}.</p>

    @foreach ($errors->all() as $erreur)
        <p class="erreur">{{ $erreur }}</p>
    @endforeach

    <form method="POST" action="{{ route('precommande.paiement.initier', ['uid' => $uid]) }}">
        @csrf
        <label for="phone">Numéro mobile money</label>
        <input id="phone" name="phone" inputmode="tel" placeholder="+243…" required>
        <button type="submit">Payer</button>
    </form>
</div>
</body>
</html>
```

Créer `resources/views/precommande/indisponible.blade.php` :

```blade
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pré-commande indisponible</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 24px; background: #faf9f7; color: #1a1a1a; }
        .carte { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 24px; }
    </style>
</head>
<body>
<div class="carte">
    <h1>Ce lien n'est plus valable</h1>
    @if ($precommande->status === \App\Models\Precommande::STATUT_PAYEE)
        <p>Cette pré-commande a déjà été payée. Vous pouvez suivre votre commande dans l'application.</p>
    @else
        <p>Cette pré-commande a expiré. Demandez à votre assistant de vous en refaire une, à l'identique.</p>
    @endif
    <p>Référence {{ $precommande->refernce }}.</p>
</div>
</body>
</html>
```

- [ ] **Step 5: Déclarer les routes**

Dans `routes/web.php`, **remplacer** le bouchon posé en tâche 5 par :

```php
Route::get('/paiement/precommande/{uid}', [\App\Http\Controllers\PaiementPrecommandeController::class, 'show'])
    ->name('precommande.paiement')
    ->middleware(['signed', 'throttle:lien-paiement']);

Route::post('/paiement/precommande/{uid}', [\App\Http\Controllers\PaiementPrecommandeController::class, 'initier'])
    ->name('precommande.paiement.initier')
    ->middleware('throttle:lien-paiement');
```

Le POST n'est pas signé — il porte le jeton CSRF de la page, qui joue le même rôle et survit à la soumission du formulaire.

- [ ] **Step 6: Lancer les tests**

Run: `php artisan test tests/Feature/Api/LienPaiementTest.php`
Expected: PASS, 8 tests.

Si le POST est refusé en 419, c'est le jeton CSRF : vérifier que la vue contient bien `@csrf` et que le test utilise `$this->post()` après un `get()` sur la page, ou `withoutMiddleware(VerifyCsrfToken::class)` **uniquement** dans le test concerné.

- [ ] **Step 7: Ajouter le chemin applicatif**

Le lien signé sert celui qui est encore dans la conversation. Celui qui reprend son
téléphone doit pouvoir payer depuis l'application, sans retrouver le message de
Claude. Même initiation, authentification normale, réponse JSON.

Ajouter à `tests/Feature/Api/LienPaiementTest.php` :

```php
    public function test_l_application_peut_initier_le_paiement_sans_lien(): void
    {
        $moi = \App\Models\User::factory()->create();
        \Laravel\Sanctum\Sanctum::actingAs($moi, ['*']);

        $p = Precommande::factory()->create(['user_id' => $moi->id, 'total' => 5500]);

        $this->postJson('/api/precommandes/'.Cipher::Encrypt($p->id).'/paiement', [
            'phone' => '+243810000000',
        ])->assertStatus(200);

        $this->assertSame('TEST-ORDER-1', $p->fresh()->reference_paiement);
    }

    public function test_l_application_ne_paie_pas_la_precommande_d_un_autre(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs(\App\Models\User::factory()->create(), ['*']);

        $p = Precommande::factory()->create();

        $this->postJson('/api/precommandes/'.Cipher::Encrypt($p->id).'/paiement', [
            'phone' => '+243810000000',
        ])->assertStatus(404);

        Http::assertNothingSent();
    }

    public function test_un_assistant_ne_peut_pas_declencher_un_paiement(): void
    {
        // L'ability n'existe pas : le lien de paiement EST la confirmation
        // humaine, un agent ne doit jamais engager d'argent seul.
        $moi = \App\Models\User::factory()->create();
        \Laravel\Sanctum\Sanctum::actingAs($moi, \App\Enums\TokenAbility::agent());

        $p = Precommande::factory()->create(['user_id' => $moi->id]);

        $this->postJson('/api/precommandes/'.Cipher::Encrypt($p->id).'/paiement', [
            'phone' => '+243810000000',
        ])->assertStatus(403);

        Http::assertNothingSent();
    }
```

Extraire dans `PaiementPrecommandeController` la partie commune en une méthode
`protected initierFlexPay(Precommande $precommande, string $phone): array`, qui
contient l'appel `FlexPay::sendData(...)` et l'enregistrement de
`reference_paiement` écrits à l'étape 3, et la faire appeler par `initier()`.

Puis ajouter à `app/Http/Controllers/Api/PrecommandeController.php` :

```php
    public function payer(Request $request, string $uid): JsonResponse
    {
        $precommande = $this->sienne($request, $uid);

        if (! $precommande) {
            return ApiResponse::NOT_FOUND('Oups', 'Cette pré-commande est introuvable');
        }

        if (! $precommande->estValide()) {
            return ApiResponse::BAD_REQUEST(
                'precommande_indisponible',
                'Oups',
                'Cette pré-commande a expiré ou a déjà été payée.'
            );
        }

        $phone = (string) $request->input('phone');

        if (! (new LibPhoneNumber($phone))->checkValidationNumber()) {
            return ApiResponse::BAD_REQUEST('telephone_invalide', 'Oups', 'Numéro de téléphone invalide.');
        }

        $result = app(PaiementPrecommandeController::class)->initierFlexPay($precommande, $phone);

        if (! empty($result['code']) && $result['code'] != 0) {
            return ApiResponse::BAD_REQUEST('paiement_refuse', 'Oups', $result['message'] ?? 'Paiement impossible.');
        }

        return ApiResponse::GET_DATA($result);
    }
```

> Si appeler un contrôleur depuis un autre vous gêne — c'est légitime —, déplacez
> `initierFlexPay` dans un service `app/Services/PaiementPrecommandeService.php` et
> faites-le injecter par les deux contrôleurs. Signalez le choix dans votre rapport.

Rendre `initierFlexPay` `public` sur le contrôleur web si vous gardez la première
option, et ajouter les imports `LibPhoneNumber` et `PaiementPrecommandeController`.

Déclarer la route dans `routes/api.php`, **hors** de tout groupe portant une ability
d'agent — un assistant ne doit pas pouvoir l'atteindre :

```php
Route::middleware(['auth:sanctum', 'assistant.emetteur', 'throttle:agent-ecriture'])->group(function () {
    Route::post('/precommandes/{uid}/paiement', [\App\Http\Controllers\Api\PrecommandeController::class, 'payer']);
});
```

Run: `php artisan test tests/Feature/Api/LienPaiementTest.php`
Expected: PASS, 11 tests.

- [ ] **Step 8: Formater et committer**

```bash
./vendor/bin/pint app/Http/Controllers/PaiementPrecommandeController.php app/Http/Controllers/Api/PrecommandeController.php tests/Feature/Api/LienPaiementTest.php
git diff routes/web.php
git add app/Http/Controllers/PaiementPrecommandeController.php app/Http/Controllers/Api/PrecommandeController.php resources/views/precommande routes/web.php routes/api.php tests/Feature/Api/LienPaiementTest.php
git commit -m "feat: lien de paiement signe pour une precommande

Le lien mene a une page Thalia et non directement a FlexPay : le paiement
mobile money exige le numero du payeur, que la conversation n'a pas de
raison de connaitre, et une page permet de montrer le recapitulatif avant
de debiter.

La signature autorise l'acces, elle ne dit rien de l'etat : une
precommande deja payee ou expiree n'initie rien, meme avec une signature
encore valable. C'est ce qui rend l'usage unique reel."
```

---

## Task 8: La conversion au webhook

Le point le plus sensible du sous-projet. `PayementController::webhook` traite aujourd'hui des paiements réels : **le chemin `Commande` existant doit rester rigoureusement identique.**

Ce qui rend l'extension sûre : une pré-commande porte une référence préfixée `P-`, impossible à confondre avec `commandes.refernce` qui est un entier nu. `$order` n'est donc `null` au point d'insertion que pour une référence qui n'a jamais été une commande.

**Files:**
- Create: `app/Services/ConversionPrecommande.php`
- Modify: `app/Http/Controllers/Api/PayementController.php` (une insertion)
- Test: `tests/Feature/Api/ConversionPrecommandeTest.php`

**Interfaces:**
- Consumes: `Precommande` (tâche 4).
- Produces: `App\Services\ConversionPrecommande::convertir(Precommande $precommande): Commande`, et `convertirSiPossible(string $reference): ?Commande` qui renvoie `null` si la référence ne correspond à aucune pré-commande convertible.

- [ ] **Step 1: Écrire les tests**

Créer `tests/Feature/Api/ConversionPrecommandeTest.php` :

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Commande;
use App\Models\Precommande;
use App\Models\Product;
use App\Models\Status;
use App\Services\ConversionPrecommande;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversionPrecommandeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Status::factory()->create(['id' => 2]);
    }

    private function precommandeAvecLignes(): Precommande
    {
        $precommande = Precommande::factory()->create(['total' => 5500]);
        $produit = Product::factory()->create(['price' => 9999]);

        $precommande->products()->create([
            'product_id' => $produit->id,
            'quantity' => 2,
            'price' => 1500,
        ]);

        return $precommande->fresh('products');
    }

    public function test_la_commande_produite_est_au_statut_2_et_payee(): void
    {
        $commande = app(ConversionPrecommande::class)->convertir($this->precommandeAvecLignes());

        $this->assertSame(2, (int) $commande->status_id);
        $this->assertNotNull($commande->paied_at);
    }

    public function test_elle_porte_le_total_fige_et_non_le_prix_courant(): void
    {
        $commande = app(ConversionPrecommande::class)->convertir($this->precommandeAvecLignes());

        $this->assertSame(5500.0, (float) $commande->global_price);
        $this->assertSame(1500.0, (float) $commande->product->first()->price);
    }

    public function test_elle_reprend_le_destinataire_et_l_adresse(): void
    {
        $precommande = $this->precommandeAvecLignes();

        $commande = app(ConversionPrecommande::class)->convertir($precommande);

        $this->assertSame($precommande->recipient_name, $commande->recipient_name);
        $this->assertSame($precommande->recipient_phone, $commande->recipient_phone);
        $this->assertSame($precommande->adresse_delivery, $commande->adresse_delivery);
        $this->assertSame((int) $precommande->town_id, (int) $commande->town_id);
    }

    public function test_la_precommande_est_marquee_payee_et_liee(): void
    {
        $precommande = $this->precommandeAvecLignes();

        $commande = app(ConversionPrecommande::class)->convertir($precommande);

        $precommande->refresh();

        $this->assertSame(Precommande::STATUT_PAYEE, $precommande->status);
        $this->assertSame((int) $commande->id, (int) $precommande->commande_id);
        $this->assertNotNull($precommande->paied_at);
    }

    public function test_la_reference_de_la_commande_est_un_entier_nu(): void
    {
        // Sinon elle entrerait en collision avec l'espace des pré-commandes.
        $commande = app(ConversionPrecommande::class)->convertir($this->precommandeAvecLignes());

        $this->assertTrue(ctype_digit((string) $commande->refernce));
    }

    public function test_convertir_deux_fois_ne_cree_pas_deux_commandes(): void
    {
        $precommande = $this->precommandeAvecLignes();

        app(ConversionPrecommande::class)->convertir($precommande);
        $seconde = app(ConversionPrecommande::class)->convertirSiPossible($precommande->refernce);

        $this->assertNull($seconde);
        $this->assertSame(1, Commande::query()->count());
    }

    public function test_une_reference_de_commande_ordinaire_ne_convertit_rien(): void
    {
        $this->assertNull(app(ConversionPrecommande::class)->convertirSiPossible('1038'));
    }

    public function test_une_precommande_expiree_se_convertit_quand_meme_si_elle_est_payee(): void
    {
        // Le client a payé juste avant l'expiration ; le webhook arrive après.
        // Refuser ici encaisserait sans livrer.
        $precommande = Precommande::factory()->expiree()->create();
        Product::factory()->create();

        $commande = app(ConversionPrecommande::class)->convertirSiPossible($precommande->refernce);

        $this->assertNotNull($commande);
    }
}
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `php artisan test tests/Feature/Api/ConversionPrecommandeTest.php`
Expected: FAIL — `Target class [App\Services\ConversionPrecommande] does not exist.`

- [ ] **Step 3: Écrire le service de conversion**

Créer `app/Services/ConversionPrecommande.php` :

```php
<?php

namespace App\Services;

use App\Models\Commande;
use App\Models\CommandeProduct;
use App\Models\Precommande;
use Illuminate\Support\Facades\DB;

/**
 * Transforme une pré-commande payée en Commande ordinaire.
 *
 * La Commande produite doit être indiscernable d'une commande payée par le
 * chemin habituel : statut 2, paied_at renseigné, mêmes lignes. Restaurant et
 * livreur ne doivent voir aucune différence.
 */
class ConversionPrecommande
{
    public function convertirSiPossible(string $reference): ?Commande
    {
        $precommande = Precommande::query()
            ->with('products')
            ->where('refernce', $reference)
            ->where('status', Precommande::STATUT_EN_ATTENTE)
            ->first();

        // Ni une pré-commande, ni une pré-commande encore convertible.
        // L'expiration n'est PAS un motif de refus ici : le client a pu payer
        // juste avant, et refuser encaisserait sans livrer.
        return $precommande ? $this->convertir($precommande) : null;
    }

    public function convertir(Precommande $precommande): Commande
    {
        return DB::transaction(function () use ($precommande) {
            $derniere = Commande::query()->orderByDesc('id')->first();

            $commande = new Commande;
            // Entier nu, comme toutes les references de commande : le webhook
            // cherche dessus, et les deux espaces restent disjoints.
            $commande->refernce = (string) ($derniere ? 1000 + $derniere->id : 1000);
            $commande->user_id = $precommande->user_id;
            $commande->status_id = 2;
            $commande->town_id = $precommande->town_id;
            $commande->adresse_delivery = $precommande->adresse_delivery;
            $commande->street = $precommande->street;
            $commande->number_street = $precommande->number_street;
            $commande->reference_adresse = $precommande->reference_adresse;
            $commande->lat = $precommande->lat;
            $commande->long = $precommande->long;
            $commande->recipient_name = $precommande->recipient_name;
            $commande->recipient_phone = $precommande->recipient_phone;
            $commande->global_price = $precommande->total;
            $commande->price_delivery = $precommande->frais_livraison;
            $commande->price_service = $precommande->service_price;
            $commande->reference_paiement = $precommande->reference_paiement;
            $commande->code_confirmation = rand(1000, 9999);
            $commande->code_confirmation_restaurant = rand(1000, 9999);
            $commande->paied_at = now()->format('Y-m-d H:i:s');
            $commande->save();

            foreach ($precommande->products as $ligne) {
                $commande_product = new CommandeProduct;
                $commande_product->commande_id = $commande->id;
                $commande_product->product_id = $ligne->product_id;
                $commande_product->user_id = $precommande->user_id;
                $commande_product->quantity = $ligne->quantity;
                // Le prix du devis, pas celui du produit aujourd'hui.
                $commande_product->price = $ligne->price;
                $commande_product->currency_id = $precommande->currency_id;
                $commande_product->save();
            }

            $precommande->status = Precommande::STATUT_PAYEE;
            $precommande->commande_id = $commande->id;
            $precommande->paied_at = now();
            $precommande->save();

            return $commande;
        });
    }
}
```

- [ ] **Step 4: Lancer les tests du service**

Run: `php artisan test tests/Feature/Api/ConversionPrecommandeTest.php`
Expected: PASS, 8 tests.

- [ ] **Step 5: Écrire le test de non-régression du webhook**

Ajouter à `tests/Feature/Api/ConversionPrecommandeTest.php` :

```php
    public function test_le_webhook_d_une_commande_ordinaire_est_inchange(): void
    {
        \App\Models\StatusPayement::query()->firstOrCreate(
            ['is_default' => true],
            ['title' => 'En attente', 'slug' => 'en-attente-'.\Illuminate\Support\Str::random(6), 'is_paid' => false]
        );

        $commande = Commande::query()->create([
            'refernce' => '4242',
            'user_id' => \App\Models\User::factory()->create()->id,
            'status_id' => 5,
            'global_price' => 7000,
        ]);

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([
            'code' => 0, 'message' => 'ok', 'transaction' => ['status' => '0'],
        ], 200)]);

        $this->postJson('/api/webhook-paiement-flexpay', [
            'reference' => '4242',
            'orderNumber' => 'ORD-1',
            'amount' => 7000,
            'amountCustomer' => 7000,
            'channel' => 'MPESA',
            'code' => '0',
            'phone' => '243810000000',
            'provider_reference' => 'PROV-1',
        ]);

        // Aucune Commande supplémentaire n'a été créée par l'extension.
        $this->assertSame(1, Commande::query()->count());
        $this->assertSame('4242', Commande::query()->first()->refernce);
    }
```

- [ ] **Step 6: Brancher la conversion dans le webhook**

Dans `app/Http/Controllers/Api/PayementController.php`, la branche de succès est le
`} else {` qui suit `if ($result['code'] != 0) {`. Elle commence ainsi :

```php
            } else {

                $status = $result['transaction']['status'];

                $status_paiement = StatusPayement::query()->where('code', $status)->first();

                Payement::query()->updateOrCreate([
                    'commande_id' => $order?->id,
```

Insérer **entre** la ligne `$status_paiement = ...` et la ligne `Payement::query()->updateOrCreate([` :

```php
            // Une pré-commande porte une référence préfixée « P- », impossible à
            // confondre avec commandes.refernce qui est un entier nu. $order
            // n'est donc null ici que pour une référence qui n'a jamais été une
            // commande : le chemin Commande ci-dessus est rigoureusement inchangé.
            if (! $order) {
                $order = app(\App\Services\ConversionPrecommande::class)
                    ->convertirSiPossible($reference);

                if (! $order) {
                    Log::warning('webhook: reference inconnue', ['reference' => $reference]);

                    return ApiResponse::GET_DATA(['message' => 'Référence inconnue']);
                }
            }
```

Ne modifier **aucune autre ligne** de la méthode. En particulier, laisser
`'commande_id' => $order?->id` tel quel : l'opérateur null-safe y est déjà, et le
retirer changerait le comportement du chemin existant.

- [ ] **Step 7: Lancer les tests**

Run: `php artisan test tests/Feature/Api/ConversionPrecommandeTest.php`
Expected: PASS, 9 tests.

- [ ] **Step 8: Lancer la suite complète**

Run: `php artisan test`
Expected: aucune régression. `tests/Feature/ExampleTest.php` échoue toujours en 404 — préexistant, hors périmètre.

- [ ] **Step 9: Formater et committer**

```bash
./vendor/bin/pint app/Services/ConversionPrecommande.php app/Http/Controllers/Api/PayementController.php tests/Feature/Api/ConversionPrecommandeTest.php
git diff app/Http/Controllers/Api/PayementController.php
git add app/Services/ConversionPrecommande.php app/Http/Controllers/Api/PayementController.php tests/Feature/Api/ConversionPrecommandeTest.php
git commit -m "feat: conversion d'une precommande payee en commande

La Commande produite est indiscernable d'une commande payee par le chemin
habituel : statut 2, paied_at renseigne, memes lignes aux prix du devis.
Restaurant et livreur ne voient aucune difference.

Le chemin Commande du webhook est inchange : une precommande porte une
reference prefixee P-, impossible a confondre avec commandes.refernce qui
est un entier nu, donc l'extension ne se declenche que sur une reference
qui n'a jamais ete une commande. Un test le prouve.

Une precommande expiree se convertit quand meme si le paiement a abouti :
le client a pu payer juste avant l'expiration, et refuser ici encaisserait
sans livrer."
```

---

## Après ce plan

**Ce qui n'est pas fait, et qui ne doit pas l'être sans décision explicite :**

- **C-front** — l'écran des pré-commandes et le paiement depuis l'application, l'écran « connecter un assistant ». Dans les deux clients. Sans lui, le jeton n'est pas révocable par le client et la pré-commande n'est payable que depuis le lien.
- **C-mcp** — le serveur MCP lui-même, en Python et Docker sur le VPS.
- **Le catalogue est en dollars** : 50 produits actifs, tous en USD. « J'ai 500 FC » ne renverra rien tant qu'aucun produit n'est en CDF. Décision produit.
- **`restaurants.location` est vide** : la recherche par distance reste inerte.
- **Les trois failles préexistantes** : `POST /api/ia-model-llama3` (`Http::timeout(3600)` derrière un throttle à 60/min), `GET /api/roles` (route publique qui crée des rôles), et l'absence de `StatusPayement` par défaut qui ferait échouer `valide()` après l'appel à FlexPay.
