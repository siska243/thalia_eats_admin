<?php

namespace Tests\Feature;

use App\Models\DelivreryDriver;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Les tableaux de bord restaurateur et livreur répondent.
 *
 * Ils reposent sur flowframe/laravel-trend, remonté de 0.4 à 0.5 pour suivre
 * Laravel 13. Rien ne couvrait ces deux routes : `Trend::query()` construit
 * du SQL spécifique au moteur, et un changement de version s'y voit à
 * l'exécution, pas à la compilation.
 *
 * Le test ne vérifie pas les chiffres — il vérifie que la requête part et
 * revient. C'est ce qu'une montée de version casse.
 */
class TableauxDeBordTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_tableau_de_bord_restaurateur_repond(): void
    {
        $proprietaire = User::factory()->create();

        Restaurant::query()->create([
            'user_id' => $proprietaire->id,
            'name' => 'Chez Mado',
            'slug' => 'chez-mado',
            'adresse' => 'Av. Batetela, Gombe',
            'reference' => 'R-001',
            'phone' => '+243810000000',
        ]);

        // L'ability « * » est explicite : `actingAs` ne la donne plus par defaut,
        // et le garde RefuserAgentSansAbility ferme alors la route — c'est son
        // role, un jeton d'assistant n'a rien a faire sur un tableau de bord.
        Sanctum::actingAs($proprietaire, ['*']);

        $this->getJson('/api/user/restaurant-dash')->assertSuccessful();
    }

    public function test_le_tableau_de_bord_livreur_repond(): void
    {
        $livreur = User::factory()->create();

        DelivreryDriver::query()->create([
            'user_id' => $livreur->id,
            'id_card' => 'CD-0001',
        ]);

        Sanctum::actingAs($livreur, ['*']);

        $this->getJson('/api/user/delivery-dash')->assertSuccessful();
    }
}
