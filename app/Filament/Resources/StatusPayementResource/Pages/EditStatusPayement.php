<?php

namespace App\Filament\Resources\StatusPayementResource\Pages;

use Filament\Actions\DeleteAction;
use App\Filament\Resources\StatusPayementResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditStatusPayement extends EditRecord
{
    protected static string $resource = StatusPayementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
