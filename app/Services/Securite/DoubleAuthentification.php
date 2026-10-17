<?php

namespace App\Services\Securite;

use App\Mail\CodeDoubleAuthentificationMail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PragmaRX\Google2FAQRCode\Google2FA;
use SensitiveParameter;

/**
 * La double authentification des clients, web et mobile.
 *
 * LA REGLE VIT ICI, UNE SEULE FOIS. L'administration a la sienne, fournie
 * par Filament, mais les deux lisent EXACTEMENT les memes colonnes du
 * modele : un compte qui active son application d'authentification depuis le
 * site la retrouve dans le back-office, et inversement. Deux implementations
 * sur deux jeux de colonnes auraient donne deux comptes dans un seul.
 *
 * Deux facteurs, au choix : une application d'authentification (TOTP) ou un
 * code envoye par courriel. Le proprietaire : « c'est a l'utilisateur de
 * choisir ». Aucun n'est impose.
 *
 * Le defi de connexion vit en cache et non en base : il dure cinq minutes et
 * ne sert qu'une fois. Une table de defis serait une table a purger.
 */
class DoubleAuthentification
{
    /** Le defi tient cinq minutes : le temps d'ouvrir une application, pas celui d'aller dejeuner. */
    public const DUREE_DEFI = 300;

    /** Le code par courriel vit aussi longtemps que le defi qui le porte. */
    private const LONGUEUR_CODE_COURRIEL = 6;

    /**
     * Au-dela, le defi est detruit et il faut se reconnecter.
     *
     * Six chiffres se devinent en un million d'essais ; sans plafond, un
     * defi de cinq minutes en laisse passer bien plus que cela.
     */
    private const ESSAIS_MAX = 5;

    public function __construct(private readonly Google2FA $google2fa) {}

    // --- Etat ---------------------------------------------------------------

    public function estActive(User $utilisateur): bool
    {
        return $this->applicationActive($utilisateur) || $utilisateur->hasEmailAuthentication();
    }

    public function applicationActive(User $utilisateur): bool
    {
        return filled($utilisateur->getAppAuthenticationSecret());
    }

    /**
     * @return array<string, mixed>
     */
    public function etat(User $utilisateur): array
    {
        return [
            'application' => [
                'active' => $this->applicationActive($utilisateur),
                'codes_de_secours_restants' => count($utilisateur->getAppAuthenticationRecoveryCodes() ?? []),
            ],
            'courriel' => [
                'active' => $utilisateur->hasEmailAuthentication(),
            ],
        ];
    }

    // --- Mise en place de l'application d'authentification ------------------

    /**
     * Prepare un secret SANS l'enregistrer.
     *
     * Tant que le client n'a pas prouve que son application lit bien le
     * secret, l'enregistrer reviendrait a verrouiller un compte sur un QR
     * code que personne n'a scanne.
     *
     * @return array{secret: string, qr: string, uri: string}
     */
    public function preparerApplication(User $utilisateur): array
    {
        $secret = $this->google2fa->generateSecretKey();

        return [
            'secret' => $secret,
            'qr' => $this->google2fa->getQRCodeInline(
                config('app.name'),
                $utilisateur->email,
                $secret,
            ),
            // Pour les applications qui acceptent un collage plutot qu'un scan.
            'uri' => $this->google2fa->getQRCodeUrl(config('app.name'), $utilisateur->email, $secret),
        ];
    }

