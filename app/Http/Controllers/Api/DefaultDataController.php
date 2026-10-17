<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DelivreryPriceResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\TownResource;
use App\Models\DelivreryPrice;
use App\Models\Product;
use App\Models\Town;
use App\Settings\FoodSettings;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

class DefaultDataController extends Controller
{
    //
    public function index()
    {
        $delivrery_price = DelivreryPrice::with('town')->where('is_active', true)->get();
        $town = Town::where('is_active', true)->get();

        return [
            'town' => TownResource::collection($town),
            'delivrery_price' => DelivreryPriceResource::collection($delivrery_price),
            /*
           * Les reglages que les applications doivent connaitre.
           *
           * `position_client` leur dit s'il faut demander la permission de
           * localisation. La demander quand l'option est eteinte serait
           * intrusif et sans objet — le serveur ignorerait la position.
           *
           * Ajout pur : les clients qui ne lisent pas cette cle continuent de
           * fonctionner a l'identique.
           */
            'reglages' => [
                'position_client' => self::positionClientActive(),
            ],
        ];
    }

    /**
     * L'option de position, et faux si les reglages manquent.
     *
     * Cet endpoint est appele par TOUTES les applications au demarrage. Sans
     * cette precaution il repondait 500 des que la table `settings` etait
     * vide — migrations pas encore jouees apres un deploiement, ce qui est
     * deja arrive en production — et plus aucune application ne demarrait.
     *
     * Faux dans le doute : on ne demande pas sa position a quelqu'un sur la
     * foi d'un reglage qu'on n'a pas pu lire.
     */
    private static function positionClientActive(): bool
    {
        try {
            return app(FoodSettings::class)->use_client_position;
        } catch (MissingSettings) {
            return false;
        }
    }

    public function preview()
    {
        $previews = Product::with('restaurant')->where('preview', true)->get();

        return ProductResource::collection($previews);
    }
}
