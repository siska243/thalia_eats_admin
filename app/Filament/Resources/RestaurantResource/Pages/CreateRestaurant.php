<?php

namespace App\Filament\Resources\RestaurantResource\Pages;

use App\Filament\Resources\RestaurantResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateRestaurant extends CreateRecord
{
    protected static string $resource = RestaurantResource::class;
    protected function mutateFormDataBeforeCreate(array $data): array
    {

        $data['slug'] = Str::slug($data['name'], "-", 'fr');

        return $data;
    }
}
