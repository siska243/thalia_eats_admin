<?php

namespace App\Wrappers;

use Illuminate\Support\Facades\Http;

class Geocode
{
    public static function getLatLngByAddress($address): ?array
    {
        $apiKey = env('GOOGLE_MAPS_API_KEY');
        $url = "https://maps.googleapis.com/maps/api/geocode/json";
        $response = Http::get($url, [
            'address' => $address,
            'key' => $apiKey,
        ]);

        $data = $response->json();

        if (isset($data['status']) && $data['status'] === "OK") {
            $location = $data['results'][0]['geometry']['location'];
            return [
                'lat' => $location['lat'],
                'lng' => $location['lng'],
            ];
        }

        return null;
    }

}
