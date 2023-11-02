<?php

namespace App\Filament\Resources\CategoryProductResource\Pages;

use App\Filament\Resources\CategoryProductResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Str;
class EditCategoryProduct extends EditRecord
{
    protected static string $resource = CategoryProductResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {

        $data['slug'] = Str::slug($data['title'], "-", 'fr');

        return $data;
    }
    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
