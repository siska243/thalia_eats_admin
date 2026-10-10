<?php

namespace Tests\Feature\Api;

use App\Models\Precommande;
use App\Models\User;
use App\Wrappers\Cipher;
use App\Wrappers\LibPhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le paiement depuis l'application, sans passer par le lien signé. C'est le
 * second point d'entrée : il n'a pas le formulaire qui collecte les
 * coordonnées de livraison, et rendre ces colonnes nullables l'avait laissé
 * sans garde.
 */
class PaiementPrecommandeApplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            '*' => Http::response(['code' => 0, 'orderNumber' => 'ORD-TEST', 'message' => 'ok']),
        ]);
    }

    /**
     * Un jeton APPLICATIF, pas un jeton d'assistant : la route porte
     * « assistant.emetteur », qui refuse justement l'assistant. Payer est une
     * action du client sur son telephone, jamais une action de l'agent.
     * Sanctum::actingAs() donne un tableau de capacites VIDE par defaut, la ou
     * createToken() donne ['*'] — modeliser un vrai jeton d'application exige
     * donc de le passer explicitement.
     */
    private function moi(): User
    {
        $moi = User::factory()->create();
        Sanctum::actingAs($moi, ['*']);

        return $moi;
    }

    public function test_une_precommande_sans_adresse_ne_peut_pas_etre_payee(): void
    {
        $moi = $this->moi();
        $p = Precommande::factory()->sansCoordonnees()->create(['user_id' => $moi->id]);

        $response = $this->postJson('/api/precommandes/'.Cipher::Encrypt($p->id).'/paiement', [
            'phone' => '+243810000000',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'coordonnees_manquantes']);

        // Le garde doit refuser AVANT d'appeler la passerelle : sinon le client
        // est debite pour une commande que personne ne pourra livrer.
        Http::assertNothingSent();
    }

    public function test_une_precommande_complete_peut_etre_payee(): void
    {
        $moi = $this->moi();
        $p = Precommande::factory()->create(['user_id' => $moi->id]);

        $response = $this->postJson('/api/precommandes/'.Cipher::Encrypt($p->id).'/paiement', [
            'phone' => '+243810000000',
        ]);

        $response->assertStatus(200);
        Http::assertSentCount(1);
    }

    public function test_un_numero_fantaisiste_est_refuse_sans_erreur_serveur(): void
    {
        $moi = $this->moi();
        $p = Precommande::factory()->create(['user_id' => $moi->id]);

        $response = $this->postJson('/api/precommandes/'.Cipher::Encrypt($p->id).'/paiement', [
            'phone' => 'pas-un-numero',
        ]);

        // Sans le garde pose dans LibPhoneNumber, isValidNumber() recevait
        // l'exception de parsing et levait une TypeError : 500, pas 400.
        $response->assertStatus(400);
        $response->assertJson(['error' => 'telephone_invalide']);
        Http::assertNothingSent();
    }

    #[DataProvider('numerosImpossibles')]
    public function test_le_wrapper_repond_faux_au_lieu_de_lever(string $entree): void
    {
        $this->assertFalse((new LibPhoneNumber($entree))->checkValidationNumber());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function numerosImpossibles(): array
    {
        return [
            'chaine vide' => [''],
            'lettres' => ['pas-un-numero'],
            'ponctuation seule' => ['+++'],
            'espaces' => ['   '],
            'indicatif inconnu' => ['+999999999999999'],
        ];
    }

    public function test_un_vrai_numero_congolais_reste_valide(): void
    {
        $this->assertTrue((new LibPhoneNumber('+243810000000'))->checkValidationNumber());
    }
}
