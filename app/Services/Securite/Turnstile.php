<?php

namespace App\Services\Securite;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verification d'un jeton Cloudflare Turnstile.
 *
 * Posee apres deux inscriptions manifestement automatisees le 17 octobre.
 * Le widget seul ne protege rien : tant que le serveur ne verifie pas le
 * jeton, il suffit d'appeler l'API directement pour le contourner. C'est ici
 * que la mesure existe reellement.
 *
 * ETEINTE PAR DEFAUT. Sans `TURNSTILE_SECRET_KEY`, `verifier()` rend vrai et
 * l'inscription se comporte exactement comme avant : la cle se pose au
 * deploiement, et le retour arriere consiste a la retirer. Un garde qui
 * refuserait tout parce qu'on a oublie une variable d'environnement fermerait
 * les inscriptions sans prevenir.
 */
class Turnstile
{
    private const VERIFICATION = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function actif(): bool
    {
        return (bool) config('securite.turnstile.secret');
    }

    public function verifier(?string $jeton, ?string $ip = null): bool
    {
        if (! $this->actif()) {
            return true;
        }

        if (blank($jeton)) {
            return false;
        }

        try {
            $reponse = Http::asForm()
                ->timeout(config('securite.turnstile.timeout'))
                ->post(self::VERIFICATION, array_filter([
                    'secret' => config('securite.turnstile.secret'),
                    'response' => $jeton,
                    'remoteip' => $ip,
                ]));
        } catch (\Throwable $exception) {
            /*
             * Cloudflare injoignable ne doit pas fermer les inscriptions.
             * On laisse passer et on le journalise : le risque d'un bot qui
             * entre pendant une panne reseau est moindre que celui de perdre
             * tous les vrais clients pendant la meme panne.
             */
            Log::warning('Turnstile injoignable, inscription laissee passer', [
                'erreur' => $exception->getMessage(),
            ]);

            return true;
        }

        return $reponse->successful() && $reponse->json('success') === true;
    }
}
