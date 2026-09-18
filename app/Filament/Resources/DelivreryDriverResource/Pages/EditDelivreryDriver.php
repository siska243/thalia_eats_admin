<?php

namespace App\Filament\Resources\DelivreryDriverResource\Pages;

use Filament\Actions\DeleteAction;
use App\Filament\Resources\DelivreryDriverResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDelivreryDriver extends EditRecord
{
    protected static string $resource = DelivreryDriverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
