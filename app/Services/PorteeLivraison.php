<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\Town;
use App\Settings\FoodSettings;

/**
 * Ce restaurant peut-il livrer a cet endroit ?
 *
 * **La regle vit ici, une seule fois.** Deux bugs de production sont nes du
 * motif inverse : le prix du panier calcule dans le navigateur et absent du
 * serveur, puis l'ouverture du restaurant ecrite dans les deux applications et
 * nulle part sur le serveur. Le catalogue, la recherche, le devis, la
 * precommande et la commande appellent donc tous cette classe.
 *
 * Elle ne refuse jamais faute de donnees. Un parc mal renseigne ne doit pas
 * fermer la plateforme le jour du deploiement : c'est deja la doctrine de
 * `ProductSearchService::eligibleRestaurants()`, « on ne filtre jamais sur une
 * donnee absente ».
 */
class PorteeLivraison
{
    public function __construct(private readonly FoodSettings $settings) {}

    public function pour(
        Restaurant $restaurant,
        ?Town $livraison = null,
        ?float $lat = null,
        ?float $lng = null,
    ): Portee {
        $depuis = $this->positionDuRestaurant($restaurant);
        $vers = $this->positionDeLivraison($livraison, $lat, $lng);

        if ($depuis === null || $vers === null) {
            return Portee::sansControle();
        }

        $distance = RestaurantGeo::distanceKm(
            $depuis['lat'], $depuis['lng'], $vers['lat'], $vers['lng'],
        );

        $rayon = $this->rayonPour($restaurant);

        // Borne inclusive : un restaurant pile sur la limite livre.
        if ($distance <= $rayon) {
            return new Portee(true, $distance, $rayon);
        }

        return new Portee(false, $distance, $rayon, __($this->settings->out_of_radius_message, [
            'restaurant' => $restaurant->name,
            'distance' => $distance,
            'rayon' => $rayon,
        ]));
    }

    /**
     * Le rayon applicable a ce restaurant.
     *
     * Celui de SA ville, pas celui de la livraison : c'est le restaurant qui
     * offre le service, et c'est son marche qui dit jusqu'ou il va. Quand les
     * deux villes different, la distance depasse de toute facon n'importe quel
     * rayon.
     */
    public function rayonPour(Restaurant $restaurant): float
    {
        return $restaurant->town?->city?->max_delivery_radius_km
            ?? $this->settings->max_delivery_radius_km;
    }

    /** @return array{lat: float, lng: float}|null */
    private function positionDuRestaurant(Restaurant $restaurant): ?array
    {
        return RestaurantGeo::coordinates($restaurant->location)
            ?? $this->centroide($restaurant->town);
    }

    /** @return array{lat: float, lng: float}|null */
    private function positionDeLivraison(?Town $town, ?float $lat, ?float $lng): ?array
    {
        if ($lat !== null && $lng !== null) {
            $coords = RestaurantGeo::coordinates(['lat' => $lat, 'lng' => $lng]);

            if ($coords !== null && ! $this->estLePointZero($coords)) {
                return $coords;
            }
        }

        return $this->centroide($town);
    }

    /**
     * Le centroide d'une commune.
     *
     * Approximatif — quelques kilometres sur une commune de Kinshasa — et
     * c'est assume : il sert a separer Gombe de Lubumbashi, pas deux avenues.
     *
     * @return array{lat: float, lng: float}|null
     */
    private function centroide(?Town $town): ?array
    {
        if ($town?->latitude === null || $town->longitude === null) {
            return null;
        }

        return RestaurantGeo::coordinates([
            'lat' => (float) $town->latitude,
            'lng' => (float) $town->longitude,
        ]);
    }

    /**
     * Le point zero du golfe de Guinee.
     *
     * Un GPS qui n'a pas encore fixe renvoie (0, 0). Le prendre au mot
     * placerait chaque client a 5 000 km de Kinshasa et fermerait tout le
     * catalogue : on le traite comme une position absente.
     *
     * @param  array{lat: float, lng: float}  $coords
     */
    private function estLePointZero(array $coords): bool
    {
        return abs($coords['lat']) < 0.000001 && abs($coords['lng']) < 0.000001;
    }
}
