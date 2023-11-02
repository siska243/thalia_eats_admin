<?php

namespace App\Filament\Resources\PaiementMethodResource\Pages;

use App\Filament\Resources\PaiementMethodResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPaiementMethods extends ListRecords
{
    protected static string $resource = PaiementMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
