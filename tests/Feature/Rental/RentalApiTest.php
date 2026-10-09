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

/** L'API de location, telle que les applications la consomment. */
class RentalApiTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;

    private Vehicle $vehicle;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        RentalSettings::fake([
            'deposit_percentage' => 10.0,
            'refund_percentage' => 100.0,
            'minimum_hours' => 1.0,
            'pickup_code_max_attempts' => 3,
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
            'plate_number' => 'KIN-0001', 'hourly_rate' => 10,
            'currency_id' => $this->currency->id,
        ]);

        $this->client = User::factory()->create();
    }

    private function connecte(): void
    {
        // L'ability « * » est explicite : `actingAs` ne la donne plus par
        // defaut, et le garde RefuserAgentSansAbility ferme alors la route.
        Sanctum::actingAs($this->client, ['*']);
    }

    private function creneau(string $from = '+2 days 10:00', string $to = '+2 days 15:00'): array
    {
        return [
            'starts_at' => Carbon::parse($from)->toDateTimeString(),
            'ends_at' => Carbon::parse($to)->toDateTimeString(),
        ];
    }

    public function test_le_catalogue_annonce_le_prix_du_creneau(): void
    {
        $this->connecte();

        $reponse = $this->getJson('/api/rental/vehicles?'.http_build_query($this->creneau()));

        $reponse->assertSuccessful();

        // Le prix accompagne le vehicule : sans lui, le client compare des
        // tarifs horaires et decouvre le total une fois son choix fait.
        $reponse->assertJsonPath('data.0.quote.total', 50);
        $reponse->assertJsonPath('data.0.quote.deposit', 5);
        $reponse->assertJsonPath('data.0.quote.balance', 45);
    }

    /**
     * Le catalogue se consulte sans creneau, comme la liste des restaurants.
     *
     * On regarde les voitures d'abord, on choisit ses dates ensuite, sur la
     * fiche. L'exiger ici obligeait le client a composer un creneau avant
     * meme de savoir ce qui existe.
     */
    public function test_le_catalogue_se_consulte_sans_creneau(): void
    {
        $reponse = $this->getJson('/api/rental/vehicles');

        $reponse->assertSuccessful();
        $reponse->assertJsonPath('data.0.slug', $this->vehicle->slug);
        // Pas de creneau, donc pas de total a annoncer : seul le tarif
        // horaire a un sens.
        $reponse->assertJsonPath('data.0.quote', null);
        $reponse->assertJsonPath('data.0.available', true);
    }

    /** Un debut sans fin ne decrit pas un creneau : le prix serait incalculable. */
    public function test_un_creneau_incomplet_est_refuse(): void
    {
        $this->getJson('/api/rental/vehicles?starts_at='.urlencode($this->creneau()['starts_at']))
            ->assertStatus(422);
    }

    /**
     * Un vehicule en course reste au catalogue, marque indisponible.
     *
     * Le retirer laisserait croire qu'il n'existe pas, alors qu'il sera
     * peut-etre libre demain. Le client le voit grise et peut ouvrir sa fiche.
     */
    public function test_un_vehicule_en_course_reste_visible_mais_indisponible(): void
    {
        Booking::query()->create([
            'user_id' => User::factory()->create()->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'pickup_location' => 'Gombe',
            'hourly_rate' => 10, 'duration_hours' => 2,
            'total' => 20, 'deposit' => 2, 'balance' => 18,
            'currency_id' => $this->currency->id,
            'status' => Booking::STATUS_IN_PROGRESS,
        ]);

        $this->getJson('/api/rental/vehicles')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.available', false);
    }

    /** Le catalogue chiffre au mode demande. */
    public function test_le_catalogue_chiffre_a_la_journee(): void
    {
        $this->vehicle->update(['daily_rate' => 149]);

        $this->getJson('/api/rental/vehicles?'.http_build_query(array_merge(
            $this->creneau('+2 days 08:00', '+3 days 08:00'),
            ['pricing_mode' => 'daily'],
        )))
            ->assertSuccessful()
            ->assertJsonPath('data.0.quote.mode', 'daily')
            ->assertJsonPath('data.0.quote.billed_units', 1)
            ->assertJsonPath('data.0.quote.total', 149)
            ->assertJsonPath('data.0.daily_rate', 149);
    }

    /**
     * Un vehicule sans tarif journalier reste au catalogue, chiffre a l'heure.
     *
     * Le cacher laisserait croire qu'il n'existe pas alors qu'il se loue
     * parfaitement, simplement pas dans ce mode.
     */
    public function test_le_catalogue_retombe_a_l_heure_sans_tarif_journalier(): void
    {
        $this->getJson('/api/rental/vehicles?'.http_build_query(array_merge(
            $this->creneau(),
            ['pricing_mode' => 'daily'],
        )))
            ->assertSuccessful()
            ->assertJsonPath('data.0.quote.mode', 'hourly')
            ->assertJsonPath('data.0.daily_rate', null);
    }

    /** Reserver a la journee fige le mode sur la reservation. */
    public function test_le_client_reserve_a_la_journee(): void
    {
        $this->vehicle->update(['daily_rate' => 149]);
        $this->connecte();

        $this->postJson('/api/rental/bookings', array_merge(
            $this->creneau('+2 days 08:00', '+3 days 08:00'),
            [
                'vehicle' => Cipher::Encrypt($this->vehicle->id),
                'pickup_location' => 'Gombe',
                'pricing_mode' => 'daily',
            ],
        ))
            ->assertSuccessful()
            ->assertJsonPath('data.pricing_mode', 'daily')
            ->assertJsonPath('data.unit_price', 149)
            ->assertJsonPath('data.billed_units', 1)
            ->assertJsonPath('data.total', 149);
    }

    /**
     * Reserver a la journee un vehicule qui ne l'est pas est REFUSE.
     *
     * Le catalogue retombe a l'heure pour ne pas cacher le vehicule ; ici le
     * client paie, et un prix qui n'est pas celui qu'il a choisi n'est pas un
     * prix.
     */
    public function test_reserver_a_la_journee_un_vehicule_horaire_est_refuse(): void
    {
        $this->connecte();

        $this->postJson('/api/rental/bookings', array_merge($this->creneau(), [
            'vehicle' => Cipher::Encrypt($this->vehicle->id),
            'pickup_location' => 'Gombe',
            'pricing_mode' => 'daily',
        ]))
            ->assertStatus(400)
            ->assertJsonPath('error', 'mode_indisponible');
    }

    /**
     * Le catalogue se pagine, pour le defilement infini.
     *
     * Sans `meta`, l'application redemanderait indefiniment une page qui
     * n'existe pas : rien ne lui dirait qu'elle est arrivee au bout.
     */
    public function test_le_catalogue_se_pagine(): void
    {
        // Seize vehicules pour deborder la page de quinze.
        for ($i = 0; $i < 15; $i++) {
            Vehicle::query()->create([
                'brand' => 'Suzuki', 'model' => "Alto {$i}",
                'plate_number' => "KIN-10{$i}", 'hourly_rate' => 5 + $i,
                'currency_id' => $this->currency->id,
            ]);
        }

        $this->getJson('/api/rental/vehicles')
            ->assertSuccessful()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 16);

        $this->getJson('/api/rental/vehicles?page=2')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }

    /** La recherche porte sur tout le parc, pas sur la page courante. */
    public function test_la_recherche_traverse_les_pages(): void
    {
        for ($i = 0; $i < 20; $i++) {
            Vehicle::query()->create([
                'brand' => 'Suzuki', 'model' => "Alto {$i}",
                'plate_number' => "KIN-20{$i}", 'hourly_rate' => 5,
                'currency_id' => $this->currency->id,
            ]);
        }

        // « Land Cruiser » est le vehicule de setUp, trie en derniere position
        // par tarif : une recherche filtree apres pagination ne le trouverait
        // jamais.
        $this->getJson('/api/rental/vehicles?search=cruiser')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.model', 'Land Cruiser');
    }

    /** La fiche s'ouvre sans creneau, comme celle d'un restaurant. */
    public function test_la_fiche_s_ouvre_sans_creneau(): void
    {
        $this->getJson('/api/rental/vehicles/'.$this->vehicle->slug)
            ->assertSuccessful()
            ->assertJsonPath('data.slug', $this->vehicle->slug)
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.quote', null);
    }

    /** Avec un creneau, elle porte le prix et la disponibilite sur ces heures. */
    public function test_la_fiche_avec_un_creneau_porte_le_prix(): void
    {
        $this->getJson('/api/rental/vehicles/'.$this->vehicle->slug.'?'.http_build_query($this->creneau()))
            ->assertSuccessful()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.quote.total', 50)
            ->assertJsonPath('data.quote.deposit', 5);
    }

    /**
     * Un vehicule pris sur le creneau se dit indisponible, sans disparaitre.
     *
     * Le drapeau du catalogue est calcule sur l'instant : il ne peut pas dire
     * si le vehicule est libre sur des heures futures. C'est la fiche qui
     * tranche, une fois le creneau choisi.
     */
    public function test_la_fiche_dit_indisponible_sur_un_creneau_pris(): void
    {
        Booking::query()->create(array_merge($this->creneau(), [
            'user_id' => User::factory()->create()->id,
            'vehicle_id' => $this->vehicle->id,
            'pickup_location' => 'Gombe',
            'hourly_rate' => 10, 'duration_hours' => 5,
            'total' => 50, 'deposit' => 5, 'balance' => 45,
            'currency_id' => $this->currency->id,
            'status' => Booking::STATUS_CONFIRMED,
        ]));

        $this->getJson('/api/rental/vehicles/'.$this->vehicle->slug.'?'.http_build_query($this->creneau()))
            ->assertSuccessful()
            ->assertJsonPath('data.available', false)
            // Le prix reste annonce : le client doit savoir ce que couterait
            // ce creneau avant d'en essayer un autre.
            ->assertJsonPath('data.quote.total', 50);
    }

    /**
     * Le slug n'est pas accepte a la place de l'identifiant chiffre.
     *
     * Le parcours mobile navigue au slug, et c'est en l'envoyant ici qu'un
     * vehicule parfaitement existant s'est retrouve « introuvable ». Le test
     * fixe le contrat plutot que de le laisser se redecouvrir a l'usage.
     */
    public function test_reserver_exige_l_identifiant_chiffre_du_vehicule(): void
    {
        $this->connecte();

        $this->postJson('/api/rental/bookings', array_merge($this->creneau(), [
            'vehicle' => $this->vehicle->slug,
            'pickup_location' => 'Gombe',
        ]))->assertStatus(404);

        $this->postJson('/api/rental/bookings', array_merge($this->creneau(), [
            'vehicle' => Cipher::Encrypt($this->vehicle->id),
            'pickup_location' => 'Gombe',
        ]))->assertSuccessful();
    }

    public function test_une_fiche_inconnue_rend_404(): void
    {
        $this->getJson('/api/rental/vehicles/pas-un-vehicule')->assertStatus(404);
    }

    /** La recherche porte sur la marque et le modele, comme /list-restaurant sur le nom. */
    public function test_le_catalogue_se_cherche_par_marque(): void
    {
        Vehicle::query()->create([
            'brand' => 'Suzuki', 'model' => 'Alto',
            'plate_number' => 'KIN-0099', 'hourly_rate' => 5,
            'currency_id' => $this->currency->id,
        ]);

        $this->getJson('/api/rental/vehicles?search=suzuki')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.brand', 'Suzuki');

        $this->getJson('/api/rental/vehicles?search=land')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.brand', 'Toyota');
    }

    public function test_le_catalogue_cache_un_vehicule_deja_pris(): void
    {
        $this->connecte();

        Booking::query()->create(array_merge($this->creneau(), [
            'user_id' => User::factory()->create()->id,
            'vehicle_id' => $this->vehicle->id,
            'pickup_location' => 'Gombe',
            'hourly_rate' => 10, 'duration_hours' => 5,
            'total' => 50, 'deposit' => 5, 'balance' => 45,
            'currency_id' => $this->currency->id,
            'status' => Booking::STATUS_CONFIRMED,
        ]));

        $this->getJson('/api/rental/vehicles?'.http_build_query($this->creneau()))
            ->assertSuccessful()
            ->assertJsonCount(0, 'data');
    }

    public function test_le_client_reserve_et_le_serveur_calcule_le_prix(): void
    {
        $this->connecte();

        $reponse = $this->postJson('/api/rental/bookings', array_merge($this->creneau(), [
            'vehicle' => Cipher::Encrypt($this->vehicle->id),
            'pickup_location' => 'Av. Batetela, Gombe',
        ]));

        $reponse->assertSuccessful();
        $reponse->assertJsonPath('data.total', 50);
        $reponse->assertJsonPath('data.deposit', 5);
        $reponse->assertJsonPath('data.status', Booking::STATUS_PENDING_PAYMENT);
    }

    public function test_un_montant_envoye_par_le_client_est_ignore(): void
    {
        $this->connecte();

        // Le prix ne vient jamais du client : c'est la faille deja corrigee
        // sur les commandes de repas.
        $this->postJson('/api/rental/bookings', array_merge($this->creneau(), [
            'vehicle' => Cipher::Encrypt($this->vehicle->id),
            'pickup_location' => 'Gombe',
            'total' => 1, 'deposit' => 0.1, 'hourly_rate' => 0.01,
        ]))->assertSuccessful()->assertJsonPath('data.total', 50);
    }

    public function test_reserver_un_vehicule_pris_est_refuse(): void
    {
        $this->connecte();

        Booking::query()->create(array_merge($this->creneau(), [
            'user_id' => User::factory()->create()->id,
            'vehicle_id' => $this->vehicle->id,
            'pickup_location' => 'Gombe',
            'hourly_rate' => 10, 'duration_hours' => 5,
            'total' => 50, 'deposit' => 5, 'balance' => 45,
            'currency_id' => $this->currency->id,
            'status' => Booking::STATUS_CONFIRMED,
        ]));

        $this->postJson('/api/rental/bookings', array_merge($this->creneau(), [
            'vehicle' => Cipher::Encrypt($this->vehicle->id),
            'pickup_location' => 'Gombe',
        ]))->assertStatus(400)->assertJsonPath('error', 'vehicule_indisponible');
    }

    public function test_la_reservation_herite_du_chauffeur_attitre(): void
    {
        $chauffeur = Chauffeur::query()->create([
            'user_id' => User::factory()->create()->id,
            'full_name' => 'Jean Kabila', 'phone' => '+243810000000',
        ]);
        $this->vehicle->update(['default_chauffeur_id' => $chauffeur->id]);

        $this->connecte();

        $this->postJson('/api/rental/bookings', array_merge($this->creneau(), [
            'vehicle' => Cipher::Encrypt($this->vehicle->id),
            'pickup_location' => 'Gombe',
        ]))->assertSuccessful()->assertJsonPath('data.chauffeur.full_name', 'Jean Kabila');
    }

    public function test_on_ne_lit_pas_la_reservation_d_un_autre(): void
    {
        $autre = Booking::query()->create(array_merge($this->creneau(), [
            'user_id' => User::factory()->create()->id,
            'vehicle_id' => $this->vehicle->id,
            'pickup_location' => 'Gombe',
            'hourly_rate' => 10, 'duration_hours' => 5,
            'total' => 50, 'deposit' => 5, 'balance' => 45,
            'currency_id' => $this->currency->id,
        ]));

        $this->connecte();

        // L'uid n'est pas une capacite : il se forge. L'autorisation vient de
        // la clause SQL, pas du fait de connaitre l'identifiant.
        $this->getJson('/api/rental/bookings/'.Cipher::Encrypt($autre->id))
            ->assertStatus(404);
    }

    public function test_le_code_de_prise_en_charge_n_est_montre_qu_a_son_proprietaire(): void
    {
        $booking = Booking::query()->create(array_merge($this->creneau(), [
            'user_id' => $this->client->id,
            'vehicle_id' => $this->vehicle->id,
            'pickup_location' => 'Gombe',
            'hourly_rate' => 10, 'duration_hours' => 5,
            'total' => 50, 'deposit' => 5, 'balance' => 45,
            'currency_id' => $this->currency->id,
            'status' => Booking::STATUS_CONFIRMED,
            'pickup_code' => '4821',
        ]));

        $this->connecte();

        $this->getJson('/api/rental/bookings/'.Cipher::Encrypt($booking->id))
            ->assertSuccessful()
            ->assertJsonPath('data.pickup_code', '4821');
    }

    public function test_le_client_annule_sa_reservation(): void
    {
        $booking = Booking::query()->create(array_merge($this->creneau(), [
            'user_id' => $this->client->id,
            'vehicle_id' => $this->vehicle->id,
            'pickup_location' => 'Gombe',
            'hourly_rate' => 10, 'duration_hours' => 5,
            'total' => 50, 'deposit' => 5, 'balance' => 45,
            'currency_id' => $this->currency->id,
            'status' => Booking::STATUS_CONFIRMED,
        ]));

        $this->connecte();

        $this->postJson('/api/rental/bookings/'.Cipher::Encrypt($booking->id).'/cancel', [
            'reason' => 'Changement de programme',
        ])->assertSuccessful();

        $this->assertSame(Booking::STATUS_CANCELLED, $booking->refresh()->status);
    }

    public function test_une_course_commencee_ne_s_annule_plus(): void
    {
        $booking = Booking::query()->create([
            'user_id' => $this->client->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'pickup_location' => 'Gombe',
            'hourly_rate' => 10, 'duration_hours' => 2,
            'total' => 20, 'deposit' => 2, 'balance' => 18,
            'currency_id' => $this->currency->id,
            'status' => Booking::STATUS_IN_PROGRESS,
        ]);

        $this->connecte();

        $this->postJson('/api/rental/bookings/'.Cipher::Encrypt($booking->id).'/cancel')
            ->assertStatus(400)
            ->assertJsonPath('error', 'annulation_impossible');
    }

    /**
     * Le catalogue se consulte sans compte.
     *
     * On regarde les voitures avant de decider d'en louer une : exiger un
     * jeton pour voir les prix perdrait le visiteur avant qu'il ait une
     * raison de s'inscrire.
     */
    public function test_le_catalogue_se_consulte_sans_compte(): void
    {
        $reponse = $this->getJson('/api/rental/vehicles?'.http_build_query($this->creneau()));

        $reponse->assertSuccessful();
        $reponse->assertJsonPath('data.0.quote.total', 50);
    }

    /** La plaque identifie une voiture sur la voie publique : pas au catalogue. */
    public function test_le_catalogue_public_ne_montre_pas_la_plaque(): void
    {
        $this->getJson('/api/rental/vehicles?'.http_build_query($this->creneau()))
            ->assertSuccessful()
            ->assertJsonMissing(['plate_number' => 'KIN-0001']);
    }

    /** Reserver, en revanche, demande un compte. */
    public function test_reserver_exige_une_authentification(): void
    {
        $this->postJson('/api/rental/bookings', array_merge($this->creneau(), [
            'vehicle' => Cipher::Encrypt($this->vehicle->id),
            'pickup_location' => 'Gombe',
        ]))->assertStatus(401);

        $this->getJson('/api/rental/bookings')->assertStatus(401);
    }
}
