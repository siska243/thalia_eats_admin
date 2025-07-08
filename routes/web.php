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





