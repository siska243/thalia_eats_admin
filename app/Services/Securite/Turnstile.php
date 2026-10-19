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
                /*
                 * Une reprise, pas plus.
                 *
                 * Une coupure d'une seconde ne doit pas valoir un refus, et le
                 * reseau d'ici en produit. Au-dela, insister ferait patienter
                 * le client devant un formulaire fige : mieux vaut un refus
                 * qu'il comprend et peut reessayer.
                 */
                ->retry(2, 200, throw: false)
                ->post(self::VERIFICATION, array_filter([
                    'secret' => config('securite.turnstile.secret'),
                    'response' => $jeton,
                    'remoteip' => $ip,
                ]));
        } catch (\Throwable $exception) {
            /*
             * Cloudflare injoignable : on REFUSE, sauf consigne contraire.
             *
             * Laisser passer en silence rendait la mesure nulle sans que
             * personne ne s'en apercoive — il suffisait d'empecher l'appel
             * sortant pour desactiver le defi. En `error` et non `warning` :
             * c'est une panne de securite, elle doit remonter.
             *
             * `TURNSTILE_FAIL_OPEN` existe pour le jour ou Cloudflare tombe
             * vraiment : rouvrir alors tient a une variable, pas a une
             * livraison. Fermee par defaut.
             */
            Log::error('Turnstile injoignable', [
                'erreur' => $exception->getMessage(),
                'laisse_passer' => config('securite.turnstile.fail_open'),
            ]);

            return (bool) config('securite.turnstile.fail_open');
        }

        return $reponse->successful() && $reponse->json('success') === true;
    }
}
