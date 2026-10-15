# Lot 1 — Portée de livraison : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refuser, côté serveur, toute commande dont le restaurant est plus loin de l'adresse de livraison que le rayon autorisé, et montrer au client pourquoi.

**Architecture:** Une seule classe, `App\Services\PorteeLivraison`, répond à « ce restaurant peut-il livrer là ». Tous les points d'entrée (catalogue, recherche de plats, devis, précommande, commande) l'appellent au lieu de recopier la règle. Le rayon vient d'un réglage global surchargeable par ville ; les coordonnées manquantes font retomber sur le centroïde de la commune, et leur absence totale autorise plutôt que de refuser.

**Tech Stack:** Laravel 12, PHP 8.1, `spatie/laravel-settings`, Filament 3, PHPUnit. Côté clients : Expo SDK 57 + NativeWind (mobile), Next.js 15 (web).

**Spec:** `docs/superpowers/specs/2026-10-15-portee-geographique-design.md` (sections 1 à 3, 6, 7, 8)

**Ticket :** T81 · **Branche :** `feat/T81-portee-geographique` (déjà créée, depuis `release1.0`)

## Global Constraints

- **Application en production, tolérance zéro régression.** Aucun renommage de route, de champ de réponse ni de clé de `Route.ts`.
- **Tous les ajouts d'API sont optionnels.** Les mobiles déjà installés n'envoient pas `lat`/`lng` et doivent continuer à fonctionner à l'identique.
- Les fautes de frappe historiques (`DelivreryDriver`, `Payement`, `refernce`, `delivrery_price`) **ne se corrigent pas** : elles font partie du contrat.
- Domaine et commentaires **en français**. Messages utilisateur en français.
- Les réponses passent par `App\Wrappers\ApiResponse` ; leur forme est du contrat public.
- Les erreurs axios côté clients sont **retournées**, pas levées : ne pas convertir en `throw`.
- Aucune couleur en dur côté mobile : tokens de `tailwind.config.js` / `theme/tokens.ts` uniquement (`DESIGN.md`).
- Jamais de commit sur `prod`. Tout passe par `feat/T81-portee-geographique`.
- `phpunit.xml` ne bascule pas sur SQLite en mémoire : la suite tape la base du `.env`. Vérifier la connexion avant de lancer, et ne pas écrire dans le projet depuis une autre session pendant un run.
- Réglages dans les tests : `FoodSettings::fake([...], loadMissingValues: false)`. **Le drapeau est indispensable** — une autre suite vide la table `settings`, et sans lui le test passe seul mais échoue dans la suite complète.

## Review Focus

Cinq cas que le spec implique sans qu'aucune tâche ne les exerce spontanément. Chacun a son test rattaché à la tâche qui porte le code.

1. **Distance exactement égale au rayon** → autorisée. Un restaurant à 30,000 km avec un rayon de 30 km doit passer. (Tâche 2)
2. **`lat = 0`, `lng = 0`** — un GPS qui n'a pas encore fixé renvoie le point zéro, dans le golfe de Guinée. Doit être traité comme une position absente, donc autorisé, pas comme « à 5 000 km ». (Tâche 2)
3. **Ordre des refus dans le devis** — panier vide, multi-restaurant et devises mélangées sont diagnostiqués **avant** `hors_rayon`. Un panier vide ne doit pas revenir « hors rayon ». (Tâche 3)
4. **`cities.max_delivery_radius_km = 0`** → refuse tout, et c'est un choix d'administration assumé. Filament impose un minimum de 1 pour éviter la fausse manipulation. (Tâche 8)
5. **Restaurant dont la commune a été supprimée, et sans `location`** → autorisé, `distance_km` à `null`, jamais une exception. (Tâche 2)

---

## Structure des fichiers

| Fichier | Responsabilité |
|---|---|
| `app/Settings/FoodSettings.php` (créé) | le rayon par défaut et le message de refus |
| `database/settings/2026_10_15_100000_create_food_settings.php` (créé) | pose les deux réglages |
| `database/migrations/2026_10_15_100100_add_delivery_radius_to_cities.php` (créé) | `cities.max_delivery_radius_km` |
| `app/Services/Portee.php` (créé) | le résultat figé d'un calcul de portée |
| `app/Services/PorteeLivraison.php` (créé) | **la** règle : résolution des coordonnées, rayon, verdict |
| `app/Services/QuotationService.php` (modifié) | nouveau refus `hors_rayon` |
| `app/Http/Requests/QuoteRequest.php` (modifié) | `lat` / `lng` optionnels |
| `app/Http/Controllers/Api/QuotationController.php` (modifié) | passe la position au service |
| `app/Http/Controllers/Api/CommandeController.php` (modifié) | `refuserSiRestaurantTropLoin()` |
| `app/Services/PrecommandeService.php` (modifié) | même refus |
| `app/Http/Controllers/Api/RestaurantController.php` (modifié) | `index()` accepte une position |
| `app/Http/Resources/RestaurantResource.php` (modifié) | `distance_km`, `livrable`, `portee_message` |
| `app/Services/ProductSearchService.php` (modifié) | plafonne le `radius` client |
| `app/Filament/Resources/CityResource.php` (modifié) | le champ rayon |
| `app/Models/City.php` (modifié) | cast et relation `towns` |
| `components/home/restaurant-row.tsx` (modifié, mobile) | l'état hors rayon |
| `components/rental/hors-zone.tsx` (modifié, mobile) | une prop de service |

Les tests vivent dans `tests/Unit/PorteeLivraisonTest.php` et `tests/Feature/Portee/`.

---

### Task 1: Les réglages et le rayon par ville

**Files:**
- Create: `app/Settings/FoodSettings.php`
- Create: `database/settings/2026_10_15_100000_create_food_settings.php`
- Create: `database/migrations/2026_10_15_100100_add_delivery_radius_to_cities.php`
- Modify: `app/Models/City.php`
- Test: `tests/Feature/Portee/ReglagesPorteeTest.php`

**Interfaces:**
- Consumes: rien.
- Produces: `App\Settings\FoodSettings` avec `public float $max_delivery_radius_km` et `public string $out_of_radius_message` ; `City::$max_delivery_radius_km` (`?float`) ; `City::towns(): HasMany`.

- [ ] **Step 1: Écrire le test qui échoue**

`tests/Feature/Portee/ReglagesPorteeTest.php` :

```php
<?php

namespace Tests\Feature\Portee;

use App\Models\City;
use App\Models\Town;
use App\Settings\FoodSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le rayon de livraison : une valeur globale, qu'une ville peut surcharger.
 */
class ReglagesPorteeTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_reglages_portent_le_rayon_global_et_son_message(): void
    {
        FoodSettings::fake([
            'max_delivery_radius_km' => 30.0,
            'out_of_radius_message' => 'Trop loin.',
        ], loadMissingValues: false);

        $settings = app(FoodSettings::class);

        $this->assertSame(30.0, $settings->max_delivery_radius_km);
        $this->assertSame('Trop loin.', $settings->out_of_radius_message);
    }

    public function test_une_ville_peut_surcharger_le_rayon_et_le_relit_en_float(): void
    {
        $city = City::factory()->create([
            'title' => 'Lubumbashi',
            'is_active' => true,
            'max_delivery_radius_km' => 20,
        ]);

        $this->assertSame(20.0, $city->fresh()->max_delivery_radius_km);
    }

    public function test_une_ville_sans_surcharge_vaut_null_et_non_zero(): void
    {
        $city = City::factory()->create(['title' => 'Kinshasa', 'is_active' => true]);

        // null signifie « cette ville n'a pas d'avis », pas « rayon nul ».
        $this->assertNull($city->fresh()->max_delivery_radius_km);
    }

    public function test_une_ville_expose_ses_communes(): void
    {
        $city = City::factory()->create(['title' => 'Kinshasa', 'is_active' => true]);
        Town::factory()->create(['city_id' => $city->id]);

        $this->assertCount(1, $city->towns);
    }
}
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `php artisan test --filter=ReglagesPorteeTest`
Expected: FAIL — `Class "App\Settings\FoodSettings" not found`.

- [ ] **Step 3: Créer la classe de réglages**

`app/Settings/FoodSettings.php` :

```php
<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Les reglages de la restauration.
 *
 * Pendant de `RentalSettings` pour la location. Ces valeurs apparaissent dans
 * le catalogue, dans le devis et dans le refus montre au client : une seconde
 * copie divergerait a la premiere negociation commerciale.
 */
class FoodSettings extends Settings
{
    /**
     * Le rayon maximal entre un restaurant et l'adresse de livraison.
     *
     * Une ville peut le surcharger (`cities.max_delivery_radius_km`). Sans
     * surcharge, c'est cette valeur qui s'applique.
     */
    public float $max_delivery_radius_km;

    /**
     * La phrase montree quand le restaurant est trop loin.
     *
     * Trois emplacements nommes : `:restaurant`, `:distance`, `:rayon`. Elle
     * vit ici et non dans le code, comme `RentalSettings::out_of_zone_message`,
     * pour se corriger sans livraison.
     */
    public string $out_of_radius_message;

