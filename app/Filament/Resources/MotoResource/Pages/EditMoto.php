<?php

namespace App\Filament\Resources\MotoResource\Pages;

use Filament\Actions\DeleteAction;
use App\Filament\Resources\MotoResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMoto extends EditRecord
{
    protected static string $resource = MotoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
