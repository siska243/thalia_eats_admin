<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginUserRequest;
use App\Http\Requests\RegistrationRequest;
use App\Models\User;
use App\Wrappers\ApiResponse;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{

    public function register(RegistrationRequest $request){
        try {
            //code...

            $user=User::create([
              'password'=> Hash::make($request->password),
              'name'=>$request->name,
              'last_name'=>$request->last_name,
              'email'=>$request->email,
              'phone'=>$request->phone,
              'slug'=>Str::slug($request->name.'-'.time().'-'. $request->last_name)
            ]
            );
            $user->assignRole('clients');
            $user->save();
           return ApiResponse::SUCCESS_DATA($user,"Felicitations","Votre compte a été créer avec succès");
        } catch (Exception $e) {
            //throw $th;
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function login(LoginUserRequest $request){
        try {
            //code...

                $credentials = $request->only('email', 'password');

                $token = Auth::guard('api')->attempt($credentials);

                if(!$token) return ApiResponse::BAD_REQUEST('invalide credential','oups','erreur');
                $user = Auth::guard('api')->user();
                return ApiResponse::GET_DATA(
                    [
                    'user_role'=>$user->roles()->pluck('name'),
                    'token'=>$token,
                    'full_name'=>$user->name. ' '. $user->last_name
                    ],
                );

            //return ApiResponse::BAD_REQUEST('Invalid credential','Oups','Erreur');
        } catch (Exception $e) {
            //throw $th;
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function logout()
    {
        try{
            Auth::guard('api')->logout();
            return response()->json([
                'status' => 'success',
                'message' => 'Successfully logged out',
            ]);
        }
        catch(Exception $e){
            return ApiResponse::SERVER_ERROR($e);
        }

    }


    public function refresh()
    {
        return response()->json([
            'status' => 'success',
            'user' => Auth::guard('api')->user(),
            'authorisation' => [
                'token' => Auth::guard('api')->refresh(),
                'type' => 'bearer',
            ]
        ]);
    }
}
