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

    /**
     * Masque une valeur destinee a une page atteignable par simple detention
     * d'un lien (lien de paiement signe, transferable et journalisable).
     *
     * Largeur FIXE, deliberement : Str::mask() remplace caractere par
     * caractere et le nombre d'asterisques trahit alors la longueur exacte de
     * l'adresse ou du nom. Ici la marque est toujours la meme, quelle que soit
     * la valeur.
     *
     * Une valeur de $visible caracteres ou moins ne laisse filtrer AUCUN
     * prefixe : sans cette borne, un destinataire nomme « Eve » s'afficherait
     * en clair.
     */
    public static function masquer(?string $valeur, int $visible = 3): string
    {
        $valeur = trim((string) $valeur);

        $prefixe = mb_strlen($valeur) > $visible ? mb_substr($valeur, 0, $visible) : '';

        return $prefixe.'******';
    }
}
