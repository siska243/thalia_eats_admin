<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    public function mutateFormDataBeforeCreate($data):array
    {

        $slug= Str::slug($data['name'] . '-' . time() . '-' . $data['last_name']);

         // Récupérez le nom du rôle depuis $data
  $data['slug']=$slug;


        return $data;
    }

    public function handleRecordCreation(array $data):Model
    {

        $roleName = $data['role'] ?? '';
        unset($data['role']);
        $record= static::getModel()::create($data);

        if (!empty($roleName)) {
            $record->assignRole($roleName); // Utilisez la méthode pour attribuer le rôle à l'utilisateur
        }

        return $record;
    }
}
