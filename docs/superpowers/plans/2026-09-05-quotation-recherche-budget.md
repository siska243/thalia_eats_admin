# Sous-projet A — Quotation, recherche et budget : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Donner au backend Laravel la capacité de calculer lui-même un total « plat + livraison + service », de chercher des plats, et de proposer ce qu'un budget permet de commander — sans changer le comportement de la production.

**Architecture:** Un moteur unique `QuotationService` reproduit à l'identique la règle de tarification qui vit aujourd'hui dans le navigateur (`calculePrice.js`). Il est exposé en lecture via trois nouveaux endpoints, et branché en **observation** sur `CommandeController::valide()` : il journalise les écarts avec le total envoyé par le client, mais le client reste autorité tant que le flag `QUOTATION_AUTHORITATIVE` est à `false`.

**Tech Stack:** PHP 8.1, Laravel 12, MySQL 8 (index FULLTEXT), Sanctum, PHPUnit, Laravel Pint.

**Spec:** `docs/superpowers/specs/2026-09-05-quotation-recherche-budget-design.md`

## Global Constraints

- **PHP `^8.1`** — propriétés `readonly` et enums disponibles ; PAS de `readonly class` (8.2+), PAS de constantes typées (8.3+).
- **Application en production, tolérance zéro régression.** Aucune route existante renommée, aucun champ de réponse modifié, aucune forme `ApiResponse` changée.
- **Toutes les réponses passent par `App\Wrappers\ApiResponse`.** Signatures exactes : `GET_DATA($data)` → 200 ; `SUCCESS_DATA($data, $title, $message)` → 201 ; `BAD_REQUEST($errors, $title, $message)` → 400 ; `NOT_FOUND($title, $message)` → 404 ; `NOT_AUTHORIZED($title, $message)` → 401.
- **Messages utilisateur en français**, consommés tels quels par les toasts des clients.
- **Les identifiants publics de produits sont chiffrés** par `App\Wrappers\Cipher` : `Cipher::Encrypt($id)` et `Cipher::Decrypt($uid)`. Les `uid` reçus en entrée doivent être déchiffrés ; les ids ne sortent jamais en clair.
- **Fautes de frappe historiques du domaine à conserver** : `DelivreryPrice`, `DelivreryDriver`, `Payement`, `refernce`, `delivrery_prices`, `delivrery_at`. Ne jamais les « corriger ».
- **La table des statuts s'appelle `status`** (singulier), pas `statuses`.
- **Formatage** : `./vendor/bin/pint` avant chaque commit.
- **Toute nouvelle route API doit être ajoutée** dans `front end/next-app/helpers/Route.ts` **et** `front end/thalia-delivery/helpers/Route.ts`. Ces deux fichiers appartiennent à des dépôts git distincts : les commits y sont séparés.
- **Aucun nouveau seeder** — `script-run.sh` lance `db:seed --force` en production.
- **Aucun secret en clair.** Les nouvelles valeurs de configuration passent par `.env`.

---

## Structure des fichiers

| Fichier | Responsabilité |
|---|---|
| `app/Services/Quotation.php` | Objet de valeur immuable : le résultat d'un chiffrage |
| `app/Services/QuotationService.php` | Le moteur : sous-total, sélection de tranche, total. Cache les tranches par town |
| `app/Services/RestaurantGeo.php` | Extraction défensive des coordonnées de `restaurants.location` + haversine |
| `app/Services/ProductSearchService.php` | Recherche catalogue : texte, catégorie, prix, devise, géo |
| `app/Services/BudgetSuggestionService.php` | Suggestions par budget, et explication quand rien ne rentre |
| `app/Http/Controllers/Api/QuotationController.php` | `POST /api/quote` et `POST /api/budget-suggestions` |
| `app/Http/Controllers/Api/ProductSearchController.php` | `GET /api/products/search` |
| `app/Http/Requests/QuoteRequest.php` | Validation de `/api/quote` |
| `app/Http/Requests/BudgetSuggestionRequest.php` | Validation de `/api/budget-suggestions` |
| `config/quotation.php` | Le flag `authoritative` |

Découpage par responsabilité, pas par couche : chaque service est testable seul et tient en contexte.

---

## Écart assumé avec la spec

Un point a été affiné en écrivant le plan, et **la spec a été mise à jour en conséquence** :

La spec disait qu'une tranche de livraison dont la devise diffère de celle des produits provoque un refus `devises_melangees`. C'est faux vis-à-vis de la décision « garder le comportement actuel » : `calculePrice.js` filtre les tranches **par town uniquement**, jamais par devise, et somme les frais quelle que soit leur devise. Un refus créerait un écart systématique dans le journal d'observation.

