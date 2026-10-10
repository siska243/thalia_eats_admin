<?php

namespace Tests\Feature\Rental;

use App\Models\Booking;
use App\Models\Chauffeur;
use App\Models\Currency;
use App\Models\User;
use App\Models\Vehicle;
use App\Settings\RentalSettings;
use App\Wrappers\Cipher;
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

    private function uid(Booking $course): string
    {
        return Cipher::Encrypt($course->id);
    }

    /** Le code juste fait partir la course, et remet le compteur a zero. */
    public function test_le_bon_code_demarre_la_course(): void
    {
        $course = $this->course(Booking::STATUS_CONFIRMED);
        $course->forceFill(['pickup_code_attempts' => 2])->save();

        Sanctum::actingAs($this->compteChauffeur, ['*']);

        $this->postJson('/api/rental/chauffeur/courses/'.$this->uid($course).'/depart', [
            'code' => '4821',
        ])->assertSuccessful();

        $course->refresh();

        $this->assertSame(Booking::STATUS_IN_PROGRESS, $course->status);
        $this->assertNotNull($course->picked_up_at);
        $this->assertSame(0, (int) $course->pickup_code_attempts);
    }

    /**
     * Un code faux compte une tentative et ne demarre rien.
     *
     * Sans ce compteur, un code a quatre chiffres se devine en quelques
     * milliers d'essais depuis le telephone du chauffeur.
     */
    public function test_un_code_faux_compte_une_tentative(): void
    {
        $course = $this->course(Booking::STATUS_CONFIRMED);

        Sanctum::actingAs($this->compteChauffeur, ['*']);

        $this->postJson('/api/rental/chauffeur/courses/'.$this->uid($course).'/depart', [
            'code' => '0000',
        ])->assertStatus(400);

        $course->refresh();

        $this->assertSame(Booking::STATUS_CONFIRMED, $course->status);
        $this->assertSame(1, (int) $course->pickup_code_attempts);
        $this->assertNull($course->pickup_code_locked_at);
    }

    /** Au-dela du maximum regle, la saisie se verrouille. */
    public function test_la_saisie_se_verrouille_au_dela_du_maximum(): void
    {
        $course = $this->course(Booking::STATUS_CONFIRMED);

        Sanctum::actingAs($this->compteChauffeur, ['*']);

        // Le reglage est a trois essais.
        foreach (range(1, 3) as $ignore) {
            $this->postJson('/api/rental/chauffeur/courses/'.$this->uid($course).'/depart', [
                'code' => '0000',
            ])->assertStatus(400);
        }

        $course->refresh();
        $this->assertNotNull($course->pickup_code_locked_at);

        // Verrouillee, meme le bon code ne passe plus : seul un
        // administrateur rouvre la saisie.
        $this->postJson('/api/rental/chauffeur/courses/'.$this->uid($course).'/depart', [
            'code' => '4821',
        ])->assertStatus(400);

        $this->assertSame(Booking::STATUS_CONFIRMED, $course->refresh()->status);
    }

    /** Le code d'un autre chauffeur ne demarre pas sa course. */
    public function test_un_chauffeur_ne_demarre_pas_la_course_d_un_autre(): void
    {
        $autreCompte = User::factory()->create();
        Chauffeur::query()->create([
            'user_id' => $autreCompte->id,
            'full_name' => 'Paul Mbemba',
            'phone' => '+243810000003',
            'licence_expires_at' => now()->addYear(),
            'is_active' => true,
        ]);

        $course = $this->course(Booking::STATUS_CONFIRMED);

        Sanctum::actingAs($autreCompte, ['*']);

        $this->postJson('/api/rental/chauffeur/courses/'.$this->uid($course).'/depart', [
            'code' => '4821',
        ])->assertNotFound();

        $this->assertSame(Booking::STATUS_CONFIRMED, $course->refresh()->status);
    }

    /** L'encaissement termine la course et laisse une ligne de paiement. */
    public function test_l_encaissement_du_solde_termine_la_course(): void
    {
        $course = $this->course(Booking::STATUS_IN_PROGRESS);

        Sanctum::actingAs($this->compteChauffeur, ['*']);

        $this->postJson('/api/rental/chauffeur/courses/'.$this->uid($course).'/encaisser')
            ->assertSuccessful();

        $course->refresh();

        $this->assertSame(Booking::STATUS_COMPLETED, $course->status);
        $this->assertNotNull($course->completed_at);
        $this->assertNotNull($course->balance_collected_at);
        $this->assertSame(1, $course->payments()->where('kind', 'balance')->count());
    }

    /** On n'encaisse pas une course qui n'a jamais demarre. */
    public function test_on_n_encaisse_pas_une_course_non_demarree(): void
    {
        $course = $this->course(Booking::STATUS_CONFIRMED);

        Sanctum::actingAs($this->compteChauffeur, ['*']);

        $this->postJson('/api/rental/chauffeur/courses/'.$this->uid($course).'/encaisser')
            ->assertStatus(400);

        $this->assertSame(Booking::STATUS_CONFIRMED, $course->refresh()->status);
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
