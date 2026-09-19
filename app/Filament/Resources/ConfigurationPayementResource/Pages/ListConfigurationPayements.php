<?php

namespace App\Filament\Resources\ConfigurationPayementResource\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\ConfigurationPayementResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListConfigurationPayements extends ListRecords
{
    protected static string $resource = ConfigurationPayementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