    public static function group(): string
    {
        return 'food';
    }
}
```

- [ ] **Step 4: Créer la migration de réglages**

`database/settings/2026_10_15_100000_create_food_settings.php` :

```php
<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        /*
         * Trente kilometres : la borne haute des deux valeurs citees par le
         * proprietaire (« 20 km ou 30 km maximum »). On ouvre au plus large,
         * et chaque ville resserre si elle le veut — l'inverse aurait ferme
         * des livraisons qui se font aujourd'hui.
         */
        $this->migrator->add('food.max_delivery_radius_km', 30.0);

        $this->migrator->add(
            'food.out_of_radius_message',
            ':restaurant est à :distance km de votre adresse de livraison. '
            .'Nous ne livrons que dans un rayon de :rayon km.',
        );
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('food.max_delivery_radius_km');
        $this->migrator->deleteIfExists('food.out_of_radius_message');
    }
};
```

- [ ] **Step 5: Créer la migration de la colonne**

`database/migrations/2026_10_15_100100_add_delivery_radius_to_cities.php` :

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le rayon de livraison propre a une ville.
 *
 * Nullable, et c'est le coeur du reglage : `null` veut dire « cette ville n'a
 * pas d'avis, applique le rayon global ». Une valeur par defaut aurait fige 30
 * dans chaque ligne et rendu impossible de changer le global apres coup.
 *
 * Kinshasa s'etale sur une quarantaine de kilometres, Lubumbashi beaucoup
 * moins : la valeur ne peut pas etre la meme partout, et elle se negocie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->decimal('max_delivery_radius_km', 6, 2)
                ->nullable()
                ->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('cities', fn (Blueprint $table) => $table->dropColumn('max_delivery_radius_km'));
    }
};
```

- [ ] **Step 6: Compléter le modèle City**

Dans `app/Models/City.php`, ajouter les imports `Illuminate\Database\Eloquent\Relations\HasMany` et `App\Models\Town`, puis, sous le `boot()` existant :

> **Ne pas ajouter de `$fillable`.** `City` n'en a pas, et le `$guarded` par
> defaut d'Eloquent vaut `['*']` : le modele est totalement protege contre
> l'affectation en masse. Lui ouvrir `$fillable` changerait ce comportement sur
> un modele de production pour un besoin qui n'existe pas — les factories
> tournent sans protection, et Filament ecrit deja `title` aujourd'hui.
> C'est pour cette raison que les tests passent par `City::factory()`.

```php
    protected $casts = [
        'is_active' => 'boolean',
        // Caste en float et non en decimal: : le rayon se compare a une
        // distance calculee, jamais a une chaine. `decimal:2` aurait rendu
        // une string et fait echouer un `===` sur un float.
        'max_delivery_radius_km' => 'float',
    ];

    public function towns(): HasMany
    {
        return $this->hasMany(Town::class);
    }
```

- [ ] **Step 7: Lancer les migrations et le test**

Run: `php artisan migrate && php artisan test --filter=ReglagesPorteeTest`
Expected: PASS, 4 tests.

- [ ] **Step 8: Commit**

```bash
git add app/Settings/FoodSettings.php database/settings/2026_10_15_100000_create_food_settings.php database/migrations/2026_10_15_100100_add_delivery_radius_to_cities.php app/Models/City.php tests/Feature/Portee/ReglagesPorteeTest.php
git commit -m "T81 : reglages de portee de livraison et rayon par ville"
```

---

### Task 2: `PorteeLivraison`, la règle unique

**Files:**
- Create: `app/Services/Portee.php`
- Create: `app/Services/PorteeLivraison.php`
- Test: `tests/Unit/PorteeLivraisonTest.php`

**Interfaces:**
- Consumes: `App\Settings\FoodSettings` (tâche 1), `App\Services\RestaurantGeo` (existant), `City::$max_delivery_radius_km` (tâche 1).
- Produces:
  - `Portee` (readonly) : `bool $autorise`, `?float $distance_km`, `?float $rayon_km`, `?string $message`.
  - `PorteeLivraison::pour(Restaurant $restaurant, ?Town $livraison, ?float $lat = null, ?float $lng = null): Portee`
  - `PorteeLivraison::rayonPour(Restaurant $restaurant): float`

- [ ] **Step 1: Écrire le test qui échoue**

`tests/Unit/PorteeLivraisonTest.php` :

```php
<?php

namespace Tests\Unit;

use App\Models\City;
use App\Models\Restaurant;
use App\Models\Town;
use App\Services\PorteeLivraison;
use App\Settings\FoodSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La regle de distance, et elle seule.
 *
 * Gombe (-4.3045, 15.3075) et Limete (-4.3700, 15.3500) sont a environ 9 km.
 * Lubumbashi (-11.6600, 27.4800) est a plus de 1 500 km de Kinshasa.
 */
class PorteeLivraisonTest extends TestCase
{
    use RefreshDatabase;

    private const GOMBE = [-4.3045, 15.3075];

    private const LIMETE = [-4.3700, 15.3500];

    private const LUBUMBASHI = [-11.6600, 27.4800];

    protected function setUp(): void
    {
        parent::setUp();

        FoodSettings::fake([
            'max_delivery_radius_km' => 30.0,
            'out_of_radius_message' => ':restaurant est à :distance km. Rayon : :rayon km.',
        ], loadMissingValues: false);
    }

    private function portee(): PorteeLivraison
    {
        return app(PorteeLivraison::class);
    }

    public function test_un_restaurant_dans_le_rayon_est_autorise(): void
    {
        $restaurant = Restaurant::factory()->located(...self::GOMBE)->create();

        $portee = $this->portee()->pour($restaurant, null, ...self::LIMETE);

        $this->assertTrue($portee->autorise);
        $this->assertNull($portee->message);
        $this->assertLessThan(30.0, $portee->distance_km);
    }

    public function test_un_restaurant_hors_rayon_est_refuse_avec_la_distance_dans_le_message(): void
    {
        $restaurant = Restaurant::factory()
            ->located(...self::LUBUMBASHI)
            ->create(['name' => 'Al-Dar']);

        $portee = $this->portee()->pour($restaurant, null, ...self::GOMBE);

        $this->assertFalse($portee->autorise);
        $this->assertGreaterThan(1000.0, $portee->distance_km);
        $this->assertStringContainsString('Al-Dar', $portee->message);
        $this->assertStringContainsString((string) $portee->distance_km, $portee->message);
        $this->assertStringContainsString('30', $portee->message);
    }

    /** Review Focus 1 : la borne est inclusive. */
    public function test_une_distance_egale_au_rayon_est_autorisee(): void
    {
        $restaurant = Restaurant::factory()->located(0.0, 0.0)->create();

        // On force le rayon a la distance exacte plutot que de chercher deux
        // points a 30,000 km : c'est la comparaison qu'on teste, pas Haversine.
        $city = City::factory()->create([
            'title' => 'Test', 'is_active' => true, 'max_delivery_radius_km' => 0.0,
        ]);
        $town = Town::factory()->create([
            'city_id' => $city->id, 'latitude' => 0.0, 'longitude' => 0.0,
        ]);
        $restaurant->update(['town_id' => $town->id, 'location' => null]);

        $portee = $this->portee()->pour($restaurant->fresh(), $town, 0.0, 0.0);

        // distance 0, rayon 0 : egal, donc autorise.
        $this->assertSame(0.0, $portee->distance_km);
        $this->assertTrue($portee->autorise);
    }

    /** Review Focus 2 : un GPS qui n'a pas fixe renvoie le point zero. */
    public function test_une_position_de_livraison_a_zero_zero_est_ignoree(): void
    {
        $restaurant = Restaurant::factory()->located(...self::GOMBE)->create();

        $portee = $this->portee()->pour($restaurant, null, 0.0, 0.0);

        $this->assertTrue($portee->autorise);
        $this->assertNull($portee->distance_km);
    }

    public function test_sans_location_on_retombe_sur_le_centroide_de_la_commune(): void
    {
        $town = Town::factory()->create([
            'latitude' => self::LUBUMBASHI[0], 'longitude' => self::LUBUMBASHI[1],
        ]);
        $restaurant = Restaurant::factory()->create([
            'town_id' => $town->id, 'location' => null,
        ]);

        $portee = $this->portee()->pour($restaurant, null, ...self::GOMBE);

        $this->assertFalse($portee->autorise);
        $this->assertGreaterThan(1000.0, $portee->distance_km);
    }

    public function test_la_position_de_livraison_retombe_sur_le_centroide_de_sa_commune(): void
    {
        $restaurant = Restaurant::factory()->located(...self::GOMBE)->create();
        $livraison = Town::factory()->create([
            'latitude' => self::LUBUMBASHI[0], 'longitude' => self::LUBUMBASHI[1],
        ]);

        $portee = $this->portee()->pour($restaurant, $livraison, null, null);

        $this->assertFalse($portee->autorise);
    }

    /** Review Focus 5 : aucune donnee des deux cotes, aucune exception. */
    public function test_un_restaurant_sans_commune_ni_position_est_autorise(): void
    {
        $restaurant = Restaurant::factory()->create([
            'town_id' => null, 'location' => null,
        ]);

        $portee = $this->portee()->pour($restaurant, null, ...self::GOMBE);

        $this->assertTrue($portee->autorise);
        $this->assertNull($portee->distance_km);
        $this->assertNull($portee->message);
    }

    public function test_le_rayon_de_la_ville_du_restaurant_prime_sur_le_global(): void
    {
        $city = City::factory()->create([
            'title' => 'Lubumbashi', 'is_active' => true, 'max_delivery_radius_km' => 5.0,
        ]);
        $town = Town::factory()->create(['city_id' => $city->id]);
        $restaurant = Restaurant::factory()
            ->located(...self::GOMBE)
            ->create(['town_id' => $town->id]);

        $this->assertSame(5.0, $this->portee()->rayonPour($restaurant->fresh()));

        // 9 km entre Gombe et Limete, rayon 5 : refuse.
        $portee = $this->portee()->pour($restaurant->fresh(), null, ...self::LIMETE);
        $this->assertFalse($portee->autorise);
    }

    public function test_une_ville_sans_surcharge_applique_le_rayon_global(): void
    {
        $city = City::factory()->create(['title' => 'Kinshasa', 'is_active' => true]);
        $town = Town::factory()->create(['city_id' => $city->id]);
        $restaurant = Restaurant::factory()
            ->located(...self::GOMBE)
            ->create(['town_id' => $town->id]);

        $this->assertSame(30.0, $this->portee()->rayonPour($restaurant->fresh()));
    }
}
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `php artisan test --filter=PorteeLivraisonTest`
Expected: FAIL — `Class "App\Services\PorteeLivraison" not found`.

- [ ] **Step 3: Écrire le résultat figé**

`app/Services/Portee.php` :

```php
<?php

