<?php

namespace App\Http\Controllers\Api;

use App\Enums\Device;
use App\Enums\MobilePermissions;
use App\Enums\TypeUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginUserRequest;
use App\Http\Requests\RegistrationRequest;
use App\Http\Resources\UserResource;
use App\Mail\WelcomeOtpMail;
use App\Models\User;
use App\Wrappers\ApiResponse;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public static function generateOtp(): string
    {
        return Str::padLeft(random_int(0, 9999), 4, '0');
    }

    public function register(RegistrationRequest $request)
    {
        try {
            //code...
            $check_user=User::query()->where('email',$request->email)->first();

            if($check_user){
                return ApiResponse::BAD_REQUEST('Oups','Error email','You have account, please login');
            }

            $user = User::query()->firstOrCreate(
                [
                    'password' => Hash::make($request->password),
                    'name' => $request->name,
                    'last_name' => $request->last_name,
                    'email' => $request->email,
                    'phone' => $request->phone,
                    'slug' => Str::slug($request->name . '-' . time() . '-' . $request->last_name),
                    'mobile_permissions'=>[MobilePermissions::AddOrder->value],
                    'devices'=>[Device::Mobile->value,Device::Web->value]
                ]
            );
            $user->type_user=TypeUser::Client->value;
            $user->otp=self::generateOtp();
            $user->otp_expire_at=now()->addMinutes(30);
            $user->assignRole('client');

            $data['full_name'] = "{$user->last_name} {$user->name}";
            $data['otp_valide_at'] = $user->otp_expire_at->format('Y-m-d H:i:s');
            $data['otp'] = $user->otp;
            Mail::to($user->email)->send(new WelcomeOtpMail($data));


            $user->save();

            return ApiResponse::SUCCESS_DATA($user, "Felicitations", "Votre compte a été créer avec succès");

        } catch (Exception $e) {
            //throw $th;
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function activation(Request $request){
        try{
            $validator = Validator::make($request->all(), [
                'email' => ['email', 'required', 'string','exists:users,email'],
                'otp' => ['required', 'string'],

            ]);

            if ($validator->fails()) {
                return ApiResponse::BAD_REQUEST('Error validation', 'Oups', "Veuillez saisir un email correcte");
            }

            $credentials = $request->only('email', 'otp');

            $user = User::query()->where('email',  $credentials['email'])
                ->where('otp', $credentials['otp'])
                ->first();

            if (!$user) {
                return ApiResponse::BAD_REQUEST('Errors', __('Oups'), __("Otp incorrect"));
            }

            $user->otp=null;
            $user->otp_expire_at=null;

            $user->email_verified_at=now();

            $user->save();

            return ApiResponse::SUCCESS_DATA($user, "Felicitations", "Votre compte a été activer avec succès");
        }
        catch (\Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function login(LoginUserRequest $request)
    {
        try {
            //code...

            $validator = Validator::make($request->all(), [
                'email' => ['email', 'required', 'string'],
                'password' => ['required', 'string'],

            ]);

            if ($validator->fails()) {
                return ApiResponse::BAD_REQUEST('Error validation', 'Oups', "Veuillez saisir un email correcte");
            }
            $credentials = $request->only('email', 'password');

            $user = User::query()->where('email',  $credentials['email'])->first();

            if (!$user) {
                return ApiResponse::BAD_REQUEST('Errors', 'Oups', 'Email incorrect');
            }

            if (!Hash::check($request->password, $user->password)) {
                return ApiResponse::BAD_REQUEST('Errors', 'Oups', 'Password incorrect');
            }

            $token=$user->createToken('api token')->plainTextToken;

            $roles = $user->getRoleNames();

            return ApiResponse::GET_DATA(
                [
                    'user' => new UserResource($user),
                    'roles' => $roles,
                    'token' => $token,

                ],
            );

        } catch (Exception $e) {
            //throw $th;
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function logout(Request $request)
    {
        try {
            $user = $request->user();
            $user->currentAccessToken()->delete();

            return ApiResponse::SUCCESS_DATA([]);
        } catch (Exception $e) {
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
