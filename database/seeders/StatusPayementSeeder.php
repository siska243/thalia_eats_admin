<?php

namespace Database\Seeders;

use App\Models\StatusPayement;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class StatusPayementSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //
        $status=StatusPayement::query()->first();
        if(!$status){
            StatusPayement::query()->create(["code" => "0", "name" => "Transaction traitée avec succès","is_paid" => true]);
            StatusPayement::query()->create( ["code" => 1, "name" => "Transaction n'a pas abouti"]);
            StatusPayement::query()->create(["code" => 2, "name" => "Paiement en attente","is_default"=> true]);
            StatusPayement::query()->create(["code" => 3, "name" => "Le paiement va être remboursé au client"]);
            StatusPayement::query()->create( ["code" => 4, "name" => "Le paiement a été remboursé au client"]);
            StatusPayement::query()->create(["code" => 5, "name" => "La transaction a été annulée par le marchand"]);

        }
    }
}
