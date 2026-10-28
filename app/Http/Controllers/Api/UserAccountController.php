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
            'roles' => $roles,
        ]);
    }

    /**
     * Suppression du compte par son titulaire.
     *
     * Exigee par Google Play de toute application qui permet de creer un
     * compte : depuis l'application, et depuis une adresse web accessible
     * sans l'installer.
     *
     * Le mot de passe est redemande, sauf pour les comptes Google : ceux-la
     * n'en ont jamais choisi — `GoogleAuthController` leur pose un
     * `md5(Str::random(10))` que personne ne connait. L'exiger les
     * empecherait de partir, et une porte de sortie qui ne s'ouvre pas ne
     * vaut pas mieux que pas de porte.
     *
     * La suppression elle-meme vit sur le modele, pas ici : l'administration
     * et un futur traitement de demandes web poseront la meme question.
     */
    public function destroy(Request $request)
    {
        $user = $request->user();

        if (! $user->google_id) {
            $validator = Validator::make($request->all(), [
                'password' => 'required|string',
            ], ['password.required' => 'Votre mot de passe est requis pour confirmer la suppression.']);

            if ($validator->fails()) {
                return ApiResponse::BAD_REQUEST(
                    $validator->errors(),
                    'Confirmation requise',
                    'Votre mot de passe est requis pour confirmer la suppression.'
                );
            }

            if (! Hash::check($request->input('password'), $user->password)) {
                return ApiResponse::BAD_REQUEST(
                    ['password' => ['Mot de passe incorrect.']],
                    'Mot de passe incorrect',
                    'Le mot de passe saisi ne correspond pas a celui du compte.'
                );
            }
        }

        $raisons = $user->engagementsEnCours();

        if ($raisons !== []) {
            return ApiResponse::BAD_REQUEST(
                ['engagements' => $raisons],
                'Suppression impossible',
                'Votre compte ne peut pas être supprimé tant que '.implode(' et ', $raisons)
                    .'. Annulez-la ou attendez la fin, puis réessayez.'
            );
        }

        $user->supprimerLeCompte();

        return ApiResponse::SUCCESS_DATA(
            null,
            'Compte supprimé',
            'Votre compte a été supprimé et vos données personnelles effacées. Vous avez été déconnecté.'
        );
    }

    public function expo(Request $request)
    {
        $user = auth()->user();
        $messages = [
            'expo_token.required' => 'Expo token est requis.',
            // 'champ1.max' => 'Expo token ne doit pas dépasser :max caractères.',

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
            'user_id' => $user->id,
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

        if (! Hash::check($request->current_password, $password)) {
            return ApiResponse::BAD_REQUEST('Errors', 'Oups', 'Votre mot de passe actuel est incorrect');
        }

        // return [$request->password,$request->confirm_password];
        if ($request->password !== $request->confirm_password) {
            return ApiResponse::BAD_REQUEST('Errors', 'Oups', 'Les deux mots de passe ne sont pas identiques');
        }

        $user = auth()->user();
        $user->password = Hash::make($request->password);

        $user->save();

        return ApiResponse::SUCCESS_DATA(new UserResource($user), 'Mot de passe modifié', 'Votre mot de passe a été mis à jour.');

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

            if (! $principal_adress) {
                return ApiResponse::BAD_REQUEST('Errors', 'Oups', 'Veuillez saisir votre adresse');
            }
            if (! $town) {
                return ApiResponse::BAD_REQUEST('Errors', 'Oups', 'Veuillez choisir votre commune');
            }

            $user = auth()->user();
            $user->principal_adresse = $principal_adress;
            $user->street = $street;
            $user->number_street = $number_street;

            $town_id = Town::query()->where('slug', $town)->first();

            if ($town) {
                $user->town_id = $town_id->id;
            }

            $user->save();

            return ApiResponse::SUCCESS_DATA(new UserResource($user), 'Adresse mise à jour', 'Votre adresse a été enregistrée.');
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

            return ApiResponse::SUCCESS_DATA(new UserResource($user), 'Profil mis à jour', 'Vos informations ont été enregistrées.');
        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }
}
