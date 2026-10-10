<?php

namespace App\Http\Controllers\Oauth;

use App\Enums\TokenAbility;
use App\Http\Controllers\Controller;
use App\Models\OauthAuthorizationCode;
use App\Models\OauthClient;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

/**
 * La page que voit le client : « tel assistant demande l'accès à votre compte ».
 *
 * Elle porte à la fois la connexion et le consentement, parce que Thalia est
 * une API : il n'existe pas de session web pour un client, donc pas de
 * « vous êtes déjà connecté » à réutiliser. Un seul écran, deux champs,
 * deux boutons.
 */
class AutorisationController extends Controller
{
    /**
     * Ce que chaque capacité autorise, dit au client.
     *
     * Les phrases sont reprises MOT POUR MOT de la page web
     * (`components/account/AssistantsConnectes.jsx`) et de l'écran mobile
     * (`app/custom-screens/assistants.tsx`). Trois promesses différentes selon
     * l'écran seraient pires que pas de promesse du tout.
     *
     * La table est indexée par capacité, et non une liste figée, parce que la
     * page doit annoncer exactement ce qui sera accordé : un client qui ne
     * demande que la lecture du catalogue ne reçoit que ça, et ne doit pas
     * lire quatre promesses dont trois ne le concernent pas.
     *
     * @var array<string, string>
     */
    public const CAPACITES = [
        'catalogue:lire' => 'Chercher des plats et des restaurants',
        'devis:calculer' => "Calculer le prix d'une commande, livraison comprise",
        'precommande:creer' => 'Préparer une pré-commande',
        'precommande:lire' => "Suivre l'état de vos commandes",
        'commande:lire' => "Suivre l'état de vos commandes",
    ];

    /**
     * @var array<int, string>
     */
    public const JAMAIS = [
        'Déclencher un paiement',
        'Annuler ou modifier une commande en cours',
        'Changer une adresse de livraison',
        'Créer un autre accès',
    ];

    /**
     * Un code d'autorisation ne sert qu'à faire un aller-retour immédiat entre
     * le navigateur et l'assistant. Soixante secondes, c'est large.
     */
    private const SECONDES_DE_VALIDITE = 60;

    /**
     * Une empreinte bcrypt qui ne correspond à aucun mot de passe.
     *
     * `Hash::check()` ne s'exécute que si un compte existe : sans ceci, une
     * adresse inconnue répondrait en moins d'une milliseconde là où un compte
     * réel coûte un bcrypt entier. Les deux pages ont beau être identiques à
     * l'octet près, le chronomètre dirait laquelle des deux adresses existe.
     *
     * NE PAS SUPPRIMER ce calcul « inutile » : son résultat est jeté, c'est
     * son temps d'exécution qui est utile. Le coût doit suivre
     * `config('hashing.bcrypt.rounds')`, aujourd'hui 10.
     */
    private const EMPREINTE_FACTICE = '$2y$10$k7NqlevMl.hMk4Q2TRT0leNN6LacBzZGsWcTXLUyPsGI03N7zAbsK';

    public function show(Request $request): Response|RedirectResponse
    {
        [$client, $redirectUri, $erreurPage] = $this->clientEtRedirection($request);

        if ($erreurPage !== null) {
            return $erreurPage;
        }

        $probleme = $this->verifierParametres($request);

        if ($probleme !== null) {
            return $this->redirigerAvecErreur($redirectUri, $probleme[0], $probleme[1], $request->input('state'));
        }

        return $this->pageConsentement($request, $client);
    }

