<?php

namespace Tests\Feature\Portee;

use App\Filament\Pages\Parametres;
use App\Models\User;
use App\Settings\ExpirationSettings;
use App\Settings\FoodSettings;
use App\Settings\ProspectionSettings;
use App\Settings\RentalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * L'ecran unique des reglages.
 *
 * Quatre pages separees ont fusionne en une page a onglets, et deux classes
 * de reglages qui n'avaient aucun ecran y sont enfin exposees.
 *
 * Le risque propre a cette forme : une page qui ecrit cinq classes d'un coup
 * peut en perdre quatre si on n'y prend pas garde. C'est ce que verifie
 * `test_enregistrer_un_onglet_ne_perd_pas_les_autres`.
 */
class AdminReglagesLivraisonTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les valeurs de depart, groupe par groupe.
     *
     * De vraies lignes, pas des reglages truques : la page lit les reglages
     * reels au montage, et une autre suite vide la table `settings`.
     * `FoodSettings::fake()` passerait seul puis echouerait en suite complete.
     *
     * @var array<string, array<string, mixed>>
     */
    private const DEPART = [
        'food' => [
            'max_delivery_radius_km' => 30.0,
            'out_of_radius_message' => ':restaurant est à :distance km.',
            'use_client_position' => false,
            'city_first' => false,
            'stock_actif' => true,
        ],
        'rental' => [
            'deposit_percentage' => 10.0,
            'refund_percentage' => 100.0,
            'minimum_hours' => 1.0,
            'pickup_code_max_attempts' => 3,
            'whatsapp_number' => '+243900000000',
            'brand_food' => 'Thalia Eats',
            'brand_rental' => 'Thalia Trans',
            'out_of_zone_message' => 'Commune non desservie.',
            'hours_min' => 1,
            'hours_max' => 23,
            'days_min' => 1,
            'days_max' => 30,
            'lead_time_minutes' => 30,
        ],
        'expiration' => ['minutes' => 15],
        'suivi' => ['interval_minutes' => 1, 'stale_minutes' => 5, 'retention_days' => 30],
        'prospection' => ['email_destinataire' => 'partenaires@thalia.test'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::DEPART as $groupe => $valeurs) {
            foreach ($valeurs as $nom => $valeur) {
                DB::table('settings')->updateOrInsert(
                    ['group' => $groupe, 'name' => $nom],
                    ['payload' => json_encode($valeur), 'locked' => false],
                );
            }
        }

        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        Gate::before(fn () => true);
        $this->actingAs($admin);
    }

    /** @param array<string, mixed> $modifications */
    private function enregistrer(array $modifications): Testable
    {
        $donnees = [];

        foreach (self::DEPART as $groupe => $valeurs) {
            foreach ($valeurs as $nom => $valeur) {
                $donnees["{$groupe}.{$nom}"] = $valeur;
            }
        }

        return Livewire::test(Parametres::class)
            ->fillForm(array_merge($donnees, $modifications))
            ->call('save');
    }

    public function test_l_option_de_position_s_enregistre(): void
    {
        $this->enregistrer(['food.use_client_position' => true])->assertHasNoFormErrors();

        $this->assertTrue(app(FoodSettings::class)->use_client_position);
    }

    public function test_le_rayon_global_et_le_message_sont_modifiables(): void
    {
        $this->enregistrer([
            'food.max_delivery_radius_km' => 18,
            'food.out_of_radius_message' => 'Trop loin de chez vous.',
        ])->assertHasNoFormErrors();

        $reglages = app(FoodSettings::class);

        $this->assertSame(18.0, $reglages->max_delivery_radius_km);
        $this->assertSame('Trop loin de chez vous.', $reglages->out_of_radius_message);
    }

    /** Zero fermerait toute la plateforme sur une faute de frappe. */
    public function test_un_rayon_a_zero_est_refuse(): void
    {
        $this->enregistrer(['food.max_delivery_radius_km' => 0])
            ->assertHasFormErrors(['food.max_delivery_radius_km']);
    }

    /**
     * Le risque propre a une page qui ecrit cinq classes d'un coup.
     *
     * Changer le rayon de livraison ne doit toucher ni l'acompte de la
     * location, ni le delai d'expiration, ni le reste.
     */
    public function test_enregistrer_un_onglet_ne_perd_pas_les_autres(): void
    {
        $this->enregistrer(['food.max_delivery_radius_km' => 25])->assertHasNoFormErrors();

        $this->assertSame(25.0, app(FoodSettings::class)->max_delivery_radius_km);
        $this->assertSame(10.0, app(RentalSettings::class)->deposit_percentage);
        $this->assertSame('Thalia Trans', app(RentalSettings::class)->brand_rental);
        $this->assertSame(15, app(ExpirationSettings::class)->minutes);
    }

    /** Les reglages de la location restent modifiables depuis leur onglet. */
    public function test_l_onglet_location_enregistre_ses_valeurs(): void
    {
        $this->enregistrer(['rental.deposit_percentage' => 25])->assertHasNoFormErrors();

        $this->assertSame(25.0, app(RentalSettings::class)->deposit_percentage);
    }

    /**
     * La validation croisee de la location survit au demenagement.
     *
     * `hours_max` ne peut pas descendre sous `hours_min`, et cette regle
     * s'exprime par `$get('hours_min')` : elle depend du chemin d'etat, que
     * l'onglet porte desormais.
     */
    public function test_la_duree_maximale_ne_peut_pas_passer_sous_la_minimale(): void
    {
        $this->enregistrer(['rental.hours_min' => 10, 'rental.hours_max' => 4])
            ->assertHasFormErrors(['rental.hours_max']);
    }

    /** La priorite donnee a la ville se regle depuis l'ecran. */
    public function test_la_priorite_a_la_ville_s_enregistre(): void
    {
        $this->enregistrer(['food.city_first' => true])->assertHasNoFormErrors();

        $this->assertTrue(app(FoodSettings::class)->city_first);
    }

    /**
     * Une adresse de prospection vide ne doit rien bloquer.
     *
     * Elle n'avait jamais eu d'ecran, donc elle est vide chez tout le monde.
     * La rendre obligatoire empechait d'enregistrer les quatre autres onglets
     * — signale par le proprietaire des la premiere ouverture.
     */
    public function test_une_adresse_de_prospection_vide_n_empeche_pas_d_enregistrer(): void
    {
        $this->enregistrer([
            'prospection.email_destinataire' => '',
            'food.max_delivery_radius_km' => 22,
        ])->assertHasNoFormErrors();

        $this->assertSame(22.0, app(FoodSettings::class)->max_delivery_radius_km);
    }

    /** L'adresse de prospection n'avait jamais eu d'ecran. */
    public function test_l_onglet_prospection_enregistre_son_adresse(): void
    {
        $this->enregistrer(['prospection.email_destinataire' => 'contact@thalia.test'])
            ->assertHasNoFormErrors();

        $this->assertSame(
            'contact@thalia.test',
            app(ProspectionSettings::class)->email_destinataire,
        );
    }
}
