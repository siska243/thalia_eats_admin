<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Confirmer son adresse avant de commander.
 *
 * Le proprietaire, le 17 octobre, apres deux inscriptions au nom
 * manifestement fabrique : « une confirmation d'email est obligatoire apres
 * l'inscription ».
 *
 * Le mecanisme existait deja — l'inscription envoie un code,
 * `/activation-account` le verifie et pose `email_verified_at`. Ce qui
 * manquait, c'est l'OBLIGATION : on commandait sans avoir jamais prouve
 * qu'on possede l'adresse.
 */
class ConfirmationEmailTest extends TestCase
{
    use RefreshDatabase;

    /** Le pivot au 1er janvier : tout compte de nos tests est « apres ». */
    private function exigerDepuis(?string $date = '2026-01-01'): void
    {
        config(['securite.confirmation_email.exigee_depuis' => $date]);
    }

    public function test_un_compte_non_confirme_ne_peut_pas_commander(): void
    {
        $this->exigerDepuis();

        $client = User::factory()->create([
            'email_verified_at' => null,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($client, ['*']);

        $this->postJson('/api/user/commande/add', [])
            ->assertStatus(400)
            ->assertJsonPath('error', 'adresse_non_confirmee');
    }

    public function test_un_compte_confirme_passe(): void
    {
        $this->exigerDepuis();

        $client = User::factory()->create([
            'email_verified_at' => now(),
            'created_at' => now(),
        ]);

        Sanctum::actingAs($client, ['*']);

        // Le garde laisse passer : la reponse vient du controleur, pas de
        // lui. On verifie seulement qu'on n'est PAS arrete a la porte.
        $reponse = $this->postJson('/api/user/commande/add', []);

        $this->assertNotSame('adresse_non_confirmee', $reponse->json('error'));
    }

    /**
     * LE POINT QUI COMPTE : on ne ferme pas la porte aux clients existants.
     *
     * Dix-neuf des vingt-trois comptes de la base n'ont jamais ete
     * confirmes. Les bloquer du jour au lendemain ferait perdre de vrais
     * clients, qui ne comprendraient pas pourquoi.
     */
    public function test_un_compte_anterieur_au_pivot_n_est_pas_bloque(): void
    {
        $this->exigerDepuis('2026-01-01');

        $ancien = User::factory()->create([
            'email_verified_at' => null,
            'created_at' => '2025-06-01 10:00:00',
        ]);

        Sanctum::actingAs($ancien, ['*']);

        $reponse = $this->postJson('/api/user/commande/add', []);

        $this->assertNotSame('adresse_non_confirmee', $reponse->json('error'));
    }

    /** Sans date reglee, la regle ne s'applique a personne : etat d'avant. */
    public function test_sans_date_reglee_personne_n_est_tenu(): void
    {
        $this->exigerDepuis(null);

        $client = User::factory()->create([
            'email_verified_at' => null,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($client, ['*']);

        $reponse = $this->postJson('/api/user/commande/add', []);

        $this->assertNotSame('adresse_non_confirmee', $reponse->json('error'));
    }

    /**
     * PLUS RIEN N'EST OUVERT, PAS MEME LA CONSULTATION.
     *
     * La regle ne portait d'abord que sur ce qui engage — commander, payer —
     * et la lecture restait permise. Le proprietaire l'a elargie le 17
     * octobre : « un compte non valide ou bloque ne peut pas acceder a
     * l'application (web ou mobile) ».
     */
    public function test_consulter_ses_commandes_est_refuse_aussi(): void
    {
        $this->exigerDepuis();

        $client = User::factory()->create([
            'email_verified_at' => null,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($client, ['*']);

        $this->getJson('/api/user/commande/current')
            ->assertStatus(400)
            ->assertJsonPath('error', 'adresse_non_confirmee');
    }

    /**
     * LES TROIS EXCEPTIONS, sans lesquelles la regle se mordrait la queue.
     *
     * Lire son compte : sans elle l'application ne saurait pas qui inviter a
     * confirmer. Le corriger : une adresse mal saisie est la premiere raison
     * de ne pas recevoir le code. Se deconnecter : on ne retient personne.
     */
    public function test_lire_son_compte_reste_possible_sans_confirmation(): void
    {
        $this->exigerDepuis();

        $client = User::factory()->create([
            'email_verified_at' => null,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($client, ['*']);

        $this->getJson('/api/user/account')
            ->assertSuccessful();
    }

    public function test_se_deconnecter_reste_possible_sans_confirmation(): void
    {
        $this->exigerDepuis();

        $client = User::factory()->create([
            'email_verified_at' => null,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($client, ['*']);

        $this->postJson('/api/logout')->assertSuccessful();
    }

    /** La reservation de vehicule obeit a la meme regle. */
    public function test_reserver_un_vehicule_exige_aussi_la_confirmation(): void
    {
        $this->exigerDepuis();

        $client = User::factory()->create([
            'email_verified_at' => null,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($client, ['*']);

        $this->postJson('/api/rental/bookings', [])
            ->assertStatus(400)
            ->assertJsonPath('error', 'adresse_non_confirmee');
    }
}
