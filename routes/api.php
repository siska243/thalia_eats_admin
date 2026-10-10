<?php

use App\Http\Controllers\Api\AdresseRecenteController;
use App\Http\Controllers\Api\AssistantTokenController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CallbackUrlController;
use App\Http\Controllers\Api\CategorieProductController;
use App\Http\Controllers\Api\CommandeController;
use App\Http\Controllers\Api\DefaultDataController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\PayementController;
use App\Http\Controllers\Api\PrecommandeController;
use App\Http\Controllers\Api\ProductSearchController;
use App\Http\Controllers\Api\ProspectController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\Rental\BookingController;
use App\Http\Controllers\Api\Rental\BookingTrackingController;
use App\Http\Controllers\Api\Rental\CourseChauffeurController;
use App\Http\Controllers\Api\Rental\RentalConfigController;
use App\Http\Controllers\Api\Rental\VehicleController;
use App\Http\Controllers\Api\RestaurantController;
use App\Http\Controllers\Api\UserAccountController;
use App\Http\Controllers\Api\UserAddressController;
use App\Wrappers\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
 * Le relais vers un Ollama local a ete SUPPRIME.
 *
 * `POST /api/ia-model-llama3`, vestige de prototype, etait ouvert a tout
 * Internet sans authentification ni limitation, avec un delai d'attente d'une
 * heure : chaque appel immobilisait un worker php-fpm pendant une heure, et
 * quelques dizaines suffisaient a rendre l'API entiere injoignable.
 *
 * Verifie avant de le retirer : aucun des trois clients ne l'appelait. La
 * direction du produit est de ne pas construire dessus — les agents vivent
 * dans un service separe, avec un jeton scope — donc rien ne le remplace ici.
 */

/*
 * Mise au point, en local seulement.
 *
 * Celle-ci declenchait un envoi de notification push a qui la demandait.
 */
Route::get('/notification', function () {

    abort_unless(app()->environment('local'), 404);

    $response = Notification::SEND_NOTIFICATION('test', 'test body');

    return $response;
});

Route::get('/categorie', [CategorieProductController::class, 'index']);

Route::prefix('/list-restaurant')->controller(RestaurantController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/{restaurant:slug}', 'show');
});

Route::get('/categorie-restaurant/{restaurant:slug}', [RestaurantController::class, 'categorie']);
Route::get('/menu/{restaurant:slug}/{slug}', [RestaurantController::class, 'productRestaurant']);
Route::get('/product/{slug}', [RestaurantController::class, 'product']);

Route::post('/register', [AuthController::class, 'register'])->name('api.register');

/*
 * Connexion et codes OTP sous limitation dediee.
 *
 * Le seul throttle:api (60 par minute) laissait parcourir les dix mille codes
 * OTP a quatre chiffres en quelques heures ; reset-password posant un nouveau
 * mot de passe a partir de ce code, c'etait une prise de compte. Le limiteur
 * « auth-sensibles » compte par e-mail et par IP. Il couvre aussi
 * forgot-password et resend-activation, sans quoi on regenererait un code
 * frais a volonte pour contourner le compteur. Voir RouteServiceProvider.
 */
Route::post('/activation-account', [AuthController::class, 'activation'])
    ->middleware('throttle:auth-sensibles')
    ->name('api.activation');
Route::post('/login', [AuthController::class, 'login'])
    ->middleware(['guest', 'throttle:auth-sensibles'])
    ->name('api.login');

Route::get('/not-auth', [AuthController::class, 'notlogin'])
    ->middleware('guest')
    ->name('login');

Route::post('/resend-activation', [AuthController::class, 'resendActivation'])
    ->middleware('throttle:auth-sensibles')
    ->name('api.resend-activation');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
    ->middleware('throttle:auth-sensibles')
    ->name('api.forgot-password');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:auth-sensibles')
    ->name('api.reset-password');

