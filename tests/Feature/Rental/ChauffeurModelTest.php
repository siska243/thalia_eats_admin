<?php

namespace Tests\Feature\Rental;

use App\Models\Chauffeur;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Qui peut etre affecte a une course. */
class ChauffeurModelTest extends TestCase
{
    use RefreshDatabase;

    private function chauffeur(array $attributes = []): Chauffeur
    {
        return Chauffeur::query()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'full_name' => 'Jean Kabila',
            'phone' => '+243810000000',
        ], $attributes));
    }

    public function test_un_permis_perime_disqualifie(): void
    {
        // Conduire un client avec un permis expire engage l'entreprise, et
        // personne ne verifie une date a la main dans une liste deroulante.
        $this->chauffeur(['licence_expires_at' => now()->subDay()]);

        $this->assertSame(0, Chauffeur::query()->assignable()->count());
    }

    public function test_un_permis_valide_reste_affectable(): void
    {
        $this->chauffeur(['licence_expires_at' => now()->addYear()]);

        $this->assertSame(1, Chauffeur::query()->assignable()->count());
    }

    public function test_un_permis_expirant_aujourd_hui_reste_valide(): void
    {
        $this->chauffeur(['licence_expires_at' => now()]);

        $this->assertSame(1, Chauffeur::query()->assignable()->count());
    }

    public function test_sans_date_de_permis_le_chauffeur_reste_affectable(): void
    {
        // Toutes les fiches ne seront pas completes des le premier jour.
        $this->chauffeur(['licence_expires_at' => null]);

        $this->assertSame(1, Chauffeur::query()->assignable()->count());
    }

    public function test_un_chauffeur_desactive_n_est_plus_affectable(): void
    {
        $this->chauffeur(['is_active' => false]);

        $this->assertSame(0, Chauffeur::query()->assignable()->count());
    }
}