namespace App\Services;

/**
 * Le verdict d'une portee de livraison.
 *
 * Un objet fige plutot qu'un tableau, comme `Quotation` et `BookingQuote` : le
 * verdict, la distance et le message sont calcules ensemble et ne doivent pas
 * pouvoir etre modifies un par un par l'appelant.
 */
readonly class Portee
{
    public function __construct(
        public bool $autorise,
        /** La distance en kilometres, ou null si une des deux positions manque. */
        public ?float $distance_km = null,
        /** Le rayon applique, ou null quand aucun controle n'etait possible. */
        public ?float $rayon_km = null,
        /** La phrase montree au client. Null quand c'est autorise. */
        public ?string $message = null,
    ) {}

    /** Aucune position exploitable : on laisse passer. */
    public static function sansControle(): self
    {
        return new self(true);
    }
}
```

- [ ] **Step 4: Écrire la règle**

`app/Services/PorteeLivraison.php` :

```php
<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\Town;
use App\Settings\FoodSettings;

/**
 * Ce restaurant peut-il livrer a cet endroit ?
 *
 * **La regle vit ici, une seule fois.** Deux bugs de production sont nes du
 * motif inverse : le prix du panier calcule dans le navigateur et absent du
 * serveur, puis l'ouverture du restaurant ecrite dans les deux applications et
 * nulle part sur le serveur. Le catalogue, la recherche, le devis, la
 * precommande et la commande appellent donc tous cette classe.
 *
 * Elle ne refuse jamais faute de donnees. Un parc mal renseigne ne doit pas
 * fermer la plateforme le jour du deploiement : c'est deja la doctrine de
 * `ProductSearchService::eligibleRestaurants()`, « on ne filtre jamais sur une
 * donnee absente ».
 */
class PorteeLivraison
{
    public function __construct(private readonly FoodSettings $settings) {}

    public function pour(
        Restaurant $restaurant,
        ?Town $livraison = null,
        ?float $lat = null,
        ?float $lng = null,
    ): Portee {
        $depuis = $this->positionDuRestaurant($restaurant);
        $vers = $this->positionDeLivraison($livraison, $lat, $lng);

        if ($depuis === null || $vers === null) {
            return Portee::sansControle();
        }

        $distance = RestaurantGeo::distanceKm(
            $depuis['lat'], $depuis['lng'], $vers['lat'], $vers['lng'],
        );

        $rayon = $this->rayonPour($restaurant);

        // Borne inclusive : un restaurant pile sur la limite livre.
        if ($distance <= $rayon) {
            return new Portee(true, $distance, $rayon);
        }

        return new Portee(false, $distance, $rayon, __($this->settings->out_of_radius_message, [
            'restaurant' => $restaurant->name,
            'distance' => $distance,
            'rayon' => $rayon,
        ]));
    }

    /**
     * Le rayon applicable a ce restaurant.
     *
     * Celui de SA ville, pas celui de la livraison : c'est le restaurant qui
     * offre le service, et c'est son marche qui dit jusqu'ou il va. Quand les
     * deux villes different, la distance depasse de toute facon n'importe quel
     * rayon.
     */
    public function rayonPour(Restaurant $restaurant): float
    {
        return $restaurant->town?->city?->max_delivery_radius_km
            ?? $this->settings->max_delivery_radius_km;
    }

    /** @return array{lat: float, lng: float}|null */
    private function positionDuRestaurant(Restaurant $restaurant): ?array
    {
        return RestaurantGeo::coordinates($restaurant->location)
            ?? $this->centroide($restaurant->town);
    }

    /** @return array{lat: float, lng: float}|null */
    private function positionDeLivraison(?Town $town, ?float $lat, ?float $lng): ?array
    {
        if ($lat !== null && $lng !== null) {
            $coords = RestaurantGeo::coordinates(['lat' => $lat, 'lng' => $lng]);

            if ($coords !== null && ! $this->estLePointZero($coords)) {
                return $coords;
            }
        }

        return $this->centroide($town);
    }

    /**
     * Le centroide d'une commune.
     *
     * Approximatif — quelques kilometres sur une commune de Kinshasa — et
     * c'est assume : il sert a separer Gombe de Lubumbashi, pas deux avenues.
     *
     * @return array{lat: float, lng: float}|null
     */
    private function centroide(?Town $town): ?array
    {
        if ($town?->latitude === null || $town->longitude === null) {
            return null;
        }

        return RestaurantGeo::coordinates([
            'lat' => (float) $town->latitude,
            'lng' => (float) $town->longitude,
        ]);
    }

    /**
     * Le point zero du golfe de Guinee.
     *
     * Un GPS qui n'a pas encore fixe renvoie (0, 0). Le prendre au mot
     * placerait chaque client a 5 000 km de Kinshasa et fermerait tout le
     * catalogue : on le traite comme une position absente.
     *
     * @param  array{lat: float, lng: float}  $coords
     */
    private function estLePointZero(array $coords): bool
    {
        return abs($coords['lat']) < 0.000001 && abs($coords['lng']) < 0.000001;
    }
}
```

- [ ] **Step 5: Lancer le test**

Run: `php artisan test --filter=PorteeLivraisonTest`
Expected: PASS, 9 tests.

- [ ] **Step 6: Vérifier le formatage**

Run: `./vendor/bin/pint app/Services/Portee.php app/Services/PorteeLivraison.php tests/Unit/PorteeLivraisonTest.php`
Expected: aucun fichier en erreur.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Portee.php app/Services/PorteeLivraison.php tests/Unit/PorteeLivraisonTest.php
git commit -m "T81 : PorteeLivraison, la regle de distance en un seul endroit"
```

---

### Task 3: Le devis refuse hors rayon

C'est le verrou réel : les deux applications et le connecteur MCP passent tous par le devis pour obtenir un prix.

**Files:**
- Modify: `app/Services/QuotationService.php`
- Modify: `app/Http/Requests/QuoteRequest.php`
- Modify: `app/Http/Controllers/Api/QuotationController.php`
- Test: `tests/Feature/Portee/DevisHorsRayonTest.php`

**Interfaces:**
- Consumes: `PorteeLivraison::pour()` (tâche 2).
- Produces: `QuotationService::RAISON_HORS_RAYON = 'hors_rayon'` ; `QuotationService::quote()` prend deux paramètres optionnels de plus, `?float $lat = null, ?float $lng = null` ; `Quotation::$raison` peut valoir `'hors_rayon'` et `Quotation::$message_portee` porte la phrase.

- [ ] **Step 1: Écrire le test qui échoue**

`tests/Feature/Portee/DevisHorsRayonTest.php` :

```php
<?php

namespace Tests\Feature\Portee;

use App\Models\Currency;
use App\Models\DelivreryPrice;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Town;
use App\Services\QuotationService;
use App\Settings\FoodSettings;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevisHorsRayonTest extends TestCase
{
    use RefreshDatabase;

    private const GOMBE = [-4.3045, 15.3075];

    private const LUBUMBASHI = [-11.6600, 27.4800];

    private Town $livraison;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        FoodSettings::fake([
            'max_delivery_radius_km' => 30.0,
            'out_of_radius_message' => ':restaurant est à :distance km. Rayon : :rayon km.',
        ], loadMissingValues: false);

        $this->currency = Currency::factory()->create();

        $this->livraison = Town::factory()->create([
            'latitude' => self::GOMBE[0], 'longitude' => self::GOMBE[1],
        ]);

        DelivreryPrice::factory()->create([
            'town_id' => $this->livraison->id,
            'currency_id' => $this->currency->id,
        ]);
    }

    private function produitDe(Restaurant $restaurant): Product
    {
        return Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'currency_id' => $this->currency->id,
            'price' => 1000.0,
        ]);
    }

    private function appeler(Product $produit, array $position = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/quotation', array_merge([
            'town' => $this->livraison->slug,
            'products' => [['uid' => Cipher::Encrypt($produit->id), 'quantity' => 1]],
        ], $position));
    }

    public function test_un_restaurant_trop_loin_est_refuse(): void
    {
        $restaurant = Restaurant::factory()
            ->located(...self::LUBUMBASHI)
            ->create(['name' => 'Al-Dar']);

        $reponse = $this->appeler($this->produitDe($restaurant));

        $reponse->assertOk();
        $reponse->assertJsonPath('data.disponible', false);
        $reponse->assertJsonPath('data.raison', QuotationService::RAISON_HORS_RAYON);
        $this->assertStringContainsString('Al-Dar', $reponse->json('data.message'));
    }

    public function test_un_restaurant_dans_le_rayon_est_chiffre(): void
    {
        $restaurant = Restaurant::factory()->located(...self::GOMBE)->create();

        $reponse = $this->appeler($this->produitDe($restaurant));

        $reponse->assertOk();
        $reponse->assertJsonPath('data.disponible', true);
        $reponse->assertJsonPath('data.raison', null);
    }

    public function test_la_position_envoyee_prime_sur_le_centroide(): void
    {
        $restaurant = Restaurant::factory()->located(...self::GOMBE)->create();

        // Le client est a Lubumbashi, la commune de livraison est a Gombe :
        // c'est la position reelle qui compte.
        $reponse = $this->appeler($this->produitDe($restaurant), [
            'lat' => self::LUBUMBASHI[0], 'lng' => self::LUBUMBASHI[1],
        ]);

        $reponse->assertJsonPath('data.raison', QuotationService::RAISON_HORS_RAYON);
    }

    public function test_un_restaurant_sans_position_exploitable_reste_chiffre(): void
    {
        $restaurant = Restaurant::factory()->create([
            'town_id' => null, 'location' => null,
        ]);

        $reponse = $this->appeler($this->produitDe($restaurant));

        $reponse->assertJsonPath('data.disponible', true);
    }

    /** Review Focus 3 : les refus de panier passent avant celui de distance. */
    public function test_un_panier_multi_restaurant_est_refuse_pour_cette_raison_et_non_pour_la_distance(): void
    {
        $loin = Restaurant::factory()->located(...self::LUBUMBASHI)->create();
        $pres = Restaurant::factory()->located(...self::GOMBE)->create();

        $reponse = $this->postJson('/api/quotation', [
            'town' => $this->livraison->slug,
            'products' => [
                ['uid' => Cipher::Encrypt($this->produitDe($loin)->id), 'quantity' => 1],
                ['uid' => Cipher::Encrypt($this->produitDe($pres)->id), 'quantity' => 1],
            ],
        ]);

        $reponse->assertJsonPath('data.raison', QuotationService::RAISON_MULTI_RESTAURANT);
    }
}
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `php artisan test --filter=DevisHorsRayonTest`
Expected: FAIL — `Undefined constant App\Services\QuotationService::RAISON_HORS_RAYON`.

- [ ] **Step 3: Porter le message sur `Quotation`**

Dans `app/Services/Quotation.php`, ajouter un paramètre promu en fin de constructeur, après `$raison` :

```php
        public readonly ?string $raison = null,
        /**
         * La phrase a montrer, quand le refus depend d'un reglage et ne peut
         * donc pas etre figee cote client. Meme principe que
         * `BookingRefusee::$messageClient`.
         */
        public readonly ?string $message = null,
