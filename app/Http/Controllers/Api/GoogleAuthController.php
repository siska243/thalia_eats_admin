<?php

// app/Http/Controllers/GoogleAuthController.php

namespace App\Http\Controllers\Api;

use App\Enums\TypeUser;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirect()
    {
        $redirectUri = config('services.redirect_uris.mobile_login');
        Session::put('auth-google-redirect', $redirectUri);

        return Socialite::driver('google')
            ->redirect();
    }

    public function register()
    {
        $redirectUri = config('services.redirect_uris.mobile_register');
        Session::put('auth-google-redirect', $redirectUri);

        return Socialite::driver('google')
            ->redirect();
    }

    public function callback()
    {
        $googleUser = Socialite::driver('google')->stateless()->user();

        $email = $googleUser->getEmail();

        $user = User::query()->where('email', $email)->first();

        $redirectUri = Session::get('auth-google-redirect', config('services.redirect_uris.mobile_login'));

        if (! $user) {

            $user = User::query()->create([
                'name' => $googleUser->user['family_name'],
                'email' => $googleUser->getEmail(),
                'password' => md5(Str::random(10)),
                'active' => true,
                'email_verified_at' => now(),
                'last_name' => $googleUser->user['given_name'],
                'social_media_avatar' => $googleUser->getAvatar(),
                'google_id' => $googleUser->getId(),
                'type_user' => TypeUser::Client->value,
            ]);

            $user->assignRole('client');

            $user->fresh();

        }

        if (! $user->social_media_avatar) {
            $user->social_media_avatar = $googleUser->getAvatar();
            $user->save();
        }

        $token = $user->createToken($user->email)->plainTextToken;

        $array = [
            'user' => new UserResource($user),
            'token' => $token,
            'roles' => $user->getRoleNames(),
        ];

        $data = json_encode($array);

        // Rediriger vers l’application mobile
        return redirect()->away("{$redirectUri}?token={$token}&user={$data}");
    }
}
