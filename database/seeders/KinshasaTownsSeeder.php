<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Town;
use Illuminate\Database\Seeder;

/**
 * Les vingt-quatre communes de Kinshasa, avec leurs coordonnees.
 *
 * Le rapprochement se fait par `slug` et jamais par le nom affiche. Trois
 * communes etaient enregistrees sous une orthographe qui differe de
 * l'officielle — « Kitambo » pour Kintambo, « Bandalugwa » pour Bandalungwa,
 * « Kasa vubu » pour Kasa-Vubu. Un rapprochement par le nom aurait cree une
 * jumelle a cote de chacune, alors que les anciennes sont deja referencees
 * par des adresses et des commandes en production.
 *
 * Ni `slug` ni `title` ne sont modifies : l'application mobile rapproche les
 * communes par slug pour composer `pickup_town_id`, et des adresses
 * enregistrees pointent dessus. Renommer casserait les deux.
 *
 * `is_active` et `rental_available` ne sont jamais touches non plus. Ouvrir
 * une commune est une decision commerciale, et un redeploiement ne doit pas
 * la defaire. Les communes creees ici naissent donc fermees : une commune
 * active sans grille de frais de livraison ferait livrer gratuitement.
 *
 * Idempotent : `script-run.sh` rejoue `db:seed --force` a chaque
 * deploiement en production.
 *
 * Les coordonnees sont des CENTROIDES APPROXIMATIFS, releves sur Nominatim
 * (OpenStreetMap) le 9 octobre 2026. Ce ne sont pas des limites
 * administratives : elles situent une commune sur une carte, elles ne disent
 * pas ou elle commence ni ou elle finit.
 */
class KinshasaTownsSeeder extends Seeder
{
    /**
     * Nom officiel, slug de rapprochement, centroide approximatif.
     *
     * Les trois slugs qui ne derivent pas du nom officiel sont ceux des
     * lignes deja en base, conservees telles quelles.
     *
     * @var array<int, array{nom: string, slug: string, lat: float, lng: float}>
     */
    private const COMMUNES = [
        // Orthographe en base : « Bandalugwa ».
        ['nom' => 'Bandalungwa', 'slug' => 'bandalugwa', 'lat' => -4.342220, 'lng' => 15.283165],
        ['nom' => 'Barumbu', 'slug' => 'barumbu', 'lat' => -4.319008, 'lng' => 15.325693],
        ['nom' => 'Bumbu', 'slug' => 'bumbu', 'lat' => -4.372591, 'lng' => 15.293444],
        ['nom' => 'Gombe', 'slug' => 'gombe', 'lat' => -4.311975, 'lng' => 15.289430],
        ['nom' => 'Kalamu', 'slug' => 'kalamu', 'lat' => -4.349558, 'lng' => 15.317930],
        // Orthographe en base : « Kasa vubu ».
        ['nom' => 'Kasa-Vubu', 'slug' => 'kasa-vubu', 'lat' => -4.341715, 'lng' => 15.304017],
        ['nom' => 'Kimbanseke', 'slug' => 'kimbanseke', 'lat' => -4.441714, 'lng' => 15.403544],
        ['nom' => 'Kinshasa', 'slug' => 'kinshasa', 'lat' => -4.321940, 'lng' => 15.311847],
        // Orthographe en base : « Kitambo ».
        ['nom' => 'Kintambo', 'slug' => 'kitambo', 'lat' => -4.343433, 'lng' => 15.266748],
        ['nom' => 'Kisenso', 'slug' => 'kisenso', 'lat' => -4.417662, 'lng' => 15.341662],
        ['nom' => 'Lemba', 'slug' => 'lemba', 'lat' => -4.403971, 'lng' => 15.317340],
        ['nom' => 'Limete', 'slug' => 'limete', 'lat' => -4.354347, 'lng' => 15.346685],
        ['nom' => 'Lingwala', 'slug' => 'lingwala', 'lat' => -4.325254, 'lng' => 15.301264],
        ['nom' => 'Makala', 'slug' => 'makala', 'lat' => -4.382961, 'lng' => 15.308547],
        // Commune rurale, la plus vaste de toutes : le point situe le centre
        // de la cite de Maluku, pas le milieu du territoire.
        ['nom' => 'Maluku', 'slug' => 'maluku', 'lat' => -4.054895, 'lng' => 15.561210],
        ['nom' => 'Masina', 'slug' => 'masina', 'lat' => -4.366167, 'lng' => 15.390982],
        ['nom' => 'Matete', 'slug' => 'matete', 'lat' => -4.388961, 'lng' => 15.351250],
        ['nom' => 'Mont-Ngafula', 'slug' => 'mont-ngafula', 'lat' => -4.494993, 'lng' => 15.267727],
        ['nom' => 'Ndjili', 'slug' => 'ndjili', 'lat' => -4.406956, 'lng' => 15.375464],
        ['nom' => 'Ngaba', 'slug' => 'ngaba', 'lat' => -4.381325, 'lng' => 15.321810],
        ['nom' => 'Ngaliema', 'slug' => 'ngaliema', 'lat' => -4.375472, 'lng' => 15.247363],
        ['nom' => 'Ngiri-Ngiri', 'slug' => 'ngiri-ngiri', 'lat' => -4.356894, 'lng' => 15.298694],
        ['nom' => 'Nsele', 'slug' => 'nsele', 'lat' => -4.350557, 'lng' => 15.566331],
        ['nom' => 'Selembao', 'slug' => 'selembao', 'lat' => -4.400959, 'lng' => 15.285238],
    ];

    public function run(): void
    {
        // La ville de rattachement, quand elle existe. Elle n'est pas creee
        // ici : une ville inventee par un deploiement serait plus genante
        // qu'un `city_id` nul.
        $cityId = City::query()->where('slug', 'kinshasa')->value('id');

        foreach (self::COMMUNES as $commune) {
            $town = Town::query()->where('slug', $commune['slug'])->first();

            if (! $town) {
                Town::query()->create([
                    'title' => $commune['nom'],
                    'slug' => $commune['slug'],
                    'city_id' => $cityId,
                    'latitude' => $commune['lat'],
                    'longitude' => $commune['lng'],
                ]);

                continue;
            }

            $town->latitude = $commune['lat'];
            $town->longitude = $commune['lng'];

            // Renseigne seulement s'il manque : une commune rattachee a la
            // main a une autre ville l'a ete pour une raison.
            if ($town->city_id === null && $cityId !== null) {
                $town->city_id = $cityId;
            }

            if ($town->isDirty()) {
                $town->save();
            }
        }
    }
}
