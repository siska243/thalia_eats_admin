<?php

namespace App\Filament\Resources\StatusPayementResource\Pages;

use App\Filament\Resources\StatusPayementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStatusPayements extends ListRecords
{
    protected static string $resource = StatusPayementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
