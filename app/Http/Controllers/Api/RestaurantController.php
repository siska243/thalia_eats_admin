<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActionOrderEnum;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategorieResource;
use App\Http\Resources\CommandeResource;
use App\Http\Resources\RestaurantResource;
use App\Http\Resources\SubCategoryProductResource;
use App\Models\CategoryProduct;
use App\Models\Commande;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\SubCategoryProduct;
use App\Wrappers\ApiResponse;
use App\Wrappers\Cipher;
use Exception;
use Flowframe\Trend\Trend;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RestaurantController extends Controller
{
    //
    public function index()
    {
        $restaurant = Restaurant::where('is_active', true)->get();
        return RestaurantResource::collection($restaurant);
    }

    public function show(Restaurant $restaurant)
    {

        return new RestaurantResource($restaurant);
    }

    public function categorie(Restaurant $restaurant)
    {
        $restaurantId = $restaurant->id; // Remplacez par l'ID du restaurant souhaité


        $categories = CategoryProduct::with(['sub_category_product' => function ($query) use ($restaurantId) {
            $query->whereHas('product', function ($subQuery) use ($restaurantId) {
                $subQuery->where('restaurant_id', $restaurantId);
            });
        }])->whereHas('sub_category_product.product', function ($query) use ($restaurantId) {
            $query->where('restaurant_id', $restaurantId);
        })->get();
        return CategorieResource::collection($categories);
    }

    public function productRestaurant(Restaurant $restaurant, string $slug)
    {
        try {
            //code...
            $menu = SubCategoryProduct::with(['product'])
                ->where('slug', $slug)
                ->whereHas('product', function ($query) use ($restaurant) {
                    $query->where('restaurant_id', $restaurant->id);
                })
                ->get();

            return SubCategoryProductResource::collection($menu);
        } catch (Exception $e) {
            //throw $th;

            return ApiResponse::SERVER_ERROR($e);

            //return $th;
        }
    }

    public function userRestaurant()
    {
        try {

            $restaurant = $this->getCurrentRestaurant();

            if (!$restaurant) return ApiResponse::NOT_FOUND('Oups', 'Restaurant introuvable');

            return ApiResponse::GET_DATA(new RestaurantResource($restaurant));
        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function currentOrderRestaurant()
    {
        try {

            $restaurant = $this->getCurrentRestaurant();

            if (!$restaurant) return ApiResponse::NOT_FOUND('Oups', 'Restaurant introuvable');

            $commande = Commande::where('status_id', 3)
                ->whereNotNull('accepted_at')
                ->whereHas('commande_products', fn($q) => $q->whereHas('product', fn($q) => $q->where('restaurant_id', $restaurant->id)))

                ->first();

            if (!$commande) return ApiResponse::GET_DATA(null);

            return ApiResponse::GET_DATA(new CommandeResource($commande));
        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function waitAcceptOrderRestaurant()
    {
        try {

            $restaurant = $this->getCurrentRestaurant();

            if (!$restaurant) return ApiResponse::NOT_FOUND('Oups', 'Restaurant introuvable');

            $commande = Commande::where('status_id', 2)
                ->whereNull('accepted_at')
                ->whereHas('commande_products', fn($q) => $q->whereHas('product', fn($q) => $q->where('restaurant_id', $restaurant->id)))

                ->first();

            if (!$commande) return ApiResponse::GET_DATA(null);

            return ApiResponse::GET_DATA(new CommandeResource($commande));
        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function pastOrderRestaurant()
    {
        try {

            $restaurant = $this->getCurrentRestaurant();

            if (!$restaurant) return ApiResponse::NOT_FOUND('Oups', 'Restaurant introuvable');

            $commande = Commande::where('status_id', '>', 3)
                ->whereNotNull('accepted_at')

                ->whereHas('commande_products', fn($q) => $q->whereHas('product', fn($q) => $q->where('restaurant_id', $restaurant->id)))

                ->first();

            if (!$commande) return ApiResponse::GET_DATA(null);

            return ApiResponse::GET_DATA(new CommandeResource($commande));
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
                //->where('status_id', '>', 1)
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
            return ApiResponse::GET_DATA([
                "order_per_year"=>$trendYear,
                'order_per_month'=> $trendMonth,
                'order_per_days'=> $trendDay,
            ]);
        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function confirmOrderRestaurant(Request $request)
    {
        try {

            $restaurant = $this->getCurrentRestaurant();

            if (!$restaurant) return ApiResponse::NOT_FOUND('Oups', 'Restaurant introuvable');

            $uid_order = $request->input('uid_order');

            $action = $request->input('action');

            $time = $request->input('time');

            if (!$action) {

                return ApiResponse::BAD_REQUEST("Oups", "Action", "Action is required");
            }

            $commande = Commande::query()->where('id', Cipher::Decrypt($uid_order))
                ->whereHas(
                    'commande_products',
                    fn($q) => $q->whereHas('product', fn($q) => $q->where('restaurant_id', $restaurant->id))
                )

                ->whereNull('accepted_at')
                ->whereNull('cancel_at')->first();


            if (!$commande) return ApiResponse::NOT_FOUND("Oups", "Commande not found");

            switch ($action) {

                case ActionOrderEnum::Accept->value:

                    if (!$time) return ApiResponse::BAD_REQUEST("Oups", "Time is required", "Time is required");

                    $commande->accepted_at = now()->format('Y-m-d');

                    $commande->time_restarant = $time;

                    $commande->status_id = 3;

                    $commande->reception = true;

                    //envoyer une notification au client et au livreur
                    break;

                case ActionOrderEnum::Decline->value:

                    $commande->cancel_at = now()->format('Y-m-d');
                    //envoyer une notification au client
            }



            $commande->save();



            return ApiResponse::GET_DATA(new RestaurantResource($restaurant));
        } catch (Exception $e) {
            dd($e->getMessage());
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function getUser()
    {

        return Auth()->user();
    }

    public function getCurrentRestaurant()
    {

        return Restaurant::where('user_id', $this->getUser()?->id)->first();
    }
}
