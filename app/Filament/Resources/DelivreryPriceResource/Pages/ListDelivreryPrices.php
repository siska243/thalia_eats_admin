<?php

namespace App\Filament\Resources\DelivreryPriceResource\Pages;

use App\Filament\Resources\DelivreryPriceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDelivreryPrices extends ListRecords
{
    protected static string $resource = DelivreryPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
