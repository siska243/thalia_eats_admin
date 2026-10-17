<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\Town;
use App\Settings\FoodSettings;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

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
    /**
     * Ecart maximal admis entre la position annoncee par le client et le
     * centre de la commune qu'il declare.
     *
     * Au-dela, la position n'est pas croyable pour cette commune et le
     * centroide reprend la main. Cinquante kilometres sont genereux — aucune
     * commune n'est aussi etendue — et c'est voulu : il s'agit d'ecarter une
     * contradiction grossiere, pas d'arbitrer une adresse en peripherie.
     */
    private const ECART_MAX_AVEC_LA_COMMUNE_KM = 50.0;

    public function __construct(private readonly FoodSettings $settings) {}

    /**
     * @param  array{0: float, 1: float}|null  $positionClient  Ou se trouve
     *                                                          physiquement le client, quand l'option d'administration le demande.
     */
    public function pour(
        Restaurant $restaurant,
        ?Town $livraison = null,
        ?float $lat = null,
        ?float $lng = null,
        ?array $positionClient = null,
    ): Portee {
        $depuis = $this->positionDuRestaurant($restaurant);

        if ($depuis === null) {
            return Portee::sansControle();
        }

        $rayon = $this->rayonPour($restaurant);

        /*
         * Le client doit-il etre a portee, lui aussi ?
         *
         * Option d'administration, eteinte par defaut, et qui passe AVANT
         * tout le reste — la ville comprise. « Je suis au Maroc mais je vois
         * MOOD qui est a Gombe ? c'est pas normal. »
         *
         * Contrepartie assumee : tant qu'elle est allumee, on ne commande
         * plus pour sa famille depuis l'etranger, meme vers la ville du
         * restaurant. C'est exactement ce que cette option sert a decider, et
         * c'est pourquoi elle est eteinte par defaut.
         *
         * Position inconnue, permission refusee : on ne refuse rien de ce
         * fait. Un catalogue vide pour une permission declinee serait pire
         * que la regle qu'on cherche a tenir.
         */
        $refusClient = $this->refuserSurLaPositionDuClient($restaurant, $depuis, $rayon, $positionClient);

        if ($refusClient !== null) {
            return $refusClient;
        }

        /*
         * La ville tranche avant la DISTANCE, quand l'option est allumee.
         *
         * Le proprietaire, le 16 octobre : « on tient compte soit de la
         * ville, et la distance c'est un repli ». Kinshasa fait plus de
         * quarante kilometres de large : un rayon de trente y refusait des
         * livraisons parfaitement normales.
         *
         * Le rayon reprend la main dans les deux cas ou la ville ne tranche
         * pas — villes differentes, ou ville inconnue d'un cote.
         */
        $vers = $this->positionDeLivraison($livraison, $lat, $lng);

        if ($this->memeVille($restaurant, $livraison)) {
            $distance = $vers !== null
                ? RestaurantGeo::distanceKm($depuis['lat'], $depuis['lng'], $vers['lat'], $vers['lng'])
                : null;

            return new Portee(true, $distance, is_infinite($rayon) ? null : $rayon);
        }

        if ($vers === null) {
            return Portee::sansControle();
        }

        $distance = RestaurantGeo::distanceKm(
            $depuis['lat'], $depuis['lng'], $vers['lat'], $vers['lng'],
        );

        /*
         * Rayon inconnu : la distance est calculee et montree, mais rien n'est
         * refuse. Cf. `rayonGlobal()` — un reglage manquant ne ferme pas la
         * boutique.
         */
        if (is_infinite($rayon)) {
            return new Portee(true, $distance);
        }

        // Borne inclusive : un restaurant pile sur la limite livre.
        if ($distance <= $rayon) {
            return new Portee(true, $distance, $rayon);
        }

        return new Portee(false, $distance, $rayon, __($this->message(), [
            'restaurant' => $restaurant->name,
            'distance' => $distance,
            'rayon' => $rayon,
        ]));
    }

    /**
     * Le restaurant et la livraison sont-ils dans la meme ville ?
     *
     * Rend faux des qu'une des deux villes est inconnue : on ne conclut pas
     * sur une donnee absente, le rayon reprendra la main.
     */
    private function memeVille(Restaurant $restaurant, ?Town $livraison): bool
    {
        if (! $this->villePrioritaire()) {
            return false;
        }

        $depuis = $restaurant->town?->city_id;
        $vers = $livraison?->city_id;

        return $depuis !== null && $vers !== null && (int) $depuis === (int) $vers;
    }

    /**
     * L'option est-elle allumee ?
     *
     * Meme precaution que `rayonGlobal()` : des reglages absents — migrations
     * pas encore jouees — ne doivent pas faire tomber le catalogue.
     */
    private function villePrioritaire(): bool
    {
        try {
            return $this->settings->city_first;
        } catch (MissingSettings) {
            return false;
        }
    }

    /**
     * Le client est-il trop loin du restaurant ?
     *
     * Rend un refus, ou `null` quand il n'y a rien a opposer : option eteinte,
     * position inconnue, point zero d'un GPS qui n'a pas fixe, ou rayon
     * inconnu faute de reglages.
     *
     * @param  array{lat: float, lng: float}  $restaurantCoords
     * @param  array{0: float, 1: float}|null  $positionClient
     */
    private function refuserSurLaPositionDuClient(
        Restaurant $restaurant,
        array $restaurantCoords,
        float $rayon,
        ?array $positionClient,
    ): ?Portee {
        if ($positionClient === null || is_infinite($rayon) || ! $this->positionClientActive()) {
            return null;
        }

        $client = RestaurantGeo::coordinates([
            'lat' => $positionClient[0] ?? null,
            'lng' => $positionClient[1] ?? null,
        ]);

        if ($client === null || $this->estLePointZero($client)) {
            return null;
        }

        $distance = RestaurantGeo::distanceKm(
            $restaurantCoords['lat'], $restaurantCoords['lng'], $client['lat'], $client['lng'],
        );

        if ($distance <= $rayon) {
            return null;
        }

        return new Portee(false, $distance, $rayon, __($this->message(), [
            'restaurant' => $restaurant->name,
            'distance' => $distance,
            'rayon' => $rayon,
        ]));
    }

    /**
     * L'option est-elle allumee ?
     *
     * Meme precaution que `rayonGlobal()` : des reglages absents — migrations
     * pas encore jouees — ne doivent pas faire tomber le catalogue. Dans le
     * doute, on n'applique pas la regle.
     */
    private function positionClientActive(): bool
    {
        try {
            return $this->settings->use_client_position;
        } catch (MissingSettings) {
            return false;
        }
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
            ?? $this->rayonGlobal();
    }

    /**
     * Le rayon par defaut, ou l'infini quand il n'est pas reglable.
     *
     * `settings` peut etre vide : migrations pas encore jouees apres un
     * deploiement. C'est deja arrive en production, et la page la plus
     * consultee de l'application ne doit pas tomber en 500 pour autant.
     *
     * L'infini dit « aucune borne connue » sans obliger chaque appelant a
     * traiter un null : `min($radius, INF)` rend le rayon du client, et
     * `$distance <= INF` laisse passer. Une portee inconnue n'interdit rien,
     * comme une position inconnue.
     */
    private function rayonGlobal(): float
    {
        try {
            return $this->settings->max_delivery_radius_km;
        } catch (MissingSettings) {
            return INF;
        }
    }

    /** La phrase de refus, avec un repli si le reglage manque. */
    private function message(): string
    {
        try {
            return $this->settings->out_of_radius_message;
        } catch (MissingSettings) {
            return ':restaurant est à :distance km de votre adresse de livraison, '
                .'au-delà de notre rayon de livraison de :rayon km.';
        }
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private function positionDuRestaurant(Restaurant $restaurant): ?array
    {
        $propre = RestaurantGeo::coordinates($restaurant->location);

        /*
         * Le point zero vaut ici aussi.
         *
         * Le formulaire d'administration expose deux champs numeriques, et les
         * laisser a zero est la faute de saisie la plus probable. Prise au mot,
         * elle placerait le restaurant a 5 300 km de tous ses clients : il
         * disparaitrait de la recherche et refuserait toutes ses commandes,
         * sans qu'aucun message ne previenne l'administrateur. On retombe donc
         * sur sa commune, exactement comme s'il n'avait aucune position.
         */
        if ($propre !== null && ! $this->estLePointZero($propre)) {
            return $propre;
        }

        return $this->centroide($restaurant->town);
    }

    /** @return array{lat: float, lng: float}|null */
    private function positionDeLivraison(?Town $town, ?float $lat, ?float $lng): ?array
    {
        $centroide = $this->centroide($town);

        if ($lat !== null && $lng !== null) {
            $coords = RestaurantGeo::coordinates(['lat' => $lat, 'lng' => $lng]);

            if ($coords !== null
                && ! $this->estLePointZero($coords)
                && $this->concordeAvec($coords, $centroide)
            ) {
                return $coords;
            }
        }

        return $centroide;
    }

    /**
     * La position annoncee est-elle croyable pour la commune declaree ?
     *
     * Sans ce controle la regle se contourne avec deux nombres dans le corps
     * de la requete : declarer une livraison a Lubumbashi, annoncer les
     * coordonnees de la Gombe, et commander dans un restaurant de la Gombe.
     * La commande partirait, enregistree sur Lubumbashi et facturee aux tarifs
     * de Lubumbashi. C'est le motif qui a deja produit une faille sur ce
     * depot — « le prix vient du client » — transpose a la position.
     *
     * Le centroide de la commune n'est pas falsifiable : il arbitre. Sans
     * centroide connu, rien a comparer, on fait confiance.
     *
     * @param  array{lat: float, lng: float}  $coords
     * @param  array{lat: float, lng: float}|null  $centroide
     */
    private function concordeAvec(array $coords, ?array $centroide): bool
    {
        if ($centroide === null) {
            return true;
        }

        return RestaurantGeo::distanceKm(
            $coords['lat'], $coords['lng'], $centroide['lat'], $centroide['lng'],
        ) <= self::ECART_MAX_AVEC_LA_COMMUNE_KM;
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

        $coords = RestaurantGeo::coordinates([
            'lat' => (float) $town->latitude,
            'lng' => (float) $town->longitude,
        ]);

        // Zero est une saisie oubliee, pas une commune du golfe de Guinee.
        return $coords !== null && ! $this->estLePointZero($coords) ? $coords : null;
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
