<?php

namespace Tests\Feature\Api;

use App\Models\Commande;
use App\Models\Status;
use App\Models\Town;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * « Commande non reglee » etait ecrit huit fois dans CommandeController, sous
 * la forme whereIn('status_id', [1, 5]).
 *
 * Ajouter nonAnnulee() a l'affichage sans l'ajouter au controle de creation a
 * suffi a les faire divergier. Une commande portant cancel_at avec le statut
 * reste a 5 — une des lignes heritees incoherentes — disparaissait de
 * « Commandes en cours » tout en continuant de bloquer toute nouvelle
 * commande. Le client n'avait plus d'issue : rien a annuler a l'ecran, et
 * rien de possible non plus.
 *
 * Les deux endpoints consomment desormais le meme scope nonReglee().
 */
class CommandeNonRegleeTest extends TestCase
{
    /**
     * RefreshDatabase et non DatabaseTruncation, contrairement aux autres
     * classes de ce dossier.
     *
     * CommandeValideNonRegressionTest, qui s'execute juste apres dans l'ordre
     * alphabetique, utilise RefreshDatabase : elle suppose une base vide et
     * cree Status id 5 par factory. Une classe a troncature VALIDE ses lignes
     * — elles ne sont pas dans une transaction — et le Status 5 pose ici lui
     * arrivait en heritage, d'ou une violation de contrainte unique. Les deux
     * traits ne se melangent pas impunement dans une meme suite.
     */
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([[1, 'En attente', 'en-attente'], [5, 'En attente paiement', 'en-attente-paiement']] as [$id, $t, $slug]) {
            Status::query()->firstOrCreate(['id' => $id], ['title' => $t, 'slug' => $slug]);
        }

        $this->client = User::factory()->create();
        Sanctum::actingAs($this->client, ['*']);
    }

    private function commande(int $statut): Commande
    {
        return Commande::query()->create([
            'user_id' => $this->client->id,
            'status_id' => $statut,
            'town_id' => Town::factory()->create()->id,
            'refernce' => 'CMD-'.uniqid(),
            'global_price' => 10,
            'adresse_delivery' => 'Avenue du Commerce 12',
        ]);
    }

    public function test_une_commande_non_reglee_est_visible_dans_les_commandes_en_cours(): void
    {
        $commande = $this->commande(5);

        $reponse = $this->getJson('/api/user/commande/tracking')->assertStatus(200);

        $this->assertCount(1, $reponse->json());
        $this->assertSame($commande->refernce, $reponse->json('0.reference'));
    }

    public function test_une_commande_annulee_avec_un_statut_reste_a_5_ne_bloque_plus(): void
    {
        $bloquante = $this->commande(5);

        // Ligne heritee : cancel_at pose, statut jamais bascule. Ecrite hors
        // d'Eloquent, le hook du modele l'aurait sinon corrigee.
        DB::table('commandes')->where('id', $bloquante->id)->update(['cancel_at' => now()]);

        // Elle ne s'affiche pas — elle est annulee.
        $this->getJson('/api/user/commande/tracking')
            ->assertStatus(200)
            ->assertExactJson([]);

        // Et elle ne doit donc pas bloquer non plus : c'est la divergence qui
        // enfermait le client. Le corps vide fait echouer valide() plus loin,
        // pour une autre raison — ce qui compte est que ce ne soit plus le
        // blocage.
        $reponse = $this->postJson('/api/user/commande/valide', []);

        $this->assertNotSame('Commande en attente', $reponse->json('title'));
    }

    public function test_une_vraie_commande_non_reglee_bloque_et_le_message_la_nomme(): void
    {
        $bloquante = $this->commande(5);

        $reponse = $this->postJson('/api/user/commande/valide', [])->assertStatus(400);

        $this->assertSame('Commande en attente', $reponse->json('title'));
        $this->assertStringContainsString($bloquante->refernce, $reponse->json('message'));

        // La reponse porte l'uid : l'application peut emmener le client
        // directement sur la commande a regler.
        $this->assertSame($bloquante->refernce, $reponse->json('error.reference'));
        $this->assertNotEmpty($reponse->json('error.uid'));
    }

    public function test_affichage_et_blocage_ne_peuvent_plus_divergier(): void
    {
        // Le concept n'est defini qu'a un seul endroit.
        $source = file_get_contents(app_path('Http/Controllers/Api/CommandeController.php'));

        $this->assertStringNotContainsString("status_id', [1, 5]", $source);
        $this->assertStringNotContainsString("status_id', [1,5]", $source);
        $this->assertGreaterThan(0, substr_count($source, 'nonReglee()'));
    }
}