    /**
     * Active l'application, et rend les codes de secours UNE SEULE FOIS.
     *
     * Sans codes de secours, un telephone perdu ferme le compte pour de bon.
     *
     * @return ?array<string> Les codes, ou null si le code saisi est faux.
     */
    public function activerApplication(User $utilisateur, #[SensitiveParameter] string $secret, string $code): ?array
    {
        if (! $this->google2fa->verifyKey($secret, $code)) {
            return null;
        }

        $codes = collect()
            ->times(8, fn (): string => Str::random(10).'-'.Str::random(10))
            ->all();

        $utilisateur->saveAppAuthenticationSecret($secret);
        $utilisateur->saveAppAuthenticationRecoveryCodes($codes);

        return $codes;
    }

    public function desactiverApplication(User $utilisateur): void
    {
        $utilisateur->saveAppAuthenticationSecret(null);
        $utilisateur->saveAppAuthenticationRecoveryCodes(null);
    }

    public function basculerCourriel(User $utilisateur, bool $actif): void
    {
        $utilisateur->toggleEmailAuthentication($actif);
    }

    // --- Le defi, a la connexion --------------------------------------------

    /**
     * Ouvre un defi et rend son identifiant.
     *
     * L'identifiant ne dit rien du compte : c'est une chaine au hasard, et
     * c'est le cache qui fait le lien. Un identifiant derive de l'adresse
     * aurait permis de deviner qui a la double authentification.
     *
     * @return array{defi: string, facteurs: array<string>}
     */
    public function ouvrirDefi(User $utilisateur): array
    {
        $defi = Str::random(40);
        $facteurs = [];

        if ($this->applicationActive($utilisateur)) {
            $facteurs[] = 'application';
        }

        if ($utilisateur->hasEmailAuthentication()) {
            $facteurs[] = 'courriel';
            $code = $this->envoyerCodeParCourriel($utilisateur);
        }

        Cache::put($this->cle($defi), [
            'user_id' => $utilisateur->id,
            'code_courriel' => $code ?? null,
            'essais' => 0,
        ], self::DUREE_DEFI);

        return ['defi' => $defi, 'facteurs' => $facteurs];
    }

    /**
     * Verifie un code et rend le compte, ou null.
     *
     * Trois codes sont acceptes, et c'est voulu : celui de l'application,
     * celui recu par courriel, et un code de secours. Le client ne choisit
     * pas lequel il saisit — il saisit ce qu'il a sous la main.
     */
    public function verifierDefi(string $defi, #[SensitiveParameter] string $code): ?User
    {
        $donnees = Cache::get($this->cle($defi));

        if (! $donnees) {
            return null;
        }

        $utilisateur = User::find($donnees['user_id']);

        if (! $utilisateur) {
            Cache::forget($this->cle($defi));

            return null;
        }

        if ($this->codeAccepte($utilisateur, $donnees, $code)) {
            // Un defi ne sert qu'une fois : sans cet oubli, le meme
            // identifiant rouvrirait une session pendant cinq minutes.
            Cache::forget($this->cle($defi));

            return $utilisateur;
        }

        $donnees['essais']++;

        if ($donnees['essais'] >= self::ESSAIS_MAX) {
            Cache::forget($this->cle($defi));

            return null;
        }

        // La duree ne repart pas de zero : sinon un flot d'essais prolongerait
        // le defi indefiniment.
        Cache::put($this->cle($defi), $donnees, self::DUREE_DEFI);

        return null;
    }

    /**
     * @param  array<string, mixed>  $donnees
     */
    private function codeAccepte(User $utilisateur, array $donnees, #[SensitiveParameter] string $code): bool
    {
        if (
            $donnees['code_courriel'] !== null
            && hash_equals((string) $donnees['code_courriel'], $code)
        ) {
            return true;
        }

        $secret = $utilisateur->getAppAuthenticationSecret();

        if (filled($secret) && $this->google2fa->verifyKey($secret, $code)) {
            return true;
        }

        return $this->consommerCodeDeSecours($utilisateur, $code);
    }

    /** Un code de secours ne sert qu'une fois : il est retire en passant. */
    private function consommerCodeDeSecours(User $utilisateur, #[SensitiveParameter] string $code): bool
    {
        $codes = $utilisateur->getAppAuthenticationRecoveryCodes() ?? [];

        foreach ($codes as $index => $candidat) {
            if (hash_equals($candidat, $code)) {
                unset($codes[$index]);
                $utilisateur->saveAppAuthenticationRecoveryCodes(array_values($codes));

                return true;
            }
        }

        return false;
    }

    private function envoyerCodeParCourriel(User $utilisateur): string
    {
        $code = Str::padLeft((string) random_int(0, 999999), self::LONGUEUR_CODE_COURRIEL, '0');

        Mail::to($utilisateur->email)->send(new CodeDoubleAuthentificationMail(
            $code,
            (int) (self::DUREE_DEFI / 60),
        ));

        return $code;
    }

    private function cle(string $defi): string
    {
        return 'double_authentification.defi.'.$defi;
    }
}
