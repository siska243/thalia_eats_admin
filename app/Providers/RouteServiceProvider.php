<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            // Un jeton d'assistant compte sur SA propre cle : sinon il epuise
            // le quota partage et l'application mobile du meme client se met a
            // echouer, sans que le client puisse faire le lien. La cle est
            // celle de cleDeLimitation(), deja utilisee par les limiteurs
            // « agent-* » — pas une seconde expression du meme calcul.
            $token = $request->user()?->currentAccessToken();

            if ($token !== null && isset($token->id) && ! $request->user()->tokenCan('*')) {
                return Limit::perMinute(60)->by(self::cleDeLimitation($request));
            }

            // Jeton d'application ordinaire, session ou anonyme : comportement
            // strictement inchange.
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('agent-lecture', fn (Request $request) => Limit::perMinute(60)->by(self::cleDeLimitation($request)));

        RateLimiter::for('agent-devis', fn (Request $request) => Limit::perMinute(20)->by(self::cleDeLimitation($request)));

        RateLimiter::for('agent-ecriture', fn (Request $request) => [
            Limit::perMinute(10)->by(self::cleDeLimitation($request)),
            Limit::perHour(60)->by(self::cleDeLimitation($request)),
        ]);

        RateLimiter::for('assistants', fn (Request $request) => Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()));

        /*
                 * Les demandes de partenariat : la seule ecriture anonyme de l'API.
                 *
                 * Comptee par IP, faute de compte, et large sur la minute parce qu'un
                 * operateur mobile a Kinshasa fait partager une adresse a toute une
                 * ville : trop serre, deux restaurateurs du meme quartier se
                 * bloqueraient l'un l'autre. La borne horaire est la vraie protection
                 * contre le remplissage automatique de la table.
                 */
        RateLimiter::for('prospects', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perHour(20)->by($request->ip()),
        ]);

        // La signature n'identifie pas l'appelant : on compte par IP, mais
        // l'IP seule ne suffit pas. Sous le partage d'adresses des operateurs
        // congolais, des milliers de vrais clients sortent derriere une meme
        // adresse : dix ouvertures de page par minute pour toute une ville
        // bloquerait des paiements legitimes. La cle porte donc aussi
        // l'identifiant de pre-commande — deux clients differents paient deux
        // pre-commandes differentes et ne se genent plus, tandis qu'un meme
        // lien martele reste limite.
        RateLimiter::for('lien-paiement', fn (Request $request) => Limit::perMinute(10)
            ->by($request->ip().'|'.$request->route('uid')));

        // OAuth : aucune de ces routes n'est authentifiée au moment où elle est
        // appelée, l'IP est donc la seule cle disponible.

        // L'enregistrement dynamique crée une ligne en base sans qu'aucun
        // humain n'intervienne : sans limite, on remplit la table.
        RateLimiter::for('oauth-enregistrement', fn (Request $request) => Limit::perHour(20)->by($request->ip()));

        RateLimiter::for('oauth-autorisation', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        // Celui-ci vérifie des mots de passe. C'est le seul endroit de
        // l'application où un formulaire public teste des identifiants : sans
        // limite, la page d'autorisation devient un banc d'essai de mots de passe.
        //
        // Deux contraintes, parce qu'elles ne couvrent pas la même attaque :
        // celle par IP arrête qui essaie beaucoup de comptes depuis un point,
        // celle par compte arrête qui vise UNE adresse en répartissant ses
        // tentatives sur autant d'adresses IP qu'il veut. Sans la seconde, la
        // première ne protège aucun compte en particulier.
        RateLimiter::for('oauth-connexion', function (Request $request) {
            $limites = [
                Limit::perMinute(5)->by($request->ip()),
                Limit::perHour(30)->by($request->ip()),
            ];

            $compte = self::cleDeCompte($request);

            if ($compte !== null) {
                $limites[] = Limit::perHour(10)->by($compte);
            }

            return $limites;
        });

        RateLimiter::for('oauth-jeton', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        /*
         * Les points d'entree d'authentification : connexion et codes OTP.
         *
         * Le code OTP fait quatre chiffres. Sous le seul throttle:api (60 par
         * minute) il se parcourt en entier en quelques heures, et comme
         * reset-password pose un nouveau mot de passe a partir de ce code,
         * c'est une prise de compte. forgot-password et resend-activation
         * passent aussi par ici : sans cela, un attaquant regenererait un code
         * frais aussi souvent qu'il le voudrait pour contourner le compteur.
         *
         * Compte par e-mail ET par IP : viser un compte ne permet pas de
         * bloquer les autres, et une seule IP ne peut pas marteler un compte.
         * Les bornes sont larges pour un humain (qui ne saisit pas six codes
         * d'affilee) et serrees pour une machine : sur la fenetre de validite
         * de trente minutes, la poignee d'essais autorisee rend l'espace des
         * codes hors d'atteinte.
         */
        RateLimiter::for('auth-sensibles', fn (Request $request) => [
            Limit::perMinute(6)->by(self::cleAuth($request)),
            Limit::perHour(30)->by(self::cleAuth($request)),
        ]);

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * La clé de limitation du compte visé par une tentative de connexion OAuth.
     *
     * L'adresse est normalisée (sinon « Client@X » et « client@x » seraient
     * deux compteurs distincts pour un même compte) puis hachée : une clé de
     * limiteur finit en clair dans le cache, et la liste des adresses qu'on a
     * essayé d'attaquer n'a rien à y faire.
     *
     * Rend null quand aucune adresse n'est fournie : une requête sans e-mail
     * échouera de toute façon, et lui donner un compteur commun offrirait de
     * quoi saturer ce compteur pour bloquer les autres.
     */
    private static function cleDeCompte(Request $request): ?string
    {
        $email = $request->input('email');

        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        return 'oauth-compte:'.hash('sha256', mb_strtolower(trim($email)));
    }

    /**
     * Compter par JETON et non par utilisateur : sinon un assistant bavard
     * épuise le quota de l'application mobile du même client, qui se retrouve
     * bloqué dans son app sans comprendre pourquoi.
     *
     * Retombe sur l'utilisateur lorsque le jeton n'a pas d'identifiant en base
     * (authentification de session, ou Sanctum::actingAs en test), puis sur
     * l'adresse IP.
     */
    private static function cleDeLimitation(Request $request): string
    {
        $token = $request->user()?->currentAccessToken();

        if ($token !== null && isset($token->id)) {
            return 'token:'.$token->id;
        }

        if ($request->user() !== null) {
            return 'user:'.$request->user()->id;
        }

        return 'ip:'.$request->ip();
    }

    /**
     * Cle de limitation des points d'authentification : l'e-mail vise et l'IP
     * d'ou part la tentative.
     *
     * L'e-mail est normalise et hache : deux casses du meme e-mail comptent
     * ensemble, et la cle ne porte pas l'adresse en clair dans le cache. Sans
     * e-mail dans la requete, on retombe sur la seule IP.
     */
    private static function cleAuth(Request $request): string
    {
        $email = $request->input('email');

        $partieEmail = is_string($email) && $email !== ''
            ? sha1(mb_strtolower(trim($email)))
            : 'sans-email';

        return 'auth:'.$partieEmail.'|'.$request->ip();
    }
}