```

et, dans `toArray()`, à la suite de `'raison' => $this->raison,` :

```php
            'message' => $this->message,
```

Puis élargir `refus()` :

```php
    public static function refus(string $raison, ?string $message = null): self
    {
        return new self(false, 0.0, 0.0, 0.0, 0.0, null, null, [], $raison, $message);
    }
```

- [ ] **Step 4: Ajouter la constante et le contrôle dans `QuotationService`**

Dans `app/Services/QuotationService.php`, après `RAISON_RESTAURANT_INATTENDU` :

```php
    /** Le restaurant est plus loin de l'adresse de livraison que le rayon autorise. */
    public const RAISON_HORS_RAYON = 'hors_rayon';
```

Injecter la règle :

```php
    public function __construct(private readonly PorteeLivraison $portee) {}
```

Élargir la signature de `quote()` :

```php
    public function quote(
        array $lines,
        Town $town,
        ?int $expected_restaurant_id = null,
        ?float $lat = null,
        ?float $lng = null,
    ): Quotation {
```

Puis, **après** le contrôle `RAISON_RESTAURANT_INATTENDU` et **avant** la ligne
`$currency = $lines[array_key_first($lines)]['product']->currency;` :

```php
        /*
         * La distance arrive en dernier des refus de panier.
         *
         * Un panier vide ou multi-restaurant doit se diagnostiquer pour ce
         * qu'il est : repondre « hors rayon » a un panier vide enverrait le
         * client corriger son adresse pour un probleme qui n'est pas la.
         *
         * A ce stade un seul restaurant est en jeu, les lignes precedentes
         * l'ont garanti.
         */
        $restaurant = $lines[array_key_first($lines)]['product']->restaurant;

        if ($restaurant !== null) {
            $portee = $this->portee->pour($restaurant, $town, $lat, $lng);

            if (! $portee->autorise) {
                return Quotation::refus(self::RAISON_HORS_RAYON, $portee->message);
            }
        }
```

Ajouter les imports `App\Models\Restaurant` n'est pas nécessaire ; ajouter `use App\Services\PorteeLivraison;` l'est — la classe est dans le même namespace, donc rien à importer.

- [ ] **Step 5: Accepter la position dans la requête**

Dans `app/Http/Requests/QuoteRequest.php`, ajouter aux `rules()` :

```php
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
```

- [ ] **Step 6: Passer la position et le message dans le contrôleur**

Dans `app/Http/Controllers/Api/QuotationController.php`, méthode `quote()`, remplacer l'appel :

```php
        $quotation = $this->quotations->quote(
            $lines,
            $town,
            $expected_restaurant_id,
            $request->filled('lat') ? (float) $request->input('lat') : null,
            $request->filled('lng') ? (float) $request->input('lng') : null,
        );
```

Dans `resolveLines()`, précharger ce dont la règle a besoin — sans quoi chaque
ligne déclencherait trois requêtes :

```php
        $found = Product::query()
            ->with(['currency', 'restaurant.town.city'])
            ->whereIn('id', $ids)
```

Dans `present()`, exposer le message à la suite de `'raison'` :

```php
            'message' => $quotation->message,
```

- [ ] **Step 7: Lancer le test**

Run: `php artisan test --filter=DevisHorsRayonTest`
Expected: PASS, 5 tests.

- [ ] **Step 8: Vérifier qu'aucun appelant existant n'est cassé**

`QuotationService::quote()` est aussi appelé par `BudgetSuggestionService` et par
`CommandeController` ligne 1024, avec deux ou trois arguments. Les nouveaux
paramètres étant optionnels, ces appels restent valides.

Run: `php artisan test --filter="Devise|Budget|Quotation|Commande"`
Expected: PASS, aucune régression.

- [ ] **Step 9: Commit**

```bash
git add app/Services/Quotation.php app/Services/QuotationService.php app/Http/Requests/QuoteRequest.php app/Http/Controllers/Api/QuotationController.php tests/Feature/Portee/DevisHorsRayonTest.php
git commit -m "T81 : le devis refuse un restaurant hors rayon"
```

---

### Task 4: La commande refuse hors rayon

Dernière barrière serveur, jumelle de `refuserSiRestaurantFerme()` qui existe déjà.

**Files:**
- Modify: `app/Http/Controllers/Api/CommandeController.php` (méthode privée + appels lignes ~260 et ~920)
- Test: `tests/Feature/Portee/CommandeHorsRayonTest.php`

**Interfaces:**
- Consumes: `PorteeLivraison::pour()` (tâche 2), `Portee::$message` (tâche 2).
- Produces: `CommandeController::refuserSiRestaurantTropLoin(?array $products, ?Town $town, ?float $lat, ?float $lng, string $cle = 'product_id'): ?JsonResponse`

- [ ] **Step 1: Écrire le test qui échoue**

`tests/Feature/Portee/CommandeHorsRayonTest.php` :

```php
<?php

namespace Tests\Feature\Portee;

use App\Models\Currency;
use App\Models\DelivreryPrice;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Town;
use App\Models\User;
use App\Settings\FoodSettings;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Le dernier verrou : meme si un client contourne le devis, la commande
 * refuse. C'est la lecon de `refuserSiRestaurantFerme` — le site affichait
 * « Ferme » et laissait quand meme payer.
 */
class CommandeHorsRayonTest extends TestCase
{
    use RefreshDatabase;

    private const GOMBE = [-4.3045, 15.3075];

    private const LUBUMBASHI = [-11.6600, 27.4800];

    private Town $livraison;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        FoodSettings::fake([
            'max_delivery_radius_km' => 30.0,
            'out_of_radius_message' => ':restaurant est à :distance km. Rayon : :rayon km.',
        ], loadMissingValues: false);

        $this->currency = Currency::factory()->create();

        $this->livraison = Town::factory()->create([
            'latitude' => self::GOMBE[0], 'longitude' => self::GOMBE[1],
        ]);

        DelivreryPrice::factory()->create([
            'town_id' => $this->livraison->id,
            'currency_id' => $this->currency->id,
        ]);

        Sanctum::actingAs(User::factory()->create());
    }

    private function produitDe(array $position): Product
    {
        $restaurant = Restaurant::factory()
            ->located(...$position)
            ->create(['name' => 'Al-Dar']);

        return Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'currency_id' => $this->currency->id,
            'price' => 1000.0,
        ]);
    }

    public function test_une_commande_chez_un_restaurant_trop_loin_est_refusee(): void
    {
        $produit = $this->produitDe(self::LUBUMBASHI);

        $reponse = $this->postJson('/api/commande', [
            'town' => $this->livraison->slug,
            'products' => [['product_id' => Cipher::Encrypt($produit->id), 'quantity' => 1]],
        ]);

        $reponse->assertStatus(400);
        $this->assertStringContainsString('Al-Dar', (string) $reponse->json('message'));
    }

    public function test_une_commande_dans_le_rayon_passe_le_controle_de_distance(): void
    {
        $produit = $this->produitDe(self::GOMBE);

        $reponse = $this->postJson('/api/commande', [
            'town' => $this->livraison->slug,
            'products' => [['product_id' => Cipher::Encrypt($produit->id), 'quantity' => 1]],
        ]);

        // On ne teste pas la creation complete ici : seulement que le refus de
        // distance ne se declenche pas.
        $this->assertStringNotContainsString(
            'Rayon', (string) $reponse->json('message'),
        );
    }
}
```

> **Note pour l'implémenteur :** la charge utile exacte de `POST /api/commande`
> est définie par `App\Http\Requests\CommandeRequest`. Lire ce `FormRequest`
> avant d'écrire le test et compléter la charge avec les champs requis
> (adresse, destinataire…). Le test ci-dessus montre ce qui est vérifié ; les
> champs obligatoires s'ajoutent sans changer les assertions.

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `php artisan test --filter=CommandeHorsRayonTest`
Expected: FAIL — la commande trop lointaine n'est pas refusée.

- [ ] **Step 3: Écrire le garde-fou**

Dans `app/Http/Controllers/Api/CommandeController.php`, juste après
`refuserSiRestaurantFerme()` (vers la ligne 139) :

```php
    /**
     * Refuse une commande dont le restaurant est trop loin de la livraison.
     *
     * Jumelle de `refuserSiRestaurantFerme`, et pour la meme raison : la regle
     * ne doit pas vivre que dans les ecrans. Le proprietaire, le 15 octobre :
     * « une personne a Kinshasa ne peut pas commander dans un restaurant au
     * bas-Congo ».
     *
     * La regle elle-meme vit sur `PorteeLivraison`, une seule fois, et c'est
     * elle qui decide quoi faire d'une position manquante.
     *
     * @param  array<int, array<string, mixed>>|null  $products
     */
    private function refuserSiRestaurantTropLoin(
        ?array $products,
        ?Town $town,
        ?float $lat = null,
        ?float $lng = null,
        string $cle = 'product_id',
    ): ?JsonResponse {
        $ids = collect($products ?? [])
            ->map(fn ($ligne) => Cipher::Decrypt($ligne[$cle] ?? $ligne['uid'] ?? null))
            ->filter(fn ($id) => $id !== false && $id !== '' && ctype_digit((string) $id))
            ->map(fn ($id) => (int) $id)
            ->all();

        if (! $ids) {
            return null;
        }

        $portees = app(PorteeLivraison::class);

        $refus = Product::query()
            ->with('restaurant.town.city')
            ->whereIn('id', $ids)
            ->get()
            ->pluck('restaurant')
            ->filter()
            ->unique('id')
            ->map(fn (Restaurant $restaurant) => $portees->pour($restaurant, $town, $lat, $lng))
            ->first(fn (Portee $portee) => ! $portee->autorise);

        if (! $refus) {
            return null;
        }

        return ApiResponse::BAD_REQUEST(
            ['distance_km' => $refus->distance_km, 'rayon_km' => $refus->rayon_km],
            __('Hors zone de livraison'),
            $refus->message,
        );
    }
