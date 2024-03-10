<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategorieResource;
use App\Http\Resources\RestaurantResource;
use App\Http\Resources\SubCategoryProductResource;
use App\Models\CategoryProduct;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\SubCategoryProduct;
use App\Wrappers\ApiResponse;
use Exception;
use Illuminate\Http\Request;

class RestaurantController extends Controller
{
    //
    public function index(){
        $restaurant=Restaurant::where('is_active',true)->get();
        return RestaurantResource::collection($restaurant);
    }

    public function show(Restaurant $restaurant){

       return new RestaurantResource($restaurant);
    }

    public function categorie(Restaurant $restaurant){
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

    public function productRestaurant(Restaurant $restaurant,string $slug){
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
}
