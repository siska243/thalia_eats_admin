<?php

namespace Tests\Feature\Rental;

use App\Models\Booking;
use App\Models\Chauffeur;
use App\Models\Currency;
use App\Models\User;
use App\Models\Vehicle;
use App\Settings\RentalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Les ecrans du chauffeur de location : tableau de bord, courses, historique.
 *
 * Le chauffeur est informatif — il n'accepte ni ne refuse. Ce qui doit etre
 * tenu ici, c'est donc ce qu'il VOIT : ses courses a lui, jamais celles d'un
 * autre, et jamais le code de prise en charge, qu'il saisit et ne recoit pas.
 */
class CourseChauffeurApiTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $vehicle;

    private Currency $currency;

    private User $client;

    private User $compteChauffeur;

    private Chauffeur $chauffeur;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Les reglages sont poses ici, comme dans les autres suites de
         * location : une autre classe vide la table `settings`, et la lecture
         * des bornes de duree tombait alors en plein milieu de ces tests, qui
         * passaient pourtant seuls. `loadMissingValues: false` impose la
         * liste complete plutot que de masquer un oubli.
         */
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

        $this->currency = Currency::query()->create([
            'title' => 'Dollars', 'code' => 'USD', 'is_active' => 1,
        ]);

        $this->vehicle = Vehicle::query()->create([
            'brand' => 'Toyota', 'model' => 'Land Cruiser',
            'plate_number' => 'KIN-0001', 'hourly_rate' => 10, 'is_active' => true,
            'currency_id' => $this->currency->id,
        ]);

        $this->client = User::factory()->create(['phone' => '0810000001']);
        $this->compteChauffeur = User::factory()->create();

        $this->chauffeur = Chauffeur::query()->create([
            'user_id' => $this->compteChauffeur->id,
            'full_name' => 'Jean Kabila',
            'phone' => '+243810000002',
            'licence_expires_at' => now()->addYear(),
            'is_active' => true,
        ]);
    }

    private function course(string $status, ?Chauffeur $chauffeur = null, string $depart = '+2 hours'): Booking
    {
        return Booking::query()->create([
            'user_id' => $this->client->id,
            'vehicle_id' => $this->vehicle->id,
            'currency_id' => $this->currency->id,
            'chauffeur_id' => ($chauffeur ?? $this->chauffeur)->id,
            'reference' => 'R-'.strtoupper(uniqid()),
            'status' => $status,
            'starts_at' => Carbon::parse($depart),
            'ends_at' => Carbon::parse($depart)->addHours(3),
            'duration_hours' => 3,
            'hourly_rate' => 10,
            'total' => 30,
            'deposit' => 6,
            'balance' => 24,
            'pickup_location' => 'Gombe',
            'pickup_code' => '4821',
            'pricing_mode' => 'hourly',
            'billed_units' => 3,
        ]);
    }

    public function test_le_chauffeur_voit_ses_courses_engagees(): void
    {
        $enCours = $this->course(Booking::STATUS_IN_PROGRESS);
        $aVenir = $this->course(Booking::STATUS_CONFIRMED, depart: '+5 hours');
        $terminee = $this->course(Booking::STATUS_COMPLETED, depart: '-2 days');

        Sanctum::actingAs($this->compteChauffeur, ['*']);

        $references = collect($this->getJson('/api/rental/chauffeur/courses')
            ->assertOk()
            ->json('data'))
            ->pluck('reference')
            ->all();

        $this->assertSame([$enCours->reference, $aVenir->reference], $references);
        $this->assertNotContains($terminee->reference, $references);
    }

    public function test_l_historique_ne_rend_que_les_courses_finies(): void
    {
        $terminee = $this->course(Booking::STATUS_COMPLETED, depart: '-2 days');
        $annulee = $this->course(Booking::STATUS_CANCELLED, depart: '-1 day');
        $enCours = $this->course(Booking::STATUS_IN_PROGRESS);

        Sanctum::actingAs($this->compteChauffeur, ['*']);

        $references = collect($this->getJson('/api/rental/chauffeur/historique')
            ->assertOk()
            ->json('data'))
            ->pluck('reference')
            ->all();

        $this->assertSame([$annulee->reference, $terminee->reference], $references);
        $this->assertNotContains($enCours->reference, $references);
    }

    /**
     * Les courses d'un autre chauffeur ne sortent jamais.
     *
     * L'autorisation vient d'une clause SQL, pas d'un role : sans ce test,
     * ajouter plus tard un `where` sur le role au lieu de la fiche ouvrirait
     * les courses de tout le monde a n'importe quel chauffeur.
     */
    public function test_un_chauffeur_ne_voit_pas_les_courses_d_un_autre(): void
    {
        $autreCompte = User::factory()->create();
        $autre = Chauffeur::query()->create([
            'user_id' => $autreCompte->id,
            'full_name' => 'Paul Mbemba',
            'phone' => '+243810000003',
            'licence_expires_at' => now()->addYear(),
            'is_active' => true,
        ]);

        $sienne = $this->course(Booking::STATUS_IN_PROGRESS);
        $celleDeLAutre = $this->course(Booking::STATUS_IN_PROGRESS, $autre);

        Sanctum::actingAs($autreCompte, ['*']);

        $references = collect($this->getJson('/api/rental/chauffeur/courses')
            ->assertOk()
            ->json('data'))
            ->pluck('reference')
            ->all();

        $this->assertSame([$celleDeLAutre->reference], $references);
        $this->assertNotContains($sienne->reference, $references);
    }

    /**
     * Le code de prise en charge n'est jamais rendu au chauffeur.
     *
     * C'est la serrure de la course : il le saisit, le serveur compare. Le lui
     * rendre lui permettrait de declarer une course qu'il n'a pas faite.
     */
    public function test_le_code_de_prise_en_charge_ne_sort_jamais(): void
    {
        $this->course(Booking::STATUS_IN_PROGRESS);

        Sanctum::actingAs($this->compteChauffeur, ['*']);

        $course = $this->getJson('/api/rental/chauffeur/courses')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('pickup_code', $course);
        $this->assertStringNotContainsString('4821', json_encode($course));
    }

    /** Le telephone du client ne sort qu'une fois la course engagee. */
    public function test_le_client_n_est_rendu_que_sur_une_course_engagee(): void
    {
        $this->course(Booking::STATUS_PENDING_PAYMENT);

        Sanctum::actingAs($this->compteChauffeur, ['*']);

        // En attente de paiement, la course ne figure meme pas dans sa liste.
        $this->getJson('/api/rental/chauffeur/courses')->assertOk()->assertJsonCount(0, 'data');

        $this->course(Booking::STATUS_IN_PROGRESS);

        $course = $this->getJson('/api/rental/chauffeur/courses')->assertOk()->json('data.0');

        $this->assertSame('0810000001', $course['client']['phone']);
    }

    /**
     * La duree facturee sort telle que le serveur l'a arrondie.
     *
     * L'ecran ecrivait « 3 h » sur une course reservee a la journee, en
     * divisant les heures par 24 de son cote : le chauffeur lisait une duree
     * qui n'etait pas celle facturee au client.
     */
    public function test_la_duree_facturee_est_rendue_au_chauffeur(): void
    {
        $course = $this->course(Booking::STATUS_IN_PROGRESS);
        $course->forceFill(['pricing_mode' => 'daily', 'billed_units' => 2])->save();

        Sanctum::actingAs($this->compteChauffeur, ['*']);

        $rendue = $this->getJson('/api/rental/chauffeur/courses')->assertOk()->json('data.0');

        $this->assertSame('daily', $rendue['pricing_mode']);
        $this->assertEquals(2, $rendue['billed_units']);
    }

    public function test_le_tableau_de_bord_donne_la_course_en_cours_et_la_suivante(): void
    {
        $enCours = $this->course(Booking::STATUS_IN_PROGRESS);
        $prochaine = $this->course(Booking::STATUS_CONFIRMED, depart: '+5 hours');
        $this->course(Booking::STATUS_COMPLETED, depart: '-2 days');

        Sanctum::actingAs($this->compteChauffeur, ['*']);

        $resume = $this->getJson('/api/rental/chauffeur/resume')->assertOk()->json('data');

        $this->assertSame($enCours->reference, $resume['en_cours']['reference']);
        $this->assertSame($prochaine->reference, $resume['prochaine']['reference']);
        $this->assertSame('Jean Kabila', $resume['chauffeur']['full_name']);
        $this->assertSame(1, $resume['courses_terminees']);
    }

    /** Un compte sans fiche chauffeur n'entre pas, meme authentifie. */
    public function test_un_compte_sans_fiche_chauffeur_est_refuse(): void
    {
        Sanctum::actingAs($this->client, ['*']);

        $this->getJson('/api/rental/chauffeur/courses')->assertUnauthorized();
        $this->getJson('/api/rental/chauffeur/historique')->assertUnauthorized();
        $this->getJson('/api/rental/chauffeur/resume')->assertUnauthorized();
    }
}
