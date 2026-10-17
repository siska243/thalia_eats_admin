<?php

namespace App\Http\Middleware;

use App\Wrappers\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limite les actions qui ECHOUENT, et elles seules.
 *
 * Le proprietaire, le 17 octobre : « n'oublie pas de mettre le rate limite
 * pour toutes les actions qui echouent, c'est important cote securite ».
 *
 * CE QUI EXISTAIT DEJA, et que ce middleware ne remplace pas : les codes de
 * livraison et de reception se bloquent au troisieme essai errone, les
 * points d'authentification ont leur propre compteur par compte et par IP,
 * et le code de prise en charge d'une location a le sien. Ces verrous sont
 * SERRES parce qu'ils protegent un secret court qui se devine.
 *
 * CE QU'IL AJOUTE : un filet general. Toute reponse en 4xx compte, sur
 * n'importe quelle route de l'API. Un attaquant qui cherche une faille
 * enchaine les refus — identifiants errones, identifiants chiffres devines,
 * parametres malformes — et c'est precisement ce que personne ne surveillait
 * en dehors des deux ou trois chemins deja gardes. Une route ajoutee demain
 * est couverte sans qu'on y pense.
 *
 * SEULS LES ECHECS COMPTENT. Un limiteur sur toutes les requetes punit le
 * client qui consulte beaucoup ; celui-ci ne voit que celui qui se trompe
 * beaucoup. Un usage normal n'en croise jamais la borne : personne ne
 * produit vingt refus par minute en se servant de l'application.
 *
 * Le 429 ne compte PAS lui-meme : une fois bloque, marteler la porte ne
 * doit pas rallonger la peine indefiniment — sinon une erreur de reseau qui
 * rejoue une requete en boucle enfermerait un client honnete pour la
 * journee.
 */
class LimiterLesEchecs
{
    public function handle(Request $request, Closure $next): Response
    {
        /*
         * Eteint, on ne fait rien : voir `config/securite.php`. C'est le cas
         * en test, ou la suite entiere parle depuis 127.0.0.1 et ou
         * plusieurs classes enchainent des dizaines de refus ATTENDUS.
         */
        if (! config('securite.limite_echecs.actif', true)) {
            return $next($request);
        }

        /*
         * LA CLE D'AVANT N'EST QUE L'ADRESSE, jamais le compte.
         *
         * Ce middleware tourne AVANT l'authentification : demander
         * `$request->user()` ici forcerait la resolution du garde par defaut
         * sur chaque requete, bien avant que Sanctum ait parle. C'est une
         * session ouverte pour rien et un comportement d'authentification
         * altere pour tout le monde — la premiere version de ce fichier le
         * faisait, et elle a fait trainer la suite de tests jusqu'a l'arret.
         *
         * Le compte, on le connaitra APRES, quand l'authentification aura
         * fait son travail.
         */
        $parAdresse = 'echecs:ip'.$request->ip();

        if ($this->bloque($parAdresse, 'adresse')) {
            return $this->refus($parAdresse);
        }

        $reponse = $next($request);

        /*
         * On compte APRES coup, sur le statut rendu. Compter avant
         * obligerait a deviner l'issue, et punirait les requetes qui
         * reussissent.
         *
         * 429 exclu : une fois bloque, marteler la porte ne doit pas
         * rallonger la peine indefiniment. 5xx exclu aussi — une panne de
         * notre cote n'est pas la faute de l'appelant, et l'enfermer dehors
         * pendant qu'on repare aggraverait l'incident.
         */
        $statut = $reponse->getStatusCode();

        if ($statut < 400 || $statut >= 500 || $statut === 429) {
            return $reponse;
        }

        $this->compter($parAdresse);

        /*
         * Et par COMPTE quand on sait qui parle : ici l'authentification a
         * eu lieu, la question ne coute plus rien.
         *
         * Pourquoi les deux : derriere un operateur mobile, des milliers de
         * clients partagent une poignee d'adresses. C'est le compteur par
         * compte qui serre la vis sur celui qui se trompe vraiment.
         */
        $compte = $request->user()?->getAuthIdentifier();

        if ($compte !== null) {
            $parCompte = 'echecs:u'.$compte;

            $this->compter($parCompte);

            if ($this->bloque($parCompte, 'compte')) {
                return $this->refus($parCompte);
            }
        }

        return $reponse;
    }

    /**
     * `$quoi` vaut « adresse » ou « compte » : les deux bornes different.
     *
     * Serree par compte, large par adresse. Derriere un operateur mobile,
     * des milliers de clients partagent une poignee d'adresses : les
     * compter comme un seul utilisateur ferait que le premier maladroit
     * enferme tous les autres dehors.
     */
    private function bloque(string $cle, string $quoi): bool
    {
        $parMinute = (int) config("securite.limite_echecs.{$quoi}_par_minute");
        $parHeure = (int) config("securite.limite_echecs.{$quoi}_par_heure");

        return RateLimiter::tooManyAttempts($cle.':minute', $parMinute)
            || RateLimiter::tooManyAttempts($cle.':heure', $parHeure);
    }

    private function compter(string $cle): void
    {
        RateLimiter::hit($cle.':minute', 60);
        RateLimiter::hit($cle.':heure', 3600);
    }

    private function refus(string $cle): Response
    {
        $secondes = max(
            RateLimiter::availableIn($cle.':minute'),
            RateLimiter::availableIn($cle.':heure'),
        );

        $minutes = max(1, (int) ceil($secondes / 60));

        return ApiResponse::TOO_MANY_ATTEMPTS(
            'Trop de tentatives',
            "Trop d'actions refusées. Réessayez dans {$minutes} minute".($minutes > 1 ? 's' : '').'.',
        );
    }
}
