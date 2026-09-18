<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountRequest;
use App\Http\Requests\PasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\FcmToken;
use App\Models\Town;
use App\Models\User;
use App\Wrappers\ApiResponse;
use App\Wrappers\Cipher;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserAccountController extends Controller
{

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = auth()->user();
        $roles = $user->getRoleNames();
        return ApiResponse::GET_DATA([
            'user' => new UserResource($user),
            'roles' => $roles
        ]);
    }

    public function expo(Request $request)
    {
        $user = auth()->user();
        $messages = [
            'expo_token.required' => 'Expo token est requis.',
            //'champ1.max' => 'Expo token ne doit pas dépasser :max caractères.',

        ];
        $validator = Validator::make($request->all(), [
            'expo_token' => 'required|max:255',
        ], $messages);


        if ($validator->fails()) {
            return ApiResponse::BAD_REQUEST($validator->errors(), 'Oups', 'Une erreur s est produite');
        }

        $user->expo_push_token = $request->input('expo_token');
        $user->save();

        FcmToken::query()->updateOrCreate([
            'token' => $request->input('expo_token'),
            'user_id' => $user->id
        ],
            [
                'token' => $request->input('expo_token'),
                'user_id' => $user->id,
                'is_current' => true,
            ]
        );


        return ApiResponse::SUCCESS_DATA(new UserResource($user), 'Updated', 'token updated');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function current_password(PasswordRequest $request)
    {
        //
        $password = auth()->user()->password;


        if (!Hash::check($request->current_password, $password)) {
            return ApiResponse::BAD_REQUEST('Errors', 'Oups', "Votre mot de passe actuel est incorrect");
        }

        // return [$request->password,$request->confirm_password];
        if ($request->password !== $request->confirm_password) return ApiResponse::BAD_REQUEST('Errors', 'Oups', "Les deux mots de passe ne sont pas identiques");

        $user = auth()->user();
        $user->password = Hash::make($request->password);

        $user->save();
        return ApiResponse::SUCCESS_DATA(new UserResource($user), "Mot de passe modifié", "Votre mot de passe a été mis à jour.");

    }

    /**
     * Display the specified resource.
     */
    public function update_adresse(Request $request)
    {
        //
        try {
            $principal_adress = $request->input('principal_adresse');
            $street = $request->input('street');
            $number_street = $request->input('number_street');
            $town = $request->input('town');

            if (!$principal_adress) return ApiResponse::BAD_REQUEST('Errors', 'Oups', "Veuillez saisir votre adresse");
            if (!$town) return ApiResponse::BAD_REQUEST('Errors', 'Oups', "Veuillez choisir votre commune");

            $user = auth()->user();
            $user->principal_adresse = $principal_adress;
            $user->street = $street;
            $user->number_street = $number_street;

            $town_id = Town::query()->where("slug", $town)->first();

            if ($town) $user->town_id = $town_id->id;

            $user->save();

            return ApiResponse::SUCCESS_DATA(new UserResource($user), "Adresse mise à jour", "Votre adresse a été enregistrée.");
        } catch (Exception $e) {

            return ApiResponse::SERVER_ERROR($e);
        }

    }

    /**
     * Update the specified resource in storage.
     */
    public function update(AccountRequest $request)
    {
        try {
            $user = User::query()->where('email', auth()->user()->email)->first();
            $user->name = $request->name;
            $user->last_name = $request->last_name;
            $user->phone = $request->phone;

            $user->save();

            return ApiResponse::SUCCESS_DATA(new UserResource($user), "Profil mis à jour", "Vos informations ont été enregistrées.");
        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user)
    {
        //
    }
}
