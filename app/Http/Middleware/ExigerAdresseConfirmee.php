<?php

namespace App\Http\Middleware;

use App\Wrappers\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interdit d'utiliser l'application tant que l'adresse n'est pas confirmee.
 *
 * Le proprietaire, le 17 octobre, apres deux inscriptions suspectes
 * (`otu.z.a.wuwuv.ub.0.5@gmail.com`, `be.l.lzd.7r.j.xju.s.t.i.n@gmail.com`) :
 * « une confirmation d'email est obligatoire apres l'inscription ».
 *
 * Le code existait deja : l'inscription envoie un OTP, `/activation-account`
 * le verifie et pose `email_verified_at`. Mais RIEN N'OBLIGEAIT A LE FAIRE —
 * on commandait sans jamais avoir prouve qu'on possede l'adresse.
 *
 * Le 17 octobre, le proprietaire a elargi la regle : « un compte non valide
 * ou bloque ne peut pas acceder a l'application (web ou mobile) ». Le garde
 * n'est donc plus pose sur les quelques routes qui engagent, mais sur TOUTE
 * l'API — un seul endroit, impossible a oublier sur la prochaine route.
 *
 * Trois exceptions, et seulement trois, sans lesquelles la regle se mordrait
 * la queue : lire son propre compte, le mettre a jour et se deconnecter.
 * Sans la premiere, l'application ne saurait meme pas qui elle doit inviter
 * a confirmer. Les routes d'activation, elles, sont publiques : elles ne
 * passent pas par ici.
 *
 * UNE DATE PIVOT, ET NON UN BLOCAGE GENERAL. Dix-neuf des vingt-trois comptes
 * existants n'ont jamais ete confirmes : exiger la confirmation pour tout le
 * monde fermerait la porte a de vrais clients, du jour au lendemain, sans
 * qu'ils comprennent pourquoi. Seuls les comptes crees APRES la date reglee
 * en administration sont tenus de confirmer.
 *
 * On ne marque pas non plus les anciens comptes comme « verifies » pour s'en
 * debarrasser : ce serait ecrire une chose fausse en base, et on ne saurait
 * plus jamais qui a reellement prouve son adresse.
 *
 * La regle — « ce compte doit-il confirmer ? » — vit sur le modele, dans
 * `User::doitConfirmerSonAdresse()`. Ce garde ne decide pas, il applique.
 * La connexion pose exactement la meme question, et refuse d'emettre un
 * jeton : voir Api\AuthController::login(). Ici on ne tient plus que la
 * liste des trois exceptions.
 */
class ExigerAdresseConfirmee
{
    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = $request->user();

        // La regle elle-meme vit sur le modele : la connexion pose la meme
        // question avant d'emettre un jeton, et deux lectures de la date
        // pivot finiraient par diverger.
        if (! $utilisateur?->doitConfirmerSonAdresse()) {
            return $next($request);
        }

        if ($this->toujoursPermis($request)) {
            return $next($request);
        }

        /*
         * L'ordre des arguments de ce wrapper est (erreur, titre, message),
         * et non (titre, message, erreur) comme on l'attendrait. Le premier
         * sert de code machine aux clients, les deux suivants s'affichent.
         */
        return ApiResponse::BAD_REQUEST(
            'adresse_non_confirmee',
            'Confirmez votre adresse',
            'Saisissez le code reçu par courriel pour utiliser votre compte. '
                .'Vous pouvez en demander un nouveau depuis l’écran de confirmation.',
        );
    }

    /**
     * Ce que l'on peut faire sans avoir confirme.
     *
     * Lire et corriger son compte, et s'en aller. Une adresse saisie de
     * travers est la premiere raison de ne pas recevoir le code : sans
     * `/user/account/update`, le client serait enferme dehors avec son
     * erreur de frappe.
     */
    private function toujoursPermis(Request $request): bool
    {
        return $request->routeIs(
            'api.logout',
            'api.account',
        ) || $request->is('api/user', 'api/user/account/update');
    }
}
