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
                'position_client' => app(FoodSettings::class)->use_client_position,
            ],
        ];
    }

    public function preview()
    {
        $previews = Product::with('restaurant')->where('preview', true)->get();

        return ProductResource::collection($previews);
    }
}
