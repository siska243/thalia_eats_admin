<?php

namespace App\Filament\Resources\CategoryProductResource\Pages;

use App\Filament\Resources\CategoryProductResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateCategoryProduct extends CreateRecord
{
    protected static string $resource = CategoryProductResource::class;
    protected function mutateFormDataBeforeCreate(array $data): array
    {

        $data['slug'] = Str::slug($data['title'], "-", 'fr');

        return $data;
    }
}