    public function store(Request $request): Response|RedirectResponse
    {
        [$client, $redirectUri, $erreurPage] = $this->clientEtRedirection($request);

        // Les paramètres arrivent maintenant de champs cachés : ils sont aussi
        // peu dignes de confiance qu'en GET, et revérifiés à l'identique.
        if ($erreurPage !== null) {
            return $erreurPage;
        }

        $probleme = $this->verifierParametres($request);

        if ($probleme !== null) {
            return $this->redirigerAvecErreur($redirectUri, $probleme[0], $probleme[1], $request->input('state'));
        }

        if ($request->input('decision') !== 'autoriser') {
            return $this->redirigerAvecErreur(
                $redirectUri,
                'access_denied',
                "Vous avez refusé l'accès à cet assistant.",
                $request->input('state')
            );
        }

        $email = (string) $request->input('email');
        $motDePasse = (string) $request->input('password');

        $utilisateur = $email === '' ? null : User::query()->where('email', $email)->first();

        // Le bcrypt a lieu dans les deux cas : contre le vrai mot de passe, ou
        // contre une empreinte factice dont le résultat est jeté. Voir
        // EMPREINTE_FACTICE — c'est le temps d'exécution qui compte.
        $identifiantsValides = Hash::check(
            $motDePasse,
            $utilisateur->password ?? self::EMPREINTE_FACTICE
        ) && $utilisateur !== null && $motDePasse !== '';

        // Un message distinct par champ permettrait d'énumérer les comptes :
        // « email inconnu » dirait qu'une adresse n'existe pas, et « mot de
        // passe incorrect » qu'elle existe. Réponse unique, comme AuthController.
        if (! $identifiantsValides) {
            return $this->pageConsentement($request, $client, 'Email ou mot de passe incorrect');
        }

        $codeEnClair = bin2hex(random_bytes(32));

        // Le code est lié à tout ce qui a servi à l'obtenir. Chacun de ces
        // champs est revérifié à l'échange : un code volé, présenté par un
        // autre client ou vers une autre redirection, ne vaut rien.
        $code = new OauthAuthorizationCode;
        $code->code_hash = OauthAuthorizationCode::empreinte($codeEnClair);
        $code->oauth_client_id = $client->id;
        $code->user_id = $utilisateur->id;
        $code->redirect_uri = $redirectUri;
        $code->code_challenge = (string) $request->input('code_challenge');
        $code->code_challenge_method = 'S256';
        $code->scopes = $this->scopesDemandes($request);
        $code->resource = $request->input('resource');
        $code->expires_at = now()->addSeconds(self::SECONDES_DE_VALIDITE);
        $code->save();

        return redirect()->away($this->urlAvec($redirectUri, array_filter([
            'code' => $codeEnClair,
            'state' => $request->input('state'),
        ], fn ($valeur) => $valeur !== null)));
    }

    /**
     * Le client et sa redirection, vérifiés AVANT tout le reste.
     *
     * Tant que ces deux-là ne sont pas prouvés, on ne redirige nulle part :
     * rediriger vers une URI non validée, c'est précisément la faille que ce
     * contrôle existe pour empêcher. On affiche donc une page d'erreur.
     *
     * @return array{0: ?OauthClient, 1: ?string, 2: ?Response}
     */
    private function clientEtRedirection(Request $request): array
    {
        $clientId = $request->input('client_id');

        $client = is_string($clientId) && $clientId !== ''
            ? OauthClient::query()->where('client_id', $clientId)->first()
            : null;

        if (! $client) {
            return [null, null, $this->pageErreur(
                "Cette application n'est pas reconnue",
                "L'assistant qui vous a amené ici n'est pas enregistré auprès de Thalia. Ne saisissez pas votre mot de passe et revenez à l'application qui vous a envoyé."
            )];
        }

        $redirectUri = $request->input('redirect_uri');

        if (! is_string($redirectUri) || ! $client->accepteRedirection($redirectUri)) {
            return [null, null, $this->pageErreur(
                "Cette adresse de retour n'est pas autorisée",
                "L'adresse vers laquelle cet assistant demande à être renvoyé ne fait pas partie de celles qu'il a déclarées. Ne saisissez pas votre mot de passe et revenez à l'application qui vous a envoyé."
            )];
        }

        return [$client, $redirectUri, null];
    }

    /**
     * Les autres erreurs. Celles-là se signalent au client par redirection,
     * comme la spec le demande, parce que la redirection est désormais prouvée.
     *
     * @return array{0: string, 1: string}|null
     */
    private function verifierParametres(Request $request): ?array
    {
        if ($request->input('response_type') !== 'code') {
            return ['unsupported_response_type', 'Seul le type de réponse « code » est accepté.'];
        }

        // Absent, `code_challenge_method` vaut « plain » par défaut (RFC 7636).
        // On l'exige donc explicitement plutôt que de deviner une intention.
        if ($request->input('code_challenge_method') !== 'S256') {
            return ['invalid_request', 'La méthode PKCE doit être S256.'];
        }

        $challenge = $request->input('code_challenge');

        if (! is_string($challenge) || preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $challenge) !== 1) {
            return ['invalid_request', 'Le paramètre code_challenge est absent ou mal formé.'];
        }

        $inconnus = array_diff($this->scopesDemandes($request), TokenAbility::agent());

        if ($inconnus !== []) {
            return ['invalid_scope', 'Cette autorisation demande des droits que Thalia ne délivre pas.'];
        }

        $resource = $request->input('resource');

        if ($resource !== null && ! $this->ressourceAcceptable($resource)) {
            return ['invalid_target', 'Le paramètre resource doit être une URI absolue sans fragment.'];
        }

