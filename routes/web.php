<?php

use App\Http\Controllers\Api\CallbackUrlController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

/* Route::get('/', function () {
    return view('welcome');
}); */

Route::get('/callback/{action}',[CallbackUrlController::class,'index'])->name('callback-web');

Route::post('/create-payment-intent',[CallbackUrlController::class,'index'])->name('payment-intent-web');

Route::get('/test-test',function (){
    echo gethostbyname('backend.flexpay.cd');
});

Route::post('/webhook-paiement-flexpay', [\App\Http\Controllers\Api\PayementController::class, 'webhook']);


Route::get('/auth/google', [\App\Http\Controllers\Api\GoogleAuthController::class, 'redirect']);
Route::get('/auth/google/register', [\App\Http\Controllers\Api\GoogleAuthController::class, 'register']);
Route::get('/auth/google/callback', [\App\Http\Controllers\Api\GoogleAuthController::class, 'callback']);

Route::get('/paiement/precommande/{uid}', [\App\Http\Controllers\PaiementPrecommandeController::class, 'show'])
    ->name('precommande.paiement')
    ->middleware(['signed', 'throttle:lien-paiement']);

Route::post('/paiement/precommande/{uid}', [\App\Http\Controllers\PaiementPrecommandeController::class, 'initier'])
    ->name('precommande.paiement.initier')
    ->middleware(['signed', 'throttle:lien-paiement']);






/*
|--------------------------------------------------------------------------
| Serveur d'autorisation OAuth 2.1 pour les assistants
|--------------------------------------------------------------------------
|
| Ces routes sont dans le groupe « web » et non dans l'API : la page
| d'autorisation est une vraie page HTML, avec un formulaire, une session et un
| jeton CSRF. Les deux routes que l'assistant appelle en machine à machine
| (`register` et `token`) sont exemptées de CSRF dans VerifyCsrfToken — un
| logiciel n'a pas de session d'où tirer un jeton.
|
*/

Route::get('/.well-known/oauth-authorization-server', [\App\Http\Controllers\Oauth\MetadonneesController::class, 'show'])
    ->name('oauth.metadonnees');

Route::post('/oauth/register', [\App\Http\Controllers\Oauth\EnregistrementController::class, 'store'])
    ->name('oauth.register')
    ->middleware('throttle:oauth-enregistrement');

Route::get('/oauth/authorize', [\App\Http\Controllers\Oauth\AutorisationController::class, 'show'])
    ->name('oauth.authorize')
    ->middleware('throttle:oauth-autorisation');

Route::post('/oauth/authorize', [\App\Http\Controllers\Oauth\AutorisationController::class, 'store'])
    ->name('oauth.authorize.store')
    ->middleware('throttle:oauth-connexion');

Route::post('/oauth/token', [\App\Http\Controllers\Oauth\JetonController::class, 'store'])
    ->name('oauth.token')
    ->middleware('throttle:oauth-jeton');
