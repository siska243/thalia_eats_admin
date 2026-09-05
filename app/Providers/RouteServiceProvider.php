<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('agent-lecture', fn (Request $request) => Limit::perMinute(60)->by(self::cleDeLimitation($request)));

        RateLimiter::for('agent-devis', fn (Request $request) => Limit::perMinute(20)->by(self::cleDeLimitation($request)));

        RateLimiter::for('agent-ecriture', fn (Request $request) => [
            Limit::perMinute(10)->by(self::cleDeLimitation($request)),
            Limit::perHour(60)->by(self::cleDeLimitation($request)),
        ]);

        RateLimiter::for('assistants', fn (Request $request) => Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()));

        // La signature n'identifie pas l'appelant : on compte par IP.
        RateLimiter::for('lien-paiement', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Compter par JETON et non par utilisateur : sinon un assistant bavard
     * épuise le quota de l'application mobile du même client, qui se retrouve
     * bloqué dans son app sans comprendre pourquoi.
     *
     * Retombe sur l'utilisateur lorsque le jeton n'a pas d'identifiant en base
     * (authentification de session, ou Sanctum::actingAs en test), puis sur
     * l'adresse IP.
     */
    private static function cleDeLimitation(Request $request): string
    {
        $token = $request->user()?->currentAccessToken();

        if ($token !== null && isset($token->id)) {
            return 'token:'.$token->id;
        }

        if ($request->user() !== null) {
            return 'user:'.$request->user()->id;
        }

        return 'ip:'.$request->ip();
    }
}
