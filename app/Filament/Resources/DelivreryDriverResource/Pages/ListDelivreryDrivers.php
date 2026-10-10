<?php

namespace App\Filament\Resources\DelivreryDriverResource\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\DelivreryDriverResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDelivreryDrivers extends ListRecords
{
    protected static string $resource = DelivreryDriverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
