<?php

namespace Tests\Feature\Rental;

use App\Filament\Resources\ChauffeurResource\Pages\CreateChauffeur;
use App\Filament\Resources\VehicleResource\Pages\CreateVehicle;
use App\Models\Chauffeur;
use App\Models\Currency;
use App\Models\User;
use App\Models\Vehicle;
use App\Settings\RentalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Les photos doivent atterrir la ou l'API dit qu'elles sont.
 *
 * Le formulaire n'avait pas de `->disk()` : Filament ecrivait sur le disque
 * par defaut (`storage/app`), que le serveur web ne sert pas, pendant que
 * l'API annoncait l'image sous `/images/…` via ImageUrl. Le fichier partait
 * d'un cote, l'adresse pointait de l'autre, et aucune photo ne s'affichait.
 *
 * Ce test tient les deux bouts ensemble : le fichier depose est sur
 * `uploads_image`, dont la racine est `public/images`, et l'URL rendue par
 * l'API commence par `/images/`.
 */
class RentalPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Les reglages sont declares ici : un autre test de la suite vide la
         * table `settings`, et le calcul du prix tombe alors en 500. Passe
         * seul, ce test ne l'aurait jamais montre.
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
            'whatsapp_number' => '',
            'brand_food' => 'Thalia Eats',
            'brand_rental' => 'Thalia Trans',
            'out_of_zone_message' => 'Nous ne desservons pas encore cette commune.',
        ], loadMissingValues: false);

        Storage::fake('uploads_image');
        Storage::fake('local');

        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        Gate::before(fn () => true);
        $this->actingAs($admin);
    }

    public function test_la_photo_d_un_vehicule_va_sur_le_disque_public(): void
    {
        $currency = Currency::query()->create([
            'title' => 'Dollars', 'code' => 'USD', 'is_active' => 1,
        ]);

        Livewire::test(CreateVehicle::class)
            ->fillForm([
                'brand' => 'Toyota',
                'model' => 'Land Cruiser',
                'plate_number' => 'KIN-0001',
                'hourly_rate' => 10,
                'currency_id' => $currency->id,
                'image' => UploadedFile::fake()->image('voiture.jpg'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $vehicle = Vehicle::query()->firstOrFail();

        $this->assertNotNull($vehicle->image, "Aucune photo n'a été enregistrée.");
        Storage::disk('uploads_image')->assertExists($vehicle->image);
        // Le disque par defaut ne doit rien recevoir : c'est la ou le fichier
        // se perdait.
        Storage::disk('local')->assertMissing($vehicle->image);
    }

    public function test_la_photo_d_un_chauffeur_va_sur_le_disque_public(): void
    {
        Livewire::test(CreateChauffeur::class)
            ->fillForm([
                'user_id' => User::factory()->create()->id,
                'full_name' => 'Jean Kabila',
                'phone' => '+243810000000',
                'photo' => UploadedFile::fake()->image('chauffeur.jpg'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $chauffeur = Chauffeur::query()->firstOrFail();

        $this->assertNotNull($chauffeur->photo, "Aucune photo n'a été enregistrée.");
        Storage::disk('uploads_image')->assertExists($chauffeur->photo);
        Storage::disk('local')->assertMissing($chauffeur->photo);
    }

    /** L'adresse annoncee par l'API pointe bien sur ce disque. */
    public function test_l_api_annonce_la_photo_sous_images(): void
    {
        /*
         * On quitte la session d'administration ouverte par setUp.
         *
         * RefuserAgentSansAbility refuse tout utilisateur authentifie sans
         * jeton portant `*`, y compris sur une route publique : un admin
         * connecte au back-office recevrait donc un 403 ici. Ce test regarde
         * le catalogue tel qu'un visiteur le voit.
         */
        $this->app['auth']->forgetGuards();

        $currency = Currency::query()->create([
            'title' => 'Dollars', 'code' => 'USD', 'is_active' => 1,
        ]);

        Vehicle::query()->create([
            'brand' => 'Toyota', 'model' => 'Land Cruiser',
            'plate_number' => 'KIN-0002', 'hourly_rate' => 10,
            'currency_id' => $currency->id,
            'image' => 'vehicles/voiture.jpg',
        ]);

        $this->getJson('/api/rental/vehicles?'.http_build_query([
            'starts_at' => now()->addDays(2)->setTime(10, 0)->toDateTimeString(),
            'ends_at' => now()->addDays(2)->setTime(15, 0)->toDateTimeString(),
        ]))
            ->assertSuccessful()
            ->assertJsonPath('data.0.image', url('/images/vehicles/voiture.jpg'));
    }
}
