<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    /**
     * LE REFUS APPARTIENT A LA CONNEXION.
     *
     * Avant, `login` n'avait jamais regarde `email_verified_at` : il emettait
     * le jeton, la connexion reussissait, le client voyait son nom dans
     * l'en-tete — et le garde refusait tout ce qu'il touchait ensuite. Il
     * etait dedans sans pouvoir rien faire, et rien ne lui disait ou aller.
     */
    public function test_la_connexion_refuse_un_compte_non_confirme(): void
    {
        $this->exigerDepuis();

        User::factory()->create([
            'email' => 'oublie@thalia.test',
            'password' => Hash::make('leBonMotDePasse1'),
            'email_verified_at' => null,
            'created_at' => now(),
        ]);

        $reponse = $this->postJson('/api/login', [
            'email' => 'oublie@thalia.test',
            'password' => 'leBonMotDePasse1',
        ]);

        $reponse->assertStatus(400)
            ->assertJsonPath('error', 'adresse_non_confirmee');

        // Aucun jeton : c'est tout l'objet du refus. Un jeton emis puis
        // refuse partout, c'est l'etat d'avant.
        $this->assertNull($reponse->json('data.token'));
    }

    /** Le code machine doit etre la : c'est lui qui route vers l'ecran. */
    public function test_le_refus_de_connexion_porte_le_code_attendu_par_les_clients(): void
    {
        $this->exigerDepuis();

        User::factory()->create([
            'email' => 'oublie2@thalia.test',
            'password' => Hash::make('leBonMotDePasse1'),
            'email_verified_at' => null,
            'created_at' => now(),
        ]);

        $this->postJson('/api/login', [
            'email' => 'oublie2@thalia.test',
            'password' => 'leBonMotDePasse1',
        ])->assertJsonStructure(['title', 'message', 'error']);
    }

    /**
     * ON NE REVELE PAS L'EXISTENCE DU COMPTE.
     *
     * Le refus vient APRES la verification du mot de passe. Sinon il suffirait
     * d'essayer une adresse avec n'importe quel mot de passe pour apprendre
     * qu'elle existe et qu'elle n'est pas encore confirmee.
     */
    public function test_un_mauvais_mot_de_passe_ne_revele_pas_le_compte_non_confirme(): void
    {
        $this->exigerDepuis();

        User::factory()->create([
            'email' => 'oublie3@thalia.test',
            'password' => Hash::make('leBonMotDePasse1'),
            'email_verified_at' => null,
            'created_at' => now(),
        ]);

        $this->postJson('/api/login', [
            'email' => 'oublie3@thalia.test',
            'password' => 'leMauvaisMotDePasse1',
        ])->assertJsonPath('message', 'Email ou mot de passe incorrect');
    }

    /** Un ancien compte non confirme se connecte toujours : meme pivot. */
    public function test_la_connexion_laisse_passer_un_compte_anterieur_au_pivot(): void
    {
        $this->exigerDepuis('2026-01-01');

        User::factory()->create([
            'email' => 'ancien@thalia.test',
            'password' => Hash::make('leBonMotDePasse1'),
            'email_verified_at' => null,
            'created_at' => '2025-06-01 10:00:00',
        ]);

        $this->postJson('/api/login', [
            'email' => 'ancien@thalia.test',
            'password' => 'leBonMotDePasse1',
        ])->assertSuccessful();
    }

    /**
     * LA DECONNEXION RESTE OUVERTE, QUELLE QUE SOIT LA ROUTE.
     *
     * Deux existent : la notre et celle du paquet rupadana, qui n'a pas de
     * nom propre. La liste blanche les nomme desormais par leur URL — le nom
     * d'une route de paquet n'est pas un contrat que nous maitrisons.
     *
     * L'enjeu : un compte non confirme ne doit jamais se retrouver enferme
     * dedans, incapable a la fois de commander et de partir.
     */
    public function test_les_deux_routes_de_deconnexion_restent_ouvertes(): void
    {
        $this->exigerDepuis();

        foreach (['/api/logout', '/api/auth/logout'] as $url) {
            $client = User::factory()->create([
                'email_verified_at' => null,
                'created_at' => now(),
            ]);

            Sanctum::actingAs($client, ['*']);

            $this->assertNotSame(
                'adresse_non_confirmee',
                $this->postJson($url)->json('error'),
                "La deconnexion par {$url} doit rester possible sans confirmation."
            );

            $this->app['auth']->forgetGuards();
        }
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
