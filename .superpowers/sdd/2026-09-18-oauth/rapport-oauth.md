# Rapport — Connexion en un clic pour les assistants (OAuth 2.1)

Branche `feature/oauth-assistants`, arbre de travail séparé.

## Ce qui a été construit

Un serveur d'autorisation OAuth 2.1 minimal, en quatre routes, dans le groupe `web`.
Aucun endpoint existant n'a été modifié dans son comportement. Pas de Passport : le jeton
délivré est un **jeton Sanctum ordinaire** portant `TokenAbility::agent()`, ce qui rend la
connexion visible et révocable depuis les écrans qui existent déjà.

| Route | Rôle |
|---|---|
| `GET /.well-known/oauth-authorization-server` | Découverte (RFC 8414) |
| `POST /oauth/register` | Enregistrement dynamique de client (RFC 7591) |
| `GET /oauth/authorize` | Page de connexion **et** de consentement |
| `POST /oauth/authorize` | Vérifie les identifiants, délivre le code |
| `POST /oauth/token` | Échange code → jeton Sanctum |

### Décisions, et pourquoi

**Le client_id et la redirect_uri sont vérifiés avant tout le reste, et leur échec affiche
une page — jamais une redirection.** Rediriger vers une URI non validée, c'est précisément
la faille que cette vérification existe pour empêcher. La page invite explicitement à ne
pas saisir son mot de passe. Toutes les autres erreurs (`unsupported_response_type`,
`invalid_request`, `invalid_scope`, `invalid_target`, `access_denied`) se signalent au
client par redirection avec `error=` et son `state`, comme la spec le demande — la
redirection est alors prouvée.

**La correspondance des redirections est exacte, jamais préfixée.** Un test vérifie que
`…/auth_callback.pirate.test` est refusé là où `…/auth_callback` est enregistré.

**PKCE S256 est exigé explicitement.** `code_challenge_method` absent vaut « plain » par
défaut (RFC 7636) : on refuse plutôt que de deviner une intention. Les métadonnées
n'annoncent que `S256`.

**Le code est haché (SHA-256), à usage unique, valable 60 secondes**, et lié au
`client_id`, au `redirect_uri`, à l'utilisateur, au `code_challenge` et au `resource`.
Chacun de ces cinq liens est revérifié à l'échange, et **tous les échecs rendent le même
`invalid_grant` sans dire lequel** — dire lequel apprendrait à un attaquant ce qu'il lui
manque.

**La consommation du code et la délivrance du jeton sont dans une seule transaction, avec
`lockForUpdate()` sur la ligne.** Sans le verrou, deux échanges simultanés liraient tous
deux « non consommé » avant que l'un n'écrive, et produiraient deux jetons.

**Rien ne se supprime** : un code consommé porte `consumed_at` et reste en base.

**Un seul écran porte la connexion et le consentement**, puisque Thalia est une API et
qu'il n'existe aucune session web de client à réutiliser. Les deux listes de promesses
sont reprises **mot pour mot** de `components/account/AssistantsConnectes.jsx` (web) et de
`app/custom-screens/assistants.tsx` (mobile) — elles sont désormais dans
`AutorisationController::AUTORISE` et `::JAMAIS`, et un test les vérifie.

**Le message d'échec d'identifiants est unique** (« Email ou mot de passe incorrect »),
repris d'`AuthController::login()`. Un test compare les deux pages rendues (adresse inconnue
vs mauvais mot de passe) et vérifie qu'elles sont identiques au caractère près, une fois
retirés l'adresse réaffichée et le jeton CSRF.

**Clients publics uniquement.** Aucun `client_secret` n'est délivré :
`token_endpoint_auth_methods_supported` vaut `["none"]`, et un client qui s'annonce avec
`client_secret_post` est refusé tout de suite plutôt que d'échouer plus tard sans
comprendre. En revanche un client qui demande *aussi* `refresh_token` s'enregistre : la
RFC 7591 autorise le serveur à restreindre, et la réponse lui dit ce qu'il a réellement
obtenu — le refuser bloquerait un client par ailleurs compatible.