Règle retenue :
- **produits de devises différentes entre eux** → refus `devises_melangees` (aucun total sensé n'existe) ;
- **tranche d'une devise différente de celle des produits** → **warning** `devise_tranche_differente`, le total est calculé quand même, comme le fait le client.

---

## Task 1: Infrastructure de test

Aucun test réel n'existe (`tests/` ne contient que les `ExampleTest`) et aucune factory du catalogue n'existe. Sans cette tâche, aucune des suivantes n'est testable — et lancer la suite écraserait la base de développement.

**Files:**
- Create: `.env.testing`
- Modify: `phpunit.xml`
- Create: `database/factories/CurrencyFactory.php`
- Create: `database/factories/TownFactory.php`
- Create: `database/factories/RestaurantFactory.php`
- Create: `database/factories/CategoryProductFactory.php`
- Create: `database/factories/SubCategoryProductFactory.php`
- Create: `database/factories/ProductFactory.php`
- Create: `database/factories/DelivreryPriceFactory.php`
- Create: `database/factories/StatusFactory.php`
- Test: `tests/Feature/InfrastructureTest.php`

**Interfaces:**
- Consumes: rien.
- Produces: les factories utilisées par toutes les tâches suivantes. Signatures :
  `Currency::factory()`, `Town::factory()`, `Restaurant::factory()`,
  `CategoryProduct::factory()`, `SubCategoryProduct::factory()`,
  `Product::factory()`, `DelivreryPrice::factory()`, `Status::factory()`.
  États nommés : `Restaurant::factory()->inactive()`, `Restaurant::factory()->located(float $lat, float $lng)`, `Product::factory()->inactive()`, `DelivreryPrice::factory()->inactive()`.

- [ ] **Step 1: Vérifier la base de test MySQL**

Elle a déjà été créée pendant la préparation. Le client `mysql` en ligne de
commande refuse `root` sans mot de passe sur cette machine : passer par Laravel,
qui dispose des identifiants du `.env`.

```bash
php artisan tinker --execute="DB::statement('CREATE DATABASE IF NOT EXISTS thalia_eats_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); echo 'ok';"
```

Expected: `ok`. La commande est idempotente.

SQLite n'est pas une option : l'index `FULLTEXT` de la tâche 4 est spécifique à
MySQL et ferait échouer les migrations.

- [ ] **Step 2: Créer `.env.testing`**

```bash
cp .env .env.testing
```

**Attention : les lignes de ce `.env` sont indentées** (elles commencent par des
espaces). Laravel les tolère ; il faut donc éditer les lignes existantes en
place plutôt que d'en ajouter de nouvelles en début de ligne, sinon la clé se
retrouve en double et c'est la première lue qui gagne.

Après édition, `.env.testing` doit contenir exactement ces valeurs (l'indentation
d'origine peut être conservée) :

```
APP_ENV=testing
DB_CONNECTION=mysql
DB_DATABASE=thalia_eats_test
QUOTATION_AUTHORITATIVE=false
```

`DB_HOST`, `DB_PORT`, `DB_USERNAME` et `DB_PASSWORD` sont repris du `.env` sans
modification — ils pointent déjà sur le bon serveur. `APP_KEY` doit rester
identique à celui du `.env`.

Vérifier ensuite :

```bash
php artisan tinker --env=testing --execute="echo config('database.connections.mysql.database');"
```

Expected: `thalia_eats_test`

- [ ] **Step 3: Vérifier que `.env.testing` est ignoré par git**

Le fichier contient des identifiants de base. Il ne doit jamais être committé.

```bash
grep -n "^\.env" .gitignore
```

Attendu : une ligne `.env*` ou équivalent couvrant `.env.testing`. Si absente, ajouter `.env.testing` à `.gitignore` et le committer.

- [ ] **Step 4: Retirer de `phpunit.xml` les surcharges de base de données**

Les lignes SQLite commentées doivent être supprimées pour lever toute ambiguïté : la configuration vient désormais de `.env.testing`, que Laravel charge automatiquement quand `APP_ENV=testing`.

Dans `phpunit.xml`, supprimer ces deux lignes :

```xml
        <!-- <env name="DB_CONNECTION" value="sqlite"/> -->
        <!-- <env name="DB_DATABASE" value=":memory:"/> -->
```

- [ ] **Step 5: Générer le dump de schéma**

**L'historique des migrations de ce dépôt n'est pas rejouable à zéro.** Vérifié :
un `migrate` sur une base vide échoue sur trois migrations successives —

1. `2023_11_01_201908_currencies_to_product_column.php` : `Duplicate column name 'currency_id'`, elle fait doublon avec `2023_11_01_193157_currency_to_product_column.php` ;
2. `2023_11_08_102129_rename_table_roles.php` : `Table 'roles_user' doesn't exist` ;
3. `2023_11_08_121208_create_permission_tables.php` : `Table 'roles' already exists`.

`RefreshDatabase` est donc inutilisable en l'état. **Ne pas corriger ces trois
migrations** : elles sont déjà enregistrées comme exécutées en production, les
toucher ne réparerait rien là-bas et risquerait d'en casser le rejeu. La réponse
prévue par Laravel pour exactement ce cas est le dump de schéma : `migrate` le
charge quand la table `migrations` est vide, puis n'applique que les migrations
postérieures.

```bash
php artisan schema:dump
```

Expected: `Database schema dumped successfully.` et un fichier
`database/schema/mysql-schema.sql` d'environ 43 Ko.

Le dump est généré depuis la base de développement (`thalia_eats`), qui est la
référence réelle du schéma. Il ne contient **aucune donnée métier** — seulement
la structure et les lignes de la table `migrations`.

Vérifier que le rejeu à zéro fonctionne désormais :

```bash
php artisan db:wipe --force --env=testing && php artisan migrate --force --env=testing
```

Expected: aucune erreur, puis `Nothing to migrate.` sur un second appel.

Vérifier enfin le nombre de tables et une colonne dont le fichier de migration
diverge de la base :

```bash
php artisan tinker --env=testing --execute="echo count(DB::select('SHOW TABLES')).' tables; category_product_id: '.(Schema::hasColumn('sub_category_products','category_product_id')?'OK':'ABSENTE');"
```

Expected: `40 tables; category_product_id: OK`

**Ce dump n'a aucun effet sur la production.** `script-run.sh` lance
`migrate --force` sur une base dont la table `migrations` est pleine : le dump
n'est chargé que lorsqu'elle est vide.

- [ ] **Step 6: Écrire le test d'infrastructure (il doit échouer)**

Créer `tests/Feature/InfrastructureTest.php` :

```php
<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\DelivreryPrice;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Town;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_suite_tourne_sur_la_base_de_test_dediee(): void
    {
        $this->assertSame('thalia_eats_test', config('database.connections.mysql.database'));
    }

    public function test_les_factories_du_catalogue_produisent_un_produit_complet(): void
    {
        $product = Product::factory()->create();

        $this->assertInstanceOf(Restaurant::class, $product->restaurant);
        $this->assertInstanceOf(Currency::class, $product->currency);
        $this->assertNotEmpty($product->slug);
        $this->assertTrue($product->is_active);
    }

    public function test_la_factory_de_tranche_rattache_une_town_et_une_devise(): void
    {
        $bracket = DelivreryPrice::factory()->create();

        $this->assertInstanceOf(Town::class, $bracket->town);
        $this->assertInstanceOf(Currency::class, $bracket->currency);
        $this->assertTrue($bracket->is_active);
    }
}
```

- [ ] **Step 7: Lancer le test pour vérifier qu'il échoue**

Run: `php artisan test tests/Feature/InfrastructureTest.php`
Expected: FAIL — `Call to undefined method App\Models\Product::factory()` ou `Class "Database\Factories\ProductFactory" not found`.

- [ ] **Step 8: Créer les factories**

`database/factories/CurrencyFactory.php` :

```php
<?php

namespace Database\Factories;

use App\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CurrencyFactory extends Factory
{
    protected $model = Currency::class;

    public function definition(): array
    {
        return [
            'title' => 'Franc congolais',
            'code' => 'CDF',
            'icon' => null,
            'slug' => 'cdf-'.Str::random(8),
            'is_active' => true,
        ];
    }

    public function usd(): static
    {
        return $this->state(fn () => [
            'title' => 'Dollar américain',
            'code' => 'USD',
            'slug' => 'usd-'.Str::random(8),
        ]);
    }
}
```

`database/factories/TownFactory.php` :

```php
<?php

namespace Database\Factories;

use App\Models\Town;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TownFactory extends Factory
{
    protected $model = Town::class;

    public function definition(): array
    {
        $title = $this->faker->city();

        return [
            'title' => $title,
            'zip' => null,
            'slug' => Str::slug($title).'-'.Str::random(8),
            'is_active' => true,
        ];
    }
}
```

`database/factories/RestaurantFactory.php` — attention : le modèle `Restaurant` n'a PAS de hook de génération de slug, contrairement à `Product`. Le slug doit être fourni.

```php
<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\Town;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class RestaurantFactory extends Factory
{
    protected $model = Restaurant::class;

    public function definition(): array
    {
        $name = $this->faker->company();

        return [
            'user_id' => User::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(8),
            'adresse' => $this->faker->address(),
            'description' => $this->faker->sentence(),
            'reference' => Str::random(6),
            'openHours' => null,
            'is_active' => true,
            'banniere' => null,
            'phone' => '+243900000000',
            'whatsapp' => '+243900000000',
            'town_id' => Town::factory(),
            'location' => null,
            'email' => null,
            'deleted_at' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function located(float $lat, float $lng): static
    {
        return $this->state(fn () => ['location' => ['lat' => $lat, 'lng' => $lng]]);
    }
}
```

`database/factories/CategoryProductFactory.php` :

```php
<?php

namespace Database\Factories;

use App\Models\CategoryProduct;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CategoryProductFactory extends Factory
{
    protected $model = CategoryProduct::class;

    public function definition(): array
    {
        $title = $this->faker->word();

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::random(8),
            'picture' => null,
            'is_active' => true,
        ];
    }
}
```

`database/factories/SubCategoryProductFactory.php` :

```php
<?php

namespace Database\Factories;

use App\Models\CategoryProduct;
use App\Models\SubCategoryProduct;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SubCategoryProductFactory extends Factory
{
    protected $model = SubCategoryProduct::class;

    public function definition(): array
    {
        $title = $this->faker->word();

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::random(8),
            'picture' => null,
            'is_active' => true,
            // Colonne verifiee sur la base reelle : category_product_id.
            // Le fichier de migration, qui dit « category_id », est perime.
            'category_product_id' => CategoryProduct::factory(),
        ];
    }
}
```

`database/factories/ProductFactory.php` — le modèle génère lui-même le slug dans son hook `creating`, ne pas le fournir.

```php
<?php

namespace Database\Factories;

use App\Models\Currency;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\SubCategoryProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->words(2, true),
            'description' => $this->faker->sentence(),
            'price' => 1000.0,
            'promotionnalPrice' => null,
            'restaurant_id' => Restaurant::factory(),
            'sub_category_product_id' => SubCategoryProduct::factory(),
            'picture' => null,
            'is_active' => true,
            'currency_id' => Currency::factory(),
            'deleted_at' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
```

`database/factories/DelivreryPriceFactory.php` :

```php
<?php

namespace Database\Factories;

use App\Models\Currency;
use App\Models\DelivreryPrice;
use App\Models\Town;
use Illuminate\Database\Eloquent\Factories\Factory;

class DelivreryPriceFactory extends Factory
{
    protected $model = DelivreryPrice::class;

    public function definition(): array
    {
        return [
            'town_id' => Town::factory(),
            'interval_pricing' => 0,
            'interval_max_price' => 100000.0,
            'frais' => 2000.0,
            'service_price' => 500.0,
            'currency_id' => Currency::factory(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
```

`database/factories/StatusFactory.php` — la table s'appelle `status`, au singulier.

```php
<?php

namespace Database\Factories;

use App\Models\Status;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class StatusFactory extends Factory
{
    protected $model = Status::class;

    public function definition(): array
    {
        $title = $this->faker->word();

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::random(8),
            'color' => null,
            'icon' => null,
            'description' => null,
        ];
    }
}
```

- [ ] **Step 9: Lancer le test pour vérifier qu'il passe**

Run: `php artisan test tests/Feature/InfrastructureTest.php`
Expected: PASS, 3 tests.

Si `RefreshDatabase` échoue sur une migration, corriger la factory concernée avant de continuer — toutes les tâches suivantes en dépendent.

- [ ] **Step 10: Vérifier que la base de développement n'a pas été touchée**

Run: `php artisan test tests/Feature/InfrastructureTest.php && grep '^DB_DATABASE' .env`
Expected: le test passe ET `DB_DATABASE` du `.env` de développement est inchangé. La suite doit avoir tourné exclusivement sur `thalia_eats_test`.

- [ ] **Step 11: Formater et committer**

```bash
./vendor/bin/pint
git add phpunit.xml database/schema/mysql-schema.sql database/factories tests/Feature/InfrastructureTest.php
git commit -m "test: base MySQL dediee, dump de schema et factories

Les tests tapaient jusqu'ici la base du .env. Bascule sur une base
thalia_eats_test via .env.testing.

L'historique des migrations n'est pas rejouable a zero : doublon de
currency_id sur products, rename d'une table roles_user inexistante,
et collision entre la table roles du projet et celle de spatie.
RefreshDatabase etait donc inutilisable. Le dump de schema est la
reponse prevue par Laravel : migrate le charge quand la table
migrations est vide. Aucun effet en production, ou elle est pleine.

Ajout des factories manquantes pour Currency, Town, Restaurant,
CategoryProduct, SubCategoryProduct, Product, DelivreryPrice et Status.

SQLite n'est pas utilisable : l'index FULLTEXT a venir est specifique
a MySQL."
```

---

## Task 2: Le moteur de quotation

Le cœur du sous-projet. Il reproduit à l'identique `price_delivrery()` et `total()` de `front end/next-app/helpers/calculePrice.js`. Toute divergence ici se traduirait, après la bascule de la tâche 6, par un montant facturé différent en production — c'est pourquoi cette tâche est intégralement pilotée par les tests.

**Files:**
- Create: `app/Services/Quotation.php`
- Create: `app/Services/QuotationService.php`
- Test: `tests/Feature/Services/QuotationServiceTest.php`

**Interfaces:**
- Consumes: les factories de la tâche 1.
- Produces:
  - `App\Services\Quotation` — propriétés publiques `readonly` : `bool $disponible`, `float $sous_total`, `float $frais_livraison`, `float $service_price`, `float $total`, `?Currency $currency`, `?DelivreryPrice $bracket`, `array $warnings`, `?string $raison`. Méthodes : `static refus(string $raison): self`, `toArray(): array`.
  - `App\Services\QuotationService` — méthode d'instance `quote(array $lines, Town $town, ?int $expected_restaurant_id = null): Quotation` où `$lines` est `array<int, array{product: Product, quantity: int}>`. Constantes `RAISON_*` et `WARNING_*`.

Le service est une **instance**, pas une classe statique : il met en cache les tranches par town, ce qui évite un N+1 quand la tâche 5 chiffre des dizaines de produits d'affilée. Il se résout via le conteneur (`app(QuotationService::class)`).

- [ ] **Step 1: Écrire les tests de refus et de sous-total**

Créer `tests/Feature/Services/QuotationServiceTest.php` :

```php
<?php

namespace Tests\Feature\Services;

use App\Models\Currency;
use App\Models\DelivreryPrice;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Town;
use App\Services\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationServiceTest extends TestCase
{
    use RefreshDatabase;

    private QuotationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(QuotationService::class);
    }

    /**
     * @param  array<int, array{0: Product, 1: int}>  $pairs
     * @return array<int, array{product: Product, quantity: int}>
     */
    private function lines(array $pairs): array
    {
        return array_map(fn ($pair) => ['product' => $pair[0], 'quantity' => $pair[1]], $pairs);
    }

    public function test_un_panier_vide_est_refuse(): void
    {
        $town = Town::factory()->create();

        $quotation = $this->service->quote([], $town);

        $this->assertFalse($quotation->disponible);
        $this->assertSame(QuotationService::RAISON_PANIER_VIDE, $quotation->raison);
    }

    public function test_une_quantite_nulle_ou_negative_est_refusee(): void
    {
        $town = Town::factory()->create();
        $product = Product::factory()->create();

        $quotation = $this->service->quote($this->lines([[$product, 0]]), $town);

        $this->assertFalse($quotation->disponible);
        $this->assertSame(QuotationService::RAISON_QUANTITE_INVALIDE, $quotation->raison);
    }

    public function test_des_produits_de_restaurants_differents_sont_refuses(): void
    {
        $town = Town::factory()->create();
        $currency = Currency::factory()->create();

        $a = Product::factory()->create(['currency_id' => $currency->id]);
        $b = Product::factory()->create(['currency_id' => $currency->id]);

        $quotation = $this->service->quote($this->lines([[$a, 1], [$b, 1]]), $town);

        $this->assertFalse($quotation->disponible);
        $this->assertSame(QuotationService::RAISON_MULTI_RESTAURANT, $quotation->raison);
    }

    public function test_des_produits_de_devises_differentes_sont_refuses(): void
    {
        $town = Town::factory()->create();
        $restaurant = Restaurant::factory()->create();

        $cdf = Currency::factory()->create();
        $usd = Currency::factory()->usd()->create();

        $a = Product::factory()->create(['restaurant_id' => $restaurant->id, 'currency_id' => $cdf->id]);
        $b = Product::factory()->create(['restaurant_id' => $restaurant->id, 'currency_id' => $usd->id]);

        $quotation = $this->service->quote($this->lines([[$a, 1], [$b, 1]]), $town);

        $this->assertFalse($quotation->disponible);
        $this->assertSame(QuotationService::RAISON_DEVISES_MELANGEES, $quotation->raison);
    }

    public function test_un_restaurant_attendu_qui_ne_correspond_pas_est_refuse(): void
    {
        $town = Town::factory()->create();
        $product = Product::factory()->create();

        $quotation = $this->service->quote(
            $this->lines([[$product, 1]]),
            $town,
            (int) $product->restaurant_id + 999
        );

        $this->assertFalse($quotation->disponible);
        $this->assertSame(QuotationService::RAISON_RESTAURANT_INATTENDU, $quotation->raison);
    }

    public function test_le_sous_total_multiplie_le_prix_par_la_quantite(): void
    {
        $town = Town::factory()->create();
        $restaurant = Restaurant::factory()->create();
        $currency = Currency::factory()->create();

        DelivreryPrice::factory()->create([
            'town_id' => $town->id,
            'currency_id' => $currency->id,
            'interval_pricing' => 0,
            'interval_max_price' => 100000,
            'frais' => 2000,
            'service_price' => 500,
        ]);

        $product = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'currency_id' => $currency->id,
            'price' => 1500,
        ]);

        $quotation = $this->service->quote($this->lines([[$product, 3]]), $town);

        $this->assertTrue($quotation->disponible);
        $this->assertSame(4500.0, $quotation->sous_total);
        $this->assertSame(2000.0, $quotation->frais_livraison);
        $this->assertSame(500.0, $quotation->service_price);
        $this->assertSame(7000.0, $quotation->total);
    }

    public function test_le_prix_promotionnel_est_ignore(): void
    {
        $town = Town::factory()->create();
        $currency = Currency::factory()->create();

        DelivreryPrice::factory()->create([
            'town_id' => $town->id,
            'currency_id' => $currency->id,
            'frais' => 0,
            'service_price' => 0,
        ]);

        $product = Product::factory()->create([
            'currency_id' => $currency->id,
            'price' => 1000,
            'promotionnalPrice' => 400,
        ]);

        $quotation = $this->service->quote($this->lines([[$product, 1]]), $town);

        $this->assertSame(1000.0, $quotation->sous_total);
    }
}
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `php artisan test tests/Feature/Services/QuotationServiceTest.php`
Expected: FAIL — `Target class [App\Services\QuotationService] does not exist.`

- [ ] **Step 3: Écrire l'objet de valeur `Quotation`**

Créer `app/Services/Quotation.php` :

```php
<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\DelivreryPrice;

/**
 * Résultat immuable d'un chiffrage. Aucun accès base, aucune logique métier :
 * il ne fait que porter le détail du calcul et sa justification.
 */
class Quotation
{
    /**
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public readonly bool $disponible,
        public readonly float $sous_total,
        public readonly float $frais_livraison,
        public readonly float $service_price,
        public readonly float $total,
        public readonly ?Currency $currency = null,
        public readonly ?DelivreryPrice $bracket = null,
        public readonly array $warnings = [],
        public readonly ?string $raison = null,
    ) {}

    public static function refus(string $raison): self
    {
        return new self(false, 0.0, 0.0, 0.0, 0.0, null, null, [], $raison);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'disponible' => $this->disponible,
            'sous_total' => $this->sous_total,
            'frais_livraison' => $this->frais_livraison,
            'service_price' => $this->service_price,
            'total' => $this->total,
            'currency' => $this->currency ? [
                'id' => $this->currency->id,
                'code' => $this->currency->code,
                'slug' => $this->currency->slug,
            ] : null,
            'bracket_id' => $this->bracket?->id,
            'warnings' => $this->warnings,
            'raison' => $this->raison,
        ];
    }
}
```

- [ ] **Step 4: Écrire `QuotationService`**

Créer `app/Services/QuotationService.php` :

```php
<?php

namespace App\Services;

use App\Models\DelivreryPrice;
use App\Models\Product;
use App\Models\Town;
use Illuminate\Support\Collection;

/**
 * Reproduit à l'identique la règle de tarification qui vit aujourd'hui dans
 * le navigateur : front end/next-app/helpers/calculePrice.js, fonctions
 * price_delivrery() et total().
 *
 * Toute divergence avec ce fichier est un bug de ce service, pas du client.
 */
class QuotationService
{
    public const RAISON_PANIER_VIDE = 'panier_vide';

    public const RAISON_QUANTITE_INVALIDE = 'quantite_invalide';

    public const RAISON_MULTI_RESTAURANT = 'multi_restaurant';

    public const RAISON_DEVISES_MELANGEES = 'devises_melangees';

    public const RAISON_RESTAURANT_INATTENDU = 'restaurant_inattendu';

    public const WARNING_AUCUN_TARIF_ACTIF = 'aucun_tarif_actif';

    public const WARNING_HORS_TRANCHE = 'hors_tranche';

    public const WARNING_DEVISE_TRANCHE_DIFFERENTE = 'devise_tranche_differente';

    /** @var array<int, Collection<int, DelivreryPrice>> */
    private array $brackets_cache = [];

    /**
     * @param  array<int, array{product: Product, quantity: int}>  $lines
     * @param  Town  $town  town de l'adresse de LIVRAISON, jamais celle du restaurant
     */
    public function quote(array $lines, Town $town, ?int $expected_restaurant_id = null): Quotation
    {
        if ($lines === []) {
            return Quotation::refus(self::RAISON_PANIER_VIDE);
        }

        $restaurant_ids = [];
        $currency_ids = [];
        $sous_total = 0.0;

        foreach ($lines as $line) {
            $product = $line['product'];
            $quantity = (int) $line['quantity'];

            if ($quantity < 1) {
                return Quotation::refus(self::RAISON_QUANTITE_INVALIDE);
            }

            $restaurant_ids[(int) $product->restaurant_id] = true;
            $currency_ids[(int) $product->currency_id] = true;

            // products.price, jamais promotionnalPrice : c'est ce que valide()
            // écrit dans commande_products.price.
            $sous_total += (float) $product->price * $quantity;
        }

        // calcul_price() applique toFixed(2) côté client.
        $sous_total = round($sous_total, 2);

        if (count($restaurant_ids) > 1) {
            return Quotation::refus(self::RAISON_MULTI_RESTAURANT);
        }

        if (count($currency_ids) > 1) {
            return Quotation::refus(self::RAISON_DEVISES_MELANGEES);
        }

        if ($expected_restaurant_id !== null && ! isset($restaurant_ids[$expected_restaurant_id])) {
            return Quotation::refus(self::RAISON_RESTAURANT_INATTENDU);
        }

        $currency = $lines[array_key_first($lines)]['product']->currency;
        $warnings = [];

        $brackets = $this->bracketsFor($town);

        if ($brackets->isEmpty()) {
            // price_delivrery() renvoie null, l'appelant retombe sur 0 / 0.
            $warnings[] = self::WARNING_AUCUN_TARIF_ACTIF;

            return new Quotation(true, $sous_total, 0.0, 0.0, $sous_total, $currency, null, $warnings);
        }

        $bracket = $brackets->first(
            fn (DelivreryPrice $b) => $sous_total >= (float) $b->interval_pricing
                && $sous_total <= (float) $b->interval_max_price
        );

        if ($bracket === null) {
            // findPricing est undefined, le client applique ?? 0 sur les deux frais.
            $warnings[] = self::WARNING_HORS_TRANCHE;

            return new Quotation(true, $sous_total, 0.0, 0.0, $sous_total, $currency, null, $warnings);
        }

        // Le client ne compare jamais la devise de la tranche à celle des
        // produits : on signale sans refuser, pour rester bug-compatible.
        if ($currency !== null && (int) $bracket->currency_id !== (int) $currency->id) {
            $warnings[] = self::WARNING_DEVISE_TRANCHE_DIFFERENTE;
        }

        $frais = (float) $bracket->frais;
        $service = (float) $bracket->service_price;

        return new Quotation(
            true,
            $sous_total,
            $frais,
            $service,
            round($sous_total + $frais + $service, 2),
            $currency,
            $bracket,
            $warnings,
        );
    }

    /**
     * @return Collection<int, DelivreryPrice>
     */
    private function bracketsFor(Town $town): Collection
    {
        $id = (int) $town->id;

        // orderBy('id') reproduit l'ordre dans lequel DefaultDataController::index()
        // renvoie les tranches au client — ordre sur lequel s'appuie le .find()
        // de calculePrice.js. S'en remettre à l'ordre naturel de MySQL exposerait
        // à une divergence silencieuse.
        return $this->brackets_cache[$id] ??= DelivreryPrice::query()
            ->where('town_id', $id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }
}
```

- [ ] **Step 5: Lancer les tests pour vérifier qu'ils passent**

Run: `php artisan test tests/Feature/Services/QuotationServiceTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 6: Écrire les tests de sélection de tranche**

Ajouter ces méthodes à `tests/Feature/Services/QuotationServiceTest.php` :

```php
    /**
     * Crée un produit à `price` dans une town, et renvoie [Town, Product, Currency].
     *
     * @return array{0: Town, 1: Product, 2: Currency}
     */
    private function contexte(float $price): array
    {
        $town = Town::factory()->create();
        $currency = Currency::factory()->create();
        $product = Product::factory()->create([
            'currency_id' => $currency->id,
            'price' => $price,
        ]);

        return [$town, $product, $currency];
    }

    public function test_la_borne_basse_de_la_tranche_est_inclusive(): void
    {
        [$town, $product, $currency] = $this->contexte(5000);

        DelivreryPrice::factory()->create([
            'town_id' => $town->id, 'currency_id' => $currency->id,
            'interval_pricing' => 5000, 'interval_max_price' => 9000,
            'frais' => 3000, 'service_price' => 700,
        ]);

        $quotation = $this->service->quote($this->lines([[$product, 1]]), $town);

        $this->assertSame(3000.0, $quotation->frais_livraison);
        $this->assertSame(8700.0, $quotation->total);
        $this->assertSame([], $quotation->warnings);
    }

    public function test_la_borne_haute_de_la_tranche_est_inclusive(): void
    {
        [$town, $product, $currency] = $this->contexte(9000);

        DelivreryPrice::factory()->create([
            'town_id' => $town->id, 'currency_id' => $currency->id,
            'interval_pricing' => 5000, 'interval_max_price' => 9000,
            'frais' => 3000, 'service_price' => 700,
        ]);

        $quotation = $this->service->quote($this->lines([[$product, 1]]), $town);

        $this->assertSame(3000.0, $quotation->frais_livraison);
        $this->assertSame([], $quotation->warnings);
    }

    public function test_un_sous_total_au_dessus_de_toutes_les_tranches_ne_paie_aucun_frais(): void
    {
        [$town, $product, $currency] = $this->contexte(50000);

        DelivreryPrice::factory()->create([
            'town_id' => $town->id, 'currency_id' => $currency->id,
            'interval_pricing' => 0, 'interval_max_price' => 9000,
            'frais' => 3000, 'service_price' => 700,
        ]);

        $quotation = $this->service->quote($this->lines([[$product, 1]]), $town);

        $this->assertTrue($quotation->disponible);
        $this->assertSame(0.0, $quotation->frais_livraison);
        $this->assertSame(0.0, $quotation->service_price);
        $this->assertSame(50000.0, $quotation->total);
        $this->assertContains(QuotationService::WARNING_HORS_TRANCHE, $quotation->warnings);
    }

    public function test_une_town_sans_aucune_tranche_active_ne_paie_aucun_frais(): void
    {
        [$town, $product, $currency] = $this->contexte(5000);

        DelivreryPrice::factory()->inactive()->create([
            'town_id' => $town->id, 'currency_id' => $currency->id,
            'interval_pricing' => 0, 'interval_max_price' => 100000,
            'frais' => 3000, 'service_price' => 700,
        ]);

        $quotation = $this->service->quote($this->lines([[$product, 1]]), $town);

        $this->assertSame(0.0, $quotation->frais_livraison);
        $this->assertContains(QuotationService::WARNING_AUCUN_TARIF_ACTIF, $quotation->warnings);
    }

    public function test_une_tranche_a_interval_max_price_zero_ne_matche_jamais(): void
    {
        [$town, $product, $currency] = $this->contexte(5000);

        // Cas réel : interval_max_price a été ajouté le 2025-05-25 avec default(0).
        DelivreryPrice::factory()->create([
            'town_id' => $town->id, 'currency_id' => $currency->id,
            'interval_pricing' => 0, 'interval_max_price' => 0,
            'frais' => 3000, 'service_price' => 700,
        ]);

        $quotation = $this->service->quote($this->lines([[$product, 1]]), $town);

        $this->assertSame(0.0, $quotation->frais_livraison);
        $this->assertContains(QuotationService::WARNING_HORS_TRANCHE, $quotation->warnings);
    }

    public function test_les_tranches_d_une_autre_town_sont_ignorees(): void
    {
        [$town, $product, $currency] = $this->contexte(5000);
        $autre_town = Town::factory()->create();

        DelivreryPrice::factory()->create([
            'town_id' => $autre_town->id, 'currency_id' => $currency->id,
            'interval_pricing' => 0, 'interval_max_price' => 100000,
            'frais' => 3000, 'service_price' => 700,
        ]);

        $quotation = $this->service->quote($this->lines([[$product, 1]]), $town);

        $this->assertSame(0.0, $quotation->frais_livraison);
        $this->assertContains(QuotationService::WARNING_AUCUN_TARIF_ACTIF, $quotation->warnings);
    }

    public function test_la_premiere_tranche_par_id_gagne_quand_deux_se_chevauchent(): void
    {
        [$town, $product, $currency] = $this->contexte(5000);

        $premiere = DelivreryPrice::factory()->create([
            'town_id' => $town->id, 'currency_id' => $currency->id,
            'interval_pricing' => 0, 'interval_max_price' => 100000,
            'frais' => 1000, 'service_price' => 100,
        ]);

        DelivreryPrice::factory()->create([
            'town_id' => $town->id, 'currency_id' => $currency->id,
            'interval_pricing' => 0, 'interval_max_price' => 100000,
            'frais' => 9000, 'service_price' => 900,
        ]);

        $quotation = $this->service->quote($this->lines([[$product, 1]]), $town);

        $this->assertSame($premiere->id, $quotation->bracket?->id);
        $this->assertSame(1000.0, $quotation->frais_livraison);
    }

    public function test_une_tranche_dans_une_autre_devise_est_signalee_mais_appliquee(): void
    {
        [$town, $product, $currency] = $this->contexte(5000);
        $usd = Currency::factory()->usd()->create();

        DelivreryPrice::factory()->create([
            'town_id' => $town->id, 'currency_id' => $usd->id,
            'interval_pricing' => 0, 'interval_max_price' => 100000,
            'frais' => 3000, 'service_price' => 700,
        ]);

        $quotation = $this->service->quote($this->lines([[$product, 1]]), $town);

        // Le client ne compare pas les devises : on reste bug-compatible.
        $this->assertTrue($quotation->disponible);
        $this->assertSame(8700.0, $quotation->total);
        $this->assertContains(QuotationService::WARNING_DEVISE_TRANCHE_DIFFERENTE, $quotation->warnings);
    }
```

- [ ] **Step 7: Lancer les tests pour vérifier qu'ils passent**

Run: `php artisan test tests/Feature/Services/QuotationServiceTest.php`
Expected: PASS, 15 tests.

Ces huit derniers tests documentent le comportement réel de la production, bugs compris. Aucun d'eux ne doit être « corrigé » : les faire échouer volontairement reviendrait à changer les montants facturés.

- [ ] **Step 8: Formater et committer**

```bash
./vendor/bin/pint
git add app/Services tests/Feature/Services
git commit -m "feat: moteur de quotation cote serveur

QuotationService reproduit price_delivrery() et total() de
calculePrice.js : tranche indexee sur le sous-total, selectionnee par
la town de livraison dans l'ordre de cle primaire, et retour a 0 de
frais quand aucune tranche ne matche.

Le service n'est branche nulle part a ce stade : rien ne change pour
la production."
```

---

## Task 3: `POST /api/quote`

Le premier endpoint. Lecture pure : il ne crée rien, ne modifie rien. C'est la brique que consommeront l'agent (B) et le connecteur MCP (C).

**Files:**
- Create: `app/Http/Requests/QuoteRequest.php`
- Create: `app/Http/Controllers/Api/QuotationController.php`
- Modify: `routes/api.php` (ajout d'un groupe, aucune route existante touchée)
- Test: `tests/Feature/Api/QuoteEndpointTest.php`
- Modify (dépôt web) : `front end/next-app/helpers/Route.ts`
- Modify (dépôt mobile) : `front end/thalia-delivery/helpers/Route.ts`

**Interfaces:**
- Consumes: `App\Services\QuotationService::quote()` et `App\Services\Quotation` (tâche 2).
- Produces:
  - `App\Http\Controllers\Api\QuotationController::quote(QuoteRequest $request)` → `JsonResponse`.
  - `QuotationController::resolveLines(array $products): array` — méthode `protected`, réutilisée par la tâche 5. Prend `[['uid' => string, 'quantity' => int], ...]`, renvoie `array<int, array{product: Product, quantity: int}>`, et lève `ModelNotFoundException` si un `uid` ne se déchiffre pas ou ne correspond à aucun produit actif.

- [ ] **Step 1: Écrire les tests d'endpoint**

Créer `tests/Feature/Api/QuoteEndpointTest.php` :

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Currency;
use App\Models\DelivreryPrice;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Town;
use App\Models\User;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuoteEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_l_endpoint_exige_une_authentification(): void
    {
        $this->postJson('/api/quote', [])->assertStatus(401);
    }

    public function test_il_renvoie_le_detail_du_chiffrage(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $town = Town::factory()->create();
        $currency = Currency::factory()->create();

        DelivreryPrice::factory()->create([
            'town_id' => $town->id, 'currency_id' => $currency->id,
            'interval_pricing' => 0, 'interval_max_price' => 100000,
            'frais' => 2000, 'service_price' => 500,
        ]);

        $product = Product::factory()->create([
            'currency_id' => $currency->id,
            'price' => 1500,
        ]);

        $response = $this->postJson('/api/quote', [
            'town' => $town->slug,
            'products' => [
                ['uid' => Cipher::Encrypt($product->id), 'quantity' => 2],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'disponible' => true,
                'sous_total' => 3000,
                'frais_livraison' => 2000,
                'service_price' => 500,
                'total' => 5500,
            ]);

        // Les diagnostics internes ne fuitent pas vers les clients.
        $response->assertJsonMissingPath('warnings');
        $response->assertJsonMissingPath('bracket_id');
    }

    public function test_un_panier_multi_restaurants_est_refuse_avec_sa_raison(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $town = Town::factory()->create();
        $currency = Currency::factory()->create();
        DelivreryPrice::factory()->create(['town_id' => $town->id, 'currency_id' => $currency->id]);

        $a = Product::factory()->create(['currency_id' => $currency->id]);
        $b = Product::factory()->create(['currency_id' => $currency->id]);

        $this->postJson('/api/quote', [
            'town' => $town->slug,
            'products' => [
                ['uid' => Cipher::Encrypt($a->id), 'quantity' => 1],
                ['uid' => Cipher::Encrypt($b->id), 'quantity' => 1],
            ],
        ])->assertStatus(200)->assertJson([
            'disponible' => false,
            'raison' => 'multi_restaurant',
        ]);
    }

    public function test_le_restaurant_attendu_sert_de_garde_fou(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $town = Town::factory()->create();
        $currency = Currency::factory()->create();
        DelivreryPrice::factory()->create(['town_id' => $town->id, 'currency_id' => $currency->id]);

        $product = Product::factory()->create(['currency_id' => $currency->id]);
        $autre = Restaurant::factory()->create();

        $this->postJson('/api/quote', [
            'town' => $town->slug,
            'restaurant' => $autre->slug,
            'products' => [['uid' => Cipher::Encrypt($product->id), 'quantity' => 1]],
        ])->assertStatus(200)->assertJson([
            'disponible' => false,
            'raison' => 'restaurant_inattendu',
        ]);
    }

    public function test_un_uid_illisible_renvoie_une_erreur_400(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $town = Town::factory()->create();

        $this->postJson('/api/quote', [
            'town' => $town->slug,
            'products' => [['uid' => 'pas-un-uid-chiffre', 'quantity' => 1]],
        ])->assertStatus(400);
    }

    public function test_un_produit_inactif_est_introuvable(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $town = Town::factory()->create();
        $product = Product::factory()->inactive()->create();

        $this->postJson('/api/quote', [
            'town' => $town->slug,
            'products' => [['uid' => Cipher::Encrypt($product->id), 'quantity' => 1]],
        ])->assertStatus(400);
    }

    public function test_une_town_inconnue_renvoie_404(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $product = Product::factory()->create();

        $this->postJson('/api/quote', [
            'town' => 'town-qui-n-existe-pas',
            'products' => [['uid' => Cipher::Encrypt($product->id), 'quantity' => 1]],
        ])->assertStatus(404);
    }

    public function test_une_quantite_absente_est_rejetee_par_la_validation(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $town = Town::factory()->create();
        $product = Product::factory()->create();

        $this->postJson('/api/quote', [
            'town' => $town->slug,
            'products' => [['uid' => Cipher::Encrypt($product->id)]],
        ])->assertStatus(422);
    }
}
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `php artisan test tests/Feature/Api/QuoteEndpointTest.php`
Expected: FAIL — les requêtes renvoient 404, la route n'existe pas.

- [ ] **Step 3: Écrire la validation**

Créer `app/Http/Requests/QuoteRequest.php` :

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QuoteRequest extends FormRequest
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
            'restaurant' => ['nullable', 'string'],
            'products' => ['required', 'array', 'min:1'],
            'products.*.uid' => ['required', 'string'],
            'products.*.quantity' => ['required', 'integer', 'min:1'],
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
            'products.min' => 'Veuillez indiquer au moins un produit.',
            'products.*.uid.required' => 'Chaque produit doit porter un identifiant.',
            'products.*.quantity.required' => 'Chaque produit doit porter une quantité.',
            'products.*.quantity.min' => 'La quantité doit être au moins égale à 1.',
        ];
    }
}
```

- [ ] **Step 4: Écrire le contrôleur**

Créer `app/Http/Controllers/Api/QuotationController.php` :

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuoteRequest;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Town;
use App\Services\Quotation;
use App\Services\QuotationService;
use App\Wrappers\ApiResponse;
use App\Wrappers\Cipher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

class QuotationController extends Controller
{
    public function __construct(private readonly QuotationService $quotations) {}

    public function quote(QuoteRequest $request): JsonResponse
    {
        $town = Town::query()->where('slug', $request->input('town'))->first();

        if (! $town) {
            return ApiResponse::NOT_FOUND('Oups', 'Cette ville de livraison est introuvable');
        }

        $expected_restaurant_id = null;

        if ($request->filled('restaurant')) {
            $restaurant = Restaurant::query()->where('slug', $request->input('restaurant'))->first();

            if (! $restaurant) {
                return ApiResponse::NOT_FOUND('Oups', 'Ce restaurant est introuvable');
            }

            $expected_restaurant_id = (int) $restaurant->id;
        }

        try {
            $lines = $this->resolveLines($request->input('products'));
        } catch (ModelNotFoundException $e) {
            return ApiResponse::BAD_REQUEST(
                'produit_introuvable',
                'Oups',
                'Un des produits demandés est introuvable ou n\'est plus disponible'
            );
        }

        $quotation = $this->quotations->quote($lines, $town, $expected_restaurant_id);

        return ApiResponse::GET_DATA($this->present($quotation));
    }

    /**
     * Diagnostics internes exclus : `warnings` et `bracket_id` servent à
     * l'observation côté serveur, pas aux clients.
     *
     * @return array<string, mixed>
     */
    protected function present(Quotation $quotation): array
    {
        return [
            'disponible' => $quotation->disponible,
            'sous_total' => $quotation->sous_total,
            'frais_livraison' => $quotation->frais_livraison,
            'service_price' => $quotation->service_price,
            'total' => $quotation->total,
            'currency' => $quotation->currency ? [
                'code' => $quotation->currency->code,
                'slug' => $quotation->currency->slug,
            ] : null,
            'raison' => $quotation->raison,
        ];
    }

    /**
     * @param  array<int, array{uid: string, quantity: int}>  $products
     * @return array<int, array{product: Product, quantity: int}>
     *
     * @throws ModelNotFoundException
     */
    protected function resolveLines(array $products): array
    {
        $ids = [];

        foreach ($products as $entry) {
            $id = Cipher::Decrypt($entry['uid']);

            if ($id === false || $id === '' || ! ctype_digit((string) $id)) {
                throw new ModelNotFoundException();
            }

            $ids[] = (int) $id;
        }

        $found = Product::query()
            ->with('currency')
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $lines = [];

        foreach ($products as $index => $entry) {
            $product = $found->get($ids[$index]);

            if (! $product) {
                throw new ModelNotFoundException();
            }

            $lines[] = ['product' => $product, 'quantity' => (int) $entry['quantity']];
        }

        return $lines;
    }
}
```

- [ ] **Step 5: Déclarer la route**

Dans `routes/api.php`, ajouter ce bloc **après** le groupe `Route::middleware('auth:sanctum')->prefix('/user')` existant (il se termine ligne 135 par `});`), sans modifier une seule ligne existante :

```php
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/quote', [\App\Http\Controllers\Api\QuotationController::class, 'quote']);
});
```

- [ ] **Step 6: Lancer les tests pour vérifier qu'ils passent**

Run: `php artisan test tests/Feature/Api/QuoteEndpointTest.php`
Expected: PASS, 8 tests.

- [ ] **Step 7: Vérifier qu'aucune route existante n'a bougé**

Run: `php artisan route:list --path=api | wc -l`
Expected: exactement une ligne de plus qu'avant la tâche. Comparer avec `git stash` si besoin.

- [ ] **Step 8: Formater et committer le backend**

```bash
./vendor/bin/pint
git add app/Http/Requests/QuoteRequest.php app/Http/Controllers/Api/QuotationController.php routes/api.php tests/Feature/Api/QuoteEndpointTest.php
git commit -m "feat: endpoint POST /api/quote

Expose QuotationService en lecture. Le restaurant est deduit des
produits ; le parametre restaurant, facultatif, sert de garde-fou.

Lecture pure, aucune route existante modifiee."
```

- [ ] **Step 9: Ajouter la clé au contrat web**

Dans `front end/next-app/helpers/Route.ts`, ajouter après la ligne `static produits_a_la_une="default/preview"` :

```typescript
    // chiffrage serveur : plat + livraison + service
    static quote="quote"
```

Puis, **depuis `front end/next-app/`** :

```bash
git add helpers/Route.ts
git commit -m "feat: ajoute la cle quote au contrat d'API"
```

- [ ] **Step 10: Ajouter la clé au contrat mobile**

Dans `front end/thalia-delivery/helpers/Route.ts`, ajouter la même ligne au même endroit :

```typescript
    // chiffrage serveur : plat + livraison + service
    static quote="quote"
```

Puis, **depuis `front end/thalia-delivery/`** :

```bash
git add helpers/Route.ts
git commit -m "feat: ajoute la cle quote au contrat d'API"
```

Les deux fichiers appartiennent à des dépôts distincts : ce sont bien deux commits séparés, dans deux répertoires différents. Attention aux espaces dans les chemins.

---

## Task 4: Recherche de plats — `GET /api/products/search`

Aucune recherche de produit n'existe aujourd'hui : `RestaurantController::index()` ne cherche que dans `restaurants.name` et `description`, et renvoie tout le parc sans pagination. Cette tâche apporte la première recherche catalogue, utile à l'agent mais aussi au web et au mobile.

**Contradiction schéma / code, tranchée.** Le fichier de migration
`2023_10_31_115717_create_sub_category_products_table.php` crée
`foreignIdFor(CategoryProduct::class, 'category_id')`, alors que
`SubCategoryProduct::category_product()` et
`app/Filament/Resources/SubCategoryProductResource.php:39` référencent
`category_product_id`.

**Vérification faite sur la base réelle** (`Schema::hasColumn`) : la colonne est
**`category_product_id`**. C'est le fichier de migration qui est périmé — la base
a dérivé de son historique. Le modèle et l'écran admin sont corrects.

Conséquences : la factory utilise `category_product_id`, le filtre `category`
s'appuie sans risque sur la relation `sub_category_product.category_product`, et
le fichier de migration périmé n'est **pas** corrigé ici (le dump de schéma de la
tâche 1 gouverne désormais les installations neuves, ce qui le rend inoffensif).

**Files:**
- Create: `database/migrations/2026_09_05_120000_add_fulltext_index_to_products_table.php`
- Create: `app/Services/RestaurantGeo.php`
- Create: `app/Services/ProductSearchService.php`
- Create: `app/Http/Controllers/Api/ProductSearchController.php`
- Modify: `routes/api.php`
- Test: `tests/Unit/Services/RestaurantGeoTest.php`
- Test: `tests/Feature/Api/ProductSearchEndpointTest.php`
- Modify (dépôt web) : `front end/next-app/helpers/Route.ts`
- Modify (dépôt mobile) : `front end/thalia-delivery/helpers/Route.ts`

**Interfaces:**
- Consumes: les factories de la tâche 1.
- Produces:
  - `App\Services\RestaurantGeo` — statique : `coordinates(?array $location): ?array` renvoyant `['lat' => float, 'lng' => float]` ou `null` ; `distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float`.
  - `App\Services\ProductSearchService::search(array $filters): array` — renvoie `['paginator' => LengthAwarePaginator, 'distances' => array<int, float|null>]`, les distances étant indexées par `restaurant_id`. Clés de `$filters` : `q`, `category`, `sub_category`, `price_min`, `price_max`, `currency_id`, `town_id`, `lat`, `lng`, `radius`, `sort` (`prix`|`distance`), `per_page`.
  - `ProductSearchService::eligibleRestaurants(array $filters): array` — renvoie `['ids' => array<int, int> ordonnés, 'distances' => array<int, float|null>]`. Réutilisé par la tâche 5.

- [ ] **Step 1: Écrire les tests unitaires de la géo**

`restaurants.location` est un `json` nullable jamais lu jusqu'ici : sa forme réelle en production est inconnue. Le parsing doit donc être défensif.

Créer `tests/Unit/Services/RestaurantGeoTest.php` :

```php
<?php

namespace Tests\Unit\Services;

use App\Services\RestaurantGeo;
use PHPUnit\Framework\TestCase;

class RestaurantGeoTest extends TestCase
{
    public function test_il_lit_les_cles_lat_et_lng(): void
    {
        $this->assertSame(
            ['lat' => -2.5, 'lng' => 28.86],
            RestaurantGeo::coordinates(['lat' => -2.5, 'lng' => 28.86])
        );
    }

    public function test_il_lit_aussi_latitude_et_longitude(): void
    {
        $this->assertSame(
            ['lat' => -2.5, 'lng' => 28.86],
            RestaurantGeo::coordinates(['latitude' => -2.5, 'longitude' => 28.86])
        );
    }

    public function test_il_lit_aussi_la_cle_long(): void
    {
        $this->assertSame(
            ['lat' => -2.5, 'lng' => 28.86],
            RestaurantGeo::coordinates(['lat' => -2.5, 'long' => 28.86])
        );
    }

    public function test_il_accepte_des_coordonnees_en_chaine(): void
    {
        $this->assertSame(
            ['lat' => -2.5, 'lng' => 28.86],
            RestaurantGeo::coordinates(['lat' => '-2.5', 'lng' => '28.86'])
        );
    }

    public function test_il_renvoie_null_sur_une_valeur_absente_ou_malformee(): void
    {
        $this->assertNull(RestaurantGeo::coordinates(null));
        $this->assertNull(RestaurantGeo::coordinates([]));
        $this->assertNull(RestaurantGeo::coordinates(['lat' => -2.5]));
        $this->assertNull(RestaurantGeo::coordinates(['lat' => 'abc', 'lng' => 'def']));
    }

    public function test_il_rejette_des_coordonnees_hors_bornes(): void
    {
        $this->assertNull(RestaurantGeo::coordinates(['lat' => 120, 'lng' => 28.86]));
        $this->assertNull(RestaurantGeo::coordinates(['lat' => -2.5, 'lng' => 999]));
    }

    public function test_la_distance_entre_deux_points_identiques_est_nulle(): void
    {
        $this->assertSame(0.0, RestaurantGeo::distanceKm(-2.5, 28.86, -2.5, 28.86));
    }

    public function test_la_distance_bukavu_goma_est_de_l_ordre_de_200_km(): void
    {
        // Bukavu (-2.508, 28.842) → Goma (-1.658, 29.220)
        $distance = RestaurantGeo::distanceKm(-2.508, 28.842, -1.658, 29.220);

        $this->assertGreaterThan(90.0, $distance);
        $this->assertLessThan(120.0, $distance);
    }
}
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `php artisan test tests/Unit/Services/RestaurantGeoTest.php`
Expected: FAIL — `Class "App\Services\RestaurantGeo" not found`.

- [ ] **Step 3: Écrire `RestaurantGeo`**

Créer `app/Services/RestaurantGeo.php` :

```php
<?php

namespace App\Services;

/**
 * restaurants.location est un json nullable ajouté en novembre 2024 et jamais
 * lu jusqu'ici : sa forme réelle en production est inconnue. Ce lecteur accepte
 * les conventions de nommage plausibles et dégrade vers null plutôt que de lever.
 */
class RestaurantGeo
{
    private const LAT_KEYS = ['lat', 'latitude', 'Lat', 'Latitude'];

    private const LNG_KEYS = ['lng', 'long', 'lon', 'longitude', 'Lng', 'Long', 'Longitude'];

    /**
     * @param  array<string, mixed>|null  $location
     * @return array{lat: float, lng: float}|null
     */
    public static function coordinates(?array $location): ?array
    {
        if (! is_array($location) || $location === []) {
            return null;
        }

        $lat = self::pick($location, self::LAT_KEYS);
        $lng = self::pick($location, self::LNG_KEYS);

        if ($lat === null || $lng === null) {
            return null;
        }

        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return null;
        }

        return ['lat' => $lat, 'lng' => $lng];
    }

    /**
     * @param  array<string, mixed>  $location
     * @param  array<int, string>  $keys
     */
    private static function pick(array $location, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $location) && is_numeric($location[$key])) {
                return (float) $location[$key];
            }
        }

        return null;
    }

    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $rayon = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return round($rayon * 2 * atan2(sqrt($a), sqrt(1 - $a)), 3);
    }
}
```

- [ ] **Step 4: Lancer les tests pour vérifier qu'ils passent**

Run: `php artisan test tests/Unit/Services/RestaurantGeoTest.php`
Expected: PASS, 8 tests.

- [ ] **Step 5: Créer la migration de l'index FULLTEXT**

Créer `database/migrations/2026_09_05_120000_add_fulltext_index_to_products_table.php` :

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->fullText(['title', 'description'], 'products_fulltext');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropFullText('products_fulltext');
        });
    }
};
```

