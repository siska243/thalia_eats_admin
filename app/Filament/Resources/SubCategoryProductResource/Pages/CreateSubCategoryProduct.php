<?php

namespace App\Filament\Resources\SubCategoryProductResource\Pages;

use App\Filament\Resources\SubCategoryProductResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;
class CreateSubCategoryProduct extends CreateRecord
{
    protected static string $resource = SubCategoryProductResource::class;
    protected function mutateFormDataBeforeCreate(array $data): array
    {

        $data['slug'] = Str::slug($data['title'], "-", 'fr');

        return $data;
    }
}
