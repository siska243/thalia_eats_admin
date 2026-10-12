<?php

namespace Tests\Feature;

use App\Filament\Resources\RestaurantResource\Pages\CreateRestaurant;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ReproBanniereFixTest extends TestCase
{
    use DatabaseTransactions;

    public function test_upload_persiste_avec_disque_temp_aligne(): void
    {
        // on verifie que la config du fix est bien active
        $this->assertSame('uploads_image', config('livewire.temporary_file_upload.disk'));

        Gate::before(fn () => true);

        $user = User::create([
            'name' => 'Fix', 'email' => 'fix'.uniqid().'@test.local',
            'password' => bcrypt('x'), 'slug' => 'fix-'.uniqid(),
        ]);
        $this->actingAs($user);

        $file = UploadedFile::fake()->image('b.jpg');

        Livewire::test(CreateRestaurant::class)
            ->fillForm([
                'user_id' => $user->id,
                'name' => 'Fix Repro '.uniqid(),
                'adresse' => 'x',
                'reference' => 'FX'.rand(1000, 9999),
                'phone' => '0810000000',
                'openHours' => [['day' => 'lundi', 'startAt' => '08:00', 'endAt' => '18:00']],
                'banniere' => $file,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $r = Restaurant::where('name', 'like', 'Fix Repro %')->latest('id')->first();
        $nom = $r->banniere;
        $existe = $nom ? Storage::disk('uploads_image')->exists($nom) : false;
        if ($nom && $existe) {
            Storage::disk('uploads_image')->delete($nom);
        }

        $this->assertTrue($existe, 'Le fichier final doit exister sur le disque');
        $this->assertStringNotContainsString('-meta', (string) $nom);
    }
}
