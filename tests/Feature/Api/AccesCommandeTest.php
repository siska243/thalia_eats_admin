<?php

namespace Tests\Feature\Api;

use App\Models\Commande;
use App\Models\DelivreryDriver;
use App\Models\Status;
use App\Models\Town;
use App\Models\TrackOrder;
use App\Models\User;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * showOrder et get_track_order retrouvaient la commande par son seul uid.
 *
 * Or l'uid n'est pas une capacite : Cipher chiffre un entier en AES-CBC avec
 * une cle et un IV ecrits dans le depot, sans MAC. Reconstituer les uid des
 * commandes 1 a N est immediat. Tout compte authentifie pouvait donc lire
 * l'adresse de livraison, le nom et le telephone du destinataire, les
 * coordonnees GPS et les deux codes de confirmation de n'importe qui.
 *
 * track_order, lui, laissait ecrire une fausse position de livreur.
 */
class AccesCommandeTest extends TestCase
{
    use DatabaseTruncation;


    private function commande(User $client, ?DelivreryDriver $driver = null): Commande
    {
        $status = Status::query()->firstOrCreate(
            ['id' => 2],
            ['title' => 'En cours', 'slug' => 'en-cours']
        );

        return Commande::query()->create([
            'user_id' => $client->id,
            'status_id' => $status->id,
            'town_id' => Town::factory()->create()->id,
            'delivrery_driver_id' => $driver?->id,
            'refernce' => 'CMD-'.uniqid(),
            'global_price' => 10,
            'adresse_delivery' => 'Avenue du Commerce 12',
            'code_confirmation' => 1234,
            'code_confirmation_restaurant' => '4321',
        ]);
    }

    private function livreur(): array
    {
        $user = User::factory()->create();

        $driver = DelivreryDriver::query()->create([
            'user_id' => $user->id,
            'id_card' => 'CARTE-'.$user->id,
            'is_active' => true,
        ]);

        return [$user, $driver];
    }

    public function test_un_tiers_ne_peut_pas_lire_la_commande_d_autrui(): void
    {
        $commande = $this->commande(User::factory()->create());

        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->getJson('/api/user/commande/show-order/'.Cipher::Encrypt($commande->id))
            ->assertStatus(404);
    }

    public function test_le_client_lit_sa_propre_commande(): void
    {
        $client = User::factory()->create();
        $commande = $this->commande($client);

        Sanctum::actingAs($client, ['*']);

        $this->getJson('/api/user/commande/show-order/'.Cipher::Encrypt($commande->id))
            ->assertStatus(200);
    }

    public function test_le_livreur_affecte_lit_la_commande(): void
    {
        [$driverUser, $driver] = $this->livreur();
        $commande = $this->commande(User::factory()->create(), $driver);

        Sanctum::actingAs($driverUser, ['*']);

        $this->getJson('/api/user/commande/show-order/'.Cipher::Encrypt($commande->id))
            ->assertStatus(200);
    }

    public function test_un_uid_inconnu_ne_revele_plus_l_identifiant_decode(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        // L'ancienne version repondait 200 en renvoyant l'entier decode,
        // confirmant a l'attaquant que le deguisement etait reversible.
        $this->getJson('/api/user/commande/show-order/'.Cipher::Encrypt(999999))
            ->assertStatus(404)
            ->assertJsonMissing(['data' => 999999]);
    }

    public function test_le_suivi_n_est_pas_lisible_par_un_tiers(): void
    {
        $commande = $this->commande(User::factory()->create());

        TrackOrder::query()->create([
            'commande_id' => $commande->id,
            'location_customer' => ['lat' => -4.32, 'lng' => 15.31],
            'location_delivery' => ['lat' => -4.33, 'lng' => 15.32],
        ]);

        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->getJson('/api/user/commande/get-track/'.Cipher::Encrypt($commande->id))
            ->assertStatus(404);
    }

    public function test_le_client_lit_le_suivi_de_sa_commande(): void
    {
        $client = User::factory()->create();
        $commande = $this->commande($client);

        TrackOrder::query()->create([
            'commande_id' => $commande->id,
            'location_customer' => ['lat' => -4.32, 'lng' => 15.31],
            'location_delivery' => ['lat' => -4.33, 'lng' => 15.32],
        ]);

        Sanctum::actingAs($client, ['*']);

        $this->getJson('/api/user/commande/get-track/'.Cipher::Encrypt($commande->id))
            ->assertStatus(200);
    }

    public function test_un_tiers_ne_peut_pas_pousser_une_fausse_position(): void
    {
        [, $driver] = $this->livreur();
        $commande = $this->commande(User::factory()->create(), $driver);

        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson('/api/user/commande/update-track', [
            'uid' => Cipher::Encrypt($commande->id),
            'location' => ['lat' => 0, 'lng' => 0],
        ])->assertStatus(201);

        $this->assertSame(0, TrackOrder::query()->where('commande_id', $commande->id)->count());
    }

    public function test_le_livreur_affecte_pousse_sa_position(): void
    {
        [$driverUser, $driver] = $this->livreur();
        $commande = $this->commande(User::factory()->create(), $driver);

        TrackOrder::query()->create([
            'commande_id' => $commande->id,
            'location_customer' => ['lat' => -4.32, 'lng' => 15.31],
            'location_delivery' => ['lat' => -4.33, 'lng' => 15.32],
        ]);

        Sanctum::actingAs($driverUser, ['*']);

        $this->postJson('/api/user/commande/update-track', [
            'uid' => Cipher::Encrypt($commande->id),
            'location' => ['lat' => -4.34, 'lng' => 15.33],
        ])->assertStatus(201);

        $this->assertSame(2, TrackOrder::query()->where('commande_id', $commande->id)->count());
    }

    public function test_le_livreur_ne_recoit_aucun_code_de_confirmation(): void
    {
        [$driverUser, $driver] = $this->livreur();
        $commande = $this->commande(User::factory()->create(), $driver);

        Sanctum::actingAs($driverUser, ['*']);

        // Le livreur saisit ces codes, il ne les detient pas : les recevoir
        // lui permettait de confirmer retrait et livraison sans rencontrer
        // personne.
        $this->getJson('/api/user/commande/show-order/'.Cipher::Encrypt($commande->id))
            ->assertStatus(200)
            ->assertJsonPath('code_confirmation', null)
            ->assertJsonPath('restaurant_code_confirmation', null);
    }

    public function test_le_client_recoit_son_code_et_pas_celui_du_restaurant(): void
    {
        $client = User::factory()->create();
        $commande = $this->commande($client);

        Sanctum::actingAs($client, ['*']);

        $this->getJson('/api/user/commande/show-order/'.Cipher::Encrypt($commande->id))
            ->assertStatus(200)
            ->assertJsonPath('code_confirmation', 1234)
            ->assertJsonPath('restaurant_code_confirmation', null);
    }

    public function test_l_adresse_du_flux_sse_ne_vient_plus_de_la_requete(): void
    {
        $client = User::factory()->create();
        Sanctum::actingAs($client, ['*']);

        // La valeur n'est plus lue nulle part : la configuration fait foi.
        $this->assertNull(config('sse.webhook_url'));

        $source = file_get_contents(app_path('Http/Controllers/Api/CommandeController.php'));

        $this->assertStringNotContainsString("input('webhook_sse_url')", $source);
        $this->assertStringContainsString("config('sse.webhook_url')", $source);
    }
}
