<?php

namespace App\Filament\Resources\CommandeProductResource\Pages;

use App\Filament\Resources\CommandeProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCommandeProducts extends ListRecords
{
    protected static string $resource = CommandeProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
