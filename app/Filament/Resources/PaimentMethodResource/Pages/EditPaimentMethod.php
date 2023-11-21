<?php

namespace App\Filament\Resources\PaimentMethodResource\Pages;

use App\Filament\Resources\PaimentMethodResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPaimentMethod extends EditRecord
{
    protected static string $resource = PaimentMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
