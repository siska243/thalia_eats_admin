<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    public function handleRecordUpdate(Model $record,array $data):Model
    {
        $slug = Str::slug($data['name'] . '-' . time() . '-' . $data['last_name']);

        // Récupérez le nom du rôle depuis $data
        $data['slug'] = $slug;
        $roleName = $data['role_user'] ?? '';

        if(!empty($data['changePassword'])){
            unset($data['changePassword']);
            unset($data['password_confirmation']);
        }

        //unset($data['role']);

        if (!empty($roleName)) {

            $record->assignRole($roleName); // Utilisez la méthode pour attribuer le rôle à l'utilisateur
        }
        $record->update($data);
        return $record;
    }
}