```

Ajouter les imports en tête de fichier :

```php
use App\Services\Portee;
use App\Services\PorteeLivraison;
```

(`App\Models\Restaurant`, `App\Models\Product`, `App\Models\Town`,
`App\Wrappers\ApiResponse`, `App\Wrappers\Cipher` et
`Illuminate\Http\JsonResponse` y sont déjà.)

- [ ] **Step 4: Brancher aux deux points d'entrée**

Ligne ~260, juste après l'appel existant :

```php
            if ($refus = $this->refuserSiRestaurantFerme($products)) {
                return $refus;
            }

            if ($refus = $this->refuserSiRestaurantTropLoin(
                $products,
                Town::query()->where('slug', $request->input('town'))->first(),
                $request->filled('lat') ? (float) $request->input('lat') : null,
                $request->filled('long') ? (float) $request->input('long') : null,
            )) {
                return $refus;
            }
```

Ligne ~920, même chose avec la clé `'uid'` et la commune déjà résolue à la
ligne 926 — **déplacer la résolution de `$town` avant l'appel** :

```php
            if ($refus = $this->refuserSiRestaurantFerme($products, 'uid')) {
                return $refus;
            }

            $town = Town::query()->where('slug', ! empty($adresse['town']['slug']) ? $adresse['town']['slug'] : null)->first();

            if ($refus = $this->refuserSiRestaurantTropLoin(
                $products,
                $town,
                isset($adresse['lat']) ? (float) $adresse['lat'] : null,
                isset($adresse['long']) ? (float) $adresse['long'] : null,
                'uid',
            )) {
                return $refus;
            }
```

Supprimer ensuite la ligne 926 d'origine, devenue un doublon.

> **Note pour l'implémenteur :** relire les lignes 250-270 et 915-955 avant
> d'éditer. Les noms de variables locales (`$products`, `$adresse`) doivent
> correspondre à ce qui existe réellement à ces endroits.

- [ ] **Step 5: Lancer le test**

Run: `php artisan test --filter=CommandeHorsRayonTest`
Expected: PASS, 2 tests.

- [ ] **Step 6: Vérifier la non-régression sur les commandes**

Run: `php artisan test --filter="Commande|Precommande|Echeance|Annulation"`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/CommandeController.php tests/Feature/Portee/CommandeHorsRayonTest.php
git commit -m "T81 : la commande refuse un restaurant hors rayon"
```

---

### Task 5: La précommande laisse passer le refus jusqu'au client

`PrecommandeService::creer()` appelle déjà `quote()` et lève
`PrecommandeRefusee::pour((string) $quotation->raison)` dès que le devis refuse.
Une fois la tâche 3 faite, **le refus de distance remonte tout seul**. Il ne
manque qu'une chose : la phrase. `PrecommandeRefusee` ne porte qu'un code, et
`PrecommandeController::messageDeRefus()` fait correspondre un code à une
phrase figée — or celle-ci dépend d'un réglage et de la distance réelle.

**Files:**
- Modify: `app/Exceptions/PrecommandeRefusee.php`
- Modify: `app/Services/PrecommandeService.php` (une ligne)
- Modify: `app/Http/Controllers/Api/PrecommandeController.php` (`messageDeRefus`)
- Test: `tests/Feature/Portee/PrecommandeHorsRayonTest.php`

**Interfaces:**
- Consumes: `QuotationService::RAISON_HORS_RAYON` et `Quotation::$message` (tâche 3).
- Produces: `PrecommandeRefusee::pour(string $raison, ?string $message = null): self` et `PrecommandeRefusee::$message` (`?string`).

- [ ] **Step 1: Écrire le test qui échoue**

`tests/Feature/Portee/PrecommandeHorsRayonTest.php` :

```php
<?php

namespace Tests\Feature\Portee;

use App\Models\Currency;
use App\Models\DelivreryPrice;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Town;
use App\Models\User;
use App\Settings\FoodSettings;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * La pre-commande emprunte le refus du devis. Ce test verifie qu'il arrive
 * jusqu'au client AVEC sa phrase, et pas sous la forme d'un code brut.
 */
class PrecommandeHorsRayonTest extends TestCase
{
    use RefreshDatabase;

    private const GOMBE = [-4.3045, 15.3075];

    private const LUBUMBASHI = [-11.6600, 27.4800];

    private Town $livraison;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        FoodSettings::fake([
            'max_delivery_radius_km' => 30.0,
            'out_of_radius_message' => ':restaurant est à :distance km. Rayon : :rayon km.',
        ], loadMissingValues: false);

        $this->currency = Currency::factory()->create();

        $this->livraison = Town::factory()->create([
            'latitude' => self::GOMBE[0], 'longitude' => self::GOMBE[1],
        ]);

        DelivreryPrice::factory()->create([
            'town_id' => $this->livraison->id,
            'currency_id' => $this->currency->id,
        ]);

        Sanctum::actingAs(User::factory()->create());
    }

    private function produitDe(array $position): Product
    {
        $restaurant = Restaurant::factory()
            ->located(...$position)
            ->create(['name' => 'Al-Dar']);

        return Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'currency_id' => $this->currency->id,
            'price' => 1000.0,
        ]);
    }

    private function creer(Product $produit): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/precommandes', [
            'town' => $this->livraison->slug,
            'products' => [['uid' => Cipher::Encrypt($produit->id), 'quantity' => 1]],
        ]);
    }

    public function test_une_precommande_hors_rayon_est_refusee_avec_la_phrase_du_serveur(): void
    {
        $reponse = $this->creer($this->produitDe(self::LUBUMBASHI));

        $reponse->assertStatus(400);
        $this->assertStringContainsString('Al-Dar', (string) $reponse->json('message'));
        $this->assertStringContainsString('Rayon', (string) $reponse->json('message'));
    }

    public function test_une_precommande_dans_le_rayon_est_creee(): void
    {
        $reponse = $this->creer($this->produitDe(self::GOMBE));

        $reponse->assertSuccessful();
    }
}
```

> La route exacte est dans `routes/api.php` (chercher `PrecommandeController`)
> et la charge utile dans son `FormRequest`. Si des champs sont requis en plus,
> les ajouter à `creer()` sans toucher aux assertions.

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `php artisan test --filter=PrecommandeHorsRayonTest`
Expected: FAIL — le message rendu est le texte par défaut de `messageDeRefus`,
il ne contient ni « Al-Dar » ni « Rayon ».

- [ ] **Step 3: Faire voyager la phrase avec le code**

`app/Exceptions/PrecommandeRefusee.php` :

```php
    public function __construct(
        public readonly string $raison,
        /**
         * La phrase a montrer, quand le refus depend d'un reglage et ne peut
         * donc pas etre figee cote controleur. Meme principe que
         * `BookingRefusee::$messageClient`.
         */
        public readonly ?string $message = null,
    ) {
        parent::__construct($raison);
    }

    public static function pour(string $raison, ?string $message = null): self
    {
        return new self($raison, $message);
    }
```

Les quatre appels existants de `pour()` ne passent qu'un argument : ils restent
valides, et `$message` vaut `null` comme avant.

- [ ] **Step 4: Transmettre la phrase du devis**

Dans `app/Services/PrecommandeService.php::creer()`, remplacer :

```php
        if (! $quotation->disponible) {
            throw PrecommandeRefusee::pour((string) $quotation->raison);
        }
```

par :

```php
        if (! $quotation->disponible) {
            // Le devis porte desormais une phrase pour les refus qui dependent
            // d'un reglage — la distance en est un. La laisser derriere
            // obligerait le controleur a reconstituer un message qu'il n'a pas
            // les moyens de calculer.
            throw PrecommandeRefusee::pour((string) $quotation->raison, $quotation->message);
        }
```

- [ ] **Step 5: Préférer la phrase du serveur dans le contrôleur**

Dans `app/Http/Controllers/Api/PrecommandeController.php`, à la ligne ~54,
remplacer `$this->messageDeRefus($e->raison)` par :

```php
                $e->message ?: $this->messageDeRefus($e->raison)
```

et ajouter, dans le `match` de `messageDeRefus()`, un cas de repli pour le jour
où la phrase manquerait :

```php
            QuotationService::RAISON_HORS_RAYON => 'Ce restaurant est trop loin de votre adresse de livraison.',
```

