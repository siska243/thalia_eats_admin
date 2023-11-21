<?php

namespace App\Filament\Resources\PaimentMethodResource\Pages;

use App\Filament\Resources\PaimentMethodResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;
class CreatePaimentMethod extends CreateRecord
{
    protected static string $resource = PaimentMethodResource::class;
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['slug']=Str::slug($data['title']);
        return $data;
    }
}
