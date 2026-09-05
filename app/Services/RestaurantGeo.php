<?php

namespace App\Services;

/**
 * restaurants.location est un json nullable ajouté en novembre 2024 et jamais
 * lu jusqu'ici : sa forme réelle en production est inconnue. Ce lecteur accepte
 * les conventions de nommage plausibles et dégrade vers null plutôt que de lever.
 */
class RestaurantGeo
{
    private const LAT_KEYS = ['lat', 'latitude', 'Lat', 'Latitude'];

    private const LNG_KEYS = ['lng', 'long', 'lon', 'longitude', 'Lng', 'Long', 'Longitude'];

    /**
     * @param  array<string, mixed>|null  $location
     * @return array{lat: float, lng: float}|null
     */
    public static function coordinates(?array $location): ?array
    {
        if (! is_array($location) || $location === []) {
            return null;
        }

        $lat = self::pick($location, self::LAT_KEYS);
        $lng = self::pick($location, self::LNG_KEYS);

        if ($lat === null || $lng === null) {
            return null;
        }

        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return null;
        }

        return ['lat' => $lat, 'lng' => $lng];
    }

    /**
     * @param  array<string, mixed>  $location
     * @param  array<int, string>  $keys
     */
    private static function pick(array $location, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $location) && is_numeric($location[$key])) {
                return (float) $location[$key];
            }
        }

        return null;
    }

    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $rayon = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return round($rayon * 2 * atan2(sqrt($a), sqrt(1 - $a)), 3);
    }
}