        return null;
    }

    /**
     * RFC 8707 : un indicateur de ressource est une URI absolue, sans fragment.
     */
    private function ressourceAcceptable(mixed $resource): bool
    {
        if (! is_string($resource) || $resource === '' || str_contains($resource, '#')) {
            return false;
        }

        $parties = parse_url($resource);

        return $parties !== false && isset($parties['scheme'], $parties['host']);
    }

    /**
     * @return array<int, string>
     */
    private function scopesDemandes(Request $request): array
    {
        $scope = $request->input('scope');

        if (! is_string($scope) || trim($scope) === '') {
            // Sans demande explicite, l'assistant reçoit ce que la page lui
            // promet : exactement les capacités de TokenAbility::agent().
            return TokenAbility::agent();
        }

        return array_values(array_unique(preg_split('/\s+/', trim($scope)) ?: []));
    }

    /**
     * L'hôte vers lequel le code partira, extrait côté serveur de la
     * redirection déjà validée — jamais d'une valeur affichable fournie par le
     * client.
     *
     * C'est la seule chose de cette page qui ne puisse pas mentir.
     * L'enregistrement est ouvert et `client_name` est du texte libre : rien
     * n'empêche quelqu'un de s'enregistrer sous le nom « Claude » avec une
     * redirection à lui. Le lien qu'il envoie alors à sa victime est
     * authentique, sur le vrai domaine, en TLS valide. Ce qui casse l'attaque,
     * c'est que la victime lise vers OÙ elle sera renvoyée.
     */
    private function hoteDestination(string $redirectUri): string
    {
        $parties = parse_url($redirectUri);

        $hote = (string) ($parties['host'] ?? '');

        if (isset($parties['port'])) {
            $hote .= ':'.$parties['port'];
        }

        return $hote;
    }

    /**
     * Les phrases correspondant aux capacités réellement accordées, dans
     * l'ordre de l'enum et sans doublon.
     *
     * @param  array<int, string>  $scopes
     * @return array<int, string>
     */
    private function promesses(array $scopes): array
    {
        $phrases = [];

        foreach (self::CAPACITES as $capacite => $phrase) {
            if (in_array($capacite, $scopes, true)) {
                $phrases[$phrase] = true;
            }
        }

        return array_keys($phrases);
    }

    private function pageConsentement(Request $request, OauthClient $client, ?string $erreur = null): Response
    {
        return $this->sansEncadrement(response()->view('oauth.autorisation', [
            'client' => $client,
            'erreur' => $erreur,
            'email' => (string) $request->input('email', ''),
            'destination' => $this->hoteDestination((string) $request->input('redirect_uri')),
            'autorise' => $this->promesses($this->scopesDemandes($request)),
            'jamais' => self::JAMAIS,
            'parametres' => [
                'client_id' => (string) $request->input('client_id'),
                'redirect_uri' => (string) $request->input('redirect_uri'),
                'response_type' => (string) $request->input('response_type'),
                'code_challenge' => (string) $request->input('code_challenge'),
                'code_challenge_method' => (string) $request->input('code_challenge_method'),
                'state' => $request->input('state'),
                'scope' => $request->input('scope'),
                'resource' => $request->input('resource'),
            ],
        ]));
    }

    private function pageErreur(string $titre, string $message): Response
    {
        return $this->sansEncadrement(response()->view('oauth.erreur', [
            'titre' => $titre,
            'message' => $message,
        ], 400));
    }

    /**
     * Interdit l'encadrement de la page.
     *
     * C'est un formulaire de mot de passe : chargé dans une iframe invisible
     * au-dessus d'une page attirante, il fait signer un consentement a qui
     * croyait cliquer ailleurs. `X-Frame-Options` double la directive CSP pour
     * les navigateurs qui ne la connaissent pas encore.
     */
    private function sansEncadrement(Response $reponse): Response
    {
        return $reponse
            ->header('Content-Security-Policy', "frame-ancestors 'none'")
            ->header('X-Frame-Options', 'DENY');
    }

    private function redirigerAvecErreur(string $redirectUri, string $code, string $description, mixed $state): RedirectResponse
    {
        $parametres = [
            'error' => $code,
            'error_description' => $description,
        ];

        if (is_string($state) && $state !== '') {
            $parametres['state'] = $state;
        }

        return redirect()->away($this->urlAvec($redirectUri, $parametres));
    }

    /**
     * Ajoute des paramètres à une URI de redirection sans écraser les siens :
     * un client a le droit d'avoir déjà une chaîne de requête.
     *
     * @param  array<string, mixed>  $parametres
     */
    private function urlAvec(string $uri, array $parametres): string
    {
        $separateur = str_contains($uri, '?') ? '&' : '?';

        return $uri.$separateur.http_build_query($parametres);
    }
}
