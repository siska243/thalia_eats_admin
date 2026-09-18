<?php

namespace Database\Seeders;

use App\Models\PaimentMethod;
use Illuminate\Database\Seeder;

/**
 * Le moyen de paiement « especes ».
 *
 * Le solde d'une location — les 90 % restants — est remis en liquide au
 * chauffeur. Sans cette ligne, la trace de cet encaissement n'aurait aucun
 * moyen de paiement a designer, et le rapprochement de fin de journee
 * deviendrait impossible.
 *
 * Idempotent : `script-run.sh` rejoue les seeders a chaque deploiement.
 */
class RentalPaimentMethodSeeder extends Seeder
{
    public function run(): void
    {
        PaimentMethod::query()->updateOrCreate(
            ['slug' => 'especes'],
            ['title' => 'Espèces', 'is_active' => true],
        );
    }
}
