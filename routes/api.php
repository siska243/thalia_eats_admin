<?php

use App\Http\Controllers\Api\{AuthController, CallbackUrlController, RestaurantController, CategorieProductController, CommandeController, DefaultDataController, UserAccountController};
use App\Wrappers\EasyPay;
use App\Wrappers\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

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

Route::get('/notification',function(){

    $response=Notification::SEND_NOTIFICATION('test','test body');
    return $response;
});

Route::get('/categorie',[CategorieProductController::class,'index']);

Route::prefix('/list-restaurant')->controller(RestaurantController::class)->group(function(){
    Route::get('/','index');
    Route::get('/{restaurant:slug}','show');
});

Route::get('/categorie-restaurant/{restaurant:slug}',[RestaurantController::class, 'categorie']);
Route::get('/menu/{restaurant:slug}/{slug}',[RestaurantController::class, 'productRestaurant']);
Route::get('/menu/{restaurant:slug}/{slug}',[RestaurantController::class, 'productRestaurant']);

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/refresh', [AuthController::class, 'refresh']);
Route::get('/logout', [AuthController::class, 'logout'])->name('api.logout');

Route::middleware('auth:api')->prefix('/user')->group(function(){
   Route::prefix('/account')->controller(UserAccountController::class)->group(function(){
        Route::get('/', 'index');
        Route::post('/update', 'update');
        Route::post('/update/password', 'current_password');
        Route::post('/update/adresse', 'update_adresse');

   });
   Route::post('/update/expo/token', [UserAccountController::class,'expo']);

   Route::prefix('/commande')->controller(CommandeController::class)->group(function(){

       Route::post('/add','store');
       Route::get('/current','current');
       Route::get('/past','index');
       Route::post('/valide','valide');
       Route::get('/show/{commande:refernce}','show');
       Route::get('/traitement','traitement');
   });

   Route::get('/restaurant',[RestaurantController::class, 'userRestaurant']);
   Route::get('/restaurant-wait-accept-order',[RestaurantController::class, 'waitAcceptOrderRestaurant']);
   Route::get('/restaurant-order-accept',[RestaurantController::class, 'acceptOrderRestaurant']);
   Route::get('/restaurant-current-order',[RestaurantController::class, 'currentOrderRestaurant']);
   Route::get('/restaurant-past-order',[RestaurantController::class, 'pastOrderRestaurant']);
   Route::post('/restaurant-accept-order',[RestaurantController::class, 'confirmOrderRestaurant']);
   Route::get('/restaurant-dash',[RestaurantController::class, 'dashRestaurant']);


});

Route::prefix('/default')->controller(DefaultDataController::class)->group(function(){
    Route::get('/','index');
    Route::get('/preview','preview');
});

Route::post('/create-payment-intent',[CallbackUrlController::class,'paiement'])->name('payment-intent');

Route::get('/roles', function () {

    $role = Role::create(['guard_name' => 'api', 'name' => 'clients']);
    $role = Role::create(['guard_name' => 'api', 'name' => 'drivers']);
    $role = Role::create(['guard_name' => 'api', 'name' => 'admin']);
    $role = Role::create(['guard_name' => 'api', 'name' => 'restaurant']);

});
