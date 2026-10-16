<?php

namespace Tests\Feature\Rental;

use App\Models\City;
use App\Models\CourseType;
use App\Models\Currency;
use App\Models\Town;
use App\Models\Vehicle;
use App\Settings\RentalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les filtres du catalogue de location.
 *
 * Le proprietaire, le 16 octobre : « dans le filtre il faut ajouter le type
 * de course, egalement proposer la location, ville, commune (multiple) ».
 *
 * Ils filtrent cote SERVEUR, sur la requete paginee : filtrer une page deja
 * chargee donnerait des pages a trous, et filtrer une collection complete
 * obligerait a lire tout le parc pour en renvoyer quinze.
 *
 * Aucun filtre transmis, aucun filtre applique : les applications deja
 * installees n'en envoient pas, et leur catalogue ne change pas.
 */
class FiltresCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private Currency $devise;

    protected function setUp(): void
    {
        parent::setUp();

        RentalSettings::fake([
            'deposit_percentage' => 10.0,
            'refund_percentage' => 100.0,
            'minimum_hours' => 1.0,
            'pickup_code_max_attempts' => 3,
            'hours_min' => 1,
            'hours_max' => 23,
            'days_min' => 1,
            'days_max' => 30,
            'lead_time_minutes' => 30,
            'whatsapp_number' => '',
            'brand_food' => 'Thalia Eats',
            'brand_rental' => 'Thalia Trans',
            'out_of_zone_message' => 'Nous ne desservons pas encore cette commune.',
        ], loadMissingValues: false);

        $this->devise = Currency::query()->create([
            'title' => 'Dollars', 'code' => 'USD', 'is_active' => 1,
        ]);
    }

    private function vehicule(array $attributs = []): Vehicle
    {
        return Vehicle::query()->create(array_merge([
            'brand' => 'Toyota', 'model' => 'Corolla',
            'plate_number' => 'KIN-'.uniqid(),
            'hourly_rate' => 20, 'currency_id' => $this->devise->id,
            'is_active' => true,
        ], $attributs))->fresh();
    }

    private function catalogue(array $filtres = []): array
    {
        return $this->getJson('/api/rental/vehicles?'.http_build_query($filtres))
            ->assertSuccessful()
            ->json('data');
    }

    /** Sans filtre, tout le parc louable sort : rien ne change pour l'existant. */
    public function test_sans_filtre_tout_le_parc_sort(): void
    {
        $this->vehicule();
        $this->vehicule();

        $this->assertCount(2, $this->catalogue());
    }

    public function test_le_filtre_par_ville(): void
    {
        $kinshasa = City::factory()->create(['title' => 'Kinshasa']);
        $lubumbashi = City::factory()->create(['title' => 'Lubumbashi']);

        $this->vehicule(['city_id' => $kinshasa->id]);
        $this->vehicule(['city_id' => $lubumbashi->id]);

        $resultat = $this->catalogue(['city_id' => $kinshasa->id]);

        $this->assertCount(1, $resultat);
        $this->assertSame('Kinshasa', $resultat[0]['city']['title']);
    }

    /** Les communes se cumulent : on cherche dans plusieurs a la fois. */
    public function test_le_filtre_par_communes_multiples(): void
    {
        $ville = City::factory()->create(['title' => 'Kinshasa']);
        $gombe = Town::factory()->create(['city_id' => $ville->id, 'title' => 'Gombe']);
        $limete = Town::factory()->create(['city_id' => $ville->id, 'title' => 'Limete']);
        $masina = Town::factory()->create(['city_id' => $ville->id, 'title' => 'Masina']);

        $this->vehicule()->towns()->sync([$gombe->id]);
        $this->vehicule()->towns()->sync([$limete->id]);
        $this->vehicule()->towns()->sync([$masina->id]);

        $this->assertCount(2, $this->catalogue(['town_ids' => [$gombe->id, $limete->id]]));
    }

    /**
     * Un vehicule SANS commune sort toujours : il dessert toute sa ville.
     *
     * Le retirer d'un filtre par commune cacherait justement celui qui
     * accepte la course.
     */
    public function test_un_vehicule_sans_commune_sort_de_tous_les_filtres(): void
    {
        $ville = City::factory()->create(['title' => 'Kinshasa']);
        $gombe = Town::factory()->create(['city_id' => $ville->id, 'title' => 'Gombe']);

        $this->vehicule();

        $this->assertCount(1, $this->catalogue(['town_ids' => [$gombe->id]]));
    }

    public function test_le_filtre_par_type_de_course(): void
    {
        $type = CourseType::query()->updateOrCreate(['code' => 'C'], ['title' => 'Aeroport']);

        $avec = $this->vehicule();
        $avec->coursePrices()->create(['course_type_id' => $type->id, 'hourly_rate' => 35]);
        $this->vehicule();

        $resultat = $this->catalogue(['course_type_id' => $type->id]);

        $this->assertCount(1, $resultat);
        $this->assertSame($avec->uid ?? $resultat[0]['uid'], $resultat[0]['uid']);
    }

    /** Le mode propose : on ne cherche que ce qu'on peut reserver. */
    public function test_le_filtre_par_mode_propose(): void
    {
        // Le TARIF decide : renseigne, le mode est propose ; vide, il ne
        // l'est pas. Il n'y a pas de case a cocher.
        $this->vehicule(['daily_rate' => 150]);
        $this->vehicule(['daily_rate' => null]);

        /*
         * `proposes` et non `pricing_mode` : ce dernier dit comment CHIFFRER,
         * et un vehicule sans tarif journalier reste chiffre a l'heure quand
         * la journee est demandee. Les confondre le cacherait du catalogue.
         */
        $this->assertCount(1, $this->catalogue(['proposes' => 'daily']));
        $this->assertCount(2, $this->catalogue(['proposes' => 'hourly']));
    }

    /** Les filtres se combinent, et se combinent en ET. */
    public function test_les_filtres_se_combinent(): void
    {
        $kinshasa = City::factory()->create(['title' => 'Kinshasa']);
        $lubumbashi = City::factory()->create(['title' => 'Lubumbashi']);
        $type = CourseType::query()->updateOrCreate(['code' => 'C'], ['title' => 'Aeroport']);

        $bon = $this->vehicule(['city_id' => $kinshasa->id]);
        $bon->coursePrices()->create(['course_type_id' => $type->id, 'hourly_rate' => 35]);

        // Bonne ville, mauvais type.
        $this->vehicule(['city_id' => $kinshasa->id]);

        // Bon type, mauvaise ville.
        $ailleurs = $this->vehicule(['city_id' => $lubumbashi->id]);
        $ailleurs->coursePrices()->create(['course_type_id' => $type->id, 'hourly_rate' => 35]);

        $this->assertCount(1, $this->catalogue([
            'city_id' => $kinshasa->id,
            'course_type_id' => $type->id,
        ]));
    }

    /**
     * L'ecran de filtre a besoin de savoir quels types EXISTENT.
     *
     * Cette liste avait ete retiree de la configuration en T116, parce que le
     * detail d'un vehicule devait lire SES types et non un catalogue global.
     * Un filtre pose une autre question — « quels choix proposer » — et ne
     * peut pas la poser au parc sans charger tout le parc.
     */
    public function test_la_configuration_donne_les_types_pour_le_filtre(): void
    {
        CourseType::query()->updateOrCreate(
            ['code' => 'A'],
            ['title' => 'Course simple', 'position' => 1, 'is_active' => true],
        );
        CourseType::query()->updateOrCreate(
            ['code' => 'Z'],
            ['title' => 'Retire du catalogue', 'position' => 2, 'is_active' => false],
        );

        $types = $this->getJson('/api/rental/config')
            ->assertSuccessful()
            ->json('data.course_types');

        // A, B et C sont poses par la migration de reference ; seul le
        // retrait du catalogue est en jeu ici.
        $codes = array_column($types, 'code');
        $this->assertContains('A', $codes);
        $this->assertNotContains('Z', $codes);
    }
}
