<?php

namespace App\Http\Resources\Rental;

use App\Helpers\ImageUrl;
use App\Models\CourseType;
use App\Models\Town;
use App\Models\Vehicle;
use App\Wrappers\Cipher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Vehicle $resource
 */
class VehicleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uid' => Cipher::Encrypt($this->resource->id),
            'reference' => $this->resource->reference,
            'slug' => $this->resource->slug,
            'name' => $this->resource->name,
            'brand' => $this->resource->brand,
            'model' => $this->resource->model,
            'seats' => $this->resource->seats,
            'description' => $this->resource->description,
            'image' => $this->resource->image
                ? ImageUrl::make($request, $this->resource->image)
                : null,
            'hourly_rate' => (float) $this->resource->hourly_rate,
            /*
             * Nul quand le vehicule n'est propose qu'a l'heure. L'ecran
             * n'affiche alors pas le mode journee, plutot qu'un prix a zero.
             */
            'daily_rate' => $this->resource->daily_rate !== null
                ? (float) $this->resource->daily_rate
                : null,
            'currency' => $this->resource->currency,

            /*
             * Le tarif qui S'APPLIQUE a la course demandee.
             *
             * Les deux champs au-dessus restent le prix habituel du vehicule.
             * Ceux-ci tiennent compte du type demande, et retombent dessus
             * quand le type n'a pas de tarif propre : « on affiche le prix en
             * fonction du type, et s'il n'y a pas de type on affiche la valeur
             * par defaut ».
             *
             * Ils existent parce que l'ecran annonçait un prix et en facturait
             * un autre : la bascule heure/journee lisait le tarif du vehicule
             * pendant que le total appliquait celui du type. Deux nombres pour
             * la meme course, et le client ne savait pas lequel croire.
             *
             * La regle elle-meme vit sur `Vehicle::tarifPour()`, une seule
             * fois : le devis, le catalogue et cet affichage la lisent, ils ne
             * la recalculent pas.
             */
            'applied_rates' => [
                'hourly' => $this->tarifApplique('hourly', $request),
                'daily' => $this->tarifApplique('daily', $request),
            ],

            /*
             * La ville ou ce vehicule travaille, et s'il peut prendre la
             * course demandee.
             *
             * Le catalogue reste MONDIAL — « ils peuvent voir les vehicules
             * disponibles qui se trouvent dans tous les pays mais ils peuvent
             * commander que pour la course dans le pays d'origine de la
             * voiture ». Le vehicule d'ailleurs est donc rendu, et grise,
             * exactement comme `available` traite celui qui est en course.
             *
             * `reservable` vaut VRAI quand on ne sait pas : pas de commune
             * demandee, ou vehicule sans ville. Ne rien savoir ne doit pas
             * fermer la location.
             */
            'city' => $this->resource->city
                ? [
                    'title' => $this->resource->city->title,
                    'slug' => $this->resource->city->slug,
                ]
                : null,
            'reservable' => $this->reservable($request),

            /*
             * La plaque n'est pas exposee au catalogue : elle identifie un
             * vehicule precis sur la voie publique, et le client n'en a besoin
             * qu'une fois sa reservation confirmee. Elle figure alors sur la
             * reservation.
             */
        ];
    }

    /**
     * Ce vehicule peut-il prendre une course au depart de la commune demandee ?
     *
     * La ville de la commune est resolue UNE FOIS par requete et non par
     * vehicule : quinze lignes de catalogue feraient autrement quinze
     * requetes identiques.
     */
    /**
     * Le tarif de ce mode pour le type demande, celui du vehicule sinon.
     *
     * Nul pour le journalier d'un vehicule qui ne se loue qu'a l'heure :
     * l'ecran n'affiche alors pas le mode journee, plutot qu'un prix a zero.
     *
     * Le type est resolu UNE FOIS par requete et non par vehicule : quinze
     * lignes de catalogue feraient autrement quinze lectures identiques.
     */
    private function tarifApplique(string $mode, Request $request): ?float
    {
        $defaut = $mode === 'daily'
            ? ($this->resource->daily_rate !== null ? (float) $this->resource->daily_rate : null)
            : (float) $this->resource->hourly_rate;

        $typeId = $request->integer('course_type_id');

        if (! $typeId) {
            return $defaut;
        }

        $type = once(fn () => CourseType::query()->find($typeId));

        return $this->resource->tarifPour($type, $mode) ?? $defaut;
    }

    private function reservable(Request $request): bool
    {
        $townId = $request->integer('pickup_town_id');

        if (! $townId) {
            return true;
        }

        // Les communes d'abord : la regle vit sur le modele, et le catalogue
        // doit dire exactement ce que la reservation refusera.
        if (! $this->resource->dessertLaCommune($townId)) {
            return false;
        }

        if (! $this->resource->city_id) {
            return true;
        }

        $cityId = once(fn () => Town::query()->whereKey($townId)->value('city_id'));

        return ! $cityId || (int) $cityId === (int) $this->resource->city_id;
    }
}
