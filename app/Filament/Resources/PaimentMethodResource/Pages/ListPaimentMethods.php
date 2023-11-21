<?php

namespace App\Filament\Resources\PaimentMethodResource\Pages;

use App\Filament\Resources\PaimentMethodResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPaimentMethods extends ListRecords
{
    protected static string $resource = PaimentMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
