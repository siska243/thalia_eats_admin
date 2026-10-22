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
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * « Ca passe avec le web, pas avec le mobile. Je ne comprends pas, ca doit
 * etre la meme chose. »
 *
 * Justement, ce n'est pas la meme chose. Les deux clients postent sur
 * `user/commande/valide`, mais ne construisent pas le meme corps : le web le
 * lit d'un panier encode en base64 dans l'URL
 * (next-app/app/checkout/page.tsx), le mobile le fabrique dans
 * `buildPaymentPayload()` (thalia-delivery/helpers/payment-payload.ts).
 *
 * Ce fichier met les deux cote a cote, avec exactement ce que chacun envoie.
 * Tant qu'ils ne divergent pas ici, ils ne divergent pas en production.
 */
class PaiementMobileEtWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['quotation.authoritative' => true]);

        Http::fake([
            '*' => Http::response([
                'code' => 0,
                'orderNumber' => 'TEST-ORDER-1',
                'message' => 'Transaction initiee',
            ], 200),
        ]);
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

        $product = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'currency_id' => $currency->id,
            'price' => 1500,
        ]);

        return [$town, $currency, $product];
    }

    /** @return array<string, mixed> */
    private function commun(Town $town, Currency $currency, Product $product): array
    {
        return [
            'total_price' => 5500,
            'success_url' => 'https://example.test/ok',
            'error_url' => 'https://example.test/ko',
            'cancel_url' => 'https://example.test/cancel',
            'pricing' => [
                'frais_livraison' => 2000,
                'service_price' => 500,
                'currency' => ['id' => $currency->id, 'code' => $currency->code],
            ],
            'products' => [
                ['uid' => Cipher::Encrypt($product->id), 'quantity' => 2],
            ],
            'address' => [
                'town' => ['slug' => $town->slug, 'uid' => Cipher::Encrypt($town->id), 'title' => $town->title],
                'adresse' => 'Avenue Test',
                'street' => 'Rue Test',
                'number_street' => '12',
                'reference' => 'En face du marche',
            ],
        ];
    }

    /**
     * Ce que le WEB envoie : app/checkout/page.tsx, `handleSubmit`.
     *
     * Pas de cle `method` — le serveur retombe donc sur « mobile » — et le
     * numero tel que saisi dans la fenetre, en national.
     *
     * @return array<string, mixed>
     */
    private function payloadWeb(Town $town, Currency $currency, Product $product): array
    {
        return $this->commun($town, $currency, $product) + [
            'phone' => '0810000000',
        ];
    }

    /**
     * Ce que le MOBILE envoie : helpers/payment-payload.ts.
     *
     * Quatre cles de plus — `method`, `mobile`, `callback_url`, `order` — et
     * surtout un numero recompose a partir de l'indicatif du selecteur de
     * pays, duquel le « + » et les espaces sont retires pour la passerelle.
     *
     * @return array<string, mixed>
     */
    private function payloadMobile(Town $town, Currency $currency, Product $product): array
    {
        $commun = $this->commun($town, $currency, $product);

        return $commun + [
            'method' => 'mobile',
            'phone' => '243810000000',
            'mobile' => '243 810000000',
            'callback_url' => 'https://example.test/webhook',
            'recipient_name' => null,
            'recipient_phone' => null,
            // Le mobile transporte en plus l'objet complet de la commande.
            'order' => [
                'products' => $commun['products'],
                'pricing' => $commun['pricing'],
                'adresse' => $commun['address'],
                'total_price' => 5500,
            ],
        ];
    }

    public function test_le_web_passe(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $product] = $this->contexte();

        $this->postJson('/api/user/commande/valide', $this->payloadWeb($town, $currency, $product))
            ->assertStatus(201);
    }

    /**
     * LE TEST QUI COMPTE : le meme panier, la meme adresse, le meme montant,
     * depuis le telephone.
     */
    public function test_le_mobile_passe_aussi(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $product] = $this->contexte();

        $reponse = $this->postJson('/api/user/commande/valide', $this->payloadMobile($town, $currency, $product));

        $reponse->assertStatus(201);
    }

    /**
     * UNE COMMUNE QUE LE SERVEUR NE RESOUT PAS.
     *
     * `valide()` fait `$commande->town_id = $town->id` sans verifier que
     * `$town` existe. Une adresse dont la commune ne tombe sur rien — slug
     * absent, slug inconnu, adresse carrement absente — donne donc un 500,
     * que le client lit « erreur de paiement » sans rien de plus.
     */
    public function test_une_commune_introuvable_ne_rend_pas_une_erreur_serveur(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $product] = $this->contexte();

        $payload = $this->payloadMobile($town, $currency, $product);
        $payload['address']['town'] = ['slug' => 'commune-qui-nexiste-pas'];

        $this->postJson('/api/user/commande/valide', $payload)
            ->assertStatus(400);
    }

    public function test_une_adresse_sans_commune_ne_rend_pas_une_erreur_serveur(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $product] = $this->contexte();

        $payload = $this->payloadMobile($town, $currency, $product);
        unset($payload['address']['town']);

        $this->postJson('/api/user/commande/valide', $payload)
            ->assertStatus(400);
    }

    /**
     * Le numero recompose par le selecteur de pays, sans « + ».
     *
     * C'est la forme que `buildPaymentPayload` fabrique pour la passerelle,
     * et elle traverse d'abord le controle de validite du serveur.
     */
    public function test_le_numero_sans_plus_du_mobile_est_accepte(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        [$town, $currency, $product] = $this->contexte();

        $payload = $this->payloadMobile($town, $currency, $product);
        $payload['phone'] = '243810000000';

        $this->postJson('/api/user/commande/valide', $payload)
            ->assertStatus(201);
    }
}