Ajouter `use App\Services\QuotationService;` si l'import manque.

- [ ] **Step 6: Lancer le test**

Run: `php artisan test --filter=PrecommandeHorsRayonTest`
Expected: PASS, 2 tests.

- [ ] **Step 7: Vérifier que les quatre refus existants n'ont pas bougé**

Run: `php artisan test --filter="Precommande"`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Exceptions/PrecommandeRefusee.php app/Services/PrecommandeService.php app/Http/Controllers/Api/PrecommandeController.php tests/Feature/Portee/PrecommandeHorsRayonTest.php
git commit -m "T81 : le refus de distance remonte jusqu-au client avec sa phrase"
```

---

### Task 6: Le catalogue annonce la distance sans rien masquer

**Files:**
- Modify: `app/Http/Controllers/Api/RestaurantController.php` (méthode `index()`)
- Modify: `app/Http/Resources/RestaurantResource.php`
- Test: `tests/Feature/Portee/CataloguePorteeTest.php`

**Interfaces:**
- Consumes: `PorteeLivraison::pour()` (tâche 2).
- Produces: `RestaurantResource` accepte une `?Portee` via `additional()` ou via un attribut dynamique `$restaurant->portee` ; trois clés de sortie : `distance_km` (`?float`), `livrable` (`bool`), `portee_message` (`?string`).

- [ ] **Step 1: Écrire le test qui échoue**

`tests/Feature/Portee/CataloguePorteeTest.php` :

```php
<?php

namespace Tests\Feature\Portee;

use App\Models\Restaurant;
use App\Models\Town;
use App\Settings\FoodSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le catalogue ne cache rien.
 *
 * Le proprietaire : « il peut voir Hunga Busta de Brazzaville flou, il peut
 * pas acceder ». Un restaurant hors rayon reste donc dans la reponse, avec de
 * quoi le griser et expliquer pourquoi.
 */
class CataloguePorteeTest extends TestCase
{
    use RefreshDatabase;

    private const GOMBE = [-4.3045, 15.3075];

    private const BRAZZAVILLE = [-4.2634, 15.2429];

    private const LUBUMBASHI = [-11.6600, 27.4800];

    protected function setUp(): void
    {
        parent::setUp();

        FoodSettings::fake([
            'max_delivery_radius_km' => 30.0,
            'out_of_radius_message' => ':restaurant est à :distance km. Rayon : :rayon km.',
        ], loadMissingValues: false);
    }

    public function test_un_restaurant_hors_rayon_reste_dans_la_liste_marque_non_livrable(): void
    {
        Restaurant::factory()->located(...self::LUBUMBASHI)->create(['name' => 'Al-Dar']);

        $reponse = $this->getJson('/api/list-restaurant?lat='.self::GOMBE[0].'&lng='.self::GOMBE[1]);

        $reponse->assertOk();
        $reponse->assertJsonCount(1, 'data');
        $reponse->assertJsonPath('data.0.livrable', false);
        $this->assertGreaterThan(1000.0, $reponse->json('data.0.distance_km'));
        $this->assertStringContainsString('Al-Dar', $reponse->json('data.0.portee_message'));
    }

    public function test_un_restaurant_dans_le_rayon_est_livrable(): void
    {
        Restaurant::factory()->located(...self::GOMBE)->create();

        $reponse = $this->getJson('/api/list-restaurant?lat='.self::BRAZZAVILLE[0].'&lng='.self::BRAZZAVILLE[1]);

        $reponse->assertJsonPath('data.0.livrable', true);
        $reponse->assertJsonPath('data.0.portee_message', null);
    }

    public function test_sans_position_la_reponse_est_celle_d_avant(): void
    {
        Restaurant::factory()->located(...self::LUBUMBASHI)->create();

        $reponse = $this->getJson('/api/list-restaurant');

        $reponse->assertOk();
        // Aucun controle possible : tout est livrable, comme avant ce lot.
        $reponse->assertJsonPath('data.0.livrable', true);
        $reponse->assertJsonPath('data.0.distance_km', null);
    }

    public function test_la_commune_de_livraison_suffit_quand_la_position_manque(): void
    {
        $livraison = Town::factory()->create([
            'latitude' => self::LUBUMBASHI[0], 'longitude' => self::LUBUMBASHI[1],
        ]);
        Restaurant::factory()->located(...self::GOMBE)->create();

        $reponse = $this->getJson('/api/list-restaurant?town='.$livraison->slug);

        $reponse->assertJsonPath('data.0.livrable', false);
    }
}
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `php artisan test --filter=CataloguePorteeTest`
Expected: FAIL — la clé `livrable` n'existe pas.

- [ ] **Step 3: Calculer la portée dans `index()`**

Dans `app/Http/Controllers/Api/RestaurantController.php` :

```php
    public function index(Request $request)
    {
        $search = $request->input('search');

        $restaurant = Restaurant::query()->where('is_active', true)
            // La regle lit la ville du restaurant pour son rayon : sans ce
            // prechargement, chaque ligne couterait trois requetes de plus.
            ->with('town.city')
            ->when($search, fn($query) => $query->where('name', 'like', '%' . $search . '%')
                ->orWhere('description', 'like', '%' . $search . '%')
            )
            ->get();

        /*
         * La portee est calculee ici et attachee a chaque restaurant.
         *
         * La ressource ne la calcule pas elle-meme : une ressource est
         * serialisee une fois par ligne, et y mettre une regle metier la
         * rendrait impossible a tester sans passer par HTTP.
         */
        $livraison = $request->filled('town')
            ? Town::query()->where('slug', $request->input('town'))->first()
            : null;

        $lat = $request->filled('lat') ? (float) $request->input('lat') : null;
        $lng = $request->filled('lng') ? (float) $request->input('lng') : null;

        $portees = app(PorteeLivraison::class);

        $restaurant->each(fn (Restaurant $item) => $item->setAttribute(
            'portee', $portees->pour($item, $livraison, $lat, $lng),
        ));

        return RestaurantResource::collection($restaurant);
    }
```

Ajouter les imports `App\Models\Town` et `App\Services\PorteeLivraison` en tête
de fichier.

- [ ] **Step 4: Exposer les trois clés**

Dans `app/Http/Resources/RestaurantResource.php`, à la suite de `'commune'` :

```php
            /*
             * Le restaurant hors rayon n'est pas retire de la liste : il est
             * rendu avec de quoi le griser. Le proprietaire veut le voir
             * « flou », pas le voir disparaitre.
             *
             * `livrable` vaut vrai par defaut : un client qui n'envoie pas sa
             * position voit le catalogue d'avant, inchange.
             */
            'distance_km' => $this->resource->portee?->distance_km,
            'livrable' => $this->resource->portee?->autorise ?? true,
            'portee_message' => $this->resource->portee?->message,
```

- [ ] **Step 5: Lancer le test**

Run: `php artisan test --filter=CataloguePorteeTest`
Expected: PASS, 4 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/RestaurantController.php app/Http/Resources/RestaurantResource.php tests/Feature/Portee/CataloguePorteeTest.php
git commit -m "T81 : le catalogue annonce la distance et la livrabilite"
```

---

### Task 7: La recherche de plats plafonne le rayon du client

Aujourd'hui `eligibleRestaurants()` accepte un `radius` **fourni par le client** :
il filtre, mais ne contraint rien. Un client qui envoie `radius=10000` voit tout.

**Files:**
- Modify: `app/Services/ProductSearchService.php` (lignes 51-106)
- Test: `tests/Feature/Portee/RecherchePlatsRayonTest.php`

**Interfaces:**
- Consumes: `PorteeLivraison::rayonPour()` (tâche 2).
- Produces: aucun changement de signature ; `eligibleRestaurants()` garde
  `array{ids: array<int,int>, distances: array<int,float|null>}`.

- [ ] **Step 1: Écrire le test qui échoue**

`tests/Feature/Portee/RecherchePlatsRayonTest.php` :

```php
<?php

namespace Tests\Feature\Portee;

use App\Models\Restaurant;
use App\Services\ProductSearchService;
use App\Settings\FoodSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecherchePlatsRayonTest extends TestCase
{
    use RefreshDatabase;

    private const GOMBE = [-4.3045, 15.3075];

    private const LUBUMBASHI = [-11.6600, 27.4800];

    protected function setUp(): void
    {
        parent::setUp();

        FoodSettings::fake([
            'max_delivery_radius_km' => 30.0,
            'out_of_radius_message' => 'Trop loin.',
        ], loadMissingValues: false);
    }

    public function test_un_rayon_client_demesure_est_plafonne_par_le_reglage(): void
    {
        $loin = Restaurant::factory()->located(...self::LUBUMBASHI)->create();

        $resultat = app(ProductSearchService::class)->eligibleRestaurants([
            'lat' => self::GOMBE[0],
            'lng' => self::GOMBE[1],
            'radius' => 10000.0,
        ]);

        $this->assertNotContains($loin->id, $resultat['ids']);
    }

    public function test_un_rayon_client_plus_serre_que_le_reglage_est_respecte(): void
    {
        $proche = Restaurant::factory()->located(-4.3700, 15.3500)->create();

        $resultat = app(ProductSearchService::class)->eligibleRestaurants([
            'lat' => self::GOMBE[0],
            'lng' => self::GOMBE[1],
            'radius' => 1.0,
        ]);

        // Gombe-Limete fait environ 9 km : un rayon de 1 km exclut.
        $this->assertNotContains($proche->id, $resultat['ids']);
    }

    public function test_sans_rayon_client_le_reglage_s_applique_quand_meme(): void
    {
        $loin = Restaurant::factory()->located(...self::LUBUMBASHI)->create();
        $proche = Restaurant::factory()->located(-4.3700, 15.3500)->create();

        $resultat = app(ProductSearchService::class)->eligibleRestaurants([
            'lat' => self::GOMBE[0],
            'lng' => self::GOMBE[1],
        ]);

        $this->assertNotContains($loin->id, $resultat['ids']);
        $this->assertContains($proche->id, $resultat['ids']);
    }

    public function test_sans_position_aucun_filtrage(): void
    {
        $loin = Restaurant::factory()->located(...self::LUBUMBASHI)->create();

        $resultat = app(ProductSearchService::class)->eligibleRestaurants([]);

        $this->assertContains($loin->id, $resultat['ids']);
    }
}
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `php artisan test --filter=RecherchePlatsRayonTest`
Expected: FAIL — le restaurant de Lubumbashi est éligible.

