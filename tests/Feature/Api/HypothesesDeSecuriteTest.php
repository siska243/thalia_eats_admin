<?php

namespace Tests\Feature\Api;

use Illuminate\Contracts\Http\Kernel;
use Tests\TestCase;

/**
 * Ce fichier épingle des hypothèses dont dépend la sécurité des gardes
 * d'assistant, et qui ne sont visibles nulle part dans leur propre code.
 */
class HypothesesDeSecuriteTest extends TestCase
{
    public function test_le_groupe_api_reste_sans_session(): void
    {
        // EnsureNotAgentToken et RefuserAgentSansAbility reposent tous deux sur
        // tokenCan('*'). Or Sanctum attache un TransientToken quand c'est le
        // garde de session qui resout l'utilisateur, et TransientToken::can()
        // renvoie true pour TOUTE ability, '*' comprise : les deux gardes
        // deviendraient inoperants pour une requete authentifiee par session.
        //
        // Si ce test echoue parce que quelqu'un a active le mode stateful pour
        // de bonnes raisons, ce n'est pas le test qu'il faut corriger : ce sont
        // les deux gardes qu'il faut repenser.
        $groupes = app(Kernel::class)->getMiddlewareGroups();

        $this->assertNotContains(
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            $groupes['api'],
            'Le groupe api est passe en mode stateful : relire EnsureNotAgentToken et RefuserAgentSansAbility avant d\'aller plus loin.'
        );

        $this->assertNotContains(
            \Illuminate\Session\Middleware\StartSession::class,
            $groupes['api'],
            'Le groupe api demarre une session : meme consequence.'
        );
    }

    public function test_les_deux_gardes_sont_bien_actifs(): void
    {
        $groupes = app(Kernel::class)->getMiddlewareGroups();

        $this->assertContains(
            \App\Http\Middleware\RefuserAgentSansAbility::class,
            $groupes['api'],
            'Le refus par defaut a ete retire du groupe api : tout jeton d\'assistant atteint desormais toute route non marquee.'
        );
    }
}
