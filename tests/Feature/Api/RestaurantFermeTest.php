<?php

namespace Tests\Feature\Api;

use App\Models\Commande;
use App\Models\CommandeProduct;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Status;
use App\Models\User;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Un restaurant ferme ne prend pas de commande.
 *
 * Signale par le proprietaire : « sur le web on a le restaurant fermer mais
 * j'arrive quand meme a commande ». C'etait pire que ce qu'il voyait : la
 * fermeture n'existait que dans les ecrans. Le site l'affichait et laissait
 * payer, le mobile grisait son bouton, et le serveur ne regardait jamais les
 * horaires. Un appel direct a l'API passait dans les deux cas, et un repas
 * etait regle que personne n'allait preparer.
 *
 * Sa decision, le meme jour : aucune marge de tolerance, et c'est l'heure du
 * REGLEMENT qui compte, pas celle du remplissage du panier.
 */
class RestaurantFermeTest extends TestCase
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

        // Un mardi a 14h00 : le jour et l'heure sont figes, sinon le test dit
        // le contraire selon le moment ou la suite tourne.
        Carbon::setTestNow(Carbon::parse('2026-10-13 14:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @param array<int, array<string, string>> $horaires */
    private function produitDe(array $horaires): Product
    {
        $restaurant = Restaurant::factory()->create(['openHours' => $horaires]);

        return Product::factory()->create(['restaurant_id' => $restaurant->id]);
    }

    private function ouvertMaintenant(): Product
    {
        return $this->produitDe([['day' => 'mardi', 'startAt' => '09:30:00', 'endAt' => '22:00:00']]);
    }

    private function fermeMaintenant(): Product
    {
        return $this->produitDe([['day' => 'mardi', 'startAt' => '18:00:00', 'endAt' => '22:00:00']]);
    }

    private function ajouter(Product $produit): TestResponse
    {
        return $this->postJson('/api/user/commande/add-product', [
            'products' => [[
                'product_id' => Cipher::Encrypt((string) $produit->id),
                'quantity' => 1,
                'pricing' => $produit->currency_id,
            ]],
        ]);
    }

    public function test_on_n_ajoute_pas_un_plat_d_un_restaurant_ferme(): void
    {
        $this->ajouter($this->fermeMaintenant())->assertStatus(400);

        /*
         * Comptage borne a CE client, et non global.
         *
         * `CommandeConcurrenteTest` ouvre deux vraies sessions MySQL pour
         * prouver la serialisation : ce qu'elles ecrivent est COMMITE et
         * survit au rollback de `RefreshDatabase`. Un compteur global passait
         * donc seul et tombait en suite complete, sur des lignes qui ne nous
         * regardent pas.
         */
        $this->assertSame(0, Commande::query()->where('user_id', $this->client->id)->count());
        $this->assertSame(
            0,
            CommandeProduct::query()->where('user_id', $this->client->id)->count(),
        );
    }

    /** Ouvert, rien ne change : le garde ne doit pas fermer la boutique. */
    public function test_un_restaurant_ouvert_accepte_toujours(): void
    {
        $this->ajouter($this->ouvertMaintenant())->assertSuccessful();

        $this->assertSame(
            1,
            CommandeProduct::query()->where('user_id', $this->client->id)->count(),
        );
    }

    /**
     * Le message dit l'heure de reouverture.
     *
     * « Ouvre a 18:00 » dit au client quoi faire, « ferme » le laisse devant
     * une impasse.
     */
    public function test_le_message_donne_l_heure_de_reouverture(): void
    {
        $this->ajouter($this->fermeMaintenant())
            ->assertStatus(400)
            ->assertJsonPath('title', 'Restaurant fermé')
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'ouvre à 18:00'));
    }

    /**
     * Le reglement est refuse meme si le panier a ete rempli avant la
     * fermeture. C'est la decision du proprietaire, et c'est le cas qui
     * comptait vraiment : le panier survit a l'heure de fermeture.
     */
    public function test_le_reglement_est_refuse_apres_la_fermeture(): void
    {
        $produit = $this->ouvertMaintenant();

        $this->ajouter($produit)->assertSuccessful();

        // Le restaurant ferme pendant que le panier dort.
        Carbon::setTestNow(Carbon::parse('2026-10-13 23:30:00'));

        $this->postJson('/api/user/commande/valide', [
            'products' => [['uid' => Cipher::Encrypt((string) $produit->id), 'quantity' => 1]],
            'pricing' => ['frais_livraison' => 1, 'service_price' => 1],
            'total_price' => 12,
            'method' => 'mobile',
            'phone' => '+243810000001',
            'mobile' => '+243810000001',
            'address' => ['adresse' => 'Avenue du Commerce', 'street' => 'Commerce', 'number_street' => '12', 'town' => ['slug' => 'gombe']],
        ])->assertStatus(400);

        // Aucune commande n'est partie au paiement. Borne a ce client, pour
        // la meme raison que plus haut.
        $this->assertSame(
            0,
            Commande::query()
                ->where('user_id', $this->client->id)
                ->where('status_id', Commande::STATUT_ATTENTE_PAIEMENT)
                ->count(),
        );
    }

    /**
     * Sans horaires saisis, le restaurant reste ouvert.
     *
     * Un restaurateur qui a oublie de les remplir ne doit pas se retrouver
     * ferme sans le savoir : c'est deja la regle des deux clients, et le
     * serveur ne doit pas la contredire.
     */
    public function test_sans_horaires_le_restaurant_reste_ouvert(): void
    {
        $restaurant = Restaurant::factory()->create(['openHours' => null]);
        $produit = Product::factory()->create(['restaurant_id' => $restaurant->id]);

        $this->ajouter($produit)->assertSuccessful();
    }

    /** Un creneau qui passe minuit reste ouvert apres minuit. */
    public function test_un_creneau_qui_passe_minuit_reste_ouvert(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-14 01:00:00'));

        $produit = $this->produitDe([
            ['day' => 'mardi', 'startAt' => '18:00:00', 'endAt' => '02:00:00'],
            ['day' => 'mercredi', 'startAt' => '18:00:00', 'endAt' => '02:00:00'],
        ]);

        $this->ajouter($produit)->assertSuccessful();
    }
}