- [ ] **Step 3: Plafonner le rayon**

Dans `app/Services/ProductSearchService.php`, `eligibleRestaurants()` :

le `get(['id', 'name', 'location'])` ne charge pas la commune, dont la règle a
besoin pour le rayon. Le remplacer :

```php
        $restaurants = $query->with('town.city')->get(['id', 'name', 'location', 'town_id']);
```

puis, juste après la lecture de `$radius` :

```php
        $portees = app(PorteeLivraison::class);
```

et, à la place du filtre actuel :

```php
            // Le rayon n'exclut que les restaurants dont on connaît la position.
            if ($radius !== null && $distance > $radius) {
                continue;
            }
```

écrire :

```php
            /*
             * Le rayon du client ne fait que RESSERRER celui du reglage.
             *
             * Avant ce lot, `radius` venait entierement du client : il filtrait
             * un affichage sans rien contraindre, et `radius=10000` rendait
             * tout le pays eligible. La borne haute appartient au serveur,
             * comme le prix.
             */
            $plafond = $portees->rayonPour($restaurant);
            $limite = $radius !== null ? min($radius, $plafond) : $plafond;

            if ($distance > $limite) {
                continue;
            }
```

- [ ] **Step 4: Lancer le test**

Run: `php artisan test --filter=RecherchePlatsRayonTest`
Expected: PASS, 4 tests.

- [ ] **Step 5: Vérifier la non-régression sur la recherche et le budget**

Run: `php artisan test --filter="Recherche|Budget|ProductSearch"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/ProductSearchService.php tests/Feature/Portee/RecherchePlatsRayonTest.php
git commit -m "T81 : le rayon du client ne fait que resserrer celui du reglage"
```

---

### Task 8: Le rayon se règle en administration

**Files:**
- Modify: `app/Filament/Resources/CityResource.php`
- Test: `tests/Feature/Portee/AdminRayonVilleTest.php`

**Interfaces:**
- Consumes: `City::$max_delivery_radius_km` (tâche 1).
- Produces: un champ `max_delivery_radius_km` dans le formulaire et une colonne dans la table.

- [ ] **Step 1: Lire la ressource existante**

Run: `cat app/Filament/Resources/CityResource.php`

Relever la forme exacte du `form()` et du `table()` — champs, imports, style —
pour s'y conformer plutôt que d'inventer une mise en page.

- [ ] **Step 2: Écrire le test qui échoue**

`tests/Feature/Portee/AdminRayonVilleTest.php` :

```php
<?php

namespace Tests\Feature\Portee;

use App\Filament\Resources\CityResource\Pages\CreateCity;
use App\Models\City;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Le rayon se negocie ville par ville, donc il se saisit.
 */
class AdminRayonVilleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        Gate::before(fn () => true);
        $this->actingAs($admin);
    }

    public function test_un_rayon_saisi_est_enregistre(): void
    {
        Livewire::test(CreateCity::class)
            ->fillForm([
                'title' => 'Lubumbashi',
                'is_active' => true,
                'max_delivery_radius_km' => 20,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(20.0, City::query()->first()->max_delivery_radius_km);
    }

    public function test_une_ville_sans_rayon_reste_a_null(): void
    {
        Livewire::test(CreateCity::class)
            ->fillForm(['title' => 'Kinshasa', 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(City::query()->first()->max_delivery_radius_km);
    }

    /** Review Focus 4 : zero fermerait la ville par une fausse manipulation. */
    public function test_un_rayon_a_zero_est_refuse_par_le_formulaire(): void
    {
        Livewire::test(CreateCity::class)
            ->fillForm([
                'title' => 'Kinshasa',
                'is_active' => true,
                'max_delivery_radius_km' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['max_delivery_radius_km']);
    }
}
```

- [ ] **Step 3: Lancer le test pour vérifier qu'il échoue**

Run: `php artisan test --filter=AdminRayonVilleTest`
Expected: FAIL — le champ n'existe pas dans le formulaire.

- [ ] **Step 4: Ajouter le champ**

Dans le `form()` de `app/Filament/Resources/CityResource.php` :

```php
                Forms\Components\TextInput::make('max_delivery_radius_km')
                    ->label('Rayon de livraison (km)')
                    ->helperText(
                        'Laisser vide pour appliquer le rayon global. '
                        .'Au-delà de cette distance, un client ne peut pas commander '
                        .'dans un restaurant de cette ville.'
                    )
                    ->numeric()
                    // Zero fermerait la ville entiere sur une faute de frappe.
                    // Pour fermer une ville, on la desactive.
                    ->minValue(1)
                    ->maxValue(500)
                    ->nullable(),
```

Dans le `table()` :

```php
                Tables\Columns\TextColumn::make('max_delivery_radius_km')
                    ->label('Rayon (km)')
                    ->placeholder('global')
                    ->sortable(),
```

- [ ] **Step 5: Lancer le test**

Run: `php artisan test --filter=AdminRayonVilleTest`
Expected: PASS, 3 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Resources/CityResource.php tests/Feature/Portee/AdminRayonVilleTest.php
git commit -m "T81 : le rayon d'une ville se regle en administration"
```

---

### Task 9: Toute la suite, et revue du lot backend

**Files:** aucun.

- [ ] **Step 1: Lancer la suite complète**

Run: `php artisan test`
Expected: PASS. En cas d'échec erratique, vérifier qu'aucune autre session
n'écrit dans le projet pendant le run — c'est la cause déjà constatée.

- [ ] **Step 2: Formater**

Run: `./vendor/bin/pint`
Expected: les fichiers touchés sont conformes.

- [ ] **Step 3: Vérifier le comportement d'un client qui n'a pas bougé**

Run:
```bash
php artisan test --filter="CataloguePorteeTest::test_sans_position_la_reponse_est_celle_d_avant"
php artisan test --filter="DevisHorsRayonTest::test_un_restaurant_sans_position_exploitable_reste_chiffre"
```
Expected: PASS. Ces deux tests sont la garantie de non-régression du parc installé.

- [ ] **Step 4: Commit du formatage s'il y a lieu**

```bash
git add -A
git commit -m "T81 : formatage pint"
```

---

### Task 10: Mobile — le restaurant hors rayon est flou, pas caché

**Files:**
- Modify: `front end/thalia-delivery/services/types/index.ts`
- Modify: `front end/thalia-delivery/components/home/restaurant-row.tsx`
- Modify: `front end/thalia-delivery/components/rental/hors-zone.tsx`
- Test: `front end/thalia-delivery/components/__tests__/restaurant-row.test.tsx`

**Interfaces:**
- Consumes: `distance_km`, `livrable`, `portee_message` de `GET /api/list-restaurant` (tâche 6).
- Produces: `RestaurantRow` accepte `onPressHorsRayon?: () => void` ; `HorsZone` accepte `service: string` (déjà) et devient réutilisable hors location.

- [ ] **Step 1: Étendre le type**

Dans `services/types/index.ts`, sur `RestaurantType`, après `"image"?: string` :

```ts
    /** Distance entre le restaurant et l'adresse de livraison. Null si inconnue. */
    "distance_km"?: number | null,
    /** Faux quand le restaurant est hors du rayon autorisé. Vrai par défaut. */
    "livrable"?: boolean,
    /** La phrase du serveur. Jamais recopiée ici. */
    "portee_message"?: string | null
```

Faire le même ajout dans `services/types/main.ts` si `RestaurantType` y est
aussi déclaré — les deux déclarations existent et doivent rester cohérentes.

- [ ] **Step 2: Écrire le test qui échoue**

`components/__tests__/restaurant-row.test.tsx` :

```tsx
import React from "react";
import {render} from "@testing-library/react-native";

import RestaurantRow from "@/components/home/restaurant-row";

const base = {
    name: "Hunga Busta",
    adresse: "Centre-ville",
    slug: "hunga-busta",
    opens: [],
    phone: "+243900000000",
    whatsapp: "+243900000000",
} as any;

describe("RestaurantRow", () => {
    it("affiche la distance et le motif quand le restaurant est hors rayon", async () => {
        const {getByText} = await render(
            <RestaurantRow
                restaurant={{...base, livrable: false, distance_km: 412.5}}
                deliveryFrom={null}
                onPress={() => {}}
            />,
        );

        expect(getByText(/412/)).toBeTruthy();
        expect(getByText(/hors zone/i)).toBeTruthy();
    });

    it("n'affiche rien de tel quand le restaurant est livrable", async () => {
        const {queryByText} = await render(
            <RestaurantRow
                restaurant={{...base, livrable: true, distance_km: 3.2}}
                deliveryFrom={null}
                onPress={() => {}}
            />,
        );

        expect(queryByText(/hors zone/i)).toBeNull();
    });

    it("reste livrable quand le serveur ne dit rien", async () => {
        const {queryByText} = await render(
            <RestaurantRow restaurant={base} deliveryFrom={null} onPress={() => {}} />,
        );

        expect(queryByText(/hors zone/i)).toBeNull();
    });
});
```

> `render()` de `@testing-library/react-native` v14 est **asynchrone** : le
> `await` n'est pas décoratif.

- [ ] **Step 3: Lancer le test pour vérifier qu'il échoue**

Run: `cd "front end/thalia-delivery" && npx jest components/__tests__/restaurant-row.test.tsx`
Expected: FAIL — « hors zone » n'est rendu nulle part.

- [ ] **Step 4: Rendre l'état hors rayon**

Dans `components/home/restaurant-row.tsx`, après la ligne
`const closedLabel = …` :

```tsx
    // Vrai par defaut : un serveur qui ne dit rien n'interdit rien.
    const livrable = restaurant.livrable !== false;
    const distance = restaurant.distance_km;
