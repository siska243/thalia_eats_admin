<?php

namespace Tests\Feature\Rental;

use App\Filament\Resources\CourseTypeResource\Pages\CreateCourseType;
use App\Filament\Resources\VehicleResource\Pages\EditVehicle;
use App\Filament\Resources\VehicleResource\RelationManagers\CoursePricesRelationManager;
use App\Models\Booking;
use App\Models\CourseType;
use App\Models\Currency;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Rental\BookingPricing;
use App\Settings\RentalSettings;
use App\Wrappers\Cipher;
use Database\Seeders\CourseTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Les types de course : un referentiel, et des prix par vehicule.
 *
 * Le proprietaire, le 16 octobre : « le type c'est un referentiel. Tu mets le
 * prix par defaut comme il est maintenant et ensuite tu ajoutes le prix en
 * fonction de la demande de l'utilisateur en fonction du type (par heure,
 * jour) ».
 *
 * Deux consequences, et ce sont elles qui commandent tout le reste :
 *
 * 1. Les regles de chaque type vivent SUR LE TYPE — duree minimale, mode
 *    impose, commentaire obligatoire, option parking. Ajouter un quatrieme
 *    type ne demande alors aucun code. Meme doctrine que les statuts de
 *    commande, deja editables en administration.
 * 2. Un vehicule sans prix saisi pour un type retombe sur SON PRIX PAR
 *    DEFAUT. Le parc existant continue donc de fonctionner sans aucune
 *    saisie, et on ne renseigne que ce qui differe.
 */
class TypeCourseTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $vehicle;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * `loadMissingValues: false` est indispensable : une autre suite vide
         * la table `settings`, et sans ce drapeau le test passe seul mais
         * echoue dans la suite complete.
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

        $this->currency = Currency::query()->create([
            'title' => 'Dollars', 'code' => 'USD', 'is_active' => 1,
        ]);

        // Berline de l'exemple du proprietaire : 150 $ la journee.
        $this->vehicle = Vehicle::query()->create([
            'brand' => 'Toyota', 'model' => 'Corolla', 'plate_number' => 'KIN-9001',
            'hourly_rate' => 20, 'daily_rate' => 150,
            'currency_id' => $this->currency->id,
        ]);
    }

    private function admin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        Gate::before(fn () => true);
        $this->actingAs($admin);
    }

    private function prix(): BookingPricing
    {
        return app(BookingPricing::class);
    }

    // --- Le referentiel ------------------------------------------------------

    /** Les trois types d'origine sont semes, et le seeder se rejoue sans doubler. */
    public function test_le_seeder_pose_les_trois_types_et_reste_idempotent(): void
    {
        (new CourseTypeSeeder)->run();
        (new CourseTypeSeeder)->run();

        $this->assertSame(3, CourseType::query()->count());
        $this->assertSame(
            ['A', 'B', 'C'],
            CourseType::query()->orderBy('position')->pluck('code')->all(),
        );
    }

    /**
     * Les trois types arrivent par la MIGRATION, pas par le seeder.
     *
     * `RefreshDatabase` ne joue que les migrations — exactement comme le
     * deploiement, qui ne lance QUE `migrate --force` et jamais `db:seed`.
     * Ce test est donc la garantie que le referentiel ne sera pas vide en
     * production : sans lui, tout le choix de type de course y serait
     * invisible alors que le code est livre.
     */
    public function test_les_types_arrivent_par_la_migration(): void
    {
        /*
         * La MIGRATION est rejouee ici, sur une table vidée.
         *
         * Elle n'est pas observee a travers l'etat partage de la base :
         * plusieurs suites appellent `truncateTablesForAllConnections()`, dont
         * les ecritures sont COMMITEES et survivent au rollback. Un test qui
         * constaterait seulement « la table est peuplee » passerait seul et
         * echouerait en suite complete, sans qu'aucun defaut n'existe.
         *
         * Ce qui est verifie : CETTE migration peuple le referentiel. Et rien
         * de plus — les codes sont DYNAMIQUES, l'administration renomme,
         * desactive ou supprime ce qu'elle veut, c'est tout l'objet d'un
         * referentiel. Epingler « A, B, C » figerait dans un test ce que
         * l'ecran a le droit de changer.
         *
         * Le risque de production est celui-la et aucun autre : un referentiel
         * vide, parce que le deploiement ne lance QUE `migrate --force` et
         * jamais `db:seed`. Les applications n'auraient alors aucun type a
         * proposer, alors que le code est livre et teste.
         */
        CourseType::query()->delete();

        $migration = require base_path('database/migrations/2026_10_16_220000_seed_course_types.php');
        $migration->up();

        $this->assertNotEmpty(
            CourseType::query()->actifs()->get(),
            'La migration doit peupler le referentiel : le deploiement ne seme pas.',
        );
    }

    /** Le type B impose la journee et 24 h minimum, et c'est lui qui le dit. */
    public function test_le_type_b_porte_ses_propres_regles(): void
    {
        (new CourseTypeSeeder)->run();

        $b = CourseType::query()->where('code', 'B')->first();

        $this->assertSame(BookingPricing::MODE_DAILY, $b->forced_mode);
        $this->assertSame(24, $b->minimum_hours);
        $this->assertTrue($b->note_required);
        $this->assertFalse($b->parking_option);
    }

    /** Le type C porte l'option parking et son tarif horaire. */
    public function test_le_type_c_porte_l_option_parking(): void
    {
        (new CourseTypeSeeder)->run();

        $c = CourseType::query()->where('code', 'C')->first();

        $this->assertTrue($c->parking_option);
        $this->assertSame(10.0, (float) $c->parking_hourly_fee);
        $this->assertTrue($c->note_required);
    }

    // --- Le prix par vehicule et par type ------------------------------------

    /** Sans prix saisi, le prix par defaut du vehicule s'applique. */
    public function test_sans_prix_saisi_le_vehicule_garde_son_tarif(): void
    {
        $b = CourseType::query()->updateOrCreate(
            ['code' => 'B'],
            ['title' => 'Hors agglomeration',
                'forced_mode' => BookingPricing::MODE_DAILY, 'minimum_hours' => 24,
            ]);

        $devis = $this->prix()->quote(
            $this->vehicle,
            Carbon::parse('+2 days 08:00'),
            Carbon::parse('+3 days 08:00'),
            BookingPricing::MODE_DAILY,
            $b,
        );

        $this->assertSame(150.0, $devis->unitPrice);
        $this->assertSame(150.0, $devis->total);
    }

    /** Un prix saisi pour le type remplace le tarif par defaut. */
    public function test_le_prix_du_type_remplace_le_tarif_par_defaut(): void
    {
        $b = CourseType::query()->updateOrCreate(
            ['code' => 'B'],
            ['title' => 'Hors agglomeration',
                'forced_mode' => BookingPricing::MODE_DAILY, 'minimum_hours' => 24,
            ]);

        // 225 $ la journee : l'exemple du proprietaire, saisi et non calcule.
        $this->vehicle->coursePrices()->create(['course_type_id' => $b->id, 'hourly_rate' => 30, 'daily_rate' => 225]);

        $devis = $this->prix()->quote(
            $this->vehicle->fresh(),
            Carbon::parse('+2 days 08:00'),
            Carbon::parse('+3 days 08:00'),
            BookingPricing::MODE_DAILY,
            $b,
        );

        $this->assertSame(225.0, $devis->unitPrice);
        $this->assertSame(225.0, $devis->total);
    }

    /** Le tarif horaire du type sert quand la course se compte en heures. */
    public function test_le_prix_horaire_du_type_s_applique_a_l_heure(): void
    {
        $c = CourseType::query()->updateOrCreate(['code' => 'C'], ['title' => 'Aeroport']);

        $this->vehicle->coursePrices()->create(['course_type_id' => $c->id, 'hourly_rate' => 35, 'daily_rate' => null]);

        $devis = $this->prix()->quote(
            $this->vehicle->fresh(),
            Carbon::parse('+2 days 10:00'),
            Carbon::parse('+2 days 13:00'),
            BookingPricing::MODE_HOURLY,
            $c,
        );

        $this->assertSame(35.0, $devis->unitPrice);
        $this->assertSame(105.0, $devis->total);
    }

    /** Sans type de course, rien ne change : c'est le cas des applications installees. */
    public function test_sans_type_le_devis_est_celui_d_avant(): void
    {
        $devis = $this->prix()->quote(
            $this->vehicle,
            Carbon::parse('+2 days 10:00'),
            Carbon::parse('+2 days 13:00'),
            BookingPricing::MODE_HOURLY,
        );

        $this->assertSame(20.0, $devis->unitPrice);
        $this->assertSame(60.0, $devis->total);
        $this->assertSame(0.0, $devis->parkingFee);
    }

    // --- L'option parking ----------------------------------------------------

    /** Cochee, elle ajoute son tarif horaire sur les heures facturees. */
    public function test_le_parking_aeroport_s_ajoute_au_total(): void
    {
        $c = CourseType::query()->updateOrCreate(
            ['code' => 'C'],
            ['title' => 'Aeroport',
                'parking_option' => true, 'parking_hourly_fee' => 10,
            ]);

        $devis = $this->prix()->quote(
            $this->vehicle,
            Carbon::parse('+2 days 10:00'),
            Carbon::parse('+2 days 13:00'),
            BookingPricing::MODE_HOURLY,
            $c,
            parking: true,
        );

        $this->assertSame(60.0, $devis->base);
        $this->assertSame(30.0, $devis->parkingFee);
        $this->assertSame(90.0, $devis->total);
    }

    /** Non cochee, le chauffeur attend dehors et rien ne s'ajoute. */
    public function test_sans_le_parking_rien_ne_s_ajoute(): void
    {
        $c = CourseType::query()->updateOrCreate(
            ['code' => 'C'],
            ['title' => 'Aeroport',
                'parking_option' => true, 'parking_hourly_fee' => 10,
            ]);

        $devis = $this->prix()->quote(
            $this->vehicle,
            Carbon::parse('+2 days 10:00'),
            Carbon::parse('+2 days 13:00'),
            BookingPricing::MODE_HOURLY,
            $c,
            parking: false,
        );

        $this->assertSame(0.0, $devis->parkingFee);
        $this->assertSame(60.0, $devis->total);
    }

    /** Un type sans option parking ne facture rien, meme si on la demande. */
    public function test_un_type_sans_option_parking_ne_facture_rien(): void
    {
        $a = CourseType::query()->updateOrCreate(['code' => 'A'], ['title' => 'Urbaine']);

        $devis = $this->prix()->quote(
            $this->vehicle,
            Carbon::parse('+2 days 10:00'),
            Carbon::parse('+2 days 13:00'),
            BookingPricing::MODE_HOURLY,
            $a,
            parking: true,
        );

        $this->assertSame(0.0, $devis->parkingFee);
    }

    /** Le referentiel s'edite en administration, regles comprises. */
    public function test_l_admin_cree_un_type_avec_ses_regles(): void
    {
        $this->admin();

        Livewire::test(CreateCourseType::class)
            ->fillForm([
                'code' => 'D',
                'title' => 'Longue distance',
                'position' => 4,
                'is_active' => true,
                'minimum_hours' => 48,
                'forced_mode' => BookingPricing::MODE_DAILY,
                'note_required' => true,
                'parking_option' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $type = CourseType::query()->where('code', 'D')->first();

        $this->assertSame(48, $type->minimum_hours);
        $this->assertSame(BookingPricing::MODE_DAILY, $type->forced_mode);
        $this->assertTrue($type->note_required);
        // L'option parking eteinte : aucun tarif ne doit rester en base.
        $this->assertNull($type->parking_hourly_fee);
    }

    // --- Les regles opposees a la reservation --------------------------------

    /** @param array<string, mixed> $extra */
    private function reserve(array $extra = []): TestResponse
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        return $this->postJson('/api/rental/bookings', array_merge([
            'vehicle' => Cipher::Encrypt($this->vehicle->id),
            'starts_at' => Carbon::parse('+2 days 10:00')->toDateTimeString(),
            'ends_at' => Carbon::parse('+2 days 15:00')->toDateTimeString(),
            'pickup_location' => 'Gombe',
        ], $extra));
    }

    /**
     * Le mode impose est REFUSE, pas bascule en silence.
     *
     * « Le client a choisi un mode et un prix, et un autre prix n'est pas le
     * sien » — la doctrine deja ecrite dans BookingCreator.
     */
    public function test_un_type_qui_impose_la_journee_refuse_l_heure(): void
    {
        $b = CourseType::query()->updateOrCreate(
            ['code' => 'B'],
            ['title' => 'Hors agglomeration',
                'forced_mode' => BookingPricing::MODE_DAILY, 'minimum_hours' => 24,
            ]);

        $this->reserve([
            'course_type_id' => $b->id,
            'pricing_mode' => BookingPricing::MODE_HOURLY,
            'course_note' => 'Depart pour Kisantu',
        ])
            ->assertStatus(400)
            ->assertJsonPath('error', 'mode_impose');
    }

    /** La duree minimale du type est opposee au creneau demande. */
    public function test_la_duree_minimale_du_type_est_opposee(): void
    {
        // `forced_mode` explicitement nul : le type seme l'impose, et c'est
        // ce refus-la qui repondrait au lieu de la duree qu'on veut prouver.
        $b = CourseType::query()->updateOrCreate(
            ['code' => 'B'],
            ['title' => 'Hors agglomeration', 'minimum_hours' => 24, 'forced_mode' => null],
        );

        $this->reserve([
            'course_type_id' => $b->id,
            'course_note' => 'Depart pour Kisantu',
        ])
            ->assertStatus(400)
            ->assertJsonPath('error', 'duree_minimale_type')
            ->assertJsonPath('message', fn (string $m) => str_contains($m, '24'));
    }

    /** Le commentaire exige par le type est exige a la saisie. */
    public function test_un_type_qui_exige_un_commentaire_le_reclame(): void
    {
        $c = CourseType::query()->updateOrCreate(
            ['code' => 'C'],
            ['title' => 'Aeroport', 'note_required' => true,
            ]);

        $this->reserve(['course_type_id' => $c->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('course_note');
    }

    /** Commentaire fourni, la reservation passe. */
    public function test_le_commentaire_fourni_laisse_passer(): void
    {
        $c = CourseType::query()->updateOrCreate(
            ['code' => 'C'],
            ['title' => 'Aeroport', 'note_required' => true,
            ]);

        $this->reserve([
            'course_type_id' => $c->id,
            'course_note' => 'Arrivee vol AF 123, terminal 1',
        ])->assertSuccessful();
    }

    /** Un type sans exigence ne reclame rien. */
    public function test_un_type_sans_exigence_ne_reclame_aucun_commentaire(): void
    {
        $a = CourseType::query()->updateOrCreate(['code' => 'A'], ['title' => 'Urbaine']);

        $this->reserve(['course_type_id' => $a->id])->assertSuccessful();
    }

    /**
     * SANS type de course, rien ne change.
     *
     * C'est le cas des applications deja installees, et la garantie de
     * non-regression de tout ce lot.
     */
    public function test_sans_type_la_reservation_passe_comme_avant(): void
    {
        $this->reserve()->assertSuccessful();
    }

    /** Le type, le commentaire et l'option parking sont conserves. */
    public function test_la_reservation_conserve_le_type_et_le_commentaire(): void
    {
        $c = CourseType::query()->updateOrCreate(
            ['code' => 'C'],
            ['title' => 'Aeroport',
                'note_required' => true, 'parking_option' => true, 'parking_hourly_fee' => 10,
            ]);

        $this->reserve([
            'course_type_id' => $c->id,
            'course_note' => 'Arrivee vol AF 123',
            'parking' => true,
        ])->assertSuccessful();

        $booking = Booking::query()->latest('id')->first();

        $this->assertSame($c->id, $booking->course_type_id);
        $this->assertSame('Arrivee vol AF 123', $booking->course_note);
        $this->assertTrue((bool) $booking->parking);
        // 5 heures a 20 $, plus 5 heures de parking a 10 $.
        $this->assertSame(100.0, (float) $booking->base_price);
        $this->assertSame(50.0, (float) $booking->parking_fee);
        $this->assertSame(150.0, (float) $booking->total);
    }

    // --- Ce que les applications recoivent -----------------------------------

    /**
     * La configuration porte les types ET leurs regles.
     *
     * L'ecran doit pouvoir imposer le mode et reclamer le commentaire sans
     * rien savoir des codes : un quatrieme type doit apparaitre dans
     * l'application sans qu'on la relivre.
     */
    public function test_la_configuration_expose_les_types_et_leurs_regles(): void
    {
        (new CourseTypeSeeder)->run();
        CourseType::query()->where('code', 'A')->update(['is_active' => false]);

        $reponse = $this->getJson('/api/rental/config')->assertSuccessful();

        // Le type eteint ne sort pas : la liste sert a proposer, pas a lister.
        $reponse->assertJsonCount(2, 'data.course_types')
            ->assertJsonPath('data.course_types.0.code', 'B')
            ->assertJsonPath('data.course_types.0.minimum_hours', 24)
            ->assertJsonPath('data.course_types.0.forced_mode', BookingPricing::MODE_DAILY)
            ->assertJsonPath('data.course_types.0.note_required', true)
            ->assertJsonPath('data.course_types.1.code', 'C')
            ->assertJsonPath('data.course_types.1.parking_option', true)
            ->assertJsonPath(
                'data.course_types.1.parking_hourly_fee',
                fn ($tarif) => (float) $tarif === 10.0,
            );
    }

    /**
     * Le catalogue chiffre selon le type demande.
     *
     * Sans cela, la fiche annoncerait le tarif habituel et la reservation en
     * facturerait un autre : le client croirait qu'on a change le prix au
     * moment de payer.
     */
    public function test_le_catalogue_chiffre_selon_le_type_demande(): void
    {
        $c = CourseType::query()->updateOrCreate(
            ['code' => 'C'],
            ['title' => 'Aeroport',
                'parking_option' => true, 'parking_hourly_fee' => 10,
            ]);

        $this->vehicle->coursePrices()->create([
            'course_type_id' => $c->id, 'hourly_rate' => 35,
        ]);

        $debut = Carbon::parse('+2 days 10:00')->toDateTimeString();
        $fin = Carbon::parse('+2 days 13:00')->toDateTimeString();

        $url = '/api/rental/vehicles?'.http_build_query([
            'starts_at' => $debut,
            'ends_at' => $fin,
            'course_type_id' => $c->id,
            'parking' => 1,
        ]);

        $this->getJson($url)
            ->assertSuccessful()
            // 3 heures a 35 $, plus 3 heures de parking a 10 $.
            ->assertJsonPath('data.0.quote.base_price', fn ($v) => (float) $v === 105.0)
            ->assertJsonPath('data.0.quote.parking_fee', fn ($v) => (float) $v === 30.0)
            ->assertJsonPath('data.0.quote.total', fn ($v) => (float) $v === 135.0);
    }

    // --- Le detail du total --------------------------------------------------

    // --- L'ecran d'administration --------------------------------------------

    /**
     * La saisie d'un tarif par type, depuis la fiche du vehicule.
     *
     * C'est l'ecran que le proprietaire a nomme :
     * `/admin/vehicles/{id}/edit`.
     */
    public function test_l_admin_saisit_un_tarif_par_type_sur_le_vehicule(): void
    {
        $this->admin();

        $b = CourseType::query()->updateOrCreate(['code' => 'B'], ['title' => 'Hors agglomeration']);

        Livewire::test(CoursePricesRelationManager::class, [
            'ownerRecord' => $this->vehicle,
            'pageClass' => EditVehicle::class,
        ])
            ->callTableAction('create', data: [
                'course_type_id' => $b->id,
                'hourly_rate' => 30,
                'daily_rate' => 225,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(225.0, $this->vehicle->fresh()->tarifPour($b, BookingPricing::MODE_DAILY));
    }

    /**
     * Un tarif laisse vide retombe sur le tarif habituel du vehicule.
     *
     * C'est la regle qui permet de ne renseigner que l'exception : une course
     * hors agglomeration ne se vend pas a l'heure, et sa ligne n'a donc qu'un
     * tarif journalier.
     */
    public function test_un_tarif_laisse_vide_retombe_sur_le_tarif_habituel(): void
    {
        $this->admin();

        $b = CourseType::query()->updateOrCreate(['code' => 'B'], ['title' => 'Hors agglomeration']);

        Livewire::test(CoursePricesRelationManager::class, [
            'ownerRecord' => $this->vehicle,
            'pageClass' => EditVehicle::class,
        ])
            ->callTableAction('create', data: ['course_type_id' => $b->id, 'daily_rate' => 225])
            ->assertHasNoTableActionErrors();

        $vehicule = $this->vehicle->fresh();

        $this->assertSame(225.0, $vehicule->tarifPour($b, BookingPricing::MODE_DAILY));
        // Aucun tarif horaire saisi : le devis reprendra celui du vehicule.
        $this->assertNull($vehicule->tarifPour($b, BookingPricing::MODE_HOURLY));
    }

    /**
     * Le devis dit d'ou vient le prix.
     *
     * Sans ce detail, une course aeroport afficherait 90 $ la ou le catalogue
     * annonce 60, et le client croirait qu'on a change le prix derriere son
     * dos.
     */
    public function test_le_devis_detaille_la_base_et_le_parking(): void
    {
        $c = CourseType::query()->updateOrCreate(
            ['code' => 'C'],
            ['title' => 'Aeroport',
                'parking_option' => true, 'parking_hourly_fee' => 10,
            ]);

        $devis = $this->prix()->quote(
            $this->vehicle,
            Carbon::parse('+2 days 10:00'),
            Carbon::parse('+2 days 13:00'),
            BookingPricing::MODE_HOURLY,
            $c,
            parking: true,
        );

        $this->assertSame($devis->total, round($devis->base + $devis->parkingFee, 2));
    }
}
