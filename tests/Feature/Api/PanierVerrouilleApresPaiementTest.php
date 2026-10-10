<?php

namespace Tests\Feature\Api;

use App\Models\Commande;
use App\Models\CommandeProduct;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Status;
use App\Models\Town;
use App\Models\User;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Une commande envoyee au paiement ne se modifie plus.
 *
 * `store` avait ete ferme, pas les trois routes de panier : un client dont la
 * commande attendait son reglement pouvait encore y ajouter un plat, en
 * retirer un, ou changer l'adresse de livraison pendant que la passerelle
 * tenait deja un montant. Il payait un panier et en recevait un autre, ou
 * payait une livraison a Gombe pour se faire livrer a Ngaliema.
 *
 * `paiement($uid)` est du meme chemin d'argent : il cherchait la commande par
 * son seul identifiant et son statut nu, sans propriete ni definition
 * partagee. L'uid n'est pas une capacite — Cipher chiffre un entier avec une
 * cle du depot, sans MAC — donc n'importe quel compte pouvait declencher le
 * paiement de la commande d'autrui, et une commande annulee ou perimee
 * passait encore a la caisse.
 */
class PanierVerrouilleApresPaiementTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([[1, 'En attente', 'en-attente'], [5, 'En attente paiement', 'en-attente-paiement']] as [$id, $titre, $slug]) {
            Status::query()->firstOrCreate(['id' => $id], ['title' => $titre, 'slug' => $slug]);
        }

        $this->client = User::factory()->create();
        Sanctum::actingAs($this->client, ['*']);
    }

    private function commande(int $statut, ?User $proprietaire = null): Commande
    {
        return Commande::query()->create([
            'user_id' => ($proprietaire ?? $this->client)->id,
            'status_id' => $statut,
            'town_id' => Town::factory()->create()->id,
            'refernce' => 'CMD-'.uniqid(),
            'global_price' => 10,
            'adresse_delivery' => 'Avenue du Commerce 12',
        ]);
    }

    private function ligne(Commande $commande): CommandeProduct
    {
        $produit = Product::factory()->create();

        return CommandeProduct::query()->create([
            'commande_id' => $commande->id,
            'product_id' => $produit->id,
            'user_id' => $commande->user_id,
            'currency_id' => $produit->currency_id ?? Currency::factory()->create()->id,
            'price' => $produit->price,
            'quantity' => 1,
        ]);
    }

    public function test_un_produit_ne_s_ajoute_plus_a_une_commande_envoyee_au_paiement(): void
    {
        $commande = $this->commande(Commande::STATUT_ATTENTE_PAIEMENT);
        $produit = Product::factory()->create();

        $this->postJson('/api/user/commande/add-product', [
            'products' => [[
                'product_id' => Cipher::Encrypt((string) $produit->id),
                'quantity' => 3,
                'pricing' => $produit->currency_id,
            ]],
        ])->assertStatus(400);

        // Ni ligne ajoutee, ni seconde commande ouverte en contournement.
        $this->assertSame(0, CommandeProduct::query()->where('commande_id', $commande->id)->count());
        $this->assertSame(1, Commande::query()->where('user_id', $this->client->id)->count());
    }

    public function test_un_produit_ne_se_retire_plus_d_une_commande_envoyee_au_paiement(): void
    {
        $commande = $this->commande(Commande::STATUT_ATTENTE_PAIEMENT);
        $ligne = $this->ligne($commande);

        $this->postJson('/api/user/commande/delete-product', [
            'product_id' => Cipher::Encrypt((string) $ligne->product_id),
        ])->assertStatus(400);

        $this->assertDatabaseHas('commande_products', ['id' => $ligne->id]);
    }

    public function test_l_adresse_ne_change_plus_sur_une_commande_envoyee_au_paiement(): void
    {
        $commande = $this->commande(Commande::STATUT_ATTENTE_PAIEMENT);
        $autre = Town::factory()->create();

        $this->postJson('/api/user/commande/update-address-delivery', [
            'town' => $autre->slug,
            'adresse' => 'Ailleurs, tres loin',
        ])->assertStatus(400);

        $this->assertSame('Avenue du Commerce 12', $commande->fresh()->adresse_delivery);
    }

    public function test_le_panier_en_attente_reste_modifiable(): void
    {
        $commande = $this->commande(Commande::STATUT_EN_ATTENTE);
        $produit = Product::factory()->create();

        $this->postJson('/api/user/commande/add-product', [
            'products' => [[
                'product_id' => Cipher::Encrypt((string) $produit->id),
                'quantity' => 2,
                'pricing' => $produit->currency_id,
            ]],
        ])->assertStatus(200);

        $this->assertSame(1, CommandeProduct::query()->where('commande_id', $commande->id)->count());
    }

    public function test_le_paiement_d_une_commande_d_autrui_est_refuse(): void
    {
        $commande = $this->commande(Commande::STATUT_ATTENTE_PAIEMENT, User::factory()->create());

        $this->postJson('/api/user/commande/paiement-order/'.Cipher::Encrypt((string) $commande->id), [
            'method' => 'mobile',
            'phone' => '+243810000000',
        ])->assertStatus(400);
    }

    public function test_le_paiement_d_une_commande_annulee_est_refuse(): void
    {
        $commande = $this->commande(Commande::STATUT_ATTENTE_PAIEMENT);

        // Ligne heritee : cancel_at pose, statut jamais bascule. Ecrite hors
        // d'Eloquent, le crochet du modele l'aurait sinon corrigee.
        DB::table('commandes')->where('id', $commande->id)->update(['cancel_at' => now()]);

        $this->postJson('/api/user/commande/paiement-order/'.Cipher::Encrypt((string) $commande->id), [
            'method' => 'mobile',
            'phone' => '+243810000000',
        ])->assertStatus(400);
    }

    public function test_le_paiement_d_une_commande_perimee_est_refuse(): void
    {
        $commande = $this->commande(Commande::STATUT_ATTENTE_PAIEMENT);

        DB::table('commandes')->where('id', $commande->id)->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/user/commande/paiement-order/'.Cipher::Encrypt((string) $commande->id), [
            'method' => 'mobile',
            'phone' => '+243810000000',
        ])->assertStatus(400);
    }
}
