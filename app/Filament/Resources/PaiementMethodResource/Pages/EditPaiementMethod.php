<?php

namespace App\Filament\Resources\PaiementMethodResource\Pages;

use App\Filament\Resources\PaiementMethodResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPaiementMethod extends EditRecord
{
    protected static string $resource = PaiementMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
            Actions\ForceDeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }
}