Additif et réversible. Aucune colonne créée, aucune donnée touchée.

- [ ] **Step 6: Lancer la migration sur la base de test**

Run: `php artisan migrate --env=testing`
Expected: `add_fulltext_index_to_products_table ... DONE`

Si MySQL refuse l'index, vérifier que le moteur de la table est InnoDB :
`php artisan db --env=testing` puis `SHOW TABLE STATUS LIKE 'products';`

- [ ] **Step 7: Écrire les tests de l'endpoint de recherche**

Créer `tests/Feature/Api/ProductSearchEndpointTest.php` :

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Currency;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Town;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductSearchEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_l_endpoint_exige_une_authentification(): void
    {
        $this->getJson('/api/products/search')->assertStatus(401);
    }

    public function test_il_trouve_un_plat_par_son_titre(): void
    {
        Sanctum::actingAs(User::factory()->create());

        Product::factory()->create(['title' => 'Poulet moambe', 'description' => 'plat traditionnel']);
        Product::factory()->create(['title' => 'Salade verte', 'description' => 'entree fraiche']);

        $response = $this->getJson('/api/products/search?q=poulet');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Poulet moambe', $response->json('data.0.product.title'));
    }

    public function test_il_trouve_un_plat_sur_un_prefixe(): void
    {
        Sanctum::actingAs(User::factory()->create());

        Product::factory()->create(['title' => 'Poulet moambe', 'description' => 'plat traditionnel']);

        $response = $this->getJson('/api/products/search?q=poul');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_il_exclut_les_produits_inactifs(): void
    {
        Sanctum::actingAs(User::factory()->create());

        Product::factory()->inactive()->create(['title' => 'Poulet moambe']);

        $response = $this->getJson('/api/products/search?q=poulet');

        $this->assertCount(0, $response->json('data'));
    }

    public function test_il_exclut_les_produits_des_restaurants_inactifs(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $restaurant = Restaurant::factory()->inactive()->create();
        Product::factory()->create(['title' => 'Poulet moambe', 'restaurant_id' => $restaurant->id]);

        $response = $this->getJson('/api/products/search?q=poulet');

        $this->assertCount(0, $response->json('data'));
    }

    public function test_il_filtre_par_prix_maximum(): void
    {
        Sanctum::actingAs(User::factory()->create());

        Product::factory()->create(['title' => 'Poulet cher', 'price' => 20000]);
        Product::factory()->create(['title' => 'Poulet abordable', 'price' => 3000]);

        $response = $this->getJson('/api/products/search?q=poulet&price_max=5000');

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Poulet abordable', $response->json('data.0.product.title'));
    }

    public function test_il_filtre_par_devise(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $cdf = Currency::factory()->create();
        $usd = Currency::factory()->usd()->create();

        Product::factory()->create(['title' => 'Poulet en francs', 'currency_id' => $cdf->id]);
        Product::factory()->create(['title' => 'Poulet en dollars', 'currency_id' => $usd->id]);

        $response = $this->getJson('/api/products/search?q=poulet&currency='.$cdf->slug);

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Poulet en francs', $response->json('data.0.product.title'));
    }

    public function test_il_calcule_la_distance_quand_les_deux_positions_sont_connues(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $restaurant = Restaurant::factory()->located(-2.508, 28.842)->create();
        Product::factory()->create(['title' => 'Poulet moambe', 'restaurant_id' => $restaurant->id]);

        $response = $this->getJson('/api/products/search?q=poulet&lat=-2.500&lng=28.860');

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.0.distance_km'));
        $this->assertLessThan(5.0, $response->json('data.0.distance_km'));
    }

    public function test_un_restaurant_sans_coordonnees_reste_visible_avec_une_distance_nulle(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $restaurant = Restaurant::factory()->create(['location' => null]);
        Product::factory()->create(['title' => 'Poulet moambe', 'restaurant_id' => $restaurant->id]);

        $response = $this->getJson('/api/products/search?q=poulet&lat=-2.500&lng=28.860');

        $this->assertCount(1, $response->json('data'));
        $this->assertNull($response->json('data.0.distance_km'));
    }

    public function test_une_location_malformee_ne_fait_pas_echouer_la_recherche(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $restaurant = Restaurant::factory()->create(['location' => ['n_importe_quoi' => true]]);
        Product::factory()->create(['title' => 'Poulet moambe', 'restaurant_id' => $restaurant->id]);

        $response = $this->getJson('/api/products/search?q=poulet&lat=-2.500&lng=28.860');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertNull($response->json('data.0.distance_km'));
    }

    public function test_le_filtre_town_garde_les_restaurants_sans_town(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $town = Town::factory()->create();

        $dans_la_town = Restaurant::factory()->create(['town_id' => $town->id]);
        $sans_town = Restaurant::factory()->create(['town_id' => null]);
        $ailleurs = Restaurant::factory()->create(['town_id' => Town::factory()->create()->id]);

        Product::factory()->create(['title' => 'Poulet un', 'restaurant_id' => $dans_la_town->id]);
        Product::factory()->create(['title' => 'Poulet deux', 'restaurant_id' => $sans_town->id]);
        Product::factory()->create(['title' => 'Poulet trois', 'restaurant_id' => $ailleurs->id]);

        $response = $this->getJson('/api/products/search?q=poulet&town='.$town->slug);

        // Le restaurant sans town n'est pas exclu : on ne filtre pas sur une donnée absente.
        $this->assertCount(2, $response->json('data'));
    }

    public function test_les_resultats_sont_pagines(): void
    {
        Sanctum::actingAs(User::factory()->create());

        Product::factory()->count(7)->create(['title' => 'Poulet moambe']);

        $response = $this->getJson('/api/products/search?q=poulet&per_page=3');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
        $this->assertSame(7, $response->json('meta.total'));
        $this->assertSame(3, $response->json('meta.per_page'));
    }
}
```

- [ ] **Step 8: Lancer les tests pour vérifier qu'ils échouent**

Run: `php artisan test tests/Feature/Api/ProductSearchEndpointTest.php`
Expected: FAIL — 404, la route n'existe pas.

- [ ] **Step 9: Écrire `ProductSearchService`**

Créer `app/Services/ProductSearchService.php` :

```php
<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Restaurant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ProductSearchService
{
    /**
     * innodb_ft_min_token_size vaut 3 par défaut : les mots plus courts ne sont
     * pas indexés. On bascule alors sur un LIKE.
     */
    private const MIN_TOKEN_SIZE = 3;

    /**
     * @param  array<string, mixed>  $filters
     * @return array{paginator: LengthAwarePaginator, distances: array<int, float|null>}
     */
    public function search(array $filters): array
    {
        ['ids' => $ids, 'distances' => $distances] = $this->eligibleRestaurants($filters);

        $query = Product::query()
            ->with(['currency', 'restaurant', 'sub_category_product'])
            ->where('products.is_active', true)
            ->whereNull('products.deleted_at')
            ->whereIn('products.restaurant_id', $ids === [] ? [0] : $ids);

        $this->applyText($query, $filters['q'] ?? null);
        $this->applyFilters($query, $filters);
        $this->applyOrder($query, $filters, $ids);

        $per_page = min(max((int) ($filters['per_page'] ?? 20), 1), 50);

        return [
            'paginator' => $query->paginate($per_page),
            'distances' => $distances,
        ];
    }

    /**
     * Restaurants éligibles, ordonnés : les géolocalisés par distance croissante,
     * puis ceux sans coordonnées.
     *
     * @param  array<string, mixed>  $filters
     * @return array{ids: array<int, int>, distances: array<int, float|null>}
     */
    public function eligibleRestaurants(array $filters): array
    {
        $query = Restaurant::query()
            ->where('is_active', true)
            ->whereNull('deleted_at');

        // On ne filtre jamais sur une donnée absente : un restaurant sans town
        // reste éligible, sinon un parc mal renseigné devient invisible.
        if (! empty($filters['town_id'])) {
            $town_id = (int) $filters['town_id'];
            $query->where(fn (Builder $q) => $q->whereNull('town_id')->orWhere('town_id', $town_id));
        }

        $restaurants = $query->get(['id', 'name', 'location']);

        $lat = isset($filters['lat']) ? (float) $filters['lat'] : null;
        $lng = isset($filters['lng']) ? (float) $filters['lng'] : null;
        $radius = isset($filters['radius']) ? (float) $filters['radius'] : null;

        $geolocalises = [];
        $sans_position = [];
        $distances = [];

        foreach ($restaurants as $restaurant) {
            $id = (int) $restaurant->id;
            $coords = ($lat !== null && $lng !== null)
                ? RestaurantGeo::coordinates($restaurant->location)
                : null;

            if ($coords === null) {
                $distances[$id] = null;
                $sans_position[] = ['id' => $id, 'name' => (string) $restaurant->name];

                continue;
            }

            $distance = RestaurantGeo::distanceKm($lat, $lng, $coords['lat'], $coords['lng']);

            // Le rayon n'exclut que les restaurants dont on connaît la position.
            if ($radius !== null && $distance > $radius) {
                continue;
            }

            $distances[$id] = $distance;
            $geolocalises[] = ['id' => $id, 'distance' => $distance];
        }

        usort($geolocalises, fn ($a, $b) => $a['distance'] <=> $b['distance']);
        usort($sans_position, fn ($a, $b) => strcmp($a['name'], $b['name']));

        $ids = array_merge(
            array_column($geolocalises, 'id'),
            array_column($sans_position, 'id')
        );

        return ['ids' => $ids, 'distances' => $distances];
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function applyText(Builder $query, ?string $q): void
    {
        $q = trim((string) $q);

        if ($q === '') {
            return;
        }

        // On retire les opérateurs du mode booléen pour qu'une saisie utilisateur
        // ne puisse pas construire une expression MySQL involontaire.
        $tokens = preg_split('/\s+/', preg_replace('/[+\-><()~*"@]+/', ' ', $q)) ?: [];
        $tokens = array_values(array_filter($tokens, fn ($t) => $t !== ''));

        if ($tokens === []) {
            return;
        }

        $indexables = array_filter($tokens, fn ($t) => mb_strlen($t) >= self::MIN_TOKEN_SIZE);

        if ($indexables === []) {
            $query->where(function (Builder $sub) use ($tokens) {
                foreach ($tokens as $token) {
                    $sub->orWhere('products.title', 'like', '%'.$token.'%');
                }
            });

            return;
        }

        $expression = implode(' ', array_map(fn ($t) => '+'.$t.'*', $indexables));

        $query->whereRaw(
            'MATCH(products.title, products.description) AGAINST (? IN BOOLEAN MODE)',
            [$expression]
        );
    }

    /**
     * @param  Builder<Product>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['currency_id'])) {
            $query->where('products.currency_id', (int) $filters['currency_id']);
        }

        if (isset($filters['price_min'])) {
            $query->where('products.price', '>=', (float) $filters['price_min']);
        }

        if (isset($filters['price_max'])) {
            $query->where('products.price', '<=', (float) $filters['price_max']);
        }

        if (! empty($filters['sub_category'])) {
            $query->whereHas('sub_category_product', fn (Builder $q) => $q->where('slug', $filters['sub_category']));
        }

        if (! empty($filters['category'])) {
            // sub_category_products.category_product_id, verifie sur la base reelle.
            $query->whereHas(
                'sub_category_product.category_product',
                fn (Builder $q) => $q->where('slug', $filters['category'])
            );
        }
    }

    /**
     * @param  Builder<Product>  $query
     * @param  array<string, mixed>  $filters
     * @param  array<int, int>  $ids
     */
    private function applyOrder(Builder $query, array $filters, array $ids): void
    {
        if (($filters['sort'] ?? 'prix') === 'distance' && $ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $query->orderByRaw("FIELD(products.restaurant_id, $placeholders)", $ids);
        }

        $query->orderBy('products.price')->orderBy('products.id');
    }
}
```

- [ ] **Step 10: Écrire le contrôleur**

Créer `app/Http/Controllers/Api/ProductSearchController.php` :

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Currency;
use App\Models\Town;
use App\Services\ProductSearchService;
use App\Wrappers\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductSearchController extends Controller
{
    public function __construct(private readonly ProductSearchService $search) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string'],
            'sub_category' => ['nullable', 'string'],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string'],
            'town' => ['nullable', 'string'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:0'],
            'sort' => ['nullable', 'in:prix,distance'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $filters = $validated;

        if (! empty($validated['town'])) {
            $town = Town::query()->where('slug', $validated['town'])->first();

            if (! $town) {
                return ApiResponse::NOT_FOUND('Oups', 'Cette ville est introuvable');
            }

            $filters['town_id'] = $town->id;
        }

        if (! empty($validated['currency'])) {
            $currency = Currency::query()->where('slug', $validated['currency'])->first();

            if (! $currency) {
                return ApiResponse::NOT_FOUND('Oups', 'Cette devise est introuvable');
            }

            $filters['currency_id'] = $currency->id;
        }

        ['paginator' => $paginator, 'distances' => $distances] = $this->search->search($filters);

        $data = collect($paginator->items())->map(fn ($product) => [
            'product' => new ProductResource($product),
            'distance_km' => $distances[(int) $product->restaurant_id] ?? null,
        ])->values();

        return ApiResponse::GET_DATA([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
```

- [ ] **Step 11: Déclarer la route**

Dans `routes/api.php`, ajouter la ligne au groupe créé en tâche 3 :

```php
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/quote', [\App\Http\Controllers\Api\QuotationController::class, 'quote']);
    Route::get('/products/search', [\App\Http\Controllers\Api\ProductSearchController::class, 'index']);
});
```

- [ ] **Step 12: Lancer les tests pour vérifier qu'ils passent**

Run: `php artisan test tests/Feature/Api/ProductSearchEndpointTest.php`
Expected: PASS, 12 tests.

Si `test_il_trouve_un_plat_sur_un_prefixe` échoue, vérifier `innodb_ft_min_token_size` :
`php artisan db --env=testing` puis `SHOW VARIABLES LIKE 'innodb_ft_min_token_size';`
La valeur par défaut est 3. Une valeur supérieure ferait échouer la recherche sur des mots courts — c'est une contrainte serveur à documenter, pas un bug du code.

- [ ] **Step 13: Formater et committer le backend**

```bash
./vendor/bin/pint
git add database/migrations app/Services/RestaurantGeo.php app/Services/ProductSearchService.php app/Http/Controllers/Api/ProductSearchController.php routes/api.php tests/Unit/Services tests/Feature/Api/ProductSearchEndpointTest.php
git commit -m "feat: recherche de plats GET /api/products/search

Index FULLTEXT sur products(title, description), recherche en mode
booleen avec prefixes, filtres prix/devise/categorie/town et
pagination.

La distance n'est calculee que si restaurants.location ET la position
du client sont connues ; sinon repli sur la town. Un restaurant sans
coordonnees ou sans town reste visible : on ne filtre pas sur une
donnee absente."
```

- [ ] **Step 14: Ajouter la clé aux deux contrats**

Dans `front end/next-app/helpers/Route.ts` **et** `front end/thalia-delivery/helpers/Route.ts`, sous la ligne `static quote="quote"` ajoutée en tâche 3 :

```typescript
    static products_search="products/search"
```

Puis un commit dans chaque dépôt :

```bash
git add helpers/Route.ts
git commit -m "feat: ajoute la cle products_search au contrat d'API"
```

---

## Task 5: Suggestions par budget — `POST /api/budget-suggestions`

La feature demandée : « j'ai 500 FC, propose-moi ce que je peux commander », le montant s'entendant **tout compris**. Et surtout : quand rien ne rentre, l'endpoint doit l'expliquer, pas renvoyer une liste vide.

**Pourquoi le préfiltre SQL est simplement `price ≤ budget`.** Le frais de livraison est une tranche indexée sur le sous-total, d'où une circularité apparente. Elle se lève en énumérant les tranches : pour une tranche `[i, m]` de frais `f + s` et un budget `B`, le plafond sur le plat vaut `min(m, B − f − s)`, qui est toujours `≤ B` puisque `f` et `s` sont positifs. Et le cas hors tranche — conservé tel quel, frais nuls — donne un plafond de `B` exactement. Le maximum des plafonds candidats vaut donc `B`. On sélectionne donc les produits à `price ≤ B`, puis on **requalifie chacun** par `QuotationService`, qui tranche pour de bon.

**Files:**
- Create: `app/Services/BudgetSuggestionService.php`
- Create: `app/Http/Requests/BudgetSuggestionRequest.php`
- Modify: `app/Http/Controllers/Api/QuotationController.php` (ajout d'une méthode)
- Modify: `routes/api.php`
- Test: `tests/Feature/Services/BudgetSuggestionServiceTest.php`
- Test: `tests/Feature/Api/BudgetSuggestionEndpointTest.php`
- Modify (dépôt web) : `front end/next-app/helpers/Route.ts`
- Modify (dépôt mobile) : `front end/thalia-delivery/helpers/Route.ts`

**Interfaces:**
- Consumes: `ProductSearchService::search()` et `eligibleRestaurants()` (tâche 4), `QuotationService::quote()` (tâche 2), `App\Wrappers\Cipher::Encrypt()`.
- Produces: `App\Services\BudgetSuggestionService::suggest(float $budget, Currency $currency, Town $town, array $filters = []): array` renvoyant `['suggestions' => array, 'disponible' => bool, 'raison' => ?string, 'option_la_moins_chere' => ?array]`.

- [ ] **Step 1: Écrire les tests du service**

Créer `tests/Feature/Services/BudgetSuggestionServiceTest.php` :

```php
<?php

namespace Tests\Feature\Services;

use App\Models\Currency;
use App\Models\DelivreryPrice;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Town;
use App\Services\BudgetSuggestionService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

class BudgetSuggestionServiceTest extends TestCase
{
    // DatabaseTruncation, PAS RefreshDatabase : un index FULLTEXT InnoDB n'est
    // pas visible depuis MATCH() ... AGAINST() à l'intérieur de la transaction
    // non validée dans laquelle RefreshDatabase enferme chaque test. Le test
    // du filtre texte verrait alors zéro ligne. Constaté en tâche 4.
    use DatabaseTruncation;

    private BudgetSuggestionService $service;

    private Town $town;

    private Currency $currency;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(BudgetSuggestionService::class);
        $this->town = Town::factory()->create();
        $this->currency = Currency::factory()->create();
        $this->restaurant = Restaurant::factory()->create(['town_id' => $this->town->id]);

        // Tranche unique : 2000 de livraison + 500 de service pour tout panier
        // de 0 à 100 000.
        DelivreryPrice::factory()->create([
            'town_id' => $this->town->id,
            'currency_id' => $this->currency->id,
            'interval_pricing' => 0,
            'interval_max_price' => 100000,
            'frais' => 2000,
            'service_price' => 500,
        ]);
    }

    private function plat(string $title, float $price): Product
    {
        return Product::factory()->create([
            'title' => $title,
            'price' => $price,
            'restaurant_id' => $this->restaurant->id,
            'currency_id' => $this->currency->id,
        ]);
    }

    public function test_il_retient_un_plat_dont_le_total_tient_dans_le_budget(): void
    {
        $this->plat('Beignets', 2000);   // 2000 + 2500 = 4500

        $result = $this->service->suggest(5000.0, $this->currency, $this->town);

        $this->assertTrue($result['disponible']);
        $this->assertCount(1, $result['suggestions']);
        $this->assertSame(4500.0, $result['suggestions'][0]['total']);
        $this->assertSame(500.0, $result['suggestions'][0]['reste']);
    }

    public function test_il_ecarte_un_plat_dont_les_frais_font_depasser_le_budget(): void
    {
        // Le plat seul tient dans le budget, mais pas une fois livre.
        $this->plat('Brochette', 4000);   // 4000 + 2500 = 6500 > 5000

        $result = $this->service->suggest(5000.0, $this->currency, $this->town);

        $this->assertFalse($result['disponible']);
        $this->assertSame('budget_insuffisant', $result['raison']);
        $this->assertSame([], $result['suggestions']);
    }

    public function test_quand_rien_ne_rentre_il_donne_l_option_la_moins_chere_et_le_manque(): void
    {
        $this->plat('Brochette', 4000);   // total 6500
        $this->plat('Poulet entier', 20000);

        $result = $this->service->suggest(5000.0, $this->currency, $this->town);

        $this->assertFalse($result['disponible']);
        $this->assertNotNull($result['option_la_moins_chere']);
        $this->assertSame('Brochette', $result['option_la_moins_chere']['produit']['title']);
        $this->assertSame(6500.0, $result['option_la_moins_chere']['total']);
        $this->assertSame(1500.0, $result['option_la_moins_chere']['manque']);
    }

    public function test_il_signale_l_absence_de_produit_dans_la_devise_demandee(): void
    {
        $usd = Currency::factory()->usd()->create();
        $this->plat('Beignets', 2000);

        $result = $this->service->suggest(5000.0, $usd, $this->town);

        $this->assertFalse($result['disponible']);
        $this->assertSame('aucun_produit_dans_cette_devise', $result['raison']);
        $this->assertNull($result['option_la_moins_chere']);
    }

    public function test_sans_aucune_tranche_active_la_livraison_est_gratuite(): void
    {
        // Comportement conservé de la production : hors tranche, frais nuls.
        DelivreryPrice::query()->delete();
        $this->plat('Brochette', 4000);

        $result = $this->service->suggest(5000.0, $this->currency, $this->town);

        $this->assertTrue($result['disponible']);
        $this->assertSame(4000.0, $result['suggestions'][0]['total']);
        $this->assertSame(0.0, $result['suggestions'][0]['frais_livraison']);
    }

    public function test_les_suggestions_les_plus_proches_du_budget_arrivent_en_premier(): void
    {
        $this->plat('Beignets', 500);      // total 3000
        $this->plat('Riz', 2000);          // total 4500
        $this->plat('Sombe', 1200);        // total 3700

        $result = $this->service->suggest(5000.0, $this->currency, $this->town);

        $totaux = array_column($result['suggestions'], 'total');
        $this->assertSame([4500.0, 3700.0, 3000.0], $totaux);
    }

    public function test_le_filtre_texte_restreint_les_suggestions(): void
    {
        $this->plat('Poulet moambe', 2000);
        $this->plat('Salade verte', 1000);

        $result = $this->service->suggest(5000.0, $this->currency, $this->town, ['q' => 'poulet']);

        $this->assertCount(1, $result['suggestions']);
        $this->assertSame('Poulet moambe', $result['suggestions'][0]['produit']['title']);
    }
}
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `php artisan test tests/Feature/Services/BudgetSuggestionServiceTest.php`
Expected: FAIL — `Target class [App\Services\BudgetSuggestionService] does not exist.`

- [ ] **Step 3: Écrire `BudgetSuggestionService`**

Créer `app/Services/BudgetSuggestionService.php` :

```php
<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\Product;
use App\Models\Town;
use App\Wrappers\Cipher;

class BudgetSuggestionService
{
    public const RAISON_BUDGET_INSUFFISANT = 'budget_insuffisant';

    public const RAISON_AUCUN_PRODUIT_DANS_CETTE_DEVISE = 'aucun_produit_dans_cette_devise';

    public const RAISON_AUCUN_RESTAURANT_DANS_CETTE_ZONE = 'aucun_restaurant_dans_cette_zone';

    private const CANDIDATS_MAX = 50;

    public function __construct(
        private readonly ProductSearchService $search,
        private readonly QuotationService $quotations,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  q, category, sub_category, lat, lng, radius
     * @return array{suggestions: array<int, array<string, mixed>>, disponible: bool, raison: ?string, option_la_moins_chere: ?array<string, mixed>}
     */
    public function suggest(float $budget, Currency $currency, Town $town, array $filters = []): array
    {
        $base = array_merge($filters, [
            'currency_id' => $currency->id,
            'town_id' => $town->id,
            'per_page' => self::CANDIDATS_MAX,
        ]);

        // Le plafond de recherche vaut exactement le budget : pour toute tranche
        // [i, m] de frais f + s, le plafond sur le plat est min(m, B - f - s) ≤ B,
        // et le cas hors tranche (frais nuls) donne B. Voir l'en-tête de la tâche.
        ['paginator' => $paginator, 'distances' => $distances] = $this->search->search(
            array_merge($base, ['price_max' => $budget, 'sort' => 'prix'])
        );

        $suggestions = [];

        foreach ($paginator->items() as $product) {
            $quotation = $this->quotations->quote(
                [['product' => $product, 'quantity' => 1]],
                $town
            );

            if (! $quotation->disponible || $quotation->total > $budget) {
                continue;
            }

            $suggestions[] = $this->presenter($product, $quotation, $distances, $budget);
        }

        if ($suggestions !== []) {
            // Le plus proche du budget d'abord : c'est ce qui en tire le plus de valeur.
            usort($suggestions, fn ($a, $b) => $b['total'] <=> $a['total']);

            return [
                'suggestions' => $suggestions,
                'disponible' => true,
                'raison' => null,
                'option_la_moins_chere' => null,
            ];
        }

        return $this->expliquerEchec($budget, $currency, $town, $base, $distances);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<int, float|null>  $distances
     * @return array{suggestions: array<int, array<string, mixed>>, disponible: bool, raison: ?string, option_la_moins_chere: ?array<string, mixed>}
     */
    private function expliquerEchec(float $budget, Currency $currency, Town $town, array $base, array $distances): array
    {
        // Sans plafond de prix cette fois : on cherche ce qui existe, pour dire
        // combien il manque plutôt que « je n'ai rien trouvé ».
        ['paginator' => $paginator] = $this->search->search(
            array_merge($base, ['sort' => 'prix', 'per_page' => 1])
        );

        $moins_cher = $paginator->items()[0] ?? null;

        if ($moins_cher === null) {
            $eligibles = $this->search->eligibleRestaurants($base);

            return [
                'suggestions' => [],
                'disponible' => false,
                'raison' => $eligibles['ids'] === []
                    ? self::RAISON_AUCUN_RESTAURANT_DANS_CETTE_ZONE
                    : self::RAISON_AUCUN_PRODUIT_DANS_CETTE_DEVISE,
                'option_la_moins_chere' => null,
            ];
        }

        $quotation = $this->quotations->quote(
            [['product' => $moins_cher, 'quantity' => 1]],
            $town
        );

        $option = $this->presenter($moins_cher, $quotation, $distances, $budget);
        $option['manque'] = round($quotation->total - $budget, 2);
        unset($option['reste']);

        return [
            'suggestions' => [],
            'disponible' => false,
            'raison' => self::RAISON_BUDGET_INSUFFISANT,
            'option_la_moins_chere' => $option,
        ];
    }

    /**
     * @param  array<int, float|null>  $distances
     * @return array<string, mixed>
     */
    private function presenter(Product $product, Quotation $quotation, array $distances, float $budget): array
    {
        return [
            'restaurant' => [
                'name' => $product->restaurant?->name,
                'slug' => $product->restaurant?->slug,
            ],
            'produit' => [
                'uid' => Cipher::Encrypt($product->id),
                'title' => $product->title,
                'slug' => $product->slug,
                'price' => (float) $product->price,
            ],
            'distance_km' => $distances[(int) $product->restaurant_id] ?? null,
            'sous_total' => $quotation->sous_total,
            'frais_livraison' => $quotation->frais_livraison,
            'service_price' => $quotation->service_price,
            'total' => $quotation->total,
            'reste' => round($budget - $quotation->total, 2),
        ];
    }
}
```

- [ ] **Step 4: Lancer les tests pour vérifier qu'ils passent**

Run: `php artisan test tests/Feature/Services/BudgetSuggestionServiceTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 5: Écrire les tests de l'endpoint**

Créer `tests/Feature/Api/BudgetSuggestionEndpointTest.php` :

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Currency;
use App\Models\DelivreryPrice;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Town;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BudgetSuggestionEndpointTest extends TestCase
{
    // Même raison qu'au-dessus : ce endpoint peut emprunter le chemin FULLTEXT
    // dès qu'un `q` est fourni.
    use DatabaseTruncation;

    private function contexte(): array
    {
        $town = Town::factory()->create();
        $currency = Currency::factory()->create();
        $restaurant = Restaurant::factory()->create(['town_id' => $town->id]);

        DelivreryPrice::factory()->create([
            'town_id' => $town->id, 'currency_id' => $currency->id,
            'interval_pricing' => 0, 'interval_max_price' => 100000,
            'frais' => 2000, 'service_price' => 500,
        ]);

        return [$town, $currency, $restaurant];
    }

    public function test_l_endpoint_exige_une_authentification(): void
    {
        $this->postJson('/api/budget-suggestions', [])->assertStatus(401);
    }

    public function test_il_propose_ce_qui_tient_dans_le_budget(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$town, $currency, $restaurant] = $this->contexte();

        Product::factory()->create([
            'title' => 'Beignets', 'price' => 2000,
            'restaurant_id' => $restaurant->id, 'currency_id' => $currency->id,
        ]);

        $response = $this->postJson('/api/budget-suggestions', [
            'budget' => 5000,
            'currency' => $currency->slug,
            'town' => $town->slug,
        ]);

        $response->assertStatus(200)->assertJson([
            'disponible' => true,
            'raison' => null,
        ]);

        $this->assertCount(1, $response->json('suggestions'));
        $this->assertSame(4500, $response->json('suggestions.0.total'));
    }

    public function test_quand_rien_ne_rentre_il_dit_combien_il_manque(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$town, $currency, $restaurant] = $this->contexte();

        Product::factory()->create([
            'title' => 'Brochette', 'price' => 4000,
            'restaurant_id' => $restaurant->id, 'currency_id' => $currency->id,
        ]);

        $response = $this->postJson('/api/budget-suggestions', [
            'budget' => 5000,
            'currency' => $currency->slug,
            'town' => $town->slug,
        ]);

        $response->assertStatus(200)->assertJson([
            'disponible' => false,
            'raison' => 'budget_insuffisant',
            'option_la_moins_chere' => [
                'total' => 6500,
                'manque' => 1500,
            ],
        ]);
    }

    public function test_un_budget_negatif_est_rejete(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$town, $currency] = $this->contexte();

        $this->postJson('/api/budget-suggestions', [
            'budget' => -10,
            'currency' => $currency->slug,
            'town' => $town->slug,
        ])->assertStatus(422);
    }

    public function test_une_devise_inconnue_renvoie_404(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$town] = $this->contexte();

        $this->postJson('/api/budget-suggestions', [
            'budget' => 5000,
            'currency' => 'devise-inexistante',
            'town' => $town->slug,
        ])->assertStatus(404);
    }
}
```

- [ ] **Step 6: Lancer les tests pour vérifier qu'ils échouent**

Run: `php artisan test tests/Feature/Api/BudgetSuggestionEndpointTest.php`
Expected: FAIL — 404, la route n'existe pas.

- [ ] **Step 7: Écrire la validation**

Créer `app/Http/Requests/BudgetSuggestionRequest.php` :

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BudgetSuggestionRequest extends FormRequest
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
            'budget' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string'],
            'town' => ['required', 'string'],
            'q' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string'],
            'sub_category' => ['nullable', 'string'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'budget.required' => 'Veuillez indiquer votre budget.',
            'budget.min' => 'Le budget ne peut pas être négatif.',
            'currency.required' => 'Veuillez indiquer la devise de votre budget.',
            'town.required' => 'La ville de livraison est obligatoire.',
        ];
    }
}
```

