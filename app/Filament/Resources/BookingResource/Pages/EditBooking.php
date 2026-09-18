<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Filament\Resources\BookingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBooking extends EditRecord
{
    protected static string $resource = BookingResource::class;

    /** Meme regle qu'a la creation : le serveur refait le calcul. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return array_merge($data, BookingResource::quoteFor($data)->toBookingAttributes());
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
