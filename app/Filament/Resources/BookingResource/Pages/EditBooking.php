<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Filament\Resources\BookingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBooking extends EditRecord
{
    protected static string $resource = BookingResource::class;

    /** Meme regle qu'a la creation : le serveur refait le calcul. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // La reservation existante est passee : son tarif convenu l'emporte
        // tant que l'offre - vehicule et mode - ne change pas.
        return array_merge($data, BookingResource::quoteFor($data, $this->record)->toBookingAttributes());
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