- [ ] **Step 8: Ajouter la méthode au contrôleur**

Dans `app/Http/Controllers/Api/QuotationController.php`, ajouter les imports puis la méthode.

Imports à ajouter en tête de fichier :

```php
use App\Http\Requests\BudgetSuggestionRequest;
use App\Models\Currency;
use App\Services\BudgetSuggestionService;
```

Méthode à ajouter après `quote()` :

```php
    public function budgetSuggestions(
        BudgetSuggestionRequest $request,
        BudgetSuggestionService $suggestions
    ): JsonResponse {
        $town = Town::query()->where('slug', $request->input('town'))->first();

        if (! $town) {
            return ApiResponse::NOT_FOUND('Oups', 'Cette ville de livraison est introuvable');
        }

        $currency = Currency::query()->where('slug', $request->input('currency'))->first();

        if (! $currency) {
            return ApiResponse::NOT_FOUND('Oups', 'Cette devise est introuvable');
        }

        $result = $suggestions->suggest(
            (float) $request->input('budget'),
            $currency,
            $town,
            $request->only(['q', 'category', 'sub_category', 'lat', 'lng', 'radius'])
        );

        return ApiResponse::GET_DATA(array_merge($result, [
            'budget' => (float) $request->input('budget'),
            'currency' => ['code' => $currency->code, 'slug' => $currency->slug],
        ]));
    }
```

