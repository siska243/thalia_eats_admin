<?php

namespace App\Helpers;

use App\Models\Commande;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CurrentHelpers
{
    public static function getUserByOrder(Commande $order): User|Model|null
    {
        $order = $order->loadMissing(
            [
                'commande_products',
                'commande_products.product',
                'commande_products.product.restaurant',
                'commande_products.product.restaurant.user'
            ]
        );

        if ($order->commande_products->count() > 0) {
            $cmd = $order->commande_products->first();
            return $cmd?->product?->restaurant?->user;
        }

        return null;
    }
}
