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
        $data=[
            ["code" => 0, "name" => "Transaction traitée avec succès","is_paid" => true],
            ["code" => 1, "name" => "Transaction n'a pas abouti"],
            ["code" => 2, "name" => "Paiement en attente","is_default"=> true],
            ["code" => 3, "name" => "Le paiement va être remboursé au client"],
            ["code" => 4, "name" => "Le paiement a été remboursé au client"],
            ["code" => 5, "name" => "La transaction a été annulée par le marchand"]
        ];
        if(!$status){
            StatusPayement::query()->insert($data);
        }
    }
}