- [ ] **Step 9: Déclarer la route**

Dans `routes/api.php`, compléter le groupe :

```php
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/quote', [\App\Http\Controllers\Api\QuotationController::class, 'quote']);
    Route::get('/products/search', [\App\Http\Controllers\Api\ProductSearchController::class, 'index']);
    Route::post('/budget-suggestions', [\App\Http\Controllers\Api\QuotationController::class, 'budgetSuggestions']);
});
```

- [ ] **Step 10: Lancer les tests pour vérifier qu'ils passent**

Run: `php artisan test tests/Feature/Api/BudgetSuggestionEndpointTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 11: Lancer toute la suite**

Run: `php artisan test`
Expected: PASS, tous les tests des tâches 1 à 5.

- [ ] **Step 12: Formater et committer le backend**

```bash
./vendor/bin/pint
git add app/Services/BudgetSuggestionService.php app/Http/Requests/BudgetSuggestionRequest.php app/Http/Controllers/Api/QuotationController.php routes/api.php tests/Feature/Services/BudgetSuggestionServiceTest.php tests/Feature/Api/BudgetSuggestionEndpointTest.php
git commit -m "feat: suggestions par budget POST /api/budget-suggestions

Repond a « j'ai X, propose-moi ce que je peux commander », le montant
s'entendant plat + livraison + service.

