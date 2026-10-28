<?php

namespace Tests\Feature\Api;

use App\Models\Currency;
use App\Models\DelivreryPrice;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Status;
use App\Models\StatusPayement;
use App\Models\Town;
use App\Models\User;
use App\Settings\FoodSettings;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Le panier minimum.
 *
 * « On rajoute dans la config le prix minimum pour passer une commande, et
 * c'est configurable depuis /admin/parametres onglet livraison repas. »
 *
 * Deux choses comptent ici, et la seconde autant que la premiere : le seuil
 * est respecte, ET il vaut zero tant que personne ne l'a pose. Ce reglage
 * arrive sur une application en production : une valeur par defaut non nulle
 * refuserait du jour au lendemain des paniers acceptes la veille.
 */
class PanierMinimumTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            '*' => Http::response([
                'code' => 0,
                'orderNumber' => 'TEST-ORDER-1',
                'message' => 'Transaction initiee',
            ], 200),
        ]);
    }

    /**
     * Les reglages, simules comme partout ailleurs dans cette suite.
     *
     * Lire les vrais rendait ce fichier dependant de l'ordre d'execution :
     * d'autres tests vident la table `settings`, et les sept tests d'ici
     * tombaient alors sur « properties were missing » — verts seuls, rouges
     * en suite.
     */
    private function minimum(float $valeur): void
    {
        FoodSettings::fake([
            'max_delivery_radius_km' => 500.0,
            'out_of_radius_message' => 'Trop loin.',
            'use_client_position' => false,
            'city_first' => true,
            'stock_actif' => true,
            'montant_minimum_commande' => $valeur,
        ], loadMissingValues: false);
    }

    /**
     * @return array{0: Town, 1: Currency, 2: Product}
     */
    private function contexte(): array
    {
        Status::factory()->create(['id' => 5]);

        StatusPayement::query()->firstOrCreate(
            ['code' => '2'],
            ['name' => 'Paiement en attente', 'is_default' => true]
        );

        $town = Town::factory()->create();
        $currency = Currency::factory()->create();
        $restaurant = Restaurant::factory()->create(['town_id' => $town->id]);

        DelivreryPrice::factory()->create([
            'town_id' => $town->id, 'currency_id' => $currency->id,
            'interval_pricing' => 0, 'interval_max_price' => 100000,
            'frais' => 2000, 'service_price' => 500,
        ]);

        // Un plat a 1500 : deux unites font 3000 de sous-total.
        $product = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'currency_id' => $currency->id,
            'price' => 1500,
        ]);

        return [$town, $currency, $product];
    }

    /** @return array<string, mixed> */
    private function payload(Town $town, Currency $currency, Product $product, int $quantite = 2): array
    {
        return [
            'method' => 'cart',
            'total_price' => 1500 * $quantite + 2500,
            'phone' => '0810000000',
            'mobile' => '0810000000',
            'success_url' => 'https://example.test/ok',
            'error_url' => 'https://example.test/ko',
            'cancel_url' => 'https://example.test/cancel',
            'pricing' => [
                'frais_livraison' => 2000,
                'service_price' => 500,
                'currency' => ['id' => $currency->id, 'code' => $currency->code],
            ],
            'products' => [
                ['uid' => Cipher::Encrypt($product->id), 'quantity' => $quantite],
            ],
            'address' => [
                'town' => ['slug' => $town->slug],
                'adresse' => 'Avenue Test',
                'street' => 'Rue Test',
                'number_street' => '12',
                'reference' => 'En face du marche',
            ],
        ];
    }

    /**
     * LE DEFAUT, et il compte autant que le reste.
     *
     * La migration livre `food.montant_minimum_commande` a zero : ce reglage
     * arrive sur une application en production, et une valeur non nulle
     * refuserait du jour au lendemain des paniers acceptes la veille. A zero,
     * aucun panier n'est refuse, si petit soit-il.
     */
    public function test_a_zero_aucun_minimum_n_est_impose(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $product] = $this->contexte();

        $this->minimum(0);

        $this->assertSame(0.0, FoodSettings::montantMinimum());

        $this->postJson('/api/user/commande/valide', $this->payload($town, $currency, $product, 1))
            ->assertStatus(201);
    }

    public function test_un_panier_sous_le_seuil_est_refuse(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $product] = $this->contexte();

        $this->minimum(6000);

        // 1500 x 2 = 3000 de plats, plus 2500 de livraison et de service :
        // 5500 au total, sous les 6000 exiges.
        $this->postJson('/api/user/commande/valide', $this->payload($town, $currency, $product, 2))
            ->assertStatus(400)
            ->assertJsonPath('error', 'panier_sous_le_minimum');
    }

    public function test_un_panier_au_dessus_du_seuil_passe(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $product] = $this->contexte();

        $this->minimum(5000);

        // 1500 x 4 = 6000.
        $this->postJson('/api/user/commande/valide', $this->payload($town, $currency, $product, 4))
            ->assertStatus(201);
    }

    /** Pile sur le seuil : accepte. « Minimum » veut dire « au moins ». */
    public function test_un_panier_pile_sur_le_seuil_passe(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $product] = $this->contexte();

        // 3000 de plats plus 2500 de livraison et de service.
        $this->minimum(5500);

        $this->postJson('/api/user/commande/valide', $this->payload($town, $currency, $product, 2))
            ->assertStatus(201);
    }

    /**
     * LE SEUIL PORTE SUR LE TOTAL GENERAL, LIVRAISON COMPRISE.
     *
     * Arbitrage du proprietaire, le 28 octobre : « non pas sous-total mais le
     * total general ». 1500 x 2 = 3000 de plats, plus 2500 de livraison et de
     * service, font 5500. Avec un seuil a 5000, cette commande passe — alors
     * que le sous-total seul, 3000, l'aurait fait refuser.
     */
    public function test_les_frais_comptent_dans_le_seuil(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $product] = $this->contexte();

        $this->minimum(5000);

        $this->postJson('/api/user/commande/valide', $this->payload($town, $currency, $product, 2))
            ->assertStatus(201);
    }

    /**
     * ON NE CONTOURNE PAS LE SEUIL EN ANNONÇANT UN AUTRE MONTANT.
     *
     * Les montants viennent de la base et de la cotation du serveur, jamais
     * du corps de la requete : c'est la meme doctrine que le reste des
     * chemins d'argent de ce controleur. Le total general est recalcule, pas
     * recopie — sans quoi il suffirait d'annoncer un gros chiffre pour
     * franchir le seuil.
     */
    public function test_un_total_annonce_genereux_ne_contourne_pas_le_seuil(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $product] = $this->contexte();

        $this->minimum(6000);

        $payload = $this->payload($town, $currency, $product, 2);
        $payload['total_price'] = 999999;

        $this->postJson('/api/user/commande/valide', $payload)
            ->assertStatus(400)
            ->assertJsonPath('error', 'panier_sous_le_minimum');
    }

    /**
     * LE MINIMUM DE PAIEMENT, PAR DEVISE.
     *
     * Il valait « 2 » en dur, pour la carte seulement, et se mesurait sur le
     * total ANNONCE par le client — sans savoir dans quelle devise ce total
     * etait exprime. Il se regle desormais sur la fiche de la devise, vaut
     * pour la carte comme pour le mobile money, et porte sur le montant que
     * la passerelle va reellement prelever.
     */
    public function test_le_paiement_par_carte_refuse_sous_le_seuil_de_la_devise(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $product] = $this->contexte();

        $this->minimum(0);
        // Le total facture vaut 5500 : on pose la barre au-dessus.
        $currency->update(['montant_minimum_paiement' => 99999]);

        $this->postJson('/api/user/commande/valide', $this->payload($town, $currency, $product, 2))
            ->assertStatus(400)
            ->assertJsonPath('error', 'montant_sous_le_minimum_paiement');
    }

    /** « Pour cart ou mobile » : le mobile money obeit a la meme barre. */
    public function test_le_mobile_money_refuse_aussi_sous_le_seuil(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $product] = $this->contexte();

        $this->minimum(0);
        $currency->update(['montant_minimum_paiement' => 99999]);

        $payload = $this->payload($town, $currency, $product, 2);
        $payload['method'] = 'mobile';
        $payload['phone'] = '0810000000';

        $this->postJson('/api/user/commande/valide', $payload)
            ->assertStatus(400)
            ->assertJsonPath('error', 'montant_sous_le_minimum_paiement');
    }

    public function test_un_montant_au_dessus_du_seuil_passe(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $product] = $this->contexte();

        $this->minimum(0);
        $currency->update(['montant_minimum_paiement' => 100]);

        $this->postJson('/api/user/commande/valide', $this->payload($town, $currency, $product, 2))
            ->assertStatus(201);
    }

    /** Zero sur la devise : aucun seuil, comme avant la mesure. */
    public function test_une_devise_sans_seuil_ne_refuse_rien(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $product] = $this->contexte();

        $this->minimum(0);
        $currency->update(['montant_minimum_paiement' => 0]);

        $this->postJson('/api/user/commande/valide', $this->payload($town, $currency, $product, 1))
            ->assertStatus(201);
    }

    /**
     * LE SEUIL EST DANS LA DEVISE, ET N'EST PAS CONVERTI.
     *
     * Deux dollars et deux francs congolais ne sont pas la meme barre. Une
     * devise qui n'a pas de seuil ne doit pas heriter de celui d'une autre.
     */
    public function test_le_seuil_d_une_devise_ne_s_applique_pas_a_une_autre(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $product] = $this->contexte();

        $this->minimum(0);
        $currency->update(['montant_minimum_paiement' => 0]);

        // Une autre devise, avec une barre tres haute : elle ne concerne pas
        // cette commande.
        Currency::factory()->create([
            'code' => 'XXX',
            'montant_minimum_paiement' => 99999,
        ]);

        $this->postJson('/api/user/commande/valide', $this->payload($town, $currency, $product, 2))
            ->assertStatus(201);
    }

    /** Le dollar ne descend jamais sous le plancher de la passerelle. */
    public function test_le_dollar_ne_descend_jamais_sous_deux(): void
    {
        $usd = Currency::factory()->create([
            'code' => 'USD',
            'montant_minimum_paiement' => 0.5,
        ]);

        $this->assertSame(2.0, $usd->montantMinimumPaiement());
    }

    /** Une autre devise, elle, peut valoir zero : aucun plancher a traduire. */
    public function test_une_autre_devise_peut_valoir_zero(): void
    {
        $cdf = Currency::factory()->create([
            'code' => 'CDF',
            'montant_minimum_paiement' => 0,
        ]);

        $this->assertSame(0.0, $cdf->montantMinimumPaiement());
    }

    /** Les applications doivent pouvoir l'annoncer avant le paiement. */
    public function test_le_seuil_est_expose_aux_applications(): void
    {
        $this->minimum(2500);

        $this->getJson('/api/default')
            ->assertSuccessful()
            ->assertJsonPath('reglages.montant_minimum_commande', 2500);
    }
}
