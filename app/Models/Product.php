<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function boot()
    {
        parent::boot();

        static::creating(function (Model $model) {

            $value = Str::slug($model->title);
            $md = uniqid();
            $model->slug = "{$value}-{$md}";
        });

        static::updating(function (Model $model) {
            $value = Str::slug($model->title);
            $md = uniqid();
            $model->slug = "{$value}-{$md}";
        });
    }

    /**
     * Le stock de ce plat est-il suivi ?
     *
     * `stock_quantity` a null veut dire « non suivi », et c'est le defaut :
     * tout le catalogue d'aujourd'hui se comporte comme avant. Zero est un
     * etat different, et il veut dire epuise.
     *
     * Le proprietaire, le 16 octobre : « le stock est pris en charge que si on
     * l'active depuis l'admin dans chaque produit ».
     */
    public function suitLeStock(): bool
    {
        return $this->stock_quantity !== null;
    }

    /**
     * Ce plat est-il epuise ?
     *
     * **La regle vit ici, une seule fois.** Trois ecrans et trois refus la
     * posent la meme question ; la recopier, c'est se preparer a la voir
     * diverger — c'est deja arrive sur les horaires d'ouverture et sur la
     * portee de livraison.
     *
     * Un plat non suivi n'est JAMAIS epuise : ne rien savoir du stock ne doit
     * pas fermer la vente.
     */
    public function estEpuise(): bool
    {
        return $this->suitLeStock() && $this->stock_quantity <= 0;
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class, 'restaurant_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function sub_category_product(): BelongsTo
    {
        return $this->belongsTo(SubCategoryProduct::class, 'sub_category_product_id');
    }

    public function commande_product(): HasMany
    {
        return $this->hasMany(CommandeProduct::class);
    }
}