**Durée du jeton : une seule définition.** `AssistantTokenController::JOURS_PAR_DEFAUT`
(90 jours) est passée de `private` à `public` et réutilisée, plutôt qu'une seconde valeur
qui divergerait.

### Limiteurs (dans `RouteServiceProvider`, avec les autres)

Aucune de ces routes n'est authentifiée au moment où elle est appelée : l'IP est la seule
clé disponible.

- `oauth-enregistrement` — 20/heure. Sans limite, n'importe qui remplit la table des clients.
- `oauth-autorisation` — 30/minute.
- `oauth-connexion` — **5/minute et 30/heure**. C'est le seul formulaire public de
  l'application qui teste des mots de passe.
- `oauth-jeton` — 30/minute.

### CSRF

`oauth/register` et `oauth/token` sont exemptés (appelés par un logiciel, sans session d'où
tirer un jeton, et sans cookie sur lequel s'appuyer). **`POST /oauth/authorize` reste
protégé par CSRF**, puisque c'est lui qui vérifie un mot de passe dans un navigateur.

## ⚠️ Livraison : deux migrations

Ce lot contient deux migrations. Le déploiement automatique (`deploy/auto-deploy.sh`)
refuse tout lot qui en contient, et c'est voulu : un schéma qui change sur une base portant
des commandes et des paiements réels se livre avec quelqu'un devant l'écran.

**La livraison se fera à la main :** `cd deploy && ./deploy.sh --migrate`

Les deux migrations ne font que créer deux tables neuves (`oauth_clients`,
`oauth_authorization_codes`). Elles ne touchent aucune table existante, ne verrouillent rien
et n'ont aucun effet sur les commandes en cours.

## Tests

29 tests, 144 assertions, tous verts.

```
----- php artisan test --filter=ServeurOauthTest -----

   PASS  Tests\Feature\ServeurOauthTest
  ✓ les metadonnees n annoncent que s256                                 1.73s  
  ✓ un client s enregistre et recoit un client id                        0.05s  
  ✓ un client qui demande aussi refresh token s enregistre sans l obten… 0.05s  
  ✓ un client qui ne demande pas authorization code est refuse           0.04s  
  ✓ un client qui attend un secret est refuse                            0.04s  
  ✓ une redirection http non locale est refusee                          0.04s  
  ✓ la boucle locale reste acceptee pour un logiciel installe            0.04s  
  ✓ une redirection avec fragment est refusee                            0.04s  
  ✓ un client id inconnu affiche une erreur et ne redirige pas           0.06s  
  ✓ une redirection non enregistree affiche une erreur et ne redirige p… 0.05s  
  ✓ une redirection seulement prefixee est refusee                       0.04s  
  ✓ la methode plain est refusee                                         0.04s  
  ✓ un scope inconnu est refuse                                          0.05s  
  ✓ la page annonce les deux listes de promesses                         0.04s  
  ✓ de mauvais identifiants ne disent pas lequel des champs est faux     0.11s  
  ✓ un refus redirige avec access denied                                 0.04s  
  ✓ le code n est jamais stocke en clair                                 0.10s  
  ✓ le parcours complet delivre un jeton agent                           0.13s  
  ✓ la reponse du point de jeton porte cache control no store            0.12s  
  ✓ un code verifier faux est refuse                                     0.10s  
  ✓ un code deja consomme est refuse                                     0.11s  
  ✓ un code expire est refuse                                            0.13s  
  ✓ un code presente avec un autre client id est refuse                  0.11s  
  ✓ un code presente avec un autre redirect uri est refuse               0.11s  
  ✓ un code presente pour une autre ressource est refuse                 0.10s  
  ✓ un client inconnu au point de jeton est refuse                       0.06s  
  ✓ un autre grant type est refuse                                       0.04s  
  ✓ le jeton delivre apparait dans la liste des assistants et se revoqu… 0.11s  
  ✓ le jeton delivre ne peut pas en emettre un autre                     0.10s  

  Tests:    29 passed (144 assertions)
  Duration: 3.87s


----- php artisan test (suite complète) -----

  Tests:    1 failed, 315 passed (823 assertions)
  Duration: 37.80s


```

L'unique échec de la suite complète, `Tests\Feature\ExampleTest > the application returns
a successful response`, est **antérieur à ce travail** : il appelle `GET /` alors que cette
route est commentée dans `routes/web.php`. Vérifié en remisant mes modifications
(`git stash -u`) : il échoue déjà sur `db86737`.

Base de test : `thalia_eats_oauth_test`, créée comme indiqué.

## Parcours réel au curl

`php artisan serve --port=8811`, compte de test `cliente@thaliaeats.test`.
Sortie brute, non retouchée (seul le jeton d'accès est tronqué à l'affichage) :

```
### 1. Métadonnées ###
{
    "issuer": "http://127.0.0.1:8811",
    "authorization_endpoint": "http://127.0.0.1:8811/oauth/authorize",
    "token_endpoint": "http://127.0.0.1:8811/oauth/token",
    "registration_endpoint": "http://127.0.0.1:8811/oauth/register",
    "scopes_supported": [
        "catalogue:lire",
        "devis:calculer",
        "precommande:creer",
        "precommande:lire",
        "commande:lire"
    ],
    "response_types_supported": [
        "code"
    ],
    "grant_types_supported": [
        "authorization_code"
    ],
    "code_challenge_methods_supported": [
        "S256"
    ],
    "token_endpoint_auth_methods_supported": [
        "none"
    ]
}

### 2. Enregistrement dynamique ###
HTTP/1.1 201 Created
Cache-Control: no-store, private
{
    "client_id": "thalia-fZLWxrY4Ufgr7m5aSXdU8NRwTXZoopcByPQM0TpT",
    "client_id_issued_at": 1789733771,
    "client_name": "Claude",
    "redirect_uris": [
        "http://127.0.0.1:33418/callback"
    ],
    "grant_types": [
        "authorization_code"
    ],
    "response_types": [
        "code"
    ],
    "token_endpoint_auth_method": "none"
}

### 3a. client_id inconnu : page d'erreur, AUCUNE redirection ###
HTTP/1.1 400 Bad Request
(pas de Location ci-dessus = pas de redirection)

### 3b. Page d'autorisation ###
<h1>Claude demande l'accès à votre compte Thalia</h1>
<li class="oui">Chercher des plats et des restaurants</li>
<li class="oui">Calculer le prix d&#039;une commande, livraison comprise</li>
<li class="oui">Préparer une pré-commande</li>
<li class="oui">Suivre l&#039;état de vos commandes</li>
<li class="non">Déclencher un paiement</li>
<li class="non">Annuler ou modifier une commande en cours</li>
<li class="non">Changer une adresse de livraison</li>
<li class="non">Créer un autre accès</li>

### 4. Consentement (identifiants du client) ###
HTTP/1.1 302 Found
Location: http://127.0.0.1:33418/callback?code=70e646ef17858a47a21b864c51953a5ea7cb7b0d2733c9b7bafb3916068fa98f&state=etat-123

### 5. Échange du code ###
HTTP/1.1 200 OK
Cache-Control: no-store, private
{
  "access_token": "22|YhDWwCiiN…(tronqué)",
  "token_type": "Bearer",
  "expires_in": 7776000,
  "scope": "catalogue:lire devis:calculer precommande:creer precommande:lire commande:lire"
}

### 6. Rejeu du même code ###
{
    "error": "invalid_grant",
    "error_description": "Ce code d'autorisation n'est pas utilisable."
}

### 7. Appel API authentifié avec le jeton délivré ###
HTTP 200
{"data":[],"meta":{"current_page":1,"last_page":1,"per_page":20,"total":0}}

### 8. Le jeton ne peut pas en émettre un autre ###
HTTP 403
{"title":"Oups","message":"Cette action n'est pas accessible depuis un assistant.","error":"ability_absente"}

```

Ce qu'il faut y lire : le client s'enregistre sans qu'aucun humain ne saisisse quoi que ce
soit, le `client_id` inconnu répond 400 **sans en-tête `Location`**, la page affiche les huit
promesses, le consentement redirige avec `code` et `state`, l'échange rend un jeton Bearer
portant exactement les cinq capacités de `TokenAbility::agent()` avec `Cache-Control:
no-store`, le rejeu du même code est refusé, le jeton ouvre `/api/products/search`, et il ne
peut pas s'auto-délivrer un autre accès.

## Fichiers

**Ajoutés**
- `database/migrations/2026_09_18_120000_create_oauth_clients_table.php`
- `database/migrations/2026_09_18_120100_create_oauth_authorization_codes_table.php`
- `app/Models/OauthClient.php`, `app/Models/OauthAuthorizationCode.php`
- `app/Rules/RedirectionOauth.php`
- `app/Http/Controllers/Oauth/MetadonneesController.php`
- `app/Http/Controllers/Oauth/EnregistrementController.php`
- `app/Http/Controllers/Oauth/AutorisationController.php`
- `app/Http/Controllers/Oauth/JetonController.php`
- `resources/views/oauth/autorisation.blade.php`, `resources/views/oauth/erreur.blade.php`
- `tests/Feature/ServeurOauthTest.php`

**Modifiés**
- `routes/web.php` — cinq routes ajoutées, rien d'autre touché (j'ai annulé les
  reformatages que Pint proposait sur les lignes existantes, pour garder le diff propre).
- `app/Http/Middleware/VerifyCsrfToken.php` — deux exemptions.
- `app/Providers/RouteServiceProvider.php` — quatre limiteurs.
- `app/Http/Controllers/Api/AssistantTokenController.php` — `JOURS_PAR_DEFAUT` rendue
  publique, aucun changement de comportement.

`mcp/` n'a pas été touché.

## Réserves

1. **`resource` à l'échange : strict s'il est présent, toléré s'il est absent.** Si la
   requête de jeton porte un `resource`, il doit être identique à celui de l'autorisation
   (testé). Si elle n'en porte aucun, on accepte : le code reste lié à la ressource de son
   autorisation, et refuser casserait les clients qui n'envoient l'indicateur qu'à
   l'autorisation. C'est un écart volontaire à « vérifiez tout », et je préfère qu'il soit
   écrit ici plutôt que découvert.

2. **Un `scope` inconnu est refusé (`invalid_scope`).** C'est le comportement conforme —
   les métadonnées annoncent les cinq capacités possibles. Mais si un client réclamait un
   scope hors liste (`openid`, un scope maison), il serait bloqué. À surveiller au premier
   branchement réel de Claude.

3. **La concurrence n'est pas testée automatiquement.** Le verrou `lockForUpdate()` dans la
   transaction est la bonne construction, et le rejeu séquentiel est testé, mais écrire un
   test de deux échanges vraiment simultanés demanderait deux connexions et serait erratique.
   Je l'ai laissé de côté sciemment.

4. **Le jeton porte toujours `TokenAbility::agent()` en entier**, même si le client demande
   moins. C'est ce que le brief impose et ce que la page promet au client, mais cela veut
   dire qu'un assistant ne peut pas se limiter volontairement. Si un jour il le faut, c'est
   `scopes` sur le code d'autorisation — déjà stocké — qu'il faudra lire.

5. **Pas de `refresh_token`.** Au bout de 90 jours, le client refait le parcours. C'est
   cohérent avec les connexions créées à la main, mais cela signifie une reconnexion
   trimestrielle visible pour le client.

6. **La page d'autorisation a besoin d'un vrai `SESSION_DRIVER`** (pas `array`) pour que le
   jeton CSRF survive entre le GET et le POST. La production est en `file`
   (`.env.example`) : rien à faire, mais c'est un piège si quelqu'un déroule le parcours
   avec un `.env` de test.

7. **Rien n'affiche cette page en français autre que le français.** Pas de traduction, pas
   de `lang/` : l'application est mono-langue, je n'ai pas introduit d'infrastructure i18n
   pour deux écrans.
