<?php

namespace Tests\Feature\Api;

use App\Enums\ActionOrderEnum;
use App\Models\Commande;
use App\Models\CommandeProduct;
use App\Models\Currency;
use App\Models\DelivreryDriver;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Status;
use App\Models\User;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Le cloisonnement des comptes metier, sur les chemins d'ECRITURE.
 *
 * Le proprietaire, le 17 octobre : « regarde le compte livreur, restaurant,
 * si tout est okay. Tu fais vraiment le tour, car je ne veux pas de bug en
 * production — surtout, c'est un nouveau client. »
 *
 * La LECTURE etait deja couverte : les compteurs du tableau de bord ignorent
 * les commandes des autres restaurants, et un tiers ne lit pas la commande
 * d'autrui. L'ECRITURE ne l'etait pas, et c'est elle qui fait des degats :
 * accepter la commande d'un autre, confirmer une livraison qui n'est pas la
 * sienne, encaisser a la place de quelqu'un.
 *
 * Le code se defendait deja — ces tests ne corrigent rien. Ils EMPECHENT la
 * defense de disparaitre : un `whereHas` retire par megarde lors d'un
 * remaniement ne se verrait nulle part ailleurs.
 */
class CloisonnementMetierTest extends TestCase
{
    /*
     * `RefreshDatabase` et NON `DatabaseTruncation`.
     *
     * La seconde tronque toutes les tables et COMMITE : elle emporte la
     * table des reglages, que les migrations de `settings` remplissent une
     * fois pour toutes et que rien ne reconstruit ensuite. Tout ce qui lit
     * `RentalSettings` apres ce fichier tombait alors en 500.
     *
     * C'est exactement ce qui est arrive le 17 octobre : ce fichier, ajoute
     * par moi, passe alphabetiquement avant `ConversionPrecommandeTest` et
     * faisait echouer ses 22 tests, plus ceux de `PorteeLivraisonTest`. J'ai
     * cru pendant trois mesures que le coupable etait le limiteur d'echecs.
     *
     * `RefreshDatabase` enveloppe chaque test dans une transaction annulee a
     * la fin : rien ne survit, rien n'est emporte.
     */
    use RefreshDatabase;

    private function statut(int $id, string $titre): Status
    {
        return Status::query()->firstOrCreate(
            ['id' => $id],
            ['title' => $titre, 'slug' => str($titre)->slug()->value()]
        );
    }

    /** Une commande en attente d'acceptation, rattachee a un restaurant. */
    private function commandePour(Restaurant $restaurant): Commande
    {
        $produit = Product::factory()->create(['restaurant_id' => $restaurant->id]);

        $commande = Commande::query()->create([
            'user_id' => User::factory()->create()->id,
            'status_id' => 1,
            'refernce' => (string) random_int(100000, 999999),
            'global_price' => 10,
        ]);

        CommandeProduct::query()->create([
            'commande_id' => $commande->id,
            'product_id' => $produit->id,
            'user_id' => $commande->user_id,
            'currency_id' => Currency::factory()->create()->id,
            'quantity' => 1,
            'price' => 10,
        ]);

        return $commande;
    }

    /** Pas de fabrique pour ce modele : on le cree comme CourseLivreurTest. */
    private function livreur(User $utilisateur): DelivreryDriver
    {
        return DelivreryDriver::query()->create([
            'user_id' => $utilisateur->id,
            'id_card' => 'CARTE-'.$utilisateur->id,
            'is_active' => true,
        ]);
    }

    public function test_un_restaurant_ne_peut_pas_accepter_la_commande_d_un_autre(): void
    {
        $this->statut(1, 'En attente');
        $this->statut(2, 'En cours');

        $patron = User::factory()->create();
        Restaurant::factory()->create(['user_id' => $patron->id]);

        $concurrent = Restaurant::factory()->create(['user_id' => User::factory()->create()->id]);
        $commande = $this->commandePour($concurrent);

        Sanctum::actingAs($patron, ['*']);

        $this->postJson('/api/user/restaurant-accept-order', [
            'uid_order' => Cipher::Encrypt($commande->id),
            'action' => ActionOrderEnum::Accept->value,
            'time' => 30,
        ])->assertStatus(404);

        // Et surtout : la commande du concurrent n'a pas bouge.
        $this->assertNull($commande->fresh()->accepted_at);
    }

    public function test_un_client_ordinaire_n_atteint_aucun_ecran_metier(): void
    {
        $this->statut(1, 'En attente');

        $client = User::factory()->create();
        Sanctum::actingAs($client, ['*']);

        /*
         * Ces routes ne vivent que derriere `auth:sanctum` : aucun garde de
         * role ne les protege. C'est la resolution du restaurant ou du
         * livreur qui tient — un client n'en a pas, et repart les mains
         * vides. Le jour ou cette resolution changerait, ce test tombe.
         */
        foreach ([
            '/api/user/restaurant-dash',
            '/api/user/restaurant-wait-accept-order',
            '/api/user/restaurant-past-order',
            '/api/user/delivery-dash',
            '/api/user/delivery-current-order',
        ] as $chemin) {
            $this->getJson($chemin)->assertStatus(404, "attendu vide : {$chemin}");
        }
    }

    public function test_un_livreur_ne_confirme_pas_la_livraison_d_un_autre(): void
    {
        $this->statut(2, 'En cours');
        $this->statut(3, 'Livrer');

        $mien = User::factory()->create();
        $monCompte = $this->livreur($mien);

        $autre = $this->livreur(User::factory()->create());

        $restaurant = Restaurant::factory()->create(['user_id' => User::factory()->create()->id]);
        $commande = $this->commandePour($restaurant);

        // La course appartient a L'AUTRE livreur, et porte son code.
        $commande->update([
            'status_id' => 2,
            'delivrery_driver_id' => $autre->id,
            'code_confirmation' => '1234',
            'accepted_at' => now(),
        ]);

        Sanctum::actingAs($mien, ['*']);

        /*
         * Meme avec le BON code, ce n'est pas ma course : la requete filtre
         * sur `delivrery_driver_id`, et le code seul ne suffit donc pas.
         *
         * Le serveur repond 400 et non 404 — c'est le code de « commande
         * introuvable » de ce controleur. On verifie le REFUS, pas le
         * numero : figer 404 ici ferait tomber le test au premier
         * ajustement de libelle, sans qu'aucune protection n'ait bouge.
         */
        $reponse = $this->postJson('/api/user/delivery-confirm-delivery-order', [
            'uid_order' => Cipher::Encrypt($commande->id),
            'code' => '1234',
        ]);

        $this->assertTrue(
            $reponse->status() >= 400 && $reponse->status() < 500,
            "la confirmation aurait du etre refusee, recu {$reponse->status()}",
        );

        $this->assertSame(
            $autre->id,
            (int) $commande->fresh()->delivrery_driver_id,
            'la course est restee au livreur qui l\'avait acceptee',
        );

        // Et la commande n'est pas passee a « livree ».
        $this->assertNotSame(3, (int) $commande->fresh()->status_id);

        // Mon propre compte existe bien : le refus vient du cloisonnement,
        // pas d'un livreur introuvable.
        $this->assertNotNull($monCompte->fresh());
    }
}
