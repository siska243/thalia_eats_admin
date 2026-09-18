<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActionOrderEnum;
use App\Helpers\CurrentHelpers;
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
use App\Wrappers\FirebasePushNotification;
use Exception;
use Flowframe\Trend\Trend;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DeliveryController extends Controller
{
    //
    public function index()
    {
        $restaurant = Restaurant::query()->where('is_active', true)->get();
        return RestaurantResource::collection($restaurant);
    }

    /**
     * Definition unique d'une course en cours pour un livreur.
     *
     * Elle etait ecrite deux fois — dans currentOrderDelivery et dans le
     * controle d'acceptation — avec des criteres differents. Un livreur
     * portant une commande annulee voyait donc « Ma course » vide tout en
     * etant refuse a l'acceptation, sans aucun moyen de se debloquer.
     */
    private function courseEnCours(DelivreryDriver $livreur)
    {
        return Commande::query()
            ->where('status_id', 2)
            ->whereNotNull('accepted_at')
            ->where('delivrery_driver_id', $livreur->id)
            ->whereHas('commande_products')
            // Une commande annulee ou deja remise n'est plus une course. Le
            // statut ne suffit pas : l'annulation depuis l'admin renseigne
            // cancel_at sans toujours faire passer status_id a 4.
            ->whereNull('cancel_at')
            ->whereNull('delivery_at');
    }

    public function currentOrderDelivery()
    {
        try {

            $restaurant = $this->getCurrentDelivery();

            if (!$restaurant) return ApiResponse::NOT_FOUND('Oups', 'Delivery introuvable');

            $commande = $this->courseEnCours($restaurant)
                ->with(['user', 'status', 'town', 'product.product.restaurant', 'product.currency'])
                ->orderBy('updated_at', 'desc')
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


            $commande = Commande::query()
                ->with([
                    // Sans ce prechargement, CommandeResource declenche une
                    // rafale de requetes par ligne : la ressource lit product
                    // et user via whenLoaded dont la valeur par defaut est
                    // evaluee immediatement, ce qui charge la relation au lieu
                    // de l'omettre.
                    'user',
                    'status',
                    'town',
                    'product.product.restaurant',
                    'product.currency',
                ])
                ->where('status_id', 2)
                ->whereNotNull('accepted_at')
                ->whereNull('delivrery_driver_id')
                ->whereHas('commande_products')
                // Une commande annulee ou deja remise n'est plus une course.
                // Le statut ne suffit pas : l'annulation depuis l'admin
                // renseigne cancel_at sans toujours faire passer status_id a 4,
                // et une course de juillet 2025 restait ainsi affichee comme
                // « en cours » au livreur.
                ->whereNull('cancel_at')
                ->whereNull('delivery_at')
                ->orderBy('updated_at', 'desc')
                ->get();

            if ($commande->count() == 0) return ApiResponse::GET_DATA([]);

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

            $status = Status::query()->where('id', '>', 2)->pluck('id');

            $commande = Commande::query()
                ->with(['user', 'status', 'town', 'product.product.restaurant', 'product.currency'])
                ->whereIn('status_id', $status)
                // Une commande annulee n'est pas une course : le livreur ne
                // l'a pas faite, et elle n'a pas a figurer dans son historique.
                // Le statut ne suffit pas, l'annulation depuis l'admin
                // renseigne cancel_at sans toujours passer status_id a 4.
                ->whereNull('cancel_at')
                ->where('status_id', '!=', 4)
                ->orderBy('updated_at', 'desc')
                ->where('delivrery_driver_id', $restaurant->id)
                ->whereHas('commande_products')
                ->get();

            if ($commande->count() == 0) return ApiResponse::GET_DATA([]);

            return ApiResponse::GET_DATA(CommandeResource::collection($commande));

        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function dashRestaurant()
    {
        try {

            $livreur = $this->getCurrentDelivery();

            if (!$livreur) return ApiResponse::NOT_FOUND('Oups', 'Livreur introuvable');

            // getCurrentRestaurant() n'existe que dans RestaurantController :
            // l'appel levait une Error, que le catch (Exception) ne rattrape
            // pas. GET /api/user/delivery-dash repondait donc 500 a chaque
            // fois. Le tableau de bord est celui du livreur, pas d'un
            // restaurant — il est desormais borne a ses propres courses.
            $commande = Commande::query()
                ->where('status_id', '>', 1)
                ->where('delivrery_driver_id', $livreur->id);


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

            // Ces compteurs etaient globaux : chaque livreur voyait l'activite
            // de toute la plateforme. Et whereNot('accepted_at') comparait la
            // colonne a la chaine vide au lieu de tester sa nullite, ce qui
            // renvoyait toujours tout.
            $pourCeLivreur = fn () => Commande::query()
                ->where('delivrery_driver_id', $livreur->id);

            $current_order = $pourCeLivreur()->where('status_id', 2)->nonAnnulee()->count();
            $current_order_accepted = $pourCeLivreur()
                ->where('status_id', 2)
                ->nonAnnulee()
                ->whereNull('accepted_at')
                ->count();
            $order_cancelation = $pourCeLivreur()->where('status_id', Commande::STATUT_ANNULEE)->count();
            $order_delivery = $pourCeLivreur()->where('status_id', 3)->count();
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

            $restaurant = $this->getCurrentDelivery();

            if (!$restaurant) return ApiResponse::NOT_FOUND('Oups', 'Restaurant introuvable');

            $uid_order = $request->input('uid_order');

            $check_have_cmd = $this->courseEnCours($restaurant)->first();

            if ($check_have_cmd) return ApiResponse::BAD_REQUEST('', 'Oups', 'Vous avez déja une commande en cours');


            $commande = Commande::query()->where('id', Cipher::Decrypt($uid_order))
                ->whereNull('delivrery_driver_id')
                ->whereNull('cancel_at')->first();


            if (!$commande) return ApiResponse::NOT_FOUND("Oups", "Commande not found");


            $commande->delivrery_driver_id = $restaurant->id;

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
            $code = $request->input('code');
            $uid_order = $request->input('uid_order');

            if (!$restaurant) return ApiResponse::NOT_FOUND('Oups', 'Restaurant introuvable');



            $commande = Commande::query()
                ->where('id', Cipher::Decrypt($uid_order))
                ->where('delivrery_driver_id', $restaurant->id)
                ->where('code_confirmation_restaurant', $code)
                //->where('status_id', 2)
                ->first();

            if (!$time) return ApiResponse::BAD_REQUEST('', 'Oups!!', "L'heure de livraison de la commande est obligatoire");

            if (!$code) return ApiResponse::BAD_REQUEST('', 'Oups!!', "Le code de la recuperation de la commande est obligatoire");

            if ($minutes = $this->codeLockRemaining('reception', $uid_order)) {
                return ApiResponse::TOO_MANY_ATTEMPTS(
                    'Saisie bloquee',
                    "Trop de codes errones. Reessayez dans {$minutes} minutes."
                );
            }

            if (!$commande) {
                $this->registerCodeFailure('reception', $uid_order);

                return ApiResponse::BAD_REQUEST("Oups", "Commande not found", "Code de confirmation est incorrecte");
            }

            $this->clearCodeFailures('reception', $uid_order);


            $commande->time_delivery = Carbon::parse($time)->format('H:i:s');

            $commande->save();


            if ($commande?->user->expo_push_token) {
                $push = new FirebasePushNotification();
                $push->sendPushNotification($commande?->user->expo_push_token, "Etat d'avancemant de votre commande", "Votre commande a été récupérée par le livreur. Il est actuellement en route vers vous.");

                FirebasePushNotification::sendNotification($commande?->user->expo_push_token, "Etat d'avancemant de votre commande", "Votre commande a été récupérée par le livreur. Il est actuellement en route vers vous.");
            }

            return ApiResponse::SUCCESS_DATA(new RestaurantResource($restaurant), "Saved", "Successfully confirmed commande");

        } catch (Exception $e) {

            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function confirmDeliveryRestaurant(Request $request)
    {
        try {

            $restaurant = $this->getCurrentDelivery();
            $code = $request->input('code');
            $uid_order = $request->input('uid_order');

            if (!$restaurant) return ApiResponse::NOT_FOUND('Oups', 'Delivery introuvable');

            $commande = Commande::query()->where('id', Cipher::Decrypt($uid_order))
                ->where('delivrery_driver_id', $restaurant->id)
                ->where('code_confirmation', $code)
                //->where('status_id', 2)
                ->first();


            if (!$code) return ApiResponse::BAD_REQUEST('', 'Oups!!', "Le code de livraison de la commande est obligatoire");

            if ($minutes = $this->codeLockRemaining('livraison', $uid_order)) {
                return ApiResponse::TOO_MANY_ATTEMPTS(
                    'Saisie bloquee',
                    "Trop de codes errones. Reessayez dans {$minutes} minutes."
                );
            }

            if (!$commande) {
                $this->registerCodeFailure('livraison', $uid_order);

                return ApiResponse::BAD_REQUEST("Oups", "Commande not found", "Code de confirmation est incorrecte");
            }

            $this->clearCodeFailures('livraison', $uid_order);


            $user = CurrentHelpers::getUserByOrder($commande);

            if ($user) {


                if ($user?->expo_push_token) {

                    $ref = $commande->refernce;
                    FirebasePushNotification::sendNotification($user->expo_push_token, "Thalia eats commande", "La commande {$ref} à été livrée");
                }

            }
            $commande->status_id = 3;

            $commande->delivery_at = Carbon::now();

            $commande->save();


            return ApiResponse::SUCCESS_DATA(new RestaurantResource($restaurant), "Saved", "Successfully delivery commande");

        } catch (Exception $e) {

            return ApiResponse::SERVER_ERROR($e);
        }
    }

    /** Codes errones toleres avant blocage de la saisie. */
    public const MAX_CODE_ATTEMPTS = 3;

    /** Duree du blocage, en secondes. */
    public const CODE_LOCK_SECONDS = 25 * 60;

    private function codeCacheKeys(string $step, $uid_order): array
    {
        $driver = $this->getUser()?->id;

        return [
            "driver-code-attempts:{$step}:{$driver}:{$uid_order}",
            "driver-code-lock:{$step}:{$driver}:{$uid_order}",
        ];
    }

    /**
     * Minutes restantes avant de pouvoir ressaisir un code, ou null si la
     * saisie est ouverte.
     *
     * Le verrou vit dans le cache plutot que dans une colonne : il est
     * temporaire par nature, et rien d'autre n'a besoin de le lire.
     */
    private function codeLockRemaining(string $step, $uid_order): ?int
    {
        [, $lockKey] = $this->codeCacheKeys($step, $uid_order);

        $until = Cache::get($lockKey);

        if (!$until) return null;

        return max((int) ceil(($until - Carbon::now()->timestamp) / 60), 1);
    }

    /**
     * Enregistre un code refuse. Au troisieme, la saisie est bloquee pour
     * CODE_LOCK_SECONDS a compter de cette tentative.
     */
    private function registerCodeFailure(string $step, $uid_order): void
    {
        [$attemptsKey, $lockKey] = $this->codeCacheKeys($step, $uid_order);

        $attempts = (int) Cache::get($attemptsKey, 0) + 1;

        if ($attempts >= self::MAX_CODE_ATTEMPTS) {
            Cache::forget($attemptsKey);
            Cache::put(
                $lockKey,
                Carbon::now()->addSeconds(self::CODE_LOCK_SECONDS)->timestamp,
                Carbon::now()->addSeconds(self::CODE_LOCK_SECONDS)
            );

            return;
        }

        Cache::put($attemptsKey, $attempts, Carbon::now()->addSeconds(self::CODE_LOCK_SECONDS));
    }

    private function clearCodeFailures(string $step, $uid_order): void
    {
        [$attemptsKey, $lockKey] = $this->codeCacheKeys($step, $uid_order);

        Cache::forget($attemptsKey);
        Cache::forget($lockKey);
    }

    public function getUser()
    {
        return auth()->user();
    }

    public function getCurrentDelivery()
    {

        return DelivreryDriver::query()->where('user_id', $this->getUser()?->id)->first();
    }
}
