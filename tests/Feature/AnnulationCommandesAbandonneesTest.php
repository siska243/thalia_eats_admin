<?php

namespace Tests\Feature;

use App\Models\Commande;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Une commande qui n'avance plus finit annulee.
 *
 * Elle ne disparaissait pas d'elle-meme : elle restait dans « En cours » chez
 * le client, et surtout l'empechait d'en passer une nouvelle, puisque la
 * creation refuse tant qu'une commande n'est pas reglee. Des commandes de
 * fevrier 2024 bloquaient encore des comptes.
 */
class AnnulationCommandesAbandonneesTest extends TestCase
{
    use RefreshDatabase;

    private static int $compteur = 0;

    private function commande(array $attributs): Commande
    {
        // `refernce` et `user_id` sont obligatoires en base (la faute de frappe
        // dans « refernce » fait partie du schema, on ne la corrige pas ici).
        $commande = new Commande(array_merge([
            'refernce' => (string) (2000 + ++self::$compteur),
            'user_id' => User::factory()->create()->id,
            'status_id' => Commande::STATUT_EN_COURS,
            'global_price' => 10,
        ], $attributs));

        $commande->save();

        // `created_at` est gere par Eloquent : on le force apres coup, sinon
        // toute commande de test nait a l'instant present.
        if (isset($attributs['created_at'])) {
            $commande->forceFill(['created_at' => $attributs['created_at']])->saveQuietly();
        }

        return $commande->refresh();
    }

    public function test_elle_annule_une_commande_en_cours_depuis_plus_de_deux_jours(): void
    {
        $commande = $this->commande(['created_at' => now()->subDays(3)]);

        $this->artisan('commandes:annuler-abandonnees')->assertSuccessful();

        $commande->refresh();

        $this->assertSame(Commande::STATUT_ANNULEE, (int) $commande->status_id);
        // Le crochet `saving` du modele doit avoir pose la date en meme temps
        // que le statut : c'est toute la raison de passer par save().
        $this->assertNotNull($commande->cancel_at);
    }

    public function test_elle_laisse_une_commande_recente(): void
    {
        $commande = $this->commande(['created_at' => now()->subHours(6)]);

        $this->artisan('commandes:annuler-abandonnees')->assertSuccessful();

        $this->assertSame(Commande::STATUT_EN_COURS, (int) $commande->refresh()->status_id);
    }

    public function test_elle_laisse_une_commande_livree_meme_ancienne(): void
    {
        $commande = $this->commande([
            'created_at' => now()->subDays(30),
            'status_id' => Commande::STATUT_LIVREE,
            'delivery_at' => now()->subDays(29),
        ]);

        $this->artisan('commandes:annuler-abandonnees')->assertSuccessful();

        $this->assertSame(Commande::STATUT_LIVREE, (int) $commande->refresh()->status_id);
    }

    public function test_elle_laisse_une_commande_livree_dont_le_statut_n_a_pas_suivi(): void
    {
        // Cas reel : statut et dates ont diverge sur des lignes anciennes.
        // Une commande effectivement livree ne doit pas etre annulee parce que
        // son statut est reste en arriere.
        $commande = $this->commande([
            'created_at' => now()->subDays(30),
            'status_id' => Commande::STATUT_EN_COURS,
            'delivery_at' => now()->subDays(29),
        ]);

        $this->artisan('commandes:annuler-abandonnees')->assertSuccessful();

        $this->assertSame(Commande::STATUT_EN_COURS, (int) $commande->refresh()->status_id);
    }

    public function test_elle_annule_aussi_une_commande_en_attente_de_paiement(): void
    {
        $commande = $this->commande([
            'created_at' => now()->subDays(5),
            'status_id' => Commande::STATUT_ATTENTE_PAIEMENT,
        ]);

        $this->artisan('commandes:annuler-abandonnees')->assertSuccessful();

        $this->assertSame(Commande::STATUT_ANNULEE, (int) $commande->refresh()->status_id);
    }

    public function test_la_simulation_n_ecrit_rien(): void
    {
        $commande = $this->commande(['created_at' => now()->subDays(10)]);

        $this->artisan('commandes:annuler-abandonnees', ['--simuler' => true])
            ->assertSuccessful();

        $this->assertSame(Commande::STATUT_EN_COURS, (int) $commande->refresh()->status_id);
        $this->assertNull($commande->cancel_at);
    }
}
