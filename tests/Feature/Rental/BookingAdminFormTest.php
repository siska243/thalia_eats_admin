<?php

namespace Tests\Feature\Rental;

use App\Filament\Resources\BookingResource\Pages\CreateBooking;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Le formulaire de reservation de l'administration.
 *
 * On passe par le composant Livewire reel, pas par le modele : c'est le seul
 * moyen de verifier ce que le formulaire fait des donnees saisies — et il en
 * fait deux choses qui comptent, recalculer le prix et refuser un
 * chevauchement.
 */
class BookingAdminFormTest extends TestCase
{
    use RefreshDatabase;

    private Currency $currency;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'deposit_percentage' => 10.0,
            'refund_percentage' => 100.0,
            'minimum_hours' => 1.0,
            'pickup_code_max_attempts' => 3,
        ] as $name => $value) {
            DB::table('settings')->updateOrInsert(
                ['group' => 'rental', 'name' => $name],
                ['payload' => json_encode($value), 'locked' => false],
            );
        }

        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        Gate::before(fn () => true);
        $this->actingAs($admin);

        $this->currency = Currency::query()->create([
            'title' => 'Dollars', 'code' => 'USD', 'is_active' => 1,
        ]);

        $this->vehicle = Vehicle::query()->create([
            'brand' => 'Toyota', 'model' => 'Land Cruiser',
            'plate_number' => 'KIN-0001', 'hourly_rate' => 10,
            'currency_id' => $this->currency->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function formData(array $overrides = []): array
    {
        return array_merge([
            'user_id' => User::factory()->create()->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => '2026-10-01 10:00:00',
            'ends_at' => '2026-10-01 15:00:00',
            'pickup_location' => 'Gombe',
            'status' => Booking::STATUS_PENDING_PAYMENT,
        ], $overrides);
    }

    public function test_le_montant_est_calcule_par_le_serveur(): void
    {
        /*
         * Le formulaire n'envoie aucun montant : le total, l'acompte et le
         * solde sont refaits a l'ecriture. Un total tape a la main
         * divergerait du prix annonce au client.
         */
        Livewire::test(CreateBooking::class)
            ->fillForm($this->formData())
            ->call('create')
            ->assertHasNoFormErrors();

        $booking = Booking::query()->latest('id')->first();

        $this->assertSame('5.00', $booking->duration_hours);
        $this->assertSame('50.00', $booking->total);
        $this->assertSame('5.00', $booking->deposit);
        $this->assertSame('45.00', $booking->balance);
        $this->assertSame($this->currency->id, $booking->currency_id);
    }

    public function test_un_chevauchement_est_refuse(): void
    {
        Booking::query()->create([
            'user_id' => User::factory()->create()->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => '2026-10-01 10:00:00',
            'ends_at' => '2026-10-01 15:00:00',
            'pickup_location' => 'Gombe',
            'hourly_rate' => 10, 'duration_hours' => 5,
            'total' => 50, 'deposit' => 5, 'balance' => 45,
            'currency_id' => $this->currency->id,
            'status' => Booking::STATUS_CONFIRMED,
        ]);

        // Sans cette regle, l'administration pourrait creer ce que tout le
        // reste du module interdit : deux clients sur le meme vehicule.
        Livewire::test(CreateBooking::class)
            ->fillForm($this->formData([
                'starts_at' => '2026-10-01 12:00:00',
                'ends_at' => '2026-10-01 13:00:00',
            ]))
            ->call('create')
            ->assertHasFormErrors(['ends_at']);
    }

    public function test_un_creneau_voisin_reste_acceptable(): void
    {
        Booking::query()->create([
            'user_id' => User::factory()->create()->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => '2026-10-01 10:00:00',
            'ends_at' => '2026-10-01 15:00:00',
            'pickup_location' => 'Gombe',
            'hourly_rate' => 10, 'duration_hours' => 5,
            'total' => 50, 'deposit' => 5, 'balance' => 45,
            'currency_id' => $this->currency->id,
            'status' => Booking::STATUS_CONFIRMED,
        ]);

        Livewire::test(CreateBooking::class)
            ->fillForm($this->formData([
                'starts_at' => '2026-10-01 15:00:00',
                'ends_at' => '2026-10-01 18:00:00',
            ]))
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_une_fin_avant_le_debut_est_refusee(): void
    {
        Livewire::test(CreateBooking::class)
            ->fillForm($this->formData([
                'starts_at' => '2026-10-01 15:00:00',
                'ends_at' => '2026-10-01 10:00:00',
            ]))
            ->call('create')
            ->assertHasFormErrors(['ends_at']);
    }

    public function test_une_reservation_nait_en_attente_de_paiement(): void
    {
        // C'est le paiement qui retient le vehicule, pas la saisie.
        Livewire::test(CreateBooking::class)
            ->fillForm($this->formData())
            ->call('create');

        $this->assertSame(
            Booking::STATUS_PENDING_PAYMENT,
            Booking::query()->latest('id')->first()->status,
        );
    }
}
