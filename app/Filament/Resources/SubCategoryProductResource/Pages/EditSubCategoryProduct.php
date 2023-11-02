<?php

namespace App\Filament\Resources\SubCategoryProductResource\Pages;

use App\Filament\Resources\SubCategoryProductResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSubCategoryProduct extends EditRecord
{
    protected static string $resource = SubCategoryProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
