<?php

namespace Database\Seeders;

use App\Models\CourseType;
use App\Services\Rental\BookingPricing;
use Illuminate\Database\Seeder;

/**
 * Les trois types de course d'origine.
 *
 * Le proprietaire, le 15 octobre : « ils doivent prevenir si c'est pour quel
 * type de course : A, B, C ». Ce sont des LIGNES et non des constantes : la
 * table s'edite en administration, et un quatrieme type ne demandera aucune
 * livraison.
 *
 * Idempotent : `script-run.sh` rejoue les seeders a chaque deploiement, et
 * `updateOrCreate` sur le code ne doit ni doubler les lignes ni ecraser un
 * tarif ajuste depuis l'administration — d'ou les valeurs posees seulement a
 * la creation.
 */
class CourseTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'code' => 'A',
                'title' => 'Course urbaine',
                'description' => "Dans l'agglomération, au tarif habituel du véhicule.",
                'position' => 1,
            ],
            [
                'code' => 'B',
                'title' => 'Hors agglomération',
                'description' => 'En dehors de la ville. Réservation à la journée, 24 heures minimum.',
                'minimum_hours' => 24,
                'forced_mode' => BookingPricing::MODE_DAILY,
                'note_required' => true,
                'position' => 2,
            ],
            [
                'code' => 'C',
                'title' => 'Aéroport',
                'description' => "Transfert aéroport. L'entrée dans l'enceinte est facturée à l'heure.",
                'note_required' => true,
                'parking_option' => true,
                'parking_hourly_fee' => 10,
                'position' => 3,
            ],
        ];

        foreach ($types as $type) {
            CourseType::query()->firstOrCreate(['code' => $type['code']], $type);
        }
    }
}
