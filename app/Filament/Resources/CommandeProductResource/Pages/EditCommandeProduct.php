<?php

namespace App\Filament\Resources\CommandeProductResource\Pages;

use Filament\Actions\DeleteAction;
use App\Filament\Resources\CommandeProductResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCommandeProduct extends EditRecord
{
    protected static string $resource = CommandeProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
