<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Un releve de position, sur une livraison de repas ou une course de location.
 *
 * Une seule table pour les deux : une table jumelle aurait fait deux
 * ecritures pour la meme donnee, et c'est ce motif qui a deja produit deux
 * bugs de production ici.
 *
 * Les lignes ne s'ecrivent que par `SuiviPositions`, qui tient la cadence et
 * la fenetre active. Les creer a la main contourne les deux.
 */
class TrackOrder extends Model
{
    use HasFactory;

    protected $casts = [
        'location_delivery' => 'array',
        'location_customer' => 'array',
        'delivered' => 'boolean',
    ];

    /*
     * Les colonnes du lien polymorphe ne sortent pas en JSON.
     *
     * `GET /api/user/commande/get-track/{uid}` rend ce modele tel quel a
     * l'application installee : la reponse reste octet pour octet celle
     * d'avant, et le changement de table ne se voit pas depuis le telephone.
     */
    protected $hidden = ['suivable_type', 'suivable_id'];

    protected static function booted(): void
    {
        /*
         * `commande_id` est deduite du lien polymorphe, jamais ecrite a la
         * main.
         *
         * Elle ne survit que parce que la reponse de `get-track/{uid}` la
         * contient et que l'application installee la lit. Deduite ici, il n'y
         * a toujours qu'un seul endroit qui decide a quelle course un releve
         * appartient.
         */
        static::creating(function (self $track) {
            if ($track->suivable_type === Commande::class) {
                $track->commande_id = $track->suivable_id;
            }
        });
    }

    /** La commande ou la reservation suivie. */
    public function suivable(): MorphTo
    {
        return $this->morphTo();
    }
}
