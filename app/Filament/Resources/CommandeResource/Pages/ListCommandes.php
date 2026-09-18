<?php

namespace App\Filament\Resources\CommandeResource\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\CommandeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCommandes extends ListRecords
{
    protected static string $resource = CommandeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
