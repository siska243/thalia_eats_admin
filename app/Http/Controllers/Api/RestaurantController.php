<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Illuminate\Http\Request;

class RestaurantController extends Controller
{
    //
    public function index(){
        $restaurant=Restaurant::all();
        return $restaurant;
    }
}
