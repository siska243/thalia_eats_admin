<?php

namespace App\Filament\Resources\CategoryProductResource\Pages;

use App\Filament\Resources\CategoryProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCategoryProducts extends ListRecords
{
    protected static string $resource = CategoryProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