Route::post('/refresh', [AuthController::class, 'refresh'])->name('api.refresh');

Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout')
    ->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->prefix('/user')->group(function () {

    Route::prefix('/account')->controller(UserAccountController::class)->group(function () {
        Route::get('/', 'index')->name('api.account');
        Route::post('/update', 'update');
        Route::post('/update/password', 'current_password');
        Route::post('/update/adresse', 'update_adresse');

    });
    Route::post('/update/expo/token', [UserAccountController::class, 'expo']);

    // Carnet d'adresses de livraison. La table existait depuis l'origine mais
    // n'etait alimentee nulle part.
    Route::get('/addresses', [UserAddressController::class, 'index']);
    Route::post('/addresses', [UserAddressController::class, 'store']);
    Route::delete('/addresses/{slug}', [UserAddressController::class, 'destroy']);

    Route::prefix('/commande')->controller(CommandeController::class)->group(function () {

        Route::post('/add', 'store');
        Route::get('/current', 'current');
        Route::get('/past', 'historique');
        Route::post('/cancel', 'cancel');
        Route::post('/valide', 'valide');
        Route::post('/check-paiement', 'verif_paiement');
        Route::get('/show/{commande:refernce}', 'show');
        Route::get('/traitement', 'traitement');
        Route::get('/tracking', 'track');
        Route::post('/update-track', 'track_order');
        Route::get('/get-track/{uid}', 'get_track_order');
        Route::post('/delete-product', 'deleteProduct');
        Route::post('/add-product', 'addProduct');
        Route::get('/swr-check-paiement/{orderNumber}', 'swr_check_paiement');
        Route::post('/update-address-delivery', 'updateDeliveryAddress');
        Route::get('/show-order/{uid}', 'showOrder');
        Route::post('/paiement-order/{uid}', 'paiement');

    });

    Route::get('/restaurant', [RestaurantController::class, 'userRestaurant']);
    Route::get('/restaurant-wait-accept-order', [RestaurantController::class, 'waitAcceptOrderRestaurant']);
    Route::get('/restaurant-order-accept', [RestaurantController::class, 'acceptOrderRestaurant']);
    Route::get('/restaurant-current-order', [RestaurantController::class, 'currentOrderRestaurant']);
    Route::get('/restaurant-past-order', [RestaurantController::class, 'pastOrderRestaurant']);
    Route::post('/restaurant-accept-order', [RestaurantController::class, 'confirmOrderRestaurant']);
    Route::get('/restaurant-dash', [RestaurantController::class, 'dashRestaurant']);

    Route::get('/delivery', [DeliveryController::class, 'userRestaurant']);
    Route::get('/delivery-wait-accept-order', [DeliveryController::class, 'waitAcceptOrderDelivrery']);
    Route::get('/delivery-current-order', [DeliveryController::class, 'currentOrderDelivery']);
    Route::get('/delivery-past-order', [DeliveryController::class, 'pastOrderRestaurant']);
    Route::post('/delivery-accept-order', [DeliveryController::class, 'confirmOrderRestaurant']);

    // confirmation de la livraison de la commande
    Route::post('/delivery-confirm-delivery-order', [DeliveryController::class, 'confirmDeliveryRestaurant']);

    // confirmation de la reception de la commande
    Route::post('/delivery-confirm-reception-order', [DeliveryController::class, 'confirmReceptionRestaurant']);
    Route::get('/delivery-dash', [DeliveryController::class, 'dashRestaurant']);

    Route::middleware(['assistant.emetteur', 'throttle:assistants'])->prefix('/assistants')
        ->controller(AssistantTokenController::class)
        ->group(function () {
            Route::post('/', 'store');
            Route::get('/', 'index');
            Route::delete('/{uid}', 'destroy');
        });

});

Route::middleware('auth:sanctum')->group(function () {
    Route::middleware(['ability:catalogue:lire', 'throttle:agent-lecture'])->group(function () {
        Route::get('/products/search', [ProductSearchController::class, 'index']);
    });

    Route::middleware(['ability:devis:calculer', 'throttle:agent-devis'])->group(function () {
        Route::post('/quote', [QuotationController::class, 'quote']);
        Route::post('/budget-suggestions', [QuotationController::class, 'budgetSuggestions']);
    });

    Route::middleware(['ability:precommande:creer', 'throttle:agent-ecriture'])->group(function () {
        Route::post('/precommandes', [PrecommandeController::class, 'store']);
    });

    Route::middleware(['ability:precommande:lire', 'throttle:agent-lecture'])->group(function () {
        Route::get('/precommandes', [PrecommandeController::class, 'index']);
        Route::get('/precommandes/{uid}', [PrecommandeController::class, 'show']);
        Route::get('/user/adresses-recentes', [AdresseRecenteController::class, 'index']);
    });
});

Route::middleware(['auth:sanctum', 'assistant.emetteur', 'throttle:agent-ecriture'])->group(function () {
    Route::post('/precommandes/{uid}/paiement', [PrecommandeController::class, 'payer']);
});

/*
 * Location de vehicules avec chauffeur.
 *
 * Le catalogue exige un creneau : afficher des vehicules sans savoir quand le
 * client veut partir revient a lui montrer des voitures qu'il ne pourra pas
 * reserver.
 *
 * Aucune route ne declare d'ability : le garde RefuserAgentSansAbility les
 * ferme donc aux jetons d'assistant. C'est voulu — reserver une voiture au nom
 * de quelqu'un engage un paiement et un chauffeur, et rien dans le devis ne
 * prevoit qu'un assistant le fasse. Ouvrir ces routes aux agents demandera de
 * declarer des abilities explicites, ce qui se verra en relecture.
 */
