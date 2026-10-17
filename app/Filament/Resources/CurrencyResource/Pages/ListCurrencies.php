<?php

namespace App\Filament\Resources\CurrencyResource\Pages;

use App\Filament\Resources\CurrencyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCurrencies extends ListRecords
{
    protected static string $resource = CurrencyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
