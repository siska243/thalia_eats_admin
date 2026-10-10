<?php

namespace App\Filament\Resources\ChauffeurResource\Pages;

use Filament\Actions\DeleteAction;
use App\Filament\Resources\ChauffeurResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditChauffeur extends EditRecord
{
    protected static string $resource = ChauffeurResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
