<?php

namespace App\Filament\Resources\ProspectResource\Pages;

use App\Filament\Resources\ProspectResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Pas d'action « créer » : une demande vient du site, jamais d'ici.
 */
class ListProspects extends ListRecords
{
    protected static string $resource = ProspectResource::class;
}
