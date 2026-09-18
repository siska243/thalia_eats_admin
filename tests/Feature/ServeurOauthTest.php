<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\OauthAuthorizationCode;
use App\Models\User;
use App\Wrappers\Cipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Le serveur d'autorisation OAuth 2.1 qui permet à un assistant de se
 * connecter à un compte Thalia en un clic.
 *
 * Ce qui est vérifié ici n'est pas « le code fait ce qu'il dit » mais « un
 * code volé, rejoué, détourné ou présenté par le mauvais client ne donne accès
 * à rien ». C'est de l'authentification sur une application de paiement.
 */
class ServeurOauthTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECTION = 'https://claude.ai/api/mcp/auth_callback';

    /** Un vérificateur PKCE valide : 43 caractères de l'alphabet autorisé. */
    private const VERIFIER = 'FVjWIRmsjX2Ep1BJXqYDRSX5PNKJ7pzDeMBTAtWPJ6i';

    private function defi(string $verifier = self::VERIFIER): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * @param  array<int, string>  $redirections
     */
    private function enregistrerClient(array $redirections = [self::REDIRECTION], string $nom = 'Claude'): string
    {
        $reponse = $this->postJson('/oauth/register', [
            'client_name' => $nom,
            'redirect_uris' => $redirections,
            'grant_types' => ['authorization_code'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ]);

        $reponse->assertStatus(201);

        return $reponse->json('client_id');
    }

    /**
     * @param  array<string, mixed>  $remplacements
     * @return array<string, mixed>
     */
    private function parametresAutorisation(string $clientId, array $remplacements = []): array
    {
        return array_merge([
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECTION,
            'response_type' => 'code',
            'code_challenge' => $this->defi(),
            'code_challenge_method' => 'S256',
            'state' => 'etat-opaque-du-client',
            'resource' => 'https://mcp.thaliaeats.com/mcp',
        ], $remplacements);
    }

    /**
     * Déroule autorisation + consentement et rend le code d'autorisation.
     *
     * @param  array<string, mixed>  $remplacements
     */
    private function obtenirCode(User $utilisateur, string $clientId, array $remplacements = []): string
    {
        $reponse = $this->post('/oauth/authorize', $this->parametresAutorisation($clientId, $remplacements) + [
            'email' => $utilisateur->email,
            'password' => 'password',
            'decision' => 'autoriser',
        ]);

        $reponse->assertStatus(302);

        parse_str((string) parse_url($reponse->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertArrayHasKey('code', $query, 'La redirection ne porte pas de code.');

        return $query['code'];
    }

    // ---------------------------------------------------------------- Métadonnées

    public function test_les_metadonnees_n_annoncent_que_s256(): void
    {
        $reponse = $this->getJson('/.well-known/oauth-authorization-server');

        $reponse->assertStatus(200);
        $this->assertSame(['S256'], $reponse->json('code_challenge_methods_supported'));
        $this->assertSame(['code'], $reponse->json('response_types_supported'));
        $this->assertSame(['authorization_code'], $reponse->json('grant_types_supported'));
        $this->assertSame(['none'], $reponse->json('token_endpoint_auth_methods_supported'));
        $this->assertSame(TokenAbility::agent(), $reponse->json('scopes_supported'));

        $emetteur = rtrim((string) config('app.url'), '/');
        $this->assertSame($emetteur, $reponse->json('issuer'));
        $this->assertSame($emetteur.'/oauth/authorize', $reponse->json('authorization_endpoint'));
        $this->assertSame($emetteur.'/oauth/token', $reponse->json('token_endpoint'));
        $this->assertSame($emetteur.'/oauth/register', $reponse->json('registration_endpoint'));
    }

    // ------------------------------------------------------------ Enregistrement

    public function test_un_client_s_enregistre_et_recoit_un_client_id(): void
    {
        $reponse = $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => [self::REDIRECTION],
        ]);

        $reponse->assertStatus(201);
        $this->assertNotEmpty($reponse->json('client_id'));

        // Client public : un secret ne protégerait rien et ferait croire le contraire.
        $this->assertArrayNotHasKey('client_secret', $reponse->json());
        $this->assertSame('none', $reponse->json('token_endpoint_auth_method'));

        $this->assertDatabaseHas('oauth_clients', ['client_id' => $reponse->json('client_id')]);
    }

    public function test_un_client_qui_demande_aussi_refresh_token_s_enregistre_sans_l_obtenir(): void
    {
        // Le refuser bloquerait la connexion d'un client par ailleurs
        // parfaitement compatible. Il s'enregistre, et la réponse lui dit ce
        // qu'il a réellement obtenu.
        $reponse = $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => [self::REDIRECTION],
            'grant_types' => ['authorization_code', 'refresh_token'],
        ]);

        $reponse->assertStatus(201);
        $this->assertSame(['authorization_code'], $reponse->json('grant_types'));
    }

    public function test_un_client_qui_ne_demande_pas_authorization_code_est_refuse(): void
    {
        $this->postJson('/oauth/register', [
            'client_name' => 'Client incompatible',
            'redirect_uris' => [self::REDIRECTION],
            'grant_types' => ['client_credentials'],
        ])->assertStatus(400)->assertJson(['error' => 'invalid_client_metadata']);
    }

    public function test_un_client_qui_attend_un_secret_est_refuse(): void
    {
        $this->postJson('/oauth/register', [
            'client_name' => 'Client confidentiel',
            'redirect_uris' => [self::REDIRECTION],
            'token_endpoint_auth_method' => 'client_secret_post',
        ])->assertStatus(400)->assertJson(['error' => 'invalid_client_metadata']);
    }

    public function test_une_redirection_http_non_locale_est_refusee(): void
    {
        $this->postJson('/oauth/register', [
            'client_name' => 'Pirate',
            'redirect_uris' => ['http://exemple.test/callback'],
        ])->assertStatus(400)->assertJson(['error' => 'invalid_client_metadata']);

        $this->assertDatabaseCount('oauth_clients', 0);
    }

    public function test_la_boucle_locale_reste_acceptee_pour_un_logiciel_installe(): void
    {
        // Un client de bureau ouvre un serveur sur un port que le système lui
        // attribue : la spec prévoit cette exception, et sans elle aucun
        // assistant installé localement ne peut se connecter.
        $this->postJson('/oauth/register', [
            'client_name' => 'Assistant local',
            'redirect_uris' => ['http://127.0.0.1:49152/callback', 'http://localhost:8123/cb'],
        ])->assertStatus(201);
    }

    public function test_une_redirection_avec_fragment_est_refusee(): void
    {
        $this->postJson('/oauth/register', [
            'client_name' => 'Pirate',
            'redirect_uris' => ['https://claude.ai/callback#ailleurs'],
        ])->assertStatus(400);
    }

    // -------------------------------------------------------------- Autorisation

    public function test_un_client_id_inconnu_affiche_une_erreur_et_ne_redirige_pas(): void
    {
        $reponse = $this->get('/oauth/authorize?'.http_build_query(
            $this->parametresAutorisation('client-qui-n-existe-pas')
        ));

        $reponse->assertStatus(400);
        $this->assertNull($reponse->headers->get('Location'));
        $reponse->assertSee("Cette application n'est pas reconnue");
    }

    public function test_une_redirection_non_enregistree_affiche_une_erreur_et_ne_redirige_pas(): void
    {
        $clientId = $this->enregistrerClient();

        $reponse = $this->get('/oauth/authorize?'.http_build_query(
            $this->parametresAutorisation($clientId, ['redirect_uri' => 'https://pirate.test/vol'])
        ));

        $reponse->assertStatus(400);
        $this->assertNull($reponse->headers->get('Location'));
        $reponse->assertSee("Cette adresse de retour n'est pas autorisée");
    }

    public function test_une_redirection_seulement_prefixee_est_refusee(): void
    {
        $clientId = $this->enregistrerClient();

        $this->get('/oauth/authorize?'.http_build_query(
            $this->parametresAutorisation($clientId, ['redirect_uri' => self::REDIRECTION.'.pirate.test'])
        ))->assertStatus(400);
    }

    public function test_la_methode_plain_est_refusee(): void
    {
        $clientId = $this->enregistrerClient();

        $reponse = $this->get('/oauth/authorize?'.http_build_query(
            $this->parametresAutorisation($clientId, [
                'code_challenge_method' => 'plain',
                'code_challenge' => self::VERIFIER,
            ])
        ));

        // La redirection est prouvée à ce stade : l'erreur se signale au client
        // par redirection, avec son state, comme la spec le demande.
        $reponse->assertStatus(302);
        parse_str((string) parse_url($reponse->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('invalid_request', $query['error']);
        $this->assertSame('etat-opaque-du-client', $query['state']);
        $this->assertArrayNotHasKey('code', $query);
    }

    public function test_un_scope_inconnu_est_refuse(): void
    {
        $clientId = $this->enregistrerClient();

        $reponse = $this->get('/oauth/authorize?'.http_build_query(
            $this->parametresAutorisation($clientId, ['scope' => 'commande:annuler'])
        ));

        $reponse->assertStatus(302);
        parse_str((string) parse_url($reponse->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('invalid_scope', $query['error']);
    }

    public function test_la_page_annonce_les_deux_listes_de_promesses(): void
    {
        $clientId = $this->enregistrerClient();

        $reponse = $this->get('/oauth/authorize?'.http_build_query($this->parametresAutorisation($clientId)));

        $reponse->assertStatus(200);
        $reponse->assertSee('Claude');

        // Les mêmes phrases que la page web et l'écran mobile : trois promesses
        // différentes selon l'écran seraient pires que pas de promesse.
        foreach (['Chercher des plats et des restaurants', 'Préparer une pré-commande'] as $promesse) {
            $reponse->assertSee($promesse, false);
        }

        foreach (['Déclencher un paiement', 'Créer un autre accès'] as $interdit) {
            $reponse->assertSee($interdit, false);
        }
    }

    public function test_de_mauvais_identifiants_ne_disent_pas_lequel_des_champs_est_faux(): void
    {
        $clientId = $this->enregistrerClient();
        $utilisateur = User::factory()->create();

        $inconnu = $this->post('/oauth/authorize', $this->parametresAutorisation($clientId) + [
            'email' => 'personne@exemple.test',
            'password' => 'password',
            'decision' => 'autoriser',
        ]);

        $mauvaisMotDePasse = $this->post('/oauth/authorize', $this->parametresAutorisation($clientId) + [
            'email' => $utilisateur->email,
            'password' => 'pas-le-bon',
            'decision' => 'autoriser',
        ]);

        foreach ([$inconnu, $mauvaisMotDePasse] as $reponse) {
            $reponse->assertStatus(200);
            $reponse->assertSee('Email ou mot de passe incorrect');
        }

        // Aucun code n'a été émis, et les deux pages sont identiques une fois
        // retirée l'adresse que le formulaire réaffiche : rien, ni dans le
        // message ni ailleurs, ne dit si l'adresse existe.
        $this->assertDatabaseCount('oauth_authorization_codes', 0);

        $this->assertSame(
            $this->corpsComparable($inconnu->getContent(), 'personne@exemple.test'),
            $this->corpsComparable($mauvaisMotDePasse->getContent(), $utilisateur->email),
        );
    }

    /**
     * Le contenu de la page, débarrassé de ce qui change à chaque requête sans
     * rien dire du compte : l'adresse que le formulaire réaffiche, et le jeton
     * CSRF. Ce qui reste doit être identique dans les deux cas.
     */
    private function corpsComparable(string $html, string $email): string
    {
        $debut = strpos($html, '<h1>');
        $fin = strpos($html, '</form>');

        $this->assertNotFalse($debut);
        $this->assertNotFalse($fin);

        $corps = substr($html, $debut, $fin - $debut);

        return str_replace([$email, csrf_token()], ['ADRESSE', 'CSRF'], $corps);
    }

    public function test_un_refus_redirige_avec_access_denied(): void
    {
        $clientId = $this->enregistrerClient();

        $reponse = $this->post('/oauth/authorize', $this->parametresAutorisation($clientId) + [
            'decision' => 'refuser',
        ]);

        $reponse->assertStatus(302);
        parse_str((string) parse_url($reponse->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('access_denied', $query['error']);
        $this->assertSame('etat-opaque-du-client', $query['state']);
        $this->assertDatabaseCount('oauth_authorization_codes', 0);
    }

    public function test_le_code_n_est_jamais_stocke_en_clair(): void
    {
        $clientId = $this->enregistrerClient();
        $code = $this->obtenirCode(User::factory()->create(), $clientId);

        $this->assertDatabaseMissing('oauth_authorization_codes', ['code_hash' => $code]);
        $this->assertDatabaseHas('oauth_authorization_codes', ['code_hash' => hash('sha256', $code)]);
    }

    // --------------------------------------------------------------- L'échange

    public function test_le_parcours_complet_delivre_un_jeton_agent(): void
    {
        $clientId = $this->enregistrerClient();
        $utilisateur = User::factory()->create();
        $code = $this->obtenirCode($utilisateur, $clientId);

        $reponse = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECTION,
            'client_id' => $clientId,
            'code_verifier' => self::VERIFIER,
            'resource' => 'https://mcp.thaliaeats.com/mcp',
        ]);

        $reponse->assertStatus(200);
        $this->assertSame('Bearer', $reponse->json('token_type'));
        $this->assertNotEmpty($reponse->json('access_token'));
        $this->assertSame(implode(' ', TokenAbility::agent()), $reponse->json('scope'));
        $this->assertGreaterThan(0, $reponse->json('expires_in'));

        $jeton = $utilisateur->tokens()->where('name', 'Claude')->firstOrFail();
        $this->assertSame(TokenAbility::agent(), $jeton->abilities);
        $this->assertNotNull($jeton->expires_at);

        // Le jeton ouvre bien les portes qu'il promet.
        $this->withHeader('Authorization', 'Bearer '.$reponse->json('access_token'))
            ->getJson('/api/products/search?q=poulet')
            ->assertStatus(200);
    }

    public function test_la_reponse_du_point_de_jeton_porte_cache_control_no_store(): void
    {
        $clientId = $this->enregistrerClient();
        $code = $this->obtenirCode(User::factory()->create(), $clientId);

        $reponse = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECTION,
            'client_id' => $clientId,
            'code_verifier' => self::VERIFIER,
        ]);

        $reponse->assertStatus(200);
        $this->assertStringContainsString('no-store', (string) $reponse->headers->get('Cache-Control'));
    }

    public function test_un_code_verifier_faux_est_refuse(): void
    {
        $clientId = $this->enregistrerClient();
        $utilisateur = User::factory()->create();
        $code = $this->obtenirCode($utilisateur, $clientId);

        $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECTION,
            'client_id' => $clientId,
            'code_verifier' => str_repeat('z', 43),
        ])->assertStatus(400)->assertJson(['error' => 'invalid_grant']);

        $this->assertSame(0, $utilisateur->tokens()->count());
    }

    public function test_un_code_deja_consomme_est_refuse(): void
    {
        $clientId = $this->enregistrerClient();
        $utilisateur = User::factory()->create();
        $code = $this->obtenirCode($utilisateur, $clientId);

        $echange = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECTION,
            'client_id' => $clientId,
            'code_verifier' => self::VERIFIER,
        ];

        $this->postJson('/oauth/token', $echange)->assertStatus(200);
        $this->postJson('/oauth/token', $echange)->assertStatus(400)->assertJson(['error' => 'invalid_grant']);

        // Un seul jeton, et le code est marqué — pas effacé.
        $this->assertSame(1, $utilisateur->tokens()->count());
        $this->assertDatabaseCount('oauth_authorization_codes', 1);
        $this->assertNotNull(OauthAuthorizationCode::query()->firstOrFail()->consumed_at);
    }

    public function test_un_code_expire_est_refuse(): void
    {
        $clientId = $this->enregistrerClient();
        $utilisateur = User::factory()->create();
        $code = $this->obtenirCode($utilisateur, $clientId);

        $this->travel(61)->seconds();

        $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECTION,
            'client_id' => $clientId,
            'code_verifier' => self::VERIFIER,
        ])->assertStatus(400)->assertJson(['error' => 'invalid_grant']);

        $this->assertSame(0, $utilisateur->tokens()->count());
    }

    public function test_un_code_presente_avec_un_autre_client_id_est_refuse(): void
    {
        $clientId = $this->enregistrerClient();
        $autreClientId = $this->enregistrerClient([self::REDIRECTION], 'Un autre assistant');
        $utilisateur = User::factory()->create();
        $code = $this->obtenirCode($utilisateur, $clientId);

        $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECTION,
            'client_id' => $autreClientId,
            'code_verifier' => self::VERIFIER,
        ])->assertStatus(400)->assertJson(['error' => 'invalid_grant']);

        $this->assertSame(0, $utilisateur->tokens()->count());
    }

    public function test_un_code_presente_avec_un_autre_redirect_uri_est_refuse(): void
    {
        $clientId = $this->enregistrerClient([self::REDIRECTION, 'https://claude.ai/autre']);
        $utilisateur = User::factory()->create();
        $code = $this->obtenirCode($utilisateur, $clientId);

        // La seconde redirection est pourtant enregistrée pour ce client : ce
        // n'est pas la liste blanche qui refuse, c'est la liaison du code.
        $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://claude.ai/autre',
            'client_id' => $clientId,
            'code_verifier' => self::VERIFIER,
        ])->assertStatus(400)->assertJson(['error' => 'invalid_grant']);

        $this->assertSame(0, $utilisateur->tokens()->count());
    }

    public function test_un_code_presente_pour_une_autre_ressource_est_refuse(): void
    {
        $clientId = $this->enregistrerClient();
        $utilisateur = User::factory()->create();
        $code = $this->obtenirCode($utilisateur, $clientId);

        $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECTION,
            'client_id' => $clientId,
            'code_verifier' => self::VERIFIER,
            'resource' => 'https://ailleurs.test/mcp',
        ])->assertStatus(400)->assertJson(['error' => 'invalid_grant']);

        $this->assertSame(0, $utilisateur->tokens()->count());
    }

    public function test_un_client_inconnu_au_point_de_jeton_est_refuse(): void
    {
        $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => 'peu importe',
            'redirect_uri' => self::REDIRECTION,
            'client_id' => 'client-qui-n-existe-pas',
            'code_verifier' => self::VERIFIER,
        ])->assertStatus(401)->assertJson(['error' => 'invalid_client']);
    }

    public function test_un_autre_grant_type_est_refuse(): void
    {
        $this->postJson('/oauth/token', [
            'grant_type' => 'password',
            'client_id' => 'peu importe',
        ])->assertStatus(400)->assertJson(['error' => 'unsupported_grant_type']);
    }

    // ------------------------------------------------- Réversible depuis le téléphone

    public function test_le_jeton_delivre_apparait_dans_la_liste_des_assistants_et_se_revoque(): void
    {
        $clientId = $this->enregistrerClient();
        $utilisateur = User::factory()->create();
        $code = $this->obtenirCode($utilisateur, $clientId);

        $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECTION,
            'client_id' => $clientId,
            'code_verifier' => self::VERIFIER,
        ])->assertStatus(200);

        // C'est ce qui rend la connexion réversible : la même liste, le même
        // bouton que pour un jeton créé à la main dans l'application.
        Sanctum::actingAs($utilisateur, ['*']);

        $liste = $this->getJson('/api/user/assistants');
        $liste->assertStatus(200);
        $this->assertSame('Claude', $liste->json('0.name'));

        $this->deleteJson('/api/user/assistants/'.$liste->json('0.uid'))->assertStatus(200);

        $this->assertSame(0, $utilisateur->tokens()->count());
        $this->assertNotEmpty(Cipher::Decrypt($liste->json('0.uid')));
    }

    public function test_le_jeton_delivre_ne_peut_pas_en_emettre_un_autre(): void
    {
        $clientId = $this->enregistrerClient();
        $utilisateur = User::factory()->create();
        $code = $this->obtenirCode($utilisateur, $clientId);

        $jeton = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECTION,
            'client_id' => $clientId,
            'code_verifier' => self::VERIFIER,
        ])->json('access_token');

        // Un agent ne s'auto-délivre pas de pouvoirs.
        $this->withHeader('Authorization', 'Bearer '.$jeton)
            ->postJson('/api/user/assistants', ['name' => 'un autre'])
            ->assertStatus(403);
    }
}
