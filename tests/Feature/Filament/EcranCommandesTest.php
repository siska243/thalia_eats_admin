<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CommandeResource\Pages\ListCommandes;
use App\Models\Commande;
use App\Models\Status;
use App\Models\Town;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * L'ecran des commandes : chercher, filtrer, annuler.
 *
 * Le proprietaire : « dans /admin/commandes la recherche renvoie 500, il faut
 * ajouter le filtre », et « donner la main a l'admin d'annuler une commande,
 * si c'est seulement en attente ou en attente de paiement ».
 *
 * Le 500 venait d'un `TextColumn::make('status')->searchable()` : `status`
 * est une RELATION, la table porte `status_id`. Filament cherchait donc dans
 * une colonne inexistante, et toute recherche sur cet ecran echouait — y
 * compris celle sur la reference, qui n'avait rien demande.
 */
class EcranCommandesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));

        // Les droits ne sont pas le sujet ici : `super_admin` ne donne aucun
        // laissez-passer dans ce projet, et son propre test le couvre.
        Gate::before(fn () => true);

        $this->actingAs($admin);
    }

    private function statut(int $id, string $titre): Status
    {
        return Status::query()->firstOrCreate(
            ['id' => $id],
            ['title' => $titre, 'slug' => 'statut-'.$id, 'color' => '#cccccc']
        );
    }

    private function commande(int $statut, ?string $reference = null): Commande
    {
        $this->statut($statut, 'Statut '.$statut);

        return Commande::query()->create([
            'user_id' => User::factory()->create()->id,
            'status_id' => $statut,
            'town_id' => Town::factory()->create()->id,
            'refernce' => $reference ?? 'CMD-'.uniqid(),
            'global_price' => 10,
            'adresse_delivery' => 'Avenue du Commerce 12',
        ]);
    }

    /** LE 500. Une recherche, n'importe laquelle, ne doit pas faire tomber l'ecran. */
    public function test_la_recherche_ne_tombe_plus_en_erreur(): void
    {
        $cherchee = $this->commande(Commande::STATUT_EN_ATTENTE, 'CMD-1001');

        // On s'en tient a la commande de ce test. S'appuyer sur
        // `Commande::query()->get()` faisait dependre l'assertion des lignes
        // laissees par les autres fichiers : vert seul, rouge en suite.
        Livewire::test(ListCommandes::class)
            ->set('tableSearch', 'CMD-1001')
            ->assertOk()
            ->assertCanSeeTableRecords([$cherchee]);
    }

    /** On cherche aussi par le libelle du statut, qui est ce que l'ecran affiche. */
    public function test_la_recherche_porte_sur_le_libelle_du_statut(): void
    {
        $this->statut(Commande::STATUT_EN_ATTENTE, 'En attente');
        $this->statut(Commande::STATUT_LIVREE, 'Livrée');

        $attente = $this->commande(Commande::STATUT_EN_ATTENTE);
        $livree = $this->commande(Commande::STATUT_LIVREE);

        Livewire::test(ListCommandes::class)
            ->set('tableSearch', 'En attente')
            ->assertCanSeeTableRecords([$attente])
            ->assertCanNotSeeTableRecords([$livree]);
    }

    /** Le filtre par statut, qui manquait : la liste n'en avait aucun. */
    public function test_le_filtre_par_statut_restreint_la_liste(): void
    {
        $attente = $this->commande(Commande::STATUT_EN_ATTENTE);
        $livree = $this->commande(Commande::STATUT_LIVREE);

        Livewire::test(ListCommandes::class)
            ->filterTable('status_id', [Commande::STATUT_EN_ATTENTE])
            ->assertCanSeeTableRecords([$attente])
            ->assertCanNotSeeTableRecords([$livree]);
    }

    /**
     * ANNULER, et seulement tant que rien n'est encaisse.
     *
     * Les deux statuts permis sont ceux de `STATUTS_NON_REGLES`, la liste que
     * le modele utilise deja pour ses scopes : « en attente » et « en attente
     * de paiement ».
     */
    public function test_une_commande_en_attente_peut_etre_annulee(): void
    {
        $commande = $this->commande(Commande::STATUT_EN_ATTENTE);
        $this->statut(Commande::STATUT_ANNULEE, 'Annulée');

        Livewire::test(ListCommandes::class)
            ->callAction(TestAction::make('annuler')->table($commande));

        $commande->refresh();

        $this->assertSame(Commande::STATUT_ANNULEE, (int) $commande->status_id);
        // Le crochet `saving` du modele pose la date : on verifie qu'on n'a
        // pas besoin de l'ecrire a la main dans l'action.
        $this->assertNotNull($commande->cancel_at);
    }

    public function test_une_commande_en_attente_de_paiement_peut_etre_annulee(): void
    {
        $commande = $this->commande(Commande::STATUT_ATTENTE_PAIEMENT);
        $this->statut(Commande::STATUT_ANNULEE, 'Annulée');

        Livewire::test(ListCommandes::class)
            ->callAction(TestAction::make('annuler')->table($commande));

        $this->assertSame(Commande::STATUT_ANNULEE, (int) $commande->refresh()->status_id);
    }

    /**
     * « action annuler avec confirmation » : la demande est explicite, et une
     * annulation ne se defait pas depuis cet ecran. Un clic de travers dans
     * une liste ne doit pas suffire.
     */
    public function test_l_annulation_demande_confirmation(): void
    {
        $this->commande(Commande::STATUT_EN_ATTENTE);

        $action = Livewire::test(ListCommandes::class)
            ->instance()
            ->getTable()
            ->getAction('annuler');

        $this->assertTrue($action->isConfirmationRequired());
    }

    /** LE POINT QUI COMPTE : on n'annule pas ce qui est deja encaisse ou livre. */
    public function test_une_commande_en_cours_ne_peut_pas_etre_annulee(): void
    {
        $commande = $this->commande(Commande::STATUT_EN_COURS);

        Livewire::test(ListCommandes::class)
            ->assertActionHidden(TestAction::make('annuler')->table($commande));
    }

    public function test_une_commande_livree_ne_peut_pas_etre_annulee(): void
    {
        $commande = $this->commande(Commande::STATUT_LIVREE);

        Livewire::test(ListCommandes::class)
            ->assertActionHidden(TestAction::make('annuler')->table($commande));
    }
}