Chaque candidat est requalifie par QuotationService : le total annonce
est celui qui sera facture. Quand rien ne rentre, la reponse porte
l'option la moins chere et le montant manquant plutot qu'une liste
vide."
```

- [ ] **Step 13: Ajouter la clé aux deux contrats**

Dans `front end/next-app/helpers/Route.ts` **et** `front end/thalia-delivery/helpers/Route.ts` :

```typescript
    static budget_suggestions="budget-suggestions"
```

Puis un commit dans chaque dépôt :

```bash
git add helpers/Route.ts
git commit -m "feat: ajoute la cle budget_suggestions au contrat d'API"
```

---

## Task 6: Observation sur `valide()` et flag de bascule

La tâche la plus sensible : elle touche le chemin de paiement en production. Le moteur y est branché en **observation** — il journalise, il ne décide pas.

**Découverte qui élargit la tâche.** `valide()` n'envoie pas `$commande->global_price` à FlexPay : elle envoie `floatval($total_price)`, le montant brut du client (`CommandeController.php:578`), et enregistre ce même montant dans `Payement.amount` / `amount_customer`. Basculer le seul `global_price` laisserait donc FlexPay encaisser le montant dicté par le client. On introduit une variable unique, `$montant_facture`, utilisée aux trois endroits : tant que le flag est à `false`, elle vaut exactement `$total_price` et rien ne change.

**Files:**
- Create: `config/quotation.php`
- Modify: `config/logging.php` (ajout d'un canal, aucun canal existant touché)
- Modify: `app/Http/Controllers/Api/CommandeController.php::valide()`
- Modify: `.env.example`
- Test: `tests/Feature/Api/CommandeValideNonRegressionTest.php`

**Interfaces:**
- Consumes: `QuotationService::quote()` (tâche 2), `Quotation::toArray()` (tâche 2).
- Produces: la clé de configuration `quotation.authoritative` (bool) et le canal de log `quotation`.

- [ ] **Step 1: Écrire les tests de non-régression**

Créer `tests/Feature/Api/CommandeValideNonRegressionTest.php` :

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Currency;
use App\Models\DelivreryPrice;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Status;
use App\Models\Town;
use App\Models\User;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommandeValideNonRegressionTest extends TestCase
{
    use RefreshDatabase;

    private string $log_path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log_path = storage_path('logs/quotation-test.log');
        @unlink($this->log_path);

        config(['logging.channels.quotation' => [
            'driver' => 'single',
            'path' => $this->log_path,
            'level' => 'debug',
        ]]);

        Http::fake([
            '*' => Http::response([
                'code' => 0,
                'orderNumber' => 'TEST-ORDER-1',
                'message' => 'Transaction initiee',
            ], 200),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->log_path);
        parent::tearDown();
    }

    /**
     * Contexte où le serveur calcule 5500 (3000 de plat + 2000 + 500) :
     * les tests envoient volontairement un total_price different.
     *
     * @return array{0: Town, 1: Currency, 2: Product}
     */
    private function contexte(): array
    {
        Status::factory()->create(['id' => 5]);

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
            'price' => 1500,
        ]);

        return [$town, $currency, $product];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Town $town, Currency $currency, Product $product, float $total_price): array
    {
        return [
            // method "cart" evite la validation de numero de telephone.
            'method' => 'cart',
            'total_price' => $total_price,
            'phone' => '+243810000000',
            'mobile' => '+243810000000',
            'success_url' => 'https://example.test/ok',
            'error_url' => 'https://example.test/ko',
            'cancel_url' => 'https://example.test/cancel',
            'callback_url' => 'https://example.test/callback',
            'webhook_sse_url' => 'https://example.test/sse',
            'pricing' => [
                'frais_livraison' => 2000,
                'service_price' => 500,
                'currency' => ['id' => $currency->id, 'code' => $currency->code],
            ],
            'products' => [
                ['uid' => Cipher::Encrypt($product->id), 'quantity' => 2],
            ],
            'address' => [
                'town' => ['slug' => $town->slug],
                'adresse' => 'Avenue Test',
                'street' => 'Rue Test',
                'number_street' => '12',
                'reference' => 'En face du marche',
            ],
        ];
    }

    public function test_le_client_reste_autorite_sur_le_prix_quand_le_flag_est_a_false(): void
    {
        config(['quotation.authoritative' => false]);
        Sanctum::actingAs(User::factory()->create());

        [$town, $currency, $product] = $this->contexte();

        // Le serveur calculerait 5500. Le client annonce 4000.
        $this->postJson('/api/user/commande/valide', $this->payload($town, $currency, $product, 4000))
            ->assertStatus(201);

        $this->assertDatabaseHas('commandes', ['global_price' => 4000]);
        $this->assertDatabaseHas('payements', ['amount' => 4000, 'amount_customer' => 4000]);
    }

    public function test_flexpay_recoit_le_montant_du_client_quand_le_flag_est_a_false(): void
    {
        config(['quotation.authoritative' => false]);
        Sanctum::actingAs(User::factory()->create());

        [$town, $currency, $product] = $this->contexte();

        $this->postJson('/api/user/commande/valide', $this->payload($town, $currency, $product, 4000))
            ->assertStatus(201);

        Http::assertSent(fn ($request) => (float) $request['amount'] === 4000.0);
    }

    public function test_l_ecart_est_journalise_sur_le_canal_quotation(): void
    {
        config(['quotation.authoritative' => false]);
        Sanctum::actingAs(User::factory()->create());

        [$town, $currency, $product] = $this->contexte();

        $this->postJson('/api/user/commande/valide', $this->payload($town, $currency, $product, 4000))
            ->assertStatus(201);

        $this->assertFileExists($this->log_path);

        $contenu = file_get_contents($this->log_path);
        $this->assertStringContainsString('ecart_quotation', $contenu);
    }

    public function test_aucun_ecart_n_est_journalise_quand_les_deux_calculs_concordent(): void
    {
        config(['quotation.authoritative' => false]);
        Sanctum::actingAs(User::factory()->create());

        [$town, $currency, $product] = $this->contexte();

        // 1500 x 2 = 3000, + 2000 + 500 = 5500 : exactement ce que le serveur calcule.
        $this->postJson('/api/user/commande/valide', $this->payload($town, $currency, $product, 5500))
            ->assertStatus(201);

        if (file_exists($this->log_path)) {
            $this->assertStringNotContainsString('ecart_quotation', file_get_contents($this->log_path));
        }

        $this->assertDatabaseHas('commandes', ['global_price' => 5500]);
    }

    public function test_le_serveur_devient_autorite_quand_le_flag_est_a_true(): void
    {
        config(['quotation.authoritative' => true]);
        Sanctum::actingAs(User::factory()->create());

        [$town, $currency, $product] = $this->contexte();

        $this->postJson('/api/user/commande/valide', $this->payload($town, $currency, $product, 4000))
            ->assertStatus(201);

        $this->assertDatabaseHas('commandes', ['global_price' => 5500]);
        Http::assertSent(fn ($request) => (float) $request['amount'] === 5500.0);
    }

    public function test_un_refus_du_moteur_est_journalise_a_part_et_ne_compte_pas_comme_ecart(): void
    {
        config(['quotation.authoritative' => false]);
        Sanctum::actingAs(User::factory()->create());

        [$town, $currency, $product] = $this->contexte();

        // Un second produit d'un AUTRE restaurant : le moteur refuse, le client
        // non — calculePrice.js ne connaît pas cette règle.
        $autre = Product::factory()->create([
            'currency_id' => $currency->id,
            'price' => 1000,
        ]);

        $payload = $this->payload($town, $currency, $product, 4000);
        $payload['products'][] = ['uid' => Cipher::Encrypt($autre->id), 'quantity' => 1];

        $this->postJson('/api/user/commande/valide', $payload)->assertStatus(201);

        $contenu = file_exists($this->log_path) ? file_get_contents($this->log_path) : '';

        $this->assertStringContainsString('refus_quotation', $contenu);
        $this->assertStringNotContainsString('ecart_quotation', $contenu);

        // Le client reste autorité : un refus ne change rien au montant.
        $this->assertDatabaseHas('commandes', ['global_price' => 4000]);
    }

    public function test_une_observation_impossible_ne_casse_pas_la_commande(): void
    {
        config(['quotation.authoritative' => false]);
        Sanctum::actingAs(User::factory()->create());

        [$town, $currency, $product] = $this->contexte();

        $payload = $this->payload($town, $currency, $product, 4000);
        $payload['products'][] = ['uid' => 'uid-illisible', 'quantity' => 1];

        // valide() doit continuer a se comporter comme avant, quoi qu'il arrive
        // dans le bloc d'observation.
        $response = $this->postJson('/api/user/commande/valide', $payload);

        $this->assertContains($response->status(), [201, 500]);
        $this->assertDatabaseHas('commandes', ['global_price' => 4000]);
    }
}
```

