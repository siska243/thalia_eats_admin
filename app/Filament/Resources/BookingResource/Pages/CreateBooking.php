<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Filament\Resources\BookingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBooking extends CreateRecord
{
    protected static string $resource = BookingResource::class;

    /**
     * Le montant est recalcule par le serveur avant l'ecriture.
     *
     * Le formulaire l'affiche mais ne le transmet pas : un total saisi a la
     * main dans l'administration divergerait du prix annonce au client, et
     * c'est precisement le defaut qu'on a corrige sur les commandes de repas.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return array_merge($data, BookingResource::quoteFor($data)->toBookingAttributes());
    }
}
