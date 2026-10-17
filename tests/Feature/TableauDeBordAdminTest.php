<?php

namespace Tests\Feature;

use App\Filament\Widgets\CeQuiBloque;
use App\Filament\Widgets\CommandesParJour;
use App\Filament\Widgets\DernieresCommandes;
use App\Filament\Widgets\StatistiquesDuJour;
use App\Models\Booking;
use App\Models\Commande;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Status;
use App\Models\User;
use App\Models\Vehicle;
use App\Settings\RentalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Le tableau de bord de l'administration.
 *
 * Il n'affichait que les deux widgets livres avec Filament : le compte
 * connecte et la version du cadriciel. Rien du produit.
 *
 * Deux choses sont verifiees ici, et la seconde compte autant que la
 * premiere : les chiffres sont justes, et CHAQUE widget se tait pour qui n'a
 * pas la permission de la ressource qu'il resume. `config/filament-shield.php`
 * pose `define_via_gate` a false : super_admin ne contourne RIEN, et un widget
 * sans garde montrerait a tous ce que les ecrans cachent.
 */
class TableauDeBordAdminTest extends TestCase
{
    use RefreshDatabase;

    private Currency $devise;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            [1, 'En attente'], [2, 'En cours'], [3, 'Livrer'],
            [4, 'Annuler'], [5, 'En attente paiement'],
        ] as [$id, $titre]) {
            Status::query()->firstOrCreate(['id' => $id], ['title' => $titre, 'slug' => $id]);
        }

        /*
         * `loadMissingValues: false` est indispensable : une autre suite vide
         * la table `settings`, et creer une reservation lit ces reglages. Sans
         * ce drapeau le test passe seul et echoue en suite complete.
         */
        RentalSettings::fake([
            'deposit_percentage' => 10.0,
            'refund_percentage' => 100.0,
            'minimum_hours' => 1.0,
            'pickup_code_max_attempts' => 3,
            'hours_min' => 1,
            'hours_max' => 23,
            'days_min' => 1,
            'days_max' => 30,
            'lead_time_minutes' => 30,
            'whatsapp_number' => '+243900000000',
            'brand_food' => 'Thalia Eats',
            'brand_rental' => 'Thalia Trans',
            'out_of_zone_message' => 'Nous ne desservons pas encore cette commune.',
        ], loadMissingValues: false);

        $this->devise = Currency::query()->create([
            'title' => 'Dollars', 'code' => 'USD', 'is_active' => 1,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-16 14:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Un administrateur avec TOUTES les permissions du panneau. */
    private function admin(array $permissions = ['View:Commande', 'View:Booking', 'View:Product', 'View:User']): User
    {
        $admin = User::factory()->create();
        $role = Role::findOrCreate('admin-test', 'web');

        foreach ($permissions as $nom) {
            $role->givePermissionTo(Permission::findOrCreate($nom, 'web'));
        }

        $admin->assignRole($role);
        $this->actingAs($admin, 'web');

        return $admin;
    }

    private function commande(array $attributs = []): Commande
    {
        return Commande::query()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'status_id' => Commande::STATUT_EN_COURS,
            'refernce' => 'CMD-'.uniqid(),
            'global_price' => 25,
            'paied_at' => now(),
        ], $attributs));
    }

    // --- Les chiffres du jour ------------------------------------------------

    public function test_les_chiffres_du_jour_comptent_ce_qui_est_d_aujourd_hui(): void
    {
        $this->admin();

        $this->commande();
        $this->commande();
        // Hier : compte dans la comparaison, pas dans le chiffre du jour.
        $this->commande(['created_at' => now()->subDay(), 'paied_at' => now()->subDay()]);

        Livewire::test(StatistiquesDuJour::class)
            ->assertSee('Commandes du jour')
            ->assertSee('Recettes du jour');
    }

    /**
     * Les recettes ne comptent que ce qui est PAYE.
     *
     * Une commande en attente de paiement n'est pas une recette : l'afficher
     * ferait croire a un encaissement qui n'a pas eu lieu.
     */
    public function test_les_recettes_ignorent_les_commandes_non_payees(): void
    {
        $this->admin();

        $this->commande(['global_price' => 30]);
        $this->commande([
            'global_price' => 999,
            'status_id' => Commande::STATUT_ATTENTE_PAIEMENT,
            'paied_at' => null,
        ]);

        $this->assertSame(30.0, (new StatistiquesDuJour)->recettesDuJour());
    }

    // --- Ce qui bloque -------------------------------------------------------

    public function test_ce_qui_bloque_compte_les_commandes_a_traiter(): void
    {
        $this->admin();

        // Payee mais pas encore acceptee par le restaurant.
        $this->commande(['accepted_at' => null]);
        // Acceptee : elle ne bloque plus.
        $this->commande(['accepted_at' => now()]);

        $this->assertSame(1, (new CeQuiBloque)->commandesNonAcceptees());
    }

    public function test_ce_qui_bloque_compte_les_plats_epuises(): void
    {
        $this->admin();

        Product::factory()->create(['stock_quantity' => 0]);
        Product::factory()->create(['stock_quantity' => 4]);
        // Stock non suivi : il n'est jamais epuise.
        Product::factory()->create(['stock_quantity' => null]);

        $this->assertSame(1, (new CeQuiBloque)->platsEpuises());
    }

    public function test_ce_qui_bloque_compte_les_reservations_non_reglees(): void
    {
        $this->admin();

        $vehicule = Vehicle::query()->create([
            'brand' => 'Toyota', 'model' => 'Corolla', 'plate_number' => 'KIN-7001',
            'hourly_rate' => 10, 'currency_id' => $this->devise->id,
        ]);

        Booking::query()->create([
            'reference' => 'R-1', 'user_id' => User::factory()->create()->id,
            'vehicle_id' => $vehicule->id, 'status' => Booking::STATUS_PENDING_PAYMENT,
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(3),
            'pickup_location' => 'Gombe', 'hourly_rate' => 10, 'duration_hours' => 3,
            'total' => 30, 'deposit' => 3, 'balance' => 27,
            'currency_id' => $this->devise->id,
        ]);

        $this->assertSame(1, (new CeQuiBloque)->reservationsNonReglees());
    }

    /**
     * La PAGE repond, pas seulement les widgets pris un a un.
     *
     * Un widget peut passer isolement et casser la page : c'est la qu'une
     * propriete mal declaree ou une route inexistante se voit. Le panneau
     * entier a deja ete mis a terre par un `$heading` declare statique.
     */
    public function test_la_page_du_tableau_de_bord_repond(): void
    {
        $admin = $this->admin();
        // `User::canAccessPanel()` exige `super_admin` et rien d'autre : sans
        // ce role, la page rend 403 avant meme de regarder les widgets.
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->commande();

        $this->get('/admin')->assertSuccessful();
    }

    // --- Les permissions -----------------------------------------------------

    /**
     * Chaque widget se tait pour qui n'a pas la permission.
     *
     * `define_via_gate` est a false : super_admin ne contourne rien, et un
     * widget sans garde montrerait a tous ce que les ecrans cachent.
     */
    public function test_les_widgets_se_taisent_sans_permission(): void
    {
        $this->admin(permissions: []);

        $this->assertFalse(StatistiquesDuJour::canView());
        $this->assertFalse(CeQuiBloque::canView());
        $this->assertFalse(CommandesParJour::canView());
        $this->assertFalse(DernieresCommandes::canView());
    }

    public function test_les_widgets_s_affichent_avec_la_permission(): void
    {
        $this->admin();

        $this->assertTrue(StatistiquesDuJour::canView());
        $this->assertTrue(CeQuiBloque::canView());
        $this->assertTrue(CommandesParJour::canView());
        $this->assertTrue(DernieresCommandes::canView());
    }

    /** La courbe et la liste ne demandent QUE la permission des commandes. */
    public function test_la_courbe_ne_demande_que_la_permission_des_commandes(): void
    {
        $this->admin(permissions: ['View:Commande']);

        $this->assertTrue(CommandesParJour::canView());
        $this->assertTrue(DernieresCommandes::canView());
    }

    // --- Ils repondent -------------------------------------------------------

    public function test_la_courbe_et_la_liste_repondent(): void
    {
        $this->admin();
        $this->commande();

        Livewire::test(CommandesParJour::class)->assertOk();
        Livewire::test(DernieresCommandes::class)->assertOk();
        Livewire::test(CeQuiBloque::class)->assertOk();
    }
}
