<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActionOrderEnum;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategorieResource;
use App\Http\Resources\CommandeResource;
use App\Http\Resources\ProductResource;
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
use App\Wrappers\FirebasePushNotification;
use Exception;
use Flowframe\Trend\Trend;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RestaurantController extends Controller
{
    //
    public function index(Request $request)
    {
        $search = $request->input('search');
        $restaurant = Restaurant::query()->where('is_active', true)
            ->when($search, fn($query) => $query->where('name', 'like', '%' . $search . '%')
                ->orWhere('description', 'like', '%' . $search . '%')
            )
            ->get();
        return RestaurantResource::collection($restaurant);
    }

    public function product(string $slug)
    {
        try {
            $product = Product::with(['restaurant', "currency", "sub_category_product"])
                ->where('slug', $slug)->first();
            return new ProductResource($product);
        } catch (\Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function show(Restaurant $restaurant)
    {

        return new RestaurantResource($restaurant);
    }

    public function categorie(Restaurant $restaurant)
    {
        $restaurantId = $restaurant->id;


        $categories = CategoryProduct::with(['sub_category_product' => function ($query) use ($restaurantId) {
            $query->whereHas('product', function ($subQuery) use ($restaurantId) {
                $subQuery->where('restaurant_id', $restaurantId);
            })
                // Precharge les produits en les restreignant au restaurant :
                // sans cela la ressource remontait toute la sous-categorie,
                // donc les plats des autres restaurants qui la partagent.
                ->with(['product' => function ($subQuery) use ($restaurantId) {
                    $subQuery->where('restaurant_id', $restaurantId)
                        ->where('is_active', true);
                }]);
        }])->whereHas('sub_category_product', function ($query) use ($restaurantId) {
            return $query->whereHas('product', function ($subQuery) use ($restaurantId) {
                $subQuery->where('restaurant_id', $restaurantId);
            });
        })->get();
        return CategorieResource::collection($categories);
    }

    public function productRestaurant(Restaurant $restaurant, string $slug)
    {
        try {
            //code...
            $menu = SubCategoryProduct::query()
                // Meme contrainte que dans categorie() : la sous-categorie est
                // partagee entre restaurants, ses produits ne le sont pas.
                ->with(['product' => function ($query) use ($restaurant) {
                    $query->where('restaurant_id', $restaurant->id)
                        ->where('is_active', true);
                }])
                ->where('slug', $slug)
                ->whereHas('product', function ($query) use ($restaurant) {
                    $query->where('restaurant_id', $restaurant->id);
                })
                ->get();

            return SubCategoryProductResource::collection($menu);
        } catch (Exception $e) {

            return ApiResponse::SERVER_ERROR($e);

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

            $commande = Commande::query()->where('status_id', 2)
                // Une commande annulee n'est pas a preparer : le restaurateur
                // engageait des ingredients et du temps pour un plat que
                // personne ne viendrait chercher.
                ->nonAnnulee()
                ->whereNotNull('accepted_at')
                ->whereHas('commande_products', fn($q) => $q->whereHas('product', fn($q) => $q->where('restaurant_id', $restaurant->id)))
                ->orderBy('updated_at', 'desc')
                ->get();

            if (!$commande) return ApiResponse::GET_DATA(null);

            return ApiResponse::GET_DATA(CommandeResource::collection($commande));

        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function waitAcceptOrderRestaurant()
    {
        try {

            $restaurant = $this->getCurrentRestaurant();

            if (!$restaurant) return ApiResponse::NOT_FOUND('Oups', 'Restaurant introuvable');

            $commande = Commande::query()->where('status_id', 2)
                // Une commande annulee n'est pas a preparer : le restaurateur
                // engageait des ingredients et du temps pour un plat que
                // personne ne viendrait chercher.
                ->nonAnnulee()
                ->whereNull('accepted_at')
                ->whereHas('commande_products', fn($q) => $q->whereHas('product', fn($q) => $q->where('restaurant_id', $restaurant->id)))
                ->orderBy('updated_at', 'desc')
                ->get();

            if (!$commande) return ApiResponse::GET_DATA(null);

            return ApiResponse::GET_DATA(CommandeResource::collection($commande));

        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function acceptOrderRestaurant()
    {
        try {

            $restaurant = $this->getCurrentRestaurant();

            if (!$restaurant) return ApiResponse::NOT_FOUND('Oups', 'Restaurant introuvable');

            $commande = Commande::query()->where('status_id', 2)
                // Une commande annulee n'est pas a preparer : le restaurateur
                // engageait des ingredients et du temps pour un plat que
                // personne ne viendrait chercher.
                ->nonAnnulee()
                ->whereNotNull('accepted_at')
                ->whereHas('commande_products', fn($q) => $q->whereHas('product', fn($q) => $q->where('restaurant_id', $restaurant->id)))
                ->orderBy('updated_at', 'desc')
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

            $restaurant = $this->getCurrentRestaurant();

            if (!$restaurant) return ApiResponse::NOT_FOUND('Oups', 'Restaurant introuvable');

            $status = Status::query()->where('id', '>', 2)->pluck('id');

            $commande = Commande::query()->whereIn('status_id', $status)
                ->orderBy('updated_at', 'desc')
                //->whereNotNull('accepted_at')

                ->whereHas('commande_products', fn($q) => $q->whereHas('product', fn($q) => $q->where('restaurant_id', $restaurant->id)))
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

            /*
             * Ces quatre compteurs interrogeaient Commande sans aucune
             * contrainte de restaurant : chaque restaurateur voyait donc le
             * total de la plateforme, celui de ses concurrents compris.
             *
             * whereNot('accepted_at') etait par ailleurs un usage errone —
             * whereNot attend une colonne, un operateur et une valeur, ou une
             * closure. L'intention etait whereNotNull.
             */
            $pourCeRestaurant = fn () => Commande::query()
                ->whereHas('commande_products', fn ($q) => $q->whereHas(
                    'product',
                    fn ($q) => $q->where('restaurant_id', $restaurant->id)
                ));

            $current_order = $pourCeRestaurant()->where('status_id', 2)->count();
            $current_order_accepted = $pourCeRestaurant()
                ->where('status_id', 2)
                ->whereNotNull('accepted_at')
                ->count();
            $order_cancelation = $pourCeRestaurant()->where('status_id', 4)->count();
            $order_delivery = $pourCeRestaurant()->where('status_id', 3)->count();
            $status = Status::query()->get();

            return ApiResponse::GET_DATA([
                "order_per_year" => $trendYear,
                'order_per_month' => $trendMonth,
                'order_per_days' => $trendDay,
                'order' => [
                    'current' => $current_order,
                    'order_accepted' => $current_order_accepted,
                    'order_cancel' => $order_cancelation,
                    'order_delivery' => $order_delivery,
                    'status' => StatusResource::collection($status),
                ]
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

                    $commande->time_restaurant = Carbon::parse($time)->format('H:i:s');

                    $commande->status_id = 2;

                    $commande->reception = true;

                    //envoyer une notification au client et au livreur

                    if ($commande?->user->expo_push_token) {
                        $push = new FirebasePushNotification();
                        $push->sendPushNotification($commande?->user->expo_push_token, "Etat d'avancemant de votre commande", "Votre commande est en cours de preparation");

                        FirebasePushNotification::sendNotification($commande?->user->expo_push_token, "Etat d'avancemant de votre commande", "Votre commande est en cours de preparation");
                    }
                    //notification au livreur

                    self::sendDeliveryNotification($commande);
                    break;

                case ActionOrderEnum::Decline->value:

                    $commande->cancel_at = now()->format('Y-m-d');
                    $commande->status_id = 4;

                    //envoyer une notification au client

                    if ($commande?->user->expo_push_token) {
                        $push = new FirebasePushNotification();
                        $push->sendPushNotification($commande?->user->expo_push_token, "Etat d'avancemant de votre commande", "Votre commande a été annuler par le restaurant");

                        FirebasePushNotification::sendNotification($commande?->user->expo_push_token, "Etat d'avancemant de votre commande", "Votre commande a été annuler par le restaurant");
                    }

                    break;
            }

            $commande->save();

            return ApiResponse::GET_DATA(new RestaurantResource($restaurant));
        } catch (Exception $e) {

            Log::info($e);
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function getUser()
    {

        return Auth()->user();
    }

    public function getCurrentRestaurant()
    {

        return Restaurant::query()->where('user_id', $this->getUser()?->id)->first();
    }

    public static function sendDeliveryNotification(Commande $commande)
    {
        $deliveries = DelivreryDriver::query()
            ->with("user")
            ->whereDoesntHave('commandes', fn($query) => $query->whereIn('status_id', [3]))->get();

        $tokens = [];
        collect($deliveries)->each(function ($delivery) use ($commande, &$tokens) {

            if ($delivery?->user->expo_push_token) {
                $tokens[] = $delivery?->user->expo_push_token;
            }

        });

        if (!empty($tokens)) {
            $push = new FirebasePushNotification();
            $push->sendPushNotificationMultiUser($tokens, "Nouvelle commande", "Une nouvelle commande sera bientôt disponible. N'oubliez pas de l'accepter pour procéder à la livraison");

            FirebasePushNotification::sendMultiNotification($tokens, "Nouvelle commande", "Une nouvelle commande sera bientôt disponible. N'oubliez pas de l'accepter pour procéder à la livraison");
        }

    }
}
