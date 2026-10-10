<?php

namespace App\Http;

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\EnsureNotAgentToken;
use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\RefuserAgentSansAbility;
use App\Http\Middleware\TrimStrings;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\ValidateSignature;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array<int, class-string|string>
     */
    protected $middleware = [
        // \App\Http\Middleware\TrustHosts::class,
        TrustProxies::class,
        HandleCors::class,
        PreventRequestsDuringMaintenance::class,
        ValidatePostSize::class,
        TrimStrings::class,
        ConvertEmptyStringsToNull::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array<string, array<int, class-string|string>>
     */
    protected $middlewareGroups = [
        'web' => [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            SubstituteBindings::class,
        ],

        'api' => [
            // \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            ThrottleRequests::class.':api',
            SubstituteBindings::class,
            RefuserAgentSansAbility::class,
            /*
             * NE PAS DECOMMENTER CETTE LIGNE.
             *
             * Trois raisons, dans cet ordre.
             *
             * 1. La classe App\Http\Middleware\EnsureApiKeyIsPresent n'existe
             *    pas et n'a jamais existe dans ce depot. La decommenter ferait
             *    lever « Target class does not exist » sur CHAQUE requete
             *    d'API : panne totale, web et mobile compris, des le deploiement.
             * 2. Meme ecrite, elle ne protegerait rien. La valeur de
             *    X-API-KEY est en clair dans les deux FetchData, donc dans le
             *    bundle JavaScript servi a tout visiteur et dans l'APK. Une
             *    cle que le client porte n'authentifie pas le client.
             * 3. Elle casserait tout appel qui n'envoie pas l'en-tete, a
             *    commencer par le webhook FlexPay et les callbacks de paiement.
             *
             * L'API n'est donc protegee que par Sanctum, et c'est ce qu'il
             * faut supposer en relisant n'importe quelle route. Le choix entre
             * retirer l'en-tete des clients ou poser une vraie authentification
             * de service revient au proprietaire : voir le rapport T29.
             */
            // \App\Http\Middleware\EnsureApiKeyIsPresent::class.':api'
        ],
    ];

    /**
     * The application's middleware aliases.
     *
     * Aliases may be used instead of class names to conveniently assign middleware to routes and groups.
     *
     * @var array<string, class-string|string>
     */
    protected $middlewareAliases = [
        // 'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
        // 'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
        // 'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        'abilities' => CheckAbilities::class,
        'ability' => CheckForAnyAbility::class,
        'assistant.emetteur' => EnsureNotAgentToken::class,
        'auth' => Authenticate::class,
        'auth.basic' => AuthenticateWithBasicAuth::class,
        'auth.session' => AuthenticateSession::class,
        'cache.headers' => SetCacheHeaders::class,
        'can' => Authorize::class,
        'guest' => RedirectIfAuthenticated::class,
        'password.confirm' => RequirePassword::class,
        'precognitive' => HandlePrecognitiveRequests::class,
        'signed' => ValidateSignature::class,
        'throttle' => ThrottleRequests::class,
        'verified' => EnsureEmailIsVerified::class,
    ];
}
