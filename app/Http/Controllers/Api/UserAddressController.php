<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserAdresseResource;
use App\Models\Town;
use App\Models\UserAdresse;
use App\Wrappers\ApiResponse;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Adresses de livraison enregistrees.
 *
 * Jusqu'ici un client n'avait qu'une seule adresse, stockee sur la table
 * users et ecrasee a chaque modification : impossible de retrouver une
 * adresse deja utilisee, ni d'en garder plusieurs.
 */
class UserAddressController extends Controller
{
    public function index()
    {
        try {
            $addresses = UserAdresse::query()
                ->with('town')
                ->where('user_id', auth()->id())
                ->orderByDesc('is_main')
                ->orderByDesc('updated_at')
                ->get();

            return UserAdresseResource::collection($addresses);
        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'adresse' => ['required', 'string', 'max:255'],
                'town' => ['required', 'string', 'exists:towns,slug'],
                'label' => ['nullable', 'string', 'max:50'],
                'street' => ['nullable', 'string', 'max:255'],
                'number_street' => ['nullable', 'string', 'max:50'],
                'reference' => ['nullable', 'string', 'max:255'],
                'lat' => ['nullable', 'numeric', 'between:-90,90'],
                'long' => ['nullable', 'numeric', 'between:-180,180'],
                'is_main' => ['nullable', 'boolean'],
            ]);

            if ($validator->fails()) {
                return ApiResponse::BAD_REQUEST(
                    $validator->errors(),
                    'Oups',
                    "Veuillez indiquer une adresse et une commune desservie"
                );
            }

            $town = Town::query()->where('slug', $request->input('town'))->first();

            $address = UserAdresse::query()->updateOrCreate(
                [
                    'user_id' => auth()->id(),
                    'adresse' => $request->input('adresse'),
                    'town_id' => $town->id,
                ],
                [
                    'label' => $request->input('label'),
                    'street' => $request->input('street'),
                    'number_street' => $request->input('number_street'),
                    'reference' => $request->input('reference'),
                    'lat' => $request->input('lat'),
                    'long' => $request->input('long'),
                ]
            );

            // Une seule adresse principale a la fois.
            if ($request->boolean('is_main')) {
                UserAdresse::query()
                    ->where('user_id', auth()->id())
                    ->where('id', '!=', $address->id)
                    ->update(['is_main' => false]);

                $address->update(['is_main' => true]);
            }

            return ApiResponse::SUCCESS_DATA(
                new UserAdresseResource($address->load('town')),
                'Adresse enregistrée',
                'Vous la retrouverez lors de vos prochaines commandes.'
            );
        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function destroy(string $slug)
    {
        try {
            $address = UserAdresse::query()
                ->where('user_id', auth()->id())
                ->where('slug', $slug)
                ->first();

            if (!$address) {
                return ApiResponse::NOT_FOUND('Oups', "Cette adresse n'existe pas");
            }

            $address->delete();

            return ApiResponse::GET_DATA([
                'title' => 'Adresse supprimée',
                'message' => "L'adresse a été retirée de votre carnet.",
            ]);
        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }
}
