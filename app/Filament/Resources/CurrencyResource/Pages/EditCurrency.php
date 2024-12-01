<?php

namespace App\Filament\Resources\CurrencyResource\Pages;

use App\Filament\Resources\CurrencyResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Str;

class EditCurrency extends EditRecord
{
    protected static string $resource = CurrencyResource::class;

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
