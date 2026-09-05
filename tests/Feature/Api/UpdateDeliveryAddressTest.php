<?php

namespace Tests\Feature\Api;

use App\Models\Commande;
use App\Models\Status;
use App\Models\Town;
use App\Models\User;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * L'endpoint retrouvait la commande par « la premiere en attente de cet
 * utilisateur », sans identifiant : un client ayant deux commandes en attente
 * voyait la mauvaise etre modifiee. Il n'y avait par ailleurs aucune
 * verification d'existence, et les coordonnees etaient ignorees.
 */
class UpdateDeliveryAddressTest extends TestCase
{
    use DatabaseTruncation;

    private function pendingOrder(User $user, Town $town, string $reference): Commande
    {
        $status = Status::query()->firstOrCreate(
            ['id' => 5],
            ['title' => 'En attente paiement', 'slug' => 'en-attente-paiement']
        );

        return Commande::query()->create([
            'user_id' => $user->id,
            'status_id' => $status->id,
            'town_id' => $town->id,
            'refernce' => $reference,
            'global_price' => 10,
            'adresse_delivery' => 'Ancienne adresse',
        ]);
    }

    public function test_l_endpoint_exige_une_authentification(): void
    {
        $this->postJson('/api/user/commande/update-address-delivery', [])->assertStatus(401);
    }

    public function test_la_commande_visee_est_celle_dont_l_uid_est_fourni(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $town = Town::factory()->create();
        $premiere = $this->pendingOrder($user, $town, 'CMD-1');
        $seconde = $this->pendingOrder($user, $town, 'CMD-2');

        $this->postJson('/api/user/commande/update-address-delivery', [
            'uid' => Cipher::Encrypt($seconde->id),
            'town' => $town->slug,
            'adresse' => 'Nouvelle adresse',
            'lat' => -4.31,
            'long' => 15.31,
        ])->assertStatus(200);

        $this->assertSame('Ancienne adresse', $premiere->fresh()->adresse_delivery);
        $this->assertSame('Nouvelle adresse', $seconde->fresh()->adresse_delivery);
        $this->assertEqualsWithDelta(-4.31, $seconde->fresh()->lat, 0.001);
    }

    public function test_sans_commande_en_attente_la_reponse_est_404(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $town = Town::factory()->create();

        // L'ancienne version ecrivait sur null et repondait 500.
        $this->postJson('/api/user/commande/update-address-delivery', [
            'town' => $town->slug,
            'adresse' => 'Nouvelle adresse',
        ])->assertStatus(404);
    }

    public function test_une_commune_inconnue_est_refusee(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->pendingOrder($user, Town::factory()->create(), 'CMD-3');

        $this->postJson('/api/user/commande/update-address-delivery', [
            'town' => 'commune-inexistante',
            'adresse' => 'Nouvelle adresse',
        ])->assertStatus(400);
    }
}