> Le dernier test accepte 500 : avec un `uid` illisible, la boucle d'enregistrement des produits de `valide()` échouait **déjà** avant cette tâche (`Product::find(false)` renvoie `null`, puis `$product_id->id` lève). Ce test vérifie que le bloc d'observation n'introduit pas un *nouveau* mode d'échec, pas que ce cas est correctement géré — il ne l'était pas.

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `php artisan test tests/Feature/Api/CommandeValideNonRegressionTest.php`
Expected: FAIL — `test_le_serveur_devient_autorite_quand_le_flag_est_a_true` et `test_l_ecart_est_journalise_sur_le_canal_quotation` échouent (pas de flag, pas de canal). Les autres peuvent déjà passer : c'est normal, ils décrivent le comportement actuel qui ne doit pas bouger.

- [ ] **Step 3: Créer la configuration**

Créer `config/quotation.php` :

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Autorité sur le prix
    |--------------------------------------------------------------------------
    |
    | À false, le montant facturé reste celui envoyé par le client, et le moteur
    | de quotation se contente de journaliser les écarts sur le canal
    | « quotation ». À true, le serveur fait autorité.
    |
    | Ne passer à true qu'après une période d'observation sans « ecart_quotation »
    | dans les logs, couvrant plusieurs towns et plusieurs tranches.
    | Réversible sans redéploiement de code.
    |
    */

    'authoritative' => (bool) env('QUOTATION_AUTHORITATIVE', false),

];
```

- [ ] **Step 4: Déclarer le canal de log**

Dans `config/logging.php`, ajouter cette entrée dans le tableau `'channels'`, sans modifier aucun canal existant :

```php
        'quotation' => [
            'driver' => 'daily',
            'path' => storage_path('logs/quotation.log'),
            'level' => 'debug',
            'days' => 30,
            'replace_placeholders' => true,
        ],
