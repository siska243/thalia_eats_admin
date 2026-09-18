<?php

namespace App\Filament\Resources\CategoryProductResource\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\CategoryProductResource;
use Filament\Actions;
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
