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

/*
 * La page Blade de paiement d'une pre-commande.
 *
 * CONSERVEE POUR LES LIENS DEJA EMIS, et pour eux seuls. Le lien qu'un client
 * recoit desormais mene au site Next.js
 * (GET|POST /api/precommandes/{uid}/lien-paiement), comme le veut la regle
 * d'architecture du projet : « Pas de rendu Blade cote produit ».
 *
 * Mais un lien vaut douze heures : certains circulent peut-etre encore chez
 * des clients au moment du deploiement, et les casser laisserait quelqu'un
 * avec un repas commande et aucun moyen de payer. Ces deux routes pourront
 * partir une fois cette fenetre ecoulee.
 */
Route::get('/paiement/precommande/{uid}', [\App\Http\Controllers\PaiementPrecommandeController::class, 'show'])
    ->name('precommande.paiement')
    ->middleware(['signed', 'throttle:lien-paiement']);

Route::post('/paiement/precommande/{uid}', [\App\Http\Controllers\PaiementPrecommandeController::class, 'initier'])
    ->name('precommande.paiement.initier')
    ->middleware(['signed', 'throttle:lien-paiement']);





