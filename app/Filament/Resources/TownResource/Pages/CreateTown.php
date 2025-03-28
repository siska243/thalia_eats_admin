<?php

namespace App\Filament\Resources\TownResource\Pages;

use App\Filament\Resources\TownResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateTown extends CreateRecord
{
    protected static string $resource = TownResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['slug']=Str::slug($data['title']);
        return $data;

    }
}
