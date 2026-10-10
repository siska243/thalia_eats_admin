<?php

namespace App\Filament\Resources\DelivreryPriceResource\Pages;

use Filament\Actions\DeleteAction;
use App\Filament\Resources\DelivreryPriceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDelivreryPrice extends EditRecord
{
    protected static string $resource = DelivreryPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
