<?php

namespace Tests\Feature\Portee;

use App\Models\Town;
use App\Settings\FoodSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Les communes portent leurs coordonnees jusqu'aux applications.
 *
 * Sans elles, l'ecran de nouvelle adresse devait deviner la commune a partir
 * du nom rendu par le geocodeur. Sur Android celui-ci ne rend souvent aucun
 * district, seulement « Kinshasa » a tous les niveaux — et comme une commune
 * porte aussi ce nom, toute adresse kinoise tombait sur elle.
 */
class CommuneCoordonneesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * L'endpoint de demarrage ne doit jamais tomber.
     *
     * Toutes les applications l'appellent au lancement. Il lit desormais un
     * reglage, et repondait 500 des que la table `settings` etait vide —
     * migrations pas encore jouees apres un deploiement, ce qui est deja
     * arrive en production. Plus aucune application ne demarrait.
     */
    public function test_les_donnees_par_defaut_repondent_sans_reglages(): void
    {
        DB::table('settings')->where('group', 'food')->delete();
        app()->forgetInstance(FoodSettings::class);

        $this->getJson('/api/default')
            ->assertOk()
            ->assertJsonPath('reglages.position_client', false);
    }

    public function test_les_donnees_par_defaut_portent_le_centre_des_communes(): void
    {
        $commune = Town::factory()->create([
            'title' => 'Gombe',
            'is_active' => true,
            'latitude' => -4.3045,
            'longitude' => 15.3075,
        ]);

        $reponse = $this->getJson('/api/default');

        $reponse->assertOk();

        /*
         * Retrouvee par son slug, pas par sa position dans le tableau :
         * d'autres suites laissent des communes derriere elles, et un test
         * qui compte sur l'ordre passe seul puis echoue en suite complete.
         */
        $gombe = collect($reponse->json('town'))->firstWhere('slug', $commune->slug);

        $this->assertNotNull($gombe, 'La commune creee doit figurer dans les donnees par defaut.');
        $this->assertSame(-4.3045, $gombe['latitude']);
        $this->assertSame(15.3075, $gombe['longitude']);
    }

    /** Une commune sans coordonnees ne casse rien : elle rend null. */
    public function test_une_commune_sans_coordonnees_rend_null(): void
    {
        $commune = Town::factory()->create([
            'title' => 'Sans repere',
            'is_active' => true,
            'latitude' => null,
            'longitude' => null,
        ]);

        $sans = collect($this->getJson('/api/default')->json('town'))
            ->firstWhere('slug', $commune->slug);

        $this->assertNotNull($sans);
        $this->assertNull($sans['latitude']);
        $this->assertNull($sans['longitude']);
    }
}