```

Le canal est lisible depuis l'admin via `opcodesio/log-viewer`, déjà installé.

- [ ] **Step 5: Documenter la variable d'environnement**

Ajouter à la fin de `.env.example` :

```
# Quotation : à false, le montant facturé reste celui envoyé par le client
# et le serveur se contente de journaliser les écarts (canal « quotation »).
QUOTATION_AUTHORITATIVE=false
```

- [ ] **Step 6: Ajouter les imports au contrôleur**

Dans `app/Http/Controllers/Api/CommandeController.php`, ajouter en tête de fichier :

```php
use App\Services\QuotationService;
use Illuminate\Support\Facades\Log;
```

> Vérifier d'abord que `Log` n'est pas déjà importé : `grep -n "use Illuminate\\\\Support\\\\Facades\\\\Log" app/Http/Controllers/Api/CommandeController.php`

- [ ] **Step 7: Insérer le bloc d'observation**

Dans `valide()`, repérer cette ligne (aux alentours de la ligne 541) :

```php
            $commande->global_price = $total_price;
```

La **remplacer** par le bloc suivant :

```php
            // --- Observation de la quotation serveur -------------------------
            // Ce bloc ne doit jamais modifier le comportement de valide() tant
            // que quotation.authoritative vaut false. Toute exception y est
            // absorbée : une commande ne peut pas échouer à cause de la mesure.
            $quotation = null;

            try {
                $quotation_lines = [];

                foreach ($products as $entry) {
                    $observed = Product::query()
                        ->with('currency')
                        ->find(Cipher::Decrypt($entry['uid']));

                    if ($observed) {
                        // Quantité numérique, PAS (int) : calculePrice.js fait
                        // `item.quantity * item.price` sans coercition, et les
                        // quantités arrivent ici du client sans validation
                        // d'entier. Tronquer produirait un faux écart.
                        $quotation_lines[] = [
                            'product' => $observed,
                            'quantity' => $entry['quantity'],
                        ];
                    }
                }

                if ($town && $quotation_lines !== []) {
                    $quotation = app(QuotationService::class)->quote($quotation_lines, $town);

                    if (! $quotation->disponible) {
                        // Les refus du moteur (panier vide, quantité invalide,
                        // multi-restaurant, devises mélangées) n'ont AUCUN
                        // équivalent dans calculePrice.js : le client produit un
                        // nombre dans les quatre cas. Un refus a un total de 0,
                        // donc comparer les totaux ici crierait à l'écart sur
                        // chaque commande concernée. On journalise à part.
                        Log::channel('quotation')->notice('refus_quotation', [
                            'commande' => $commande->refernce,
                            'raison' => $quotation->raison,
                            'client_total' => $total_price,
                        ]);
                    } elseif (abs($quotation->total - floatval($total_price)) > 0.01) {
                        Log::channel('quotation')->warning('ecart_quotation', [
                            'commande' => $commande->refernce,
                            'client' => [
                                'total' => $total_price,
                                'frais' => $pricing['frais_livraison'] ?? null,
                                'service' => $pricing['service_price'] ?? null,
                            ],
                            'serveur' => $quotation->toArray(),
                        ]);
                    } elseif ($quotation->warnings !== []) {
                        // Les deux calculs concordent et valent tous deux 0 de
                        // frais : c'est la fuite de données delivrery_prices,
                        // pas un bug du moteur.
                        Log::channel('quotation')->info('quotation_conforme_avec_warnings', [
                            'commande' => $commande->refernce,
                            'total' => $quotation->total,
                            'warnings' => $quotation->warnings,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::channel('quotation')->error('observation_impossible', [
                    'commande' => $commande->refernce,
                    'message' => $e->getMessage(),
                ]);
            }

            $montant_facture = (config('quotation.authoritative') && $quotation !== null && $quotation->disponible)
                ? $quotation->total
                : $total_price;
            // ----------------------------------------------------------------

            $commande->global_price = $montant_facture;
```

- [ ] **Step 8: Utiliser `$montant_facture` dans le paiement**

Toujours dans `valide()`, plus bas dans la méthode, remplacer :

```php
                'amount' => floatval($total_price),
```

par :

```php
                'amount' => floatval($montant_facture),
```

Puis, dans le `Payement::query()->updateOrCreate([...], [...])` de la même méthode, remplacer :

```php
                'amount' => $total_price,
                'amount_customer' => $total_price,
```

par :

```php
                'amount' => $montant_facture,
                'amount_customer' => $montant_facture,
```

> Attention : `paiement()` (plus bas dans le même fichier, vers la ligne 685) contient des lignes très proches mais utilise `$order->global_price`. **Ne pas la toucher** — elle facture déjà `global_price`, donc la bascule l'atteint automatiquement.

- [ ] **Step 9: Lancer les tests pour vérifier qu'ils passent**

Run: `php artisan test tests/Feature/Api/CommandeValideNonRegressionTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 10: Lancer toute la suite**

Run: `php artisan test`
Expected: PASS, l'intégralité des tests des tâches 1 à 6.

- [ ] **Step 11: Vérifier que le flag est bien à false partout**

```bash
grep -rn "QUOTATION_AUTHORITATIVE" .env .env.example .env.testing
php artisan tinker --execute="var_dump(config('quotation.authoritative'));"
```

Expected: `bool(false)` — et `.env` de production ne doit pas contenir la variable, ou la contenir à `false`.

- [ ] **Step 12: Formater et committer**

```bash
./vendor/bin/pint
git add config/quotation.php config/logging.php .env.example app/Http/Controllers/Api/CommandeController.php tests/Feature/Api/CommandeValideNonRegressionTest.php
git commit -m "feat: observation de la quotation serveur sur valide()

Le moteur est appele a chaque validation de commande, compare son
total a celui envoye par le client, et journalise l'ecart sur le canal
quotation. Le client reste autorite : QUOTATION_AUTHORITATIVE=false.

valide() envoyait total_price directement a FlexPay et a Payement,
sans passer par global_price : les trois utilisent desormais la meme
variable montant_facture, pour que la bascule soit reelle.

Aucun changement de comportement a ce stade."
```

---

## Après ce plan

**Ce qui n'est pas fait, et qui ne doit pas l'être sans décision explicite :**

- **La bascule `QUOTATION_AUTHORITATIVE=true`.** Elle se décide en lisant le canal `quotation` en production, sur une période couvrant plusieurs towns, plusieurs tranches et des paniers hors tranche. Tant que `ecart_quotation` apparaît, c'est le moteur qu'on corrige — jamais la production qu'on casse.
- **Le nettoyage de `delivrery_prices`.** Les entrées `quotation_conforme_avec_warnings` donnent la mesure de la fuite : combien de commandes passent hors tranche et pour quel manque à gagner. La correction est un chantier de données, à décider avec ces chiffres en main.
- **Les abilities Sanctum**, prérequis du sous-projet C.
- **`POST /api/ia-model-llama3`** — proxy Ollama public, hors `auth:sanctum`, sans throttle, `Http::timeout(3600)` — et **`GET /api/roles`**, route publique qui crée des rôles Spatie. Les deux sont à neutraliser, indépendamment de ce plan.
- **Les sous-projets B et C**, qui ont chacun leur spec à écrire.
