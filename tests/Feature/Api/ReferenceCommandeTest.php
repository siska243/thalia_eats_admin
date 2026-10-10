<?php

namespace Tests\Feature\Api;

use App\Models\Commande;
use App\Models\Status;
use App\Models\Town;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deux commandes n'ont jamais la meme reference.
 *
 * Elle etait calculee `1000 + id de la commande PRECEDENTE`, lue hors de tout
 * verrou et globalement. La contrainte d'unicite existe en base depuis la
 * creation de la table, donc deux clients simultanes ne pouvaient pas obtenir
 * la meme reference : le second voyait sa commande ECHOUER sur une erreur de
 * cle dupliquee. Pas un melange d'argent, mais une commande perdue au pire
 * moment, celui du paiement.
 *
 * Derivee de son propre identifiant, la reference est unique par
 * construction : il n'y a plus de course a perdre.
 */
class ReferenceCommandeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([[1, 'En attente', 'en-attente'], [5, 'En attente paiement', 'en-attente-paiement']] as [$id, $titre, $slug]) {
            Status::query()->firstOrCreate(['id' => $id], ['title' => $titre, 'slug' => $slug]);
        }
    }

    private function commande(): Commande
    {
        return Commande::query()->create([
            'user_id' => User::factory()->create()->id,
            'status_id' => Commande::STATUT_EN_ATTENTE,
            'town_id' => Town::factory()->create()->id,
            'global_price' => 10,
        ]);
    }

    public function test_la_reference_se_derive_de_l_identifiant(): void
    {
        $commande = $this->commande();

        $this->assertSame((string) (1000 + $commande->id), $commande->refernce);
    }

    /**
     * Deux creations a la suite, sans jamais relire « la derniere commande » :
     * c'est cette lecture qui rendait la reference devinable et disputee.
     */
    public function test_deux_commandes_ont_deux_references(): void
    {
        $premiere = $this->commande();
        $seconde = $this->commande();

        $this->assertNotSame($premiere->refernce, $seconde->refernce);
    }

    /** La base refuse le doublon, meme si le code venait a se tromper. */
    public function test_la_base_refuse_une_reference_dupliquee(): void
    {
        $premiere = $this->commande();

        $this->expectException(UniqueConstraintViolationException::class);

        Commande::query()->create([
            'user_id' => User::factory()->create()->id,
            'status_id' => Commande::STATUT_EN_ATTENTE,
            'town_id' => Town::factory()->create()->id,
            'global_price' => 10,
            'refernce' => $premiere->refernce,
        ]);
    }
}
