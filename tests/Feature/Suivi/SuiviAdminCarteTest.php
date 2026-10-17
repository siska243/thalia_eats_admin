<?php

namespace Tests\Feature\Suivi;

use App\Filament\Pages\SuiviDesCourses;
use App\Filament\Resources\BookingResource\Pages\ListBookings;
use App\Filament\Resources\CommandeResource\Pages\ListCommandes;
use App\Models\Booking;
use App\Models\Chauffeur;
use App\Models\Commande;
use App\Models\Currency;
use App\Models\Status;
use App\Models\Town;
use App\Models\TrackOrder;
use App\Models\User;
use App\Models\Vehicle;
use App\Settings\RentalSettings;
use App\Settings\SuiviSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * L'ecran d'administration du suivi.
 *
 * L'export n'est pas un confort : c'est la condition posee a la purge a
 * quatre-vingt-dix jours. Purger ce qu'on voulait analyser serait un
 * contresens, donc l'export doit produire un fichier.
 */
class SuiviAdminCarteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Booking $booking;

    private Commande $commande;

    protected function setUp(): void
    {
        parent::setUp();

        SuiviSettings::fake([
            'interval_minutes' => 5,
            'stale_minutes' => 15,
            'retention_days' => 90,
        ], loadMissingValues: false);

        // Le crochet `saving` de `Booking` lit les bornes de duree : la liste
        // doit etre complete, une autre suite vide la table `settings`.
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
            'whatsapp_number' => '',
            'brand_food' => 'Thalia Eats',
            'brand_rental' => 'Thalia Trans',
            'out_of_zone_message' => 'Nous ne desservons pas encore cette commune.',
        ], loadMissingValues: false);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::findOrCreate('super_admin', 'web'));

        $currency = Currency::query()->create([
            'title' => 'Dollars', 'code' => 'USD', 'is_active' => 1,
        ]);

        $vehicle = Vehicle::query()->create([
            'brand' => 'Toyota', 'model' => 'Land Cruiser',
            'plate_number' => 'KIN-0001', 'hourly_rate' => 10,
            'currency_id' => $currency->id,
        ]);

        $chauffeurUser = User::factory()->create();
        $chauffeur = Chauffeur::query()->create([
            'user_id' => $chauffeurUser->id,
            'full_name' => 'Papy Kabila',
            'phone' => '+243900000001',
            'is_active' => true,
        ]);

        $this->booking = Booking::query()->create([
            'reference' => 'R-SUIVI0001',
            'user_id' => User::factory()->create()->id,
            'vehicle_id' => $vehicle->id,
            'chauffeur_id' => $chauffeur->id,
            'starts_at' => now()->addHours(2),
            'ends_at' => now()->addHours(4),
            'pickup_location' => 'Gombe',
            'status' => Booking::STATUS_CONFIRMED,
            'hourly_rate' => 10, 'duration_hours' => 2,
            'total' => 20, 'deposit' => 2, 'balance' => 18,
            'currency_id' => $currency->id,
        ]);

        Status::query()->firstOrCreate(['id' => 2], ['title' => 'En cours', 'slug' => 'en-cours']);

        $this->commande = Commande::query()->create([
            'user_id' => User::factory()->create()->id,
            'status_id' => 2,
            'town_id' => Town::factory()->create()->id,
            'refernce' => 'CMD-SUIVI-1',
            'global_price' => 10,
        ]);

        // Deux relevés par course : une distance et une duree se mesurent
        // entre deux points, pas sur un seul.
        $this->releve(Booking::class, $this->booking->id, -4.3276, 15.3136, 10);
        $this->releve(Booking::class, $this->booking->id, -4.3190, 15.3080, 5);
        $this->releve(Commande::class, $this->commande->id, -4.3300, 15.3200, 8);
        $this->releve(Commande::class, $this->commande->id, -4.3250, 15.3150, 3);
    }

    private function releve(string $classe, int $id, float $lat, float $lng, int $ilYAMinutes): void
    {
        TrackOrder::query()->create([
            'suivable_type' => $classe,
            'suivable_id' => $id,
            'location_delivery' => ['lat' => $lat, 'lng' => $lng],
            'created_at' => now()->subMinutes($ilYAMinutes),
        ]);
    }

    public function test_la_page_s_ouvre_pour_un_administrateur(): void
    {
        Gate::before(fn () => true);

        $this->actingAs($this->admin)
            ->get(SuiviDesCourses::getUrl())
            ->assertSuccessful();
    }

    public function test_un_utilisateur_sans_droit_n_y_accede_pas(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(SuiviDesCourses::getUrl())
            ->assertForbidden();
    }

    public function test_les_deux_natures_de_course_sont_listees(): void
    {
        Gate::before(fn () => true);
        $this->actingAs($this->admin);

        $courses = Livewire::test(SuiviDesCourses::class)->instance()->courses;

        $this->assertSame(
            ['location', 'repas'],
            collect($courses)->pluck('type')->sort()->values()->all(),
        );
        $this->assertContains('R-SUIVI0001', collect($courses)->pluck('reference')->all());
        $this->assertContains('CMD-SUIVI-1', collect($courses)->pluck('reference')->all());
    }

    public function test_le_trace_porte_sa_duree_et_sa_distance(): void
    {
        Gate::before(fn () => true);
        $this->actingAs($this->admin);

        $composant = Livewire::test(SuiviDesCourses::class)
            ->set('course', 'location:'.$this->booking->id);

        $trace = $composant->get('trace');

        $this->assertCount(2, $trace['points']);
        $this->assertSame(5, $trace['duree_minutes']);
        // Environ un kilometre entre les deux points de Gombe.
        $this->assertGreaterThan(0.5, $trace['distance_km']);
        $this->assertLessThan(2.0, $trace['distance_km']);
        // La course est payee et pas finie : la carte se rafraichit.
        $this->assertTrue($trace['en_cours']);
    }

    public function test_le_filtre_par_type_ne_garde_que_la_location(): void
    {
        Gate::before(fn () => true);
        $this->actingAs($this->admin);

        $courses = Livewire::test(SuiviDesCourses::class)
            ->set('type', 'location')
            ->instance()
            ->courses;

        $this->assertSame(['location'], collect($courses)->pluck('type')->unique()->values()->all());
    }

    public function test_l_export_produit_un_fichier(): void
    {
        Gate::before(fn () => true);
        $this->actingAs($this->admin);

        $page = Livewire::test(SuiviDesCourses::class)->instance();

        $reponse = $page->exporter();

        $this->assertInstanceOf(StreamedResponse::class, $reponse);
        $this->assertStringContainsString(
            'spreadsheetml',
            $reponse->headers->get('Content-Type'),
        );

        ob_start();
        $reponse->sendContent();
        $contenu = ob_get_clean();

        // Un .xlsx est une archive zip : la signature suffit a dire qu'un
        // vrai classeur est sorti, et non une page d'erreur.
        $this->assertStringStartsWith('PK', $contenu);
        $this->assertGreaterThan(1000, strlen($contenu));
    }

    /**
     * Le chemin normal : le bouton vit sur la ligne de la course.
     *
     * L'administrateur a deja la reservation sous les yeux ; lui redemander
     * de la retrouver dans un annuaire etait precisement le reproche.
     */
    public function test_le_bouton_suivre_est_actif_sur_une_reservation_relevee(): void
    {
        Gate::before(fn () => true);
        $this->actingAs($this->admin);

        Livewire::test(ListBookings::class)
            ->assertTableActionVisible('suivre', $this->booking)
            ->assertTableActionEnabled('suivre', $this->booking);
    }

    public function test_le_bouton_suivre_est_desactive_sur_une_reservation_sans_releve(): void
    {
        Gate::before(fn () => true);
        $this->actingAs($this->admin);

        $sansReleve = $this->booking->replicate(['reference']);
        $sansReleve->reference = 'R-SUIVI0002';
        $sansReleve->save();

        Livewire::test(ListBookings::class)
            ->assertTableActionVisible('suivre', $sansReleve)
            ->assertTableActionDisabled('suivre', $sansReleve);
    }

    public function test_le_bouton_suivre_est_actif_sur_une_commande_relevee(): void
    {
        Gate::before(fn () => true);
        $this->actingAs($this->admin);

        Livewire::test(ListCommandes::class)
            ->assertTableActionVisible('suivre', $this->commande)
            ->assertTableActionEnabled('suivre', $this->commande);
    }

    public function test_le_bouton_suivre_est_desactive_sur_une_commande_sans_releve(): void
    {
        Gate::before(fn () => true);
        $this->actingAs($this->admin);

        $sansReleve = Commande::query()->create([
            'user_id' => User::factory()->create()->id,
            'status_id' => 2,
            'town_id' => Town::factory()->create()->id,
            'refernce' => 'CMD-SUIVI-2',
            'global_price' => 10,
        ]);

        Livewire::test(ListCommandes::class)
            ->assertTableActionVisible('suivre', $sansReleve)
            ->assertTableActionDisabled('suivre', $sansReleve);
    }

    /** Le lien du bouton pointe bien sur cette course-la. */
    public function test_le_bouton_suivre_pointe_sur_la_course(): void
    {
        $this->assertStringContainsString(
            'course=location%3A'.$this->booking->id,
            SuiviDesCourses::urlPour($this->booking),
        );

        $this->assertStringContainsString(
            'course=repas%3A'.$this->commande->id,
            SuiviDesCourses::urlPour($this->commande),
        );
    }

    /**
     * Ouvert sur une course, l'ecran montre cette course et rien d'autre :
     * sa reference, son client, son vehicule, son etat.
     */
    public function test_le_suivi_d_une_course_montre_cette_course(): void
    {
        Gate::before(fn () => true);

        $this->actingAs($this->admin)
            ->get(SuiviDesCourses::urlPour($this->booking))
            ->assertSuccessful()
            ->assertSee('R-SUIVI0001')
            ->assertSee('Toyota', escape: false)
            ->assertSee('Trajet')
            // La liste globale n'est plus ce qu'on voit en premier.
            ->assertDontSee('CMD-SUIVI-1');
    }

    public function test_le_suivi_d_une_commande_montre_cette_commande(): void
    {
        Gate::before(fn () => true);

        $this->actingAs($this->admin)
            ->get(SuiviDesCourses::urlPour($this->commande))
            ->assertSuccessful()
            ->assertSee('CMD-SUIVI-1')
            ->assertDontSee('R-SUIVI0001');
    }

    /** La vue d'ensemble reste atteignable, avec son export. */
    public function test_la_vue_d_ensemble_reste_atteignable(): void
    {
        Gate::before(fn () => true);

        $this->actingAs($this->admin)
            ->get(SuiviDesCourses::getUrl())
            ->assertSuccessful()
            ->assertSee('Exporter en tableur')
            ->assertSee('R-SUIVI0001')
            ->assertSee('CMD-SUIVI-1');
    }

    public function test_l_export_suit_le_filtre_de_periode(): void
    {
        Gate::before(fn () => true);
        $this->actingAs($this->admin);

        $page = Livewire::test(SuiviDesCourses::class)
            ->set('du', now()->addDay()->toDateString())
            ->set('au', now()->addDays(2)->toDateString())
            ->instance();

        $this->assertSame([], $page->courses);

        // Le fichier sort quand meme, en-tetes seuls : un export vide se
        // lit, une erreur ne se lit pas.
        ob_start();
        $page->exporter()->sendContent();
        $contenu = ob_get_clean();

        $this->assertStringStartsWith('PK', $contenu);
    }

    public function test_le_trace_porte_la_position_du_chauffeur_et_celle_du_client(): void
    {
        Gate::before(fn () => true);
        $this->actingAs($this->admin);

        $this->booking->update([
            'pickup_lat' => -4.3190,
            'pickup_lng' => 15.3080,
            'pickup_location' => 'Gombe, avenue du Commerce',
        ]);

        $trace = Livewire::test(SuiviDesCourses::class)
            ->set('course', 'location:'.$this->booking->id)
            ->get('trace');

        // Le chauffeur est le dernier releve : c'est la position qui compte.
        $this->assertSame(end($trace['points'])['lat'], $trace['chauffeur']['lat']);
        $this->assertSame(end($trace['points'])['lng'], $trace['chauffeur']['lng']);

        $this->assertEqualsWithDelta(-4.3190, $trace['client']['lat'], 0.0001);
        $this->assertEqualsWithDelta(15.3080, $trace['client']['lng'], 0.0001);
        $this->assertSame('Gombe, avenue du Commerce', $trace['client']['libelle']);
    }

    public function test_sans_coordonnees_de_prise_en_charge_aucun_repere_client(): void
    {
        Gate::before(fn () => true);
        $this->actingAs($this->admin);

        $this->booking->update(['pickup_lat' => null, 'pickup_lng' => null]);

        $trace = Livewire::test(SuiviDesCourses::class)
            ->set('course', 'location:'.$this->booking->id)
            ->get('trace');

        // Pas de repere plutot qu'un repere faux.
        $this->assertNull($trace['client']);
        $this->assertNotNull($trace['chauffeur']);
    }

    /**
     * T126 — la vue d'ensemble annonce ses chiffres.
     *
     * Un ecran d'analyse qui n'affiche aucun total laisse son lecteur
     * additionner les lignes a l'oeil.
     */
    public function test_la_vue_d_ensemble_resume_la_periode(): void
    {
        Gate::before(fn () => true);
        $this->actingAs($this->admin);

        $resume = Livewire::test(SuiviDesCourses::class)->instance()->resume;

        // Deux courses, quatre releves, deux de chaque nature.
        $this->assertSame(2, $resume['courses']);
        $this->assertSame(4, $resume['releves']);
        $this->assertSame(2, $resume['repas']);
        $this->assertSame(2, $resume['location']);
    }

    /**
     * Ce qui roule MAINTENANT est la premiere chose qu'on vient chercher
     * ici, et ca n'apparaissait qu'une fois la course ouverte.
     */
    public function test_la_liste_dit_quelles_courses_roulent_encore(): void
    {
        Gate::before(fn () => true);
        $this->actingAs($this->admin);

        $courses = collect(Livewire::test(SuiviDesCourses::class)->instance()->courses)
            ->keyBy('type');

        // La reservation est confirmee et la commande au statut 2 : les deux
        // sont dans leur fenetre de suivi.
        $this->assertTrue($courses['location']['en_cours']);
        $this->assertTrue($courses['repas']['en_cours']);

        // Une course close sort du direct, sans disparaitre de l'historique.
        $this->booking->update(['status' => Booking::STATUS_COMPLETED]);

        $apres = collect(Livewire::test(SuiviDesCourses::class)->instance()->courses)
            ->keyBy('type');

        $this->assertFalse($apres['location']['en_cours']);
        $this->assertSame(1, Livewire::test(SuiviDesCourses::class)->instance()->resume['en_cours']);
    }

    /** La duree se lit ; un nombre de releves se traduit mentalement. */
    public function test_chaque_ligne_porte_la_duree_du_suivi(): void
    {
        Gate::before(fn () => true);
        $this->actingAs($this->admin);

        $courses = collect(Livewire::test(SuiviDesCourses::class)->instance()->courses)
            ->keyBy('type');

        // Les deux releves de la location sont espaces de cinq minutes.
        $this->assertSame(5, $courses['location']['duree_minutes']);
    }

    /** Les raccourcis de periode posent les deux dates, et se reconnaissent. */
    public function test_les_raccourcis_de_periode(): void
    {
        Gate::before(fn () => true);
        $this->actingAs($this->admin);

        $composant = Livewire::test(SuiviDesCourses::class);

        // Sept jours : la valeur posee au montage, donc deja reconnue.
        $this->assertSame('semaine', $composant->instance()->raccourciActif());

        $composant->call('periode', 'jour');
        $this->assertSame(now()->toDateString(), $composant->get('du'));
        $this->assertSame('jour', $composant->instance()->raccourciActif());

        // Une periode composee a la main ne correspond a aucun raccourci :
        // aucun bouton ne doit alors s'allumer.
        $composant->set('du', now()->subDays(3)->toDateString());
        $this->assertNull($composant->instance()->raccourciActif());
    }

    /** La page rend sans erreur avec sa nouvelle mise en page. */
    public function test_la_vue_d_ensemble_s_affiche_en_entier(): void
    {
        Gate::before(fn () => true);

        $this->actingAs($this->admin)
            ->get(SuiviDesCourses::getUrl())
            ->assertSuccessful()
            ->assertSee('Courses suivies')
            ->assertSee('Courses en direct')
            ->assertSee("Aujourd'hui");
    }
}
