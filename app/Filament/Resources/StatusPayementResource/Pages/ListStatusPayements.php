<?php

namespace App\Filament\Resources\StatusPayementResource\Pages;

use App\Filament\Resources\StatusPayementResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStatusPayements extends ListRecords
{
    protected static string $resource = StatusPayementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
