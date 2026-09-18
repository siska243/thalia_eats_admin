<?php

namespace Tests\Feature\Api;

use App\Models\Commande;
use App\Models\CommandeProduct;
use App\Models\Currency;
use App\Models\DelivreryDriver;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Status;
use App\Models\Town;
use App\Models\User;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * « Ma course » ne filtrait que sur status_id, alors que l'annulation depuis
 * l'admin renseigne cancel_at sans toujours faire passer le statut a 4. Une
 * commande annulee en juillet 2025 restait ainsi affichee au livreur comme sa
 * course en cours, et masquait la vraie.
 */
class CourseLivreurTest extends TestCase
{
    use DatabaseTruncation;

    private DelivreryDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();


        $driverUser = User::factory()->create();
        Sanctum::actingAs($driverUser, ['*']);

        $this->driver = DelivreryDriver::query()->create([
            'user_id' => $driverUser->id,
            'id_card' => 'CARTE-'.$driverUser->id,
            'is_active' => true,
        ]);

        Status::query()->firstOrCreate(['id' => 2], ['title' => 'En cours', 'slug' => 'en-cours']);
    }

    private function course(array $attributs = [], bool $assignee = true): Commande
    {
        $commande = Commande::query()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'status_id' => 2,
            'town_id' => Town::factory()->create()->id,
            'delivrery_driver_id' => $assignee ? $this->driver->id : null,
            'refernce' => 'CMD-'.uniqid(),
            'global_price' => 10,
            'accepted_at' => now(),
        ], $attributs));

        // currentOrderDelivery exige whereHas('commande_products').
        $restaurant = Restaurant::factory()->create();
        $product = Product::factory()->create(['restaurant_id' => $restaurant->id]);

        CommandeProduct::query()->create([
            'commande_id' => $commande->id,
            'user_id' => $commande->user_id,
            'product_id' => $product->id,
            'currency_id' => Currency::query()->firstOrCreate(
                ['code' => 'USD'],
                ['title' => 'Dollar', 'slug' => 'usd']
            )->id,
            'quantity' => 1,
            'price' => 10,
        ]);

        return $commande;
    }

    /**
     * Ecrit cancel_at sans passer par le modele.
     *
     * Le modele garantit desormais la coherence des deux marqueurs : pour
     * verifier que les lectures resistent aux lignes ecrites avant cette
     * garantie — la commande annulee en juillet 2025 est toujours en base — il
     * faut contourner Eloquent.
     */
    private function annulerSansToucherAuStatut(Commande $commande): void
    {
        DB::table('commandes')->where('id', $commande->id)->update(['cancel_at' => now()]);
    }

    public function test_une_course_annulee_n_est_plus_ma_course(): void
    {
        $this->annulerSansToucherAuStatut($this->course());

        $this->getJson('/api/user/delivery-current-order')
            ->assertStatus(200)
            ->assertExactJson([]);
    }

    public function test_une_course_deja_remise_n_est_plus_ma_course(): void
    {
        $this->course(['delivery_at' => now()->subHour()]);

        $this->getJson('/api/user/delivery-current-order')
            ->assertStatus(200)
            ->assertExactJson([]);
    }

    public function test_la_course_annulee_ne_masque_plus_la_vraie(): void
    {
        // L'annulee est la plus recemment modifiee : sans le filtre, c'est
        // elle que orderBy('updated_at') faisait remonter.
        $vraie = $this->course();
        $annulee = $this->course();
        $annulee->touch();
        $this->annulerSansToucherAuStatut($annulee);

        $this->getJson('/api/user/delivery-current-order')
            ->assertStatus(200)
            ->assertJsonPath('reference', $vraie->refernce);
    }

    public function test_une_course_en_cours_reste_visible(): void
    {
        $commande = $this->course();

        $this->getJson('/api/user/delivery-current-order')
            ->assertStatus(200)
            ->assertJsonPath('reference', $commande->refernce);
    }

    public function test_une_course_annulee_ne_bloque_plus_l_acceptation(): void
    {
        // Le livreur porte une commande annulee : « Ma course » est vide, et
        // l'acceptation le refusait pourtant. Aucune sortie possible.
        $this->annulerSansToucherAuStatut($this->course());

        $this->getJson('/api/user/delivery-current-order')->assertExactJson([]);

        $offerte = $this->course(assignee: false);

        $this->postJson('/api/user/delivery-accept-order', [
            'uid_order' => Cipher::Encrypt($offerte->id),
        ])->assertStatus(200);

        $this->assertSame($this->driver->id, $offerte->fresh()->delivrery_driver_id);
    }

    public function test_une_course_deja_remise_ne_bloque_plus_l_acceptation(): void
    {
        $this->course(['delivery_at' => now()->subHour()]);

        $offerte = $this->course(assignee: false);

        $this->postJson('/api/user/delivery-accept-order', [
            'uid_order' => Cipher::Encrypt($offerte->id),
        ])->assertStatus(200);
    }

    public function test_une_vraie_course_en_cours_bloque_toujours_l_acceptation(): void
    {
        $this->course();

        $offerte = $this->course(assignee: false);

        $this->postJson('/api/user/delivery-accept-order', [
            'uid_order' => Cipher::Encrypt($offerte->id),
        ])->assertStatus(400);

        $this->assertNull($offerte->fresh()->delivrery_driver_id);
    }

    public function test_l_historique_ne_retient_pas_les_courses_annulees(): void
    {
        Status::query()->firstOrCreate(['id' => 3], ['title' => 'Livrer', 'slug' => 'livrer']);
        Status::query()->firstOrCreate(['id' => 4], ['title' => 'Annuler', 'slug' => 'annuler']);

        $livree = $this->course(['status_id' => 3, 'delivery_at' => now()]);
        $this->course(['status_id' => 4]);
        // Annulee depuis l'admin : cancel_at pose, statut jamais bascule.
        $this->annulerSansToucherAuStatut($this->course(['status_id' => 3, 'delivery_at' => now()]));

        $reponse = $this->getJson('/api/user/delivery-past-order')->assertStatus(200);

        $this->assertCount(1, $reponse->json());
        $this->assertSame($livree->refernce, $reponse->json('0.reference'));
    }

    public function test_l_historique_expose_la_date_de_livraison(): void
    {
        Status::query()->firstOrCreate(['id' => 3], ['title' => 'Livrer', 'slug' => 'livrer']);

        $livree = $this->course(['status_id' => 3, 'delivery_at' => '2026-03-04 18:30:00']);

        // CommandeResource lisait delivrery_at, un attribut inexistant : le
        // champ partait toujours a null, et l'ecran d'historique — qui groupe
        // les courses par date de livraison — n'affichait plus rien.
        $reponse = $this->getJson('/api/user/delivery-past-order')->assertStatus(200);

        $this->assertNotNull($reponse->json('0.delivery_at'));
        $this->assertStringContainsString('2026-03-04', (string) $reponse->json('0.delivery_at'));
        $this->assertSame($livree->refernce, $reponse->json('0.reference'));
    }

    public function test_les_demandes_n_offrent_pas_de_commande_annulee(): void
    {
        $this->annulerSansToucherAuStatut($this->course(assignee: false));

        $this->getJson('/api/user/delivery-wait-accept-order')
            ->assertStatus(200)
            ->assertExactJson([]);
    }
}
