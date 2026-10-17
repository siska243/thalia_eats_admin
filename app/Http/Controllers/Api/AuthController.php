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
use App\Services\Securite\DoubleAuthentification;
use App\Services\Securite\Turnstile;
use App\Wrappers\ApiResponse;
use Exception;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * La seconde etape de la connexion : le code du second facteur.
     *
     * Trois codes sont acceptes, et le client ne choisit pas lequel il
     * saisit — celui de son application, celui recu par courriel, ou un code
     * de secours. Il saisit ce qu'il a sous la main.
     *
     * Le defi est detruit des qu'il aboutit, et au bout de cinq essais : un
     * code a six chiffres se devine, sur cinq minutes, si on laisse essayer.
     */
    public function doubleAuthentification(Request $request, DoubleAuthentification $double)
    {
        $valide = $request->validate([
            'defi' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $user = $double->verifierDefi($valide['defi'], $valide['code']);

        if (! $user) {
            return ApiResponse::BAD_REQUEST(
                'code_invalide',
                'Code incorrect',
                'Ce code ne correspond pas, ou votre demande a expiré. Reconnectez-vous.',
            );
        }

        // Le blocage est reverifie ici : un compte peut avoir ete bloque
        // entre les deux etapes, et la seconde delivre un jeton.
        if ($user->estBloque()) {
            ApiResponse::FORBIDEN(
                'Compte bloqué',
                "Ce compte a été bloqué. Contactez le support si vous pensez qu'il s'agit d'une erreur.",
            );
        }

        return ApiResponse::GET_DATA([
            'user' => new UserResource($user),
            'roles' => $user->getRoleNames(),
            'token' => $user->createToken('api token')->plainTextToken,
        ]);
    }

    public static function generateOtp(): string
    {
        return Str::padLeft(random_int(0, 9999), 4, '0');
    }

    public function register(RegistrationRequest $request, Turnstile $turnstile)
    {
        try {
            /*
             * Le defi anti-robot, AVANT tout le reste : inutile de chercher
             * en base pour un appel qui ne vient pas d'un navigateur.
             *
             * Eteint tant que `TURNSTILE_SECRET_KEY` n'est pas posee, donc
             * sans effet sur les clients mobiles deja installes, qui
             * n'envoient pas ce jeton.
             */
            if (! $turnstile->verifier($request->input('turnstile_token'), $request->ip())) {
                return ApiResponse::BAD_REQUEST(
                    'turnstile_echoue',
                    'Vérification impossible',
                    "Nous n'avons pas pu vérifier que vous n'êtes pas un robot. Rechargez la page et réessayez."
                );
            }

            // code...
            $check_user = User::query()->where('email', $request->email)->first();

            if ($check_user) {
                return ApiResponse::BAD_REQUEST(
                    'Errors',
                    'Compte existant',
                    'Un compte existe déjà avec cette adresse email. Connectez-vous pour continuer.'
                );
            }

            $user = User::query()->firstOrCreate(
                [
                    'password' => Hash::make($request->password),
                    'name' => $request->name,
                    'last_name' => $request->last_name,
                    'email' => $request->email,
                    'phone' => $request->phone,
                    'slug' => Str::slug($request->name.'-'.time().'-'.$request->last_name),
                    'mobile_permissions' => [MobilePermissions::AddOrder->value],
                    'devices' => [Device::Mobile->value, Device::Web->value],
                ]
            );
            $user->type_user = TypeUser::Client->value;
            $user->otp = self::generateOtp();
            $user->otp_expire_at = now()->addMinutes(30);
            $user->assignRole('client');

            $data['full_name'] = "{$user->last_name} {$user->name}";
            $data['otp_valide_at'] = $user->otp_expire_at->format('Y-m-d H:i:s');
            $data['otp'] = $user->otp;
            Mail::to($user->email)->send(new WelcomeOtpMail($data));

            $user->save();

            return ApiResponse::SUCCESS_DATA(
                $user,
                'Bienvenue',
                'Votre compte a été créé. Un code de vérification vient de vous être envoyé par email.'
            );

        } catch (Exception $e) {
            // throw $th;
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function activation(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => ['email', 'required', 'string', 'exists:users,email'],
                'otp' => ['required', 'string'],

            ]);

            if ($validator->fails()) {
                return ApiResponse::BAD_REQUEST('Errors', 'Oups', 'Veuillez saisir une adresse email valide');
            }

            $credentials = $request->only('email', 'otp');

            $user = User::query()->where('email', $credentials['email'])
                ->where('otp', $credentials['otp'])
                ->first();

            if (! $user) {
                return ApiResponse::BAD_REQUEST('Errors', 'Oups', "Ce code n'est pas valide");
            }

            // otp_expire_at etait renseigne a l'inscription mais jamais relu :
            // un code d'activation restait valable indefiniment.
            if (! $user->otp_expire_at || now()->greaterThan($user->otp_expire_at)) {
                return ApiResponse::BAD_REQUEST(
                    'Errors',
                    __('Oups'),
                    'Ce code a expiré, demandez-en un nouveau'
                );
            }

            $user->otp = null;
            $user->otp_expire_at = null;

            $user->email_verified_at = now();

            $user->save();

            return ApiResponse::SUCCESS_DATA(
                $user,
                'Compte activé',
                'Votre compte est activé. Vous pouvez maintenant passer commande.'
            );
        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    /**
     * Renvoie un code d'activation.
     *
     * Les codes expirent au bout de trente minutes. Sans ce point d'entree,
     * un compte dont le code a expire ne pourrait plus jamais etre active.
     *
     * Comme pour la reinitialisation, la reponse ne dit pas si le compte
     * existe, ni s'il est deja active.
     */
    public function resendActivation(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => ['required', 'email', 'string'],
            ]);

            if ($validator->fails()) {
                return ApiResponse::BAD_REQUEST('Errors', 'Oups', 'Veuillez saisir une adresse email valide');
            }

            $user = User::query()
                ->where('email', $request->email)
                ->whereNull('email_verified_at')
                ->first();

            if ($user) {
                $user->otp = self::generateOtp();
                $user->otp_expire_at = now()->addMinutes(30);
                $user->save();

                Mail::to($user->email)->send(new WelcomeOtpMail([
                    'full_name' => "{$user->last_name} {$user->name}",
                    'otp_valide_at' => $user->otp_expire_at->format('Y-m-d H:i:s'),
                    'otp' => $user->otp,
                ]));
            }

            return ApiResponse::GET_DATA([
                'title' => 'Code renvoyé',
                'message' => "Si ce compte existe et n'est pas encore activé, un nouveau code vient d'être envoyé.",
            ]);
        } catch (Exception $e) {
            return ApiResponse::SERVER_ERROR($e);
        }
    }

    public function login(LoginUserRequest $request, DoubleAuthentification $double)
    {
        try {
            // code...

            $validator = Validator::make($request->all(), [
                'email' => ['email', 'required', 'string'],
                'password' => ['required', 'string'],

            ]);

            if ($validator->fails()) {
                return ApiResponse::BAD_REQUEST('Errors', 'Oups', 'Veuillez saisir une adresse email valide');
            }
            $credentials = $request->only('email', 'password');

            $user = User::query()->where('email', $credentials['email'])->first();

            // Un message distinct par champ permettait d'enumerer les comptes :
            // « Email incorrect » revelait qu'une adresse n'existe pas, et
            // « Password incorrect » qu'elle existe. Reponse unique.
            if (! $user || ! Hash::check($request->password, $user->password)) {
                return ApiResponse::BAD_REQUEST('Errors', 'Oups', 'Email ou mot de passe incorrect');
            }

            /*
             * Le refus vient APRES la verification du mot de passe : annoncer
             * « compte bloque » a qui ne connait pas le mot de passe
             * apprendrait a un inconnu que cette adresse existe, et laquelle
             * a ete bloquee.
             */
            if ($user->estBloque()) {
                ApiResponse::FORBIDEN(
                    'Compte bloqué',
                    "Ce compte a été bloqué. Contactez le support si vous pensez qu'il s'agit d'une erreur.",
                );
            }

            /*
             * LE SECOND FACTEUR, s'il est actif.
             *
             * Aucun jeton n'est emis a ce stade : le mot de passe seul ne
             * doit pas ouvrir la session, sinon la mesure ne protege rien.
             * Le client recoit un defi, le resout, et c'est la seconde route
             * qui delivre le jeton.
             *
             * Les clients deja installes ne voient jamais ce chemin : il ne
             * s'ouvre que pour les comptes qui ont eux-memes active la
             * double authentification.
             */
            if ($double->estActive($user)) {
                // La meme forme que la reponse de connexion ordinaire :
                // les champs a la racine, pas sous `data`. Deux formes pour
                // un meme endpoint obligeraient chaque client a deviner.
                return ApiResponse::GET_DATA(array_merge(
                    ['double_authentification_requise' => true],
                    $double->ouvrirDefi($user),
                ));
            }

            $token = $user->createToken('api token')->plainTextToken;

            $roles = $user->getRoleNames();

            return ApiResponse::GET_DATA(
                [
                    'user' => new UserResource($user),
                    'roles' => $roles,
                    'token' => $token,

                ],
            );

        } catch (HttpResponseException $e) {
            /*
             * `ApiResponse::FORBIDEN()` leve plutot que de rendre : sans cette
             * branche, le catch generique ci-dessous transformait le refus
             * d'un compte bloque en 500 « erreur serveur ». Le refus doit
             * sortir tel quel.
             */
            throw $e;
        } catch (Exception $e) {
            // throw $th;
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
                return ApiResponse::BAD_REQUEST('Errors', 'Oups', 'Veuillez saisir une adresse email valide');
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
                'title' => 'Code envoyé',
                'message' => "Si un compte existe pour cette adresse, un code de vérification vient d'être envoyé.",
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
                    'Le mot de passe doit contenir au moins 8 caractères, et les deux saisies doivent être identiques'
                );
            }

            $user = User::query()
                ->where('email', $request->email)
                ->where('otp', $request->otp)
                ->first();

            if (! $user) {
                return ApiResponse::BAD_REQUEST('Errors', 'Oups', "Ce code n'est pas valide");
            }

            if (! $user->otp_expire_at || now()->greaterThan($user->otp_expire_at)) {
                return ApiResponse::BAD_REQUEST(
                    'Errors',
                    'Oups',
                    'Ce code a expiré, demandez-en un nouveau'
                );
            }

            $user->password = Hash::make($request->password);
            $user->otp = null;
            $user->otp_expire_at = null;
            $user->save();

            $user->tokens()->delete();

            return ApiResponse::GET_DATA([
                'title' => 'Mot de passe modifié',
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
            ],
        ]);
    }

    public function notlogin()
    {
        return response()->json([
            'status' => 'error',
            'title' => 'Not login',
        ]);
    }
}