/*
 * Le catalogue est public, comme celui des restaurants.
 *
 * On regarde les voitures avant de decider d'en louer une, et exiger un
 * compte pour simplement voir les prix perdrait le visiteur avant qu'il ait
 * une raison de s'inscrire. La reservation, elle, reste derriere le jeton.
 *
 * Rien de sensible ne sort : VehicleResource n'expose pas la plaque
 * d'immatriculation, qui n'apparait qu'une fois la reservation faite et
 * seulement a son proprietaire.
 */
Route::get('/rental/config', RentalConfigController::class);
Route::get('/rental/vehicles', [VehicleController::class, 'index']);
Route::get('/rental/vehicles/{slug}', [VehicleController::class, 'show']);

Route::middleware('auth:sanctum')->prefix('/rental')->group(function () {
    Route::controller(BookingController::class)
        ->prefix('/bookings')
        ->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{uid}', 'show');
            Route::post('/{uid}/cancel', 'cancel');
            Route::post('/{uid}/deposit', 'deposit');
        });

    /*
     * Le suivi de position d'une course.
     *
     * Le chauffeur emet, le client regarde. Les deux routes verifient en SQL
     * qui elles servent : l'uid se forge, il n'autorise rien.
     */
    Route::controller(BookingTrackingController::class)
        ->prefix('/bookings')
        ->group(function () {
            Route::post('/{uid}/position', 'position');
            Route::get('/{uid}/suivi', 'suivi');
        });

    /*
     * Les ecrans du chauffeur de location.
     *
     * Trois lectures, aucune ecriture : il n'accepte ni ne refuse une course,
     * l'affectation vient de l'administration. Son autorisation ne vient pas
     * d'un role mais de sa fiche `Chauffeur` et de `bookings.chauffeur_id`.
     */
    Route::controller(CourseChauffeurController::class)
        ->prefix('/chauffeur')
        ->group(function () {
            Route::get('/resume', 'resume');
            Route::get('/courses', 'courses');
            Route::get('/historique', 'historique');

            /*
             * Les deux seules ecritures du chauffeur, et les deux seules
             * dont il porte la responsabilite sur le terrain : le client est
             * monte, et le solde lui a ete remis en especes. Il n'accepte
             * toujours ni ne refuse une course.
             */
            Route::post('/courses/{uid}/depart', 'depart');
            Route::post('/courses/{uid}/encaisser', 'encaisser');
        });
});

/*
 * Une demande de partenariat, restaurant ou livreur.
 *
 * Publique comme le catalogue : exiger un compte pour dire « je voudrais
 * travailler avec vous » perdrait le restaurateur avant qu'il ait une raison
 * de s'inscrire. Rien n'est cree pour le demandeur, ni compte ni acces : la
 * demande arrive dans l'administration et la societe rappelle.
 *
 * C'est la seule ecriture anonyme de cette API, d'ou la limitation de debit.
 */
Route::post('/prospects', [ProspectController::class, 'store'])->middleware('throttle:prospects');

Route::prefix('/default')->controller(DefaultDataController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/preview', 'preview');
});

/*
 * Ouverture d'une intention de paiement Stripe.
 *
 * Elle etait anonyme : un POST suffisait a faire ouvrir une intention chez
 * Stripe, sur un montant fixe de 1099, autant de fois que demande. Aucun des
 * trois clients ne l'appelle. Elle est fermee derriere le jeton et limitee,
 * pas supprimee.
 */
Route::post('/create-payment-intent', [CallbackUrlController::class, 'paiement'])
    ->middleware(['auth:sanctum', 'throttle:lien-paiement'])
    ->name('payment-intent');

/*
 * Amorcage des roles, en local seulement.
 *
 * Cette route CREAIT quatre roles spatie, sans authentification. Un simple
 * GET depuis Internet ecrivait donc dans la table des roles, et le second
 * appel repondait 500 sur la contrainte d'unicite. Les roles de production
 * sont poses par les seeders, pas par une requete HTTP.
 */
Route::get('/roles', function () {

    abort_unless(app()->environment('local'), 404);

    $role = Role::create(['guard_name' => 'api', 'name' => 'clients']);
    $role = Role::create(['guard_name' => 'api', 'name' => 'drivers']);
    $role = Role::create(['guard_name' => 'api', 'name' => 'admin']);
    $role = Role::create(['guard_name' => 'api', 'name' => 'restaurant']);

});

Route::post('/webhook-paiement-flexpay', [PayementController::class, 'webhook']);
