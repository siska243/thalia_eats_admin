<?php

namespace App\Http\Controllers\Api;

use App\Enums\Device;
use App\Enums\MobilePermissions;
use App\Enums\TypeUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginUserRequest;
use App\Http\Requests\RegistrationRequest;
use App\Http\Resources\UserResource;
use App\Mail\OtpReinitPasswordMail;
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

            // otp_expire_at etait renseigne a l'inscription mais jamais relu :
            // un code d'activation restait valable indefiniment.
            if (!$user->otp_expire_at || now()->greaterThan($user->otp_expire_at)) {
                return ApiResponse::BAD_REQUEST(
                    'Errors',
                    __('Oups'),
                    __("Ce code a expire, veuillez en demander un nouveau")
                );
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

            // Un message distinct par champ permettait d'enumerer les comptes :
            // « Email incorrect » revelait qu'une adresse n'existe pas, et
            // « Password incorrect » qu'elle existe. Reponse unique.
            if (!$user || !Hash::check($request->password, $user->password)) {
                return ApiResponse::BAD_REQUEST('Errors', 'Oups', 'Email ou mot de passe incorrect');
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

    /**
     * Demande un code de reinitialisation.
     *
     * La reponse est identique que le compte existe ou non : la distinguer
     * transformerait cet endpoint en outil d'enumeration des comptes.
     */
    public function forgotPassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => ['required', 'email', 'string'],
            ]);

            if ($validator->fails()) {
                return ApiResponse::BAD_REQUEST('Error validation', 'Oups', "Veuillez saisir un email correcte");
            }

            $user = User::query()->where('email', $request->email)->first();

            if ($user) {
                $user->otp = self::generateOtp();
                $user->otp_expire_at = now()->addMinutes(30);
                $user->save();

                Mail::to($user->email)->send(new OtpReinitPasswordMail([
                    'full_name' => "{$user->last_name} {$user->name}",
                    'otp' => $user->otp,
                ]));
            }

            return ApiResponse::GET_DATA([
                'title' => 'Code envoye',
                'message' => "Si un compte existe pour cette adresse, un code de verification vient d'etre envoye.",
            ]);
        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    /**
     * Change le mot de passe a partir du code recu.
     *
     * Le code est consomme, et toutes les sessions ouvertes sont revoquees :
     * si le compte etait compromis, l'ancien acces tombe avec le mot de passe.
     */
    public function resetPassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => ['required', 'email', 'string'],
                'otp' => ['required', 'string'],
                'password' => ['required', 'string', 'min:8'],
                'confirm_password' => ['required', 'same:password'],
            ]);

            if ($validator->fails()) {
                return ApiResponse::BAD_REQUEST(
                    $validator->errors(),
                    'Oups',
                    "Le mot de passe doit contenir au moins 8 caracteres et les deux saisies doivent correspondre"
                );
            }

            $user = User::query()
                ->where('email', $request->email)
                ->where('otp', $request->otp)
                ->first();

            if (!$user) {
                return ApiResponse::BAD_REQUEST('Errors', 'Oups', 'Code incorrect');
            }

            if (!$user->otp_expire_at || now()->greaterThan($user->otp_expire_at)) {
                return ApiResponse::BAD_REQUEST(
                    'Errors',
                    'Oups',
                    'Ce code a expire, veuillez en demander un nouveau'
                );
            }

            $user->password = Hash::make($request->password);
            $user->otp = null;
            $user->otp_expire_at = null;
            $user->save();

            $user->tokens()->delete();

            return ApiResponse::GET_DATA([
                'title' => 'Mot de passe modifie',
                'message' => 'Vous pouvez maintenant vous connecter avec votre nouveau mot de passe.',
            ]);
        } catch (Exception $e) {
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

    public function notlogin(){
        return response()->json([
            'status' => 'error',
            'title'=>'Not login',
        ]);
    }
}
