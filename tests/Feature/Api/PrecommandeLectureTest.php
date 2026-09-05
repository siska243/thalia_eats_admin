<?php

namespace Tests\Feature\Api;

use App\Enums\TokenAbility;
use App\Models\Commande;
use App\Models\Precommande;
use App\Models\Town;
use App\Models\User;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_le_carnet_d_adresses_est_la_source_prioritaire(): void
    {
        $moi = User::factory()->create();
        Sanctum::actingAs($moi, TokenAbility::agent());

        $town = Town::factory()->create();

        DB::table('user_adresses')->insert([
            'user_id' => $moi->id,
            'town_id' => $town->id,
            'adresse' => 'Avenue du Carnet',
            'street' => 'Rue C',
            'number_street' => '7',
            'reference' => 'Portail bleu',
            'label' => 'maison',
            'slug' => 'carnet-'.\Illuminate\Support\Str::random(8),
            'is_main' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Une commande passée existe aussi : le carnet doit primer.
        Commande::query()->create([
            'refernce' => '9010', 'user_id' => $moi->id, 'status_id' => 3,
            'town_id' => $town->id, 'adresse_delivery' => 'Vieille adresse',
        ]);

        $response = $this->getJson('/api/user/adresses-recentes');

        $response->assertStatus(200);
        $this->assertSame('Avenue du Carnet', $response->json('0.adresse'));
        $this->assertSame('maison', $response->json('0.label'));
        $this->assertSame('carnet', $response->json('0.source'));
    }

    public function test_les_commandes_passees_servent_de_repli_quand_le_carnet_est_vide(): void
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
        $this->assertSame('commandes', $response->json('0.source'));
        $this->assertNull($response->json('0.label'));
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
