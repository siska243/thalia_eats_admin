<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DelivreryPriceResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\TownResource;
use App\Models\DelivreryPrice;
use App\Models\Product;
use App\Models\Town;
use App\Wrappers\ApiResponse;
use Illuminate\Http\Request;

class DefaultDataController extends Controller
{
    //
    public function index(){
      $delivrery_price=DelivreryPrice::with('town')->where('is_active',true)->get();
      $town=Town::where('is_active',true)->get();

      return [
        'town'=>TownResource::collection($town),
        'delivrery_price'=>DelivreryPriceResource::collection($delivrery_price)
      ];
    }

    public function preview()
    {
        $previews=Product::with('restaurant')->where('preview',true)->get();

        return ProductResource::collection($previews);
    }
}
