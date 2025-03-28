<?php

namespace App\Filament\Resources\TownResource\Pages;

use App\Filament\Resources\TownResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Str;

class EditTown extends EditRecord
{
    protected static string $resource = TownResource::class;


    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['slug']=Str::slug($data['title']);
        return $data;

    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
