<?php

namespace App\Filament\Resources\SubCategoryProductResource\Pages;

use App\Filament\Resources\SubCategoryProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSubCategoryProducts extends ListRecords
{
    protected static string $resource = SubCategoryProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
