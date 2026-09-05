<?php

namespace Tests\Feature\Api;

use App\Enums\TokenAbility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgentRefuseParDefautTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_assistant_est_refuse_sur_une_route_sans_ability(): void
    {
        // /api/user/commande/current ne declare aucune ability : un assistant
        // ne doit pas l'atteindre, meme si personne n'y a pense en l'ecrivant.
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());

        $this->getJson('/api/user/commande/current')->assertStatus(403);
    }

    public function test_un_assistant_est_refuse_sur_le_changement_d_adresse(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());

        $this->postJson('/api/user/commande/update-address-delivery', [])->assertStatus(403);
    }

    public function test_un_jeton_applicatif_passe_partout(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        // Peu importe le corps : ce qui compte est que le garde ne refuse pas.
        $this->assertNotSame(403, $this->getJson('/api/user/commande/current')->status());
    }

    public function test_un_assistant_passe_sur_une_route_qui_declare_son_ability(): void
    {
        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());

        $this->getJson('/api/products/search?q=poulet')->assertStatus(200);
    }

    public function test_une_route_publique_reste_publique(): void
    {
        // Aucun utilisateur authentifie : le garde ne doit rien faire.
        $this->getJson('/api/categorie')->assertStatus(200);
    }

    public function test_la_variante_toutes_les_abilities_est_reconnue(): void
    {
        // Aucune route de l'application n'utilise encore `abilities:`. On en
        // declare une ici pour epingler le comportement avant qu'elle
        // n'existe : sans cela, la premiere route ecrite avec la variante
        // « toutes » serait silencieusement fermee aux assistants.
        \Illuminate\Support\Facades\Route::middleware([
            'api',
            'auth:sanctum',
            'abilities:catalogue:lire,devis:calculer',
        ])->get('/api/_test_abilities', fn () => response()->json(['ok' => true]));

        Sanctum::actingAs(User::factory()->create(), TokenAbility::agent());

        $this->getJson('/api/_test_abilities')->assertStatus(200);
    }
}
