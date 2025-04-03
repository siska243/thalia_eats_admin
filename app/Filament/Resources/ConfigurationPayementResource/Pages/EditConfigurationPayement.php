<?php

namespace App\Filament\Resources\ConfigurationPayementResource\Pages;

use App\Filament\Resources\ConfigurationPayementResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditConfigurationPayement extends EditRecord
{
    protected static string $resource = ConfigurationPayementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
