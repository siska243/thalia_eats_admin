<?php

namespace App\Filament\Resources\MotoResource\Pages;

use App\Filament\Resources\MotoResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMotos extends ListRecords
{
    protected static string $resource = MotoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
