<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActionOrderEnum;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategorieResource;
use App\Http\Resources\CommandeResource;
use App\Http\Resources\RestaurantResource;
use App\Http\Resources\StatusResource;
use App\Http\Resources\SubCategoryProductResource;
use App\Models\CategoryProduct;
use App\Models\Commande;
use App\Models\DelivreryDriver;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Status;
use App\Models\SubCategoryProduct;
use App\Wrappers\ApiResponse;
use App\Wrappers\Cipher;
use Exception;
use Flowframe\Trend\Trend;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DeliveryController extends Controller
{
    //
    public function index()
    {
        $restaurant = Restaurant::where('is_active', true)->get();
        return RestaurantResource::collection($restaurant);
    }



    public function currentOrderDelivery()
    {
        try {

            $restaurant = $this->getCurrentDelivery();



            if (!$restaurant) return ApiResponse::NOT_FOUND('Oups', 'Delivery introuvable');

            $commande = Commande::where('status_id', 2)
                ->whereNotNull('accepted_at')
                ->where('delivrery_driver_id',$restaurant->id)
                //->whereHas('commande_products', fn($q) => $q->whereHas('product', fn($q) => $q->where('restaurant_id', $restaurant->id)))
                ->orderBy('updated_at','desc')
                ->first();

            if (!$commande) return ApiResponse::GET_DATA(null);

            return ApiResponse::GET_DATA(new CommandeResource($commande));

        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function waitAcceptOrderDelivrery()
    {
        try {

            $restaurant = $this->getCurrentDelivery();

            if (!$restaurant) return ApiResponse::NOT_FOUND('Oups', 'Delivery introuvable');


            $commande = Commande::where('status_id', 2)
                ->whereNotNull('accepted_at')
                ->whereNull('delivrery_driver_id')
                //->whereHas('commande_products', fn($q) => $q->whereHas('product', fn($q) => $q->where('restaurant_id', $restaurant->id)))
                ->orderBy('updated_at','desc')
                ->get();

            if (!$commande) return ApiResponse::GET_DATA(null);

            return ApiResponse::GET_DATA(CommandeResource::collection($commande));

        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }



    public function pastOrderRestaurant()
    {
        try {

            $restaurant = $this->getCurrentDelivery();

            if (!$restaurant) return ApiResponse::NOT_FOUND('Oups', 'Restaurant introuvable');

            $status=Status::query()->where('id','>',2)->pluck('id');

            $commande = Commande::query()->whereIn('status_id',$status )
                ->orderBy('updated_at','desc')
                ->where('delivrery_driver_id',$restaurant->id)

                ->get();

            if (!$commande) return ApiResponse::GET_DATA([]);

            return ApiResponse::GET_DATA(CommandeResource::collection($commande));

        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function dashRestaurant()
    {
        try {

            $restaurant = $this->getCurrentRestaurant();

            if (!$restaurant) return ApiResponse::NOT_FOUND('Oups', 'Restaurant introuvable');

            $commande = Commande::query()
                ->where('status_id', '>', 1)
                //>whereNotNull('accepted_at')
                ->whereHas('commande_products', fn($q) => $q->whereHas('product', fn($q) => $q->where('restaurant_id', $restaurant->id)));


            $columns = ['global_price', 'price_delivery', 'price_service'];

            DB::statement("SET sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''));");
             // Totals per month
             $trendDay = collect();

            // Totals per month
            $trendMonth = collect();

            foreach ($columns as $column) {
                $trendMonth->put(
                    $column,
                    Trend::query($commande)
                    ->between(
                        start: now()->startOfYear(),
                        end: now()->endOfYear(),
                    )
                        ->perMonth()
                        ->sum($column)
                        //->count()
                );
            }

            $trendYear = collect();

            foreach ($columns as $column) {
                $trendYear->put(
                    $column,
                    Trend::query($commande)
                        ->between(
                            start: now()->startOfYear()->subYears(10),
                            end: now()->endOfYear()
                        )
                        ->perYear()
                        //->sum($column)
                        ->count()
                );
            }

            DB::statement("SET sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY','ONLY_FULL_GROUP_BY'));");

            $current_order=Commande::query()->where('status_id',2)->count();
            $current_order_accepted=Commande::query()->where('status_id',2)->whereNot('accepted_at')->count();;
            $order_cancelation= Commande::query()->where('status_id',4)->count();
            $order_delivery=Commande::query()->where('status_id',3)->count();
            $status=Status::query()->get();

            return ApiResponse::GET_DATA([
                "order_per_year"=>$trendYear,
                'order_per_month'=> $trendMonth,
                'order_per_days'=> $trendDay,
                'order'=>[
                    'current'=>$current_order,
                    'order_accepted'=>$current_order_accepted,
                    'order_cancel'=>$order_cancelation,
                    'order_delivery'=>$order_delivery,
                    'status'=>StatusResource::collection($status),
                ]
            ]);

        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function confirmOrderRestaurant(Request $request)
    {
        try {

            $restaurant = $this->getCurrentDelivery();

            if (!$restaurant) return ApiResponse::NOT_FOUND('Oups', 'Restaurant introuvable');

            $uid_order = $request->input('uid_order');

            $check_have_cmd=Commande::where('status_id', 2)
            ->whereNotNull('accepted_at')
            ->where('delivrery_driver_id', $restaurant->id)->first();

            if($check_have_cmd) return ApiResponse::BAD_REQUEST('','Oups','Vous avez déja une commande en cours');


            $commande = Commande::query()->where('id', Cipher::Decrypt($uid_order))

                ->whereNull('delivrery_driver_id')
                ->whereNull('cancel_at')->first();


            if (!$commande) return ApiResponse::NOT_FOUND("Oups", "Commande not found");


            $commande->delivrery_driver_id=$restaurant->id;

            $commande->save();



            return ApiResponse::GET_DATA(new RestaurantResource($restaurant));

        } catch (Exception $e) {

            return ApiResponse::SERVER_ERROR($e);
        }
    }


    public function confirmReceptionRestaurant(Request $request)
    {
        try {

            $restaurant = $this->getCurrentDelivery();
            $time = $request->input('time');
            $code= $request->input('code');
            $uid_order = $request->input('uid_order');

            if (!$restaurant) return ApiResponse::NOT_FOUND('Oups', 'Restaurant introuvable');


            $commande = Commande::query()->where('id', Cipher::Decrypt($uid_order))

                ->where('delivrery_driver_id',$restaurant->id)
                ->where('code_confirmation_restaurant',$code)
                ->where('status_id',2)
               ->first();

            if(!$time) return ApiResponse::BAD_REQUEST('', 'Oups!!',"L'heure de livraison de la commande est obligatoire");

            if(!$code) return ApiResponse::BAD_REQUEST('', 'Oups!!',"Le code de la recuperation de la commande est obligatoire");

            if (!$commande) return ApiResponse::BAD_REQUEST("Oups", "Commande not found","Code de confirmation est incorrecte");


            $commande->time_delivery=$time;

            $commande->save();


            return ApiResponse::GET_DATA(new RestaurantResource($restaurant));

        } catch (Exception $e) {

            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function confirmDeliveryRestaurant(Request $request)
    {
        try {

            $restaurant = $this->getCurrentDelivery();
            $code= $request->input('code');
            $uid_order = $request->input('uid_order');

            if (!$restaurant) return ApiResponse::NOT_FOUND('Oups', 'Delivery introuvable');


            $commande = Commande::query()->where('id', Cipher::Decrypt($uid_order))

                ->where('delivrery_driver_id',$restaurant->id)
                ->where('code_confirmation',$code)
                ->where('status_id',2)
               ->first();



            if(!$code) return ApiResponse::BAD_REQUEST('', 'Oups!!',"Le code de livraison de la commande est obligatoire");

            if (!$commande) return ApiResponse::BAD_REQUEST("Oups", "Commande not found","Code de confirmation est incorrecte");


            $commande->status_id=3;

            $commande->delivery_at=Carbon::now();

            $commande->save();


            return ApiResponse::GET_DATA(new RestaurantResource($restaurant));

        } catch (Exception $e) {

            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function getUser()
    {
        return Auth()->user();
    }

    public function getCurrentDelivery()
    {

        return DelivreryDriver::where('user_id', $this->getUser()?->id)->first();
    }
}