```

Le conteneur passe à :

```tsx
            className="flex-row items-center gap-3 rounded-card bg-surface p-3"
            style={{
                boxShadow: shadows.card,
                borderCurve: "continuous",
                // Grise, mais toujours tactile : un element eteint qui ne
                // repond pas a l'appui passe pour un bug.
                opacity: livrable ? 1 : 0.45,
            }}
```

et l'`accessibilityLabel` :

```tsx
            accessibilityLabel={
                !livrable
                    ? `${restaurant.name}, hors zone de livraison`
                    : isOpen
                      ? restaurant.name
                      : `${restaurant.name}, fermé. ${closedLabel}`
            }
```

Enfin, la troisième ligne du bloc de droite devient :

```tsx
                {!livrable ? (
                    <Text className="text-caption font-semibold text-warning" numberOfLines={1}>
                        {distance != null
                            ? `À ${distance.toFixed(1)} km  ·  hors zone de livraison`
                            : "Hors zone de livraison"}
                    </Text>
                ) : isOpen ? (
                    deliveryFrom ? (
                        <Text className="text-caption font-semibold text-brand-700">
                            Livraison dès {formatPrice(deliveryFrom.amount, deliveryFrom.currency)}
                        </Text>
                    ) : null
                ) : (
                    <Text className="text-caption font-semibold text-warning">{closedLabel}</Text>
                )}
```

Aucune couleur en dur : `text-warning` et `opacity` seulement.

- [ ] **Step 5: Lancer le test**

Run: `npx jest components/__tests__/restaurant-row.test.tsx`
Expected: PASS, 3 tests.

- [ ] **Step 6: Rendre `hors-zone` réutilisable**

Dans `components/rental/hors-zone.tsx`, la phrase de repli nomme la location.
La généraliser sans changer le comportement actuel :

```tsx
interface Props {
    /** Le message du serveur, jamais une phrase recopiee ici. */
    message?: string | null;
    /** Le numero du service, tel que l'administration le regle. */
    whatsapp?: string | null;
    /** Le nom commercial du service concerne. */
    service: string;
    /** Le libelle du bouton d'action. Defaut : « Écrire à {service} ». */
    actionLabel?: string;
}
```

et le repli :

```tsx
                {message?.trim() ||
                    `Ce service n’est pas disponible ici. Contactez ${service}.`}
```

> Le composant vit dans `components/rental/` et y reste pour ce lot : le
> déplacer vers `components/commons/` toucherait ses trois appelants actuels
> sans rien apporter au lot 1.

- [ ] **Step 7: Brancher l'appui sur un restaurant hors rayon**

Dans `app/(client)/screens/home.tsx`, le `renderItem` conserve `onPress`, mais
l'écran de détail doit refuser le panier. **Ne pas** bloquer la navigation :
« on peut vouloir regarder la carte avant l'ouverture » vaut aussi pour la
distance. Le bouton d'ajout au panier de l'écran de détail s'appuie sur la
réponse du devis, qui refuse déjà avec `raison: hors_rayon` (tâche 3) et porte
le message — rien à coder ici.

Run: `npx tsc --noEmit`
Expected: aucune erreur.

- [ ] **Step 8: Lint et commit**

```bash
cd "front end/thalia-delivery"
npm run lint
git add services/types/index.ts services/types/main.ts components/home/restaurant-row.tsx components/rental/hors-zone.tsx components/__tests__/restaurant-row.test.tsx
git commit -m "T81 : le restaurant hors rayon est grise, avec sa distance"
```

---

### Task 11: Web — la carte grisée et le refus du devis

Le web a les mêmes tokens que le mobile (`surface`, `ink-muted`, `brand-700`,
`success-surface`, `rounded-card`) : rien de nouveau à définir.

`app/restaurant/page.jsx` appelle `Route.list_restaurant` **sans position** —
le catalogue web ne connaît pas l'adresse de livraison à ce moment-là. Les
cartes s'affichent donc comme avant, `livrable` étant absent de la réponse.
La protection réelle du web est le devis, qu'il appelle au panier.

Cette tâche fait donc deux choses, et pas une de plus : la carte **sait**
rendre l'état hors rayon quand le serveur l'envoie, et le refus du devis
s'affiche avec la phrase du serveur.

**Files:**
- Modify: `front end/next-app/components/restaurants/CardRestaurant.jsx`
- Modify: l'appelant du devis (repéré au Step 1)

**Interfaces:**
- Consumes: `livrable`, `distance_km` de `GET /api/list-restaurant` (tâche 6) ; `raison: 'hors_rayon'` et `message` de `POST /api/quotation` (tâche 3).
- Produces: aucune nouvelle interface.

- [ ] **Step 1: Repérer l'appelant du devis**

Run:
```bash
cd "front end/next-app"
grep -rn "quotation" helpers/ hooks/ services/ components/ app/ --include=*.ts --include=*.tsx --include=*.js --include=*.jsx | grep -v node_modules
```

Noter le fichier qui lit la réponse du devis et la façon dont il affiche
aujourd'hui une erreur (toast, bandeau). **Le refus de distance emprunte ce
chemin-là**, pas un second.

- [ ] **Step 2: Rendre l'état hors rayon sur la carte**

Dans `components/restaurants/CardRestaurant.jsx`, après
`const horaire = etatOuverture(restaurant?.opens);` :

```jsx
    /*
     * Vrai par defaut : le catalogue web n'envoie pas encore de position, le
     * serveur ne renvoie donc pas `livrable`, et la carte doit s'afficher
     * comme avant. Elle sait rendre l'etat le jour ou la position sera
     * transmise.
     */
    const livrable = restaurant?.livrable !== false;
    const distance = restaurant?.distance_km;
```

Sur le `<Link>`, ajouter l'opacité — la carte **reste cliquable**, comme sur
mobile et pour la même raison :

```jsx
            className={`group flex h-full flex-col overflow-hidden rounded-card bg-surface shadow-card transition-shadow duration-200 hover:shadow-raised focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 ${
                livrable ? "" : "opacity-45"
            }`}
```

Remplacer la pastille d'ouverture par une pastille qui donne la priorité à la
distance — hors zone, l'horaire n'a plus d'intérêt :

```jsx
                <span
                    className={`absolute left-3 top-3 rounded-pill px-2.5 py-1 text-caption font-bold ${
                        !livrable
                            ? "bg-warning-surface text-warning"
                            : horaire.ouvert
                              ? "bg-success-surface text-success"
                              : "bg-white/90 text-ink-muted"
                    }`}
                >
                    {!livrable ? "Hors zone" : horaire.ouvert ? "Ouvert" : "Fermé"}
                </span>
```

Et la ligne du bas :

```jsx
                <p
                    className={`mt-auto pt-3 text-caption ${
                        !livrable
                            ? "font-semibold text-warning"
                            : horaire.ouvert
                              ? "text-ink-subtle"
                              : "font-semibold text-brand-700"
                    }`}
                >
                    {!livrable
                        ? distance != null
                            ? `À ${distance.toFixed(1)} km · hors zone de livraison`
                            : "Hors zone de livraison"
                        : horaire.ouvert
                          ? horaire.creneau
                          : libelleFermeture(horaire)}
                </p>
```

- [ ] **Step 3: Vérifier que `warning` existe dans le thème web**

Run: `grep -n "warning" tailwind.config.*`
Expected: `warning` et `warning-surface` sont définis. **S'ils ne le sont pas**,
utiliser `brand-700` sur `bg-brand-50`, déjà présents sur cette carte, plutôt
que d'ajouter une couleur au thème — le lot 1 n'étend pas la charte.

- [ ] **Step 4: Afficher le refus du devis**

Dans le fichier repéré au Step 1, quand la réponse porte
`data.raison === "hors_rayon"`, afficher `data.message` **tel quel**. C'est la
phrase du serveur ; la recopier ici la ferait diverger du refus de l'API, qui
est la même règle.

Rappel : les erreurs axios sont **retournées**, pas levées. Tester
`instanceof AxiosError` ou la présence de `data`, comme le fait déjà le reste
du fichier. Ne pas convertir en `throw`.

- [ ] **Step 5: Vérifier**

Run:
```bash
cd "front end/next-app"
npm run lint
npx tsc --noEmit
```
Expected: aucune erreur. `next.config.mjs` met `eslint.ignoreDuringBuilds` **et**
`typescript.ignoreBuildErrors` à `true` : ces deux commandes sont la seule
vérification réelle, le build ne détecte rien.

- [ ] **Step 6: Commit**

```bash
cd "front end/next-app"
git add components/restaurants/CardRestaurant.jsx
git add -u
git commit -m "T81 : web, la carte hors zone est grisee et le devis explique"
```

> Dépôt séparé : ce commit part dans `siska243/thalia-eats`, pas dans celui du
> backend. Il n'y a pas de git à la racine du monorepo.

---

## Ce que ce plan ne couvre pas

- **Lot 2** (enseigne `brands`, `restaurant_managers`, dé-`first()` de
  `getCurrentRestaurant`) : plan séparé, écrit après la livraison du lot 1.
  C'est le seul lot qui change une forme de réponse (`userRestaurant()`) et il
  demande un build EAS.
- **Lot 3** (`vehicles.city_id`, types de course A/B/C, tarification B et C) :
  plan séparé.
- Le peuplement des coordonnées des restaurants existants : c'est de la saisie
  en administration, pas du code. Le repli par centroïde couvre l'intervalle.
