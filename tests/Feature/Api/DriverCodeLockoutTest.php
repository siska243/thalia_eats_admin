<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\DeliveryController;
use App\Models\Commande;
use App\Models\DelivreryDriver;
use App\Models\Status;
use App\Models\Town;
use App\Models\User;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Les codes de confirmation font quatre chiffres : sans limitation, ils se
 * parcourent en quelques milliers d'appels, ce qui permet de declarer une
 * commande recuperee ou livree sans jamais voir le restaurant ni le client.
 *
 * Trois codes refuses bloquent donc la saisie vingt-cinq minutes, cote
 * serveur — le verrou de l'application mobile n'etant qu'un confort.
 */
class DriverCodeLockoutTest extends TestCase
{
    use DatabaseTruncation;

    private Commande $commande;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $driverUser = User::factory()->create();
        Sanctum::actingAs($driverUser, ['*']);

        $driver = DelivreryDriver::query()->create([
            'user_id' => $driverUser->id,
            'id_card' => 'CARTE-'.$driverUser->id,
            'is_active' => true,
        ]);

        $status = Status::query()->firstOrCreate(
            ['id' => 2],
            ['title' => 'En cours', 'slug' => 'en-cours']
        );

        $this->commande = Commande::query()->create([
            'user_id' => User::factory()->create()->id,
            'status_id' => $status->id,
            'town_id' => Town::factory()->create()->id,
            'delivrery_driver_id' => $driver->id,
            'refernce' => 'CMD-LOCK',
            'global_price' => 10,
            'code_confirmation' => 1234,
            'code_confirmation_restaurant' => '4321',
            'accepted_at' => now(),
        ]);
    }

    private function tenterReception(string $code)
    {
        return $this->postJson('/api/user/delivery-confirm-reception-order', [
            'uid_order' => Cipher::Encrypt($this->commande->id),
            'code' => $code,
            'time' => '18:30',
        ]);
    }

    private function tenterLivraison(string $code)
    {
        return $this->postJson('/api/user/delivery-confirm-delivery-order', [
            'uid_order' => Cipher::Encrypt($this->commande->id),
            'code' => $code,
        ]);
    }

    public function test_trois_codes_errones_bloquent_la_saisie(): void
    {
        $this->tenterReception('0000')->assertStatus(400);
        $this->tenterReception('0001')->assertStatus(400);
        $this->tenterReception('0002')->assertStatus(400);

        // Le quatrieme essai n'est meme plus evalue, et le bon code non plus.
        $this->tenterReception('4321')
            ->assertStatus(429)
            ->assertJsonPath('title', 'Saisie bloquee');

        $this->assertNull($this->commande->fresh()->time_delivery);
    }

    public function test_le_blocage_dure_vingt_cinq_minutes(): void
    {
        foreach (['0000', '0001', '0002'] as $code) {
            $this->tenterReception($code);
        }

        $this->tenterReception('4321')->assertStatus(429);

        $this->travel(24)->minutes();
        $this->tenterReception('4321')->assertStatus(429);

        $this->travel(2)->minutes();
        $this->tenterReception('4321')->assertStatus(201);

        $this->assertNotNull($this->commande->fresh()->time_delivery);
    }

    public function test_un_code_valide_remet_le_compteur_a_zero(): void
    {
        $this->tenterReception('0000')->assertStatus(400);
        $this->tenterReception('0001')->assertStatus(400);

        $this->tenterReception('4321')->assertStatus(201);

        // Sans remise a zero, ce seul echec aurait suffi a bloquer.
        $this->tenterReception('0002')->assertStatus(400);
        $this->tenterReception('4321')->assertStatus(201);
    }

    public function test_les_deux_etapes_ont_des_compteurs_distincts(): void
    {
        foreach (['0000', '0001', '0002'] as $code) {
            $this->tenterReception($code);
        }

        $this->tenterReception('4321')->assertStatus(429);

        // Le retrait est bloque, la remise au client ne l'est pas.
        $this->tenterLivraison('1234')->assertStatus(201);

        $this->assertSame(3, $this->commande->fresh()->status_id);
    }

    public function test_le_verrou_est_propre_a_chaque_livreur(): void
    {
        foreach (['0000', '0001', '0002'] as $code) {
            $this->tenterReception($code);
        }

        $this->tenterReception('4321')->assertStatus(429);

        $autreUser = User::factory()->create();
        DelivreryDriver::query()->create([
            'user_id' => $autreUser->id,
            'id_card' => 'CARTE-'.$autreUser->id,
            'is_active' => true,
        ]);
        Sanctum::actingAs($autreUser, ['*']);

        // Ce livreur n'est pas affecte a la commande : il obtient 400, jamais 429.
        $this->tenterReception('4321')->assertStatus(400);
    }

    /**
     * Cipher n'a pas de MAC et base64_decode ignore les caracteres hors
     * alphabet : en inserant des espaces dans l'uid, un meme ordre produit une
     * infinite de chiffres distincts qui dechiffrent tous vers le meme id. Si
     * le verrou comptait sur la chaine brute, chaque variante repartait le
     * compteur a zero et les 9000 codes se parcouraient malgre le blocage.
     */
    public function test_le_verrou_resiste_aux_variantes_d_uid(): void
    {
        $canonique = Cipher::Encrypt($this->commande->id);

        // Trois essais errones, chacun avec une representation DIFFERENTE du
        // meme uid (un espace insere a des positions differentes).
        $this->tenterReceptionAvecUid(substr($canonique, 0, 3).' '.substr($canonique, 3), '0000')
            ->assertStatus(400);
        $this->tenterReceptionAvecUid(substr($canonique, 0, 5).' '.substr($canonique, 5), '0001')
            ->assertStatus(400);
        $this->tenterReceptionAvecUid($canonique.' ', '0002')
            ->assertStatus(400);

        // La saisie doit etre bloquee, quelle que soit la variante presentee.
        $this->tenterReceptionAvecUid(' '.$canonique, '4321')
            ->assertStatus(429)
            ->assertJsonPath('title', 'Saisie bloquee');

        $this->assertNull($this->commande->fresh()->time_delivery);
    }

    private function tenterReceptionAvecUid(string $uid, string $code)
    {
        return $this->postJson('/api/user/delivery-confirm-reception-order', [
            'uid_order' => $uid,
            'code' => $code,
            'time' => '18:30',
        ]);
    }

    public function test_les_constantes_correspondent_a_la_regle_annoncee(): void
    {
        $this->assertSame(3, DeliveryController::MAX_CODE_ATTEMPTS);
        $this->assertSame(25 * 60, DeliveryController::CODE_LOCK_SECONDS);
    }
}
