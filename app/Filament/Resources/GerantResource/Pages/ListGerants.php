<?php

namespace App\Filament\Resources\GerantResource\Pages;

use App\Filament\Resources\GerantResource;
use Filament\Resources\Pages\ListRecords;

class ListGerants extends ListRecords
{
    protected static string $resource = GerantResource::class;

    /** Aucune action d'en-tete : l'ecran est en lecture seule. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
