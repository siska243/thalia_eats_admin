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


---

# Rapport de correction — suite à la relecture adverse

Cinq commits de correction sur la même branche. Les points 2 (IP réelle derrière le proxy),
7 (audience des jetons) et 9 (session sur les endpoints machine) ne sont **pas** traités
ici, conformément aux arbitrages reçus.

| Commit | Sujet |
|---|---|
| `3770052` | L'origine publiée ne dérive plus d'`APP_URL` |
| `26a9a81` | Durcir la page qui demande le mot de passe (usurpation, nom, horloge, encadrement) |
| `8131ce4` | Ne délivrer que les capacités réellement demandées |
| `5be2e7c` | Limiter aussi les tentatives par compte visé |
| `f025702` | Les tests correspondants |

## 1 — La page de consentement ne protégeait de rien contre l'usurpation ✔

La page affiche désormais, en évidence, **l'hôte du `redirect_uri`**, extrait côté serveur
de la redirection enregistrée — jamais d'une valeur d'affichage fournie par le client — et
une phrase disant que Thalia n'a pas vérifié cette application. Le test
`test_la_page_affiche_l_hote_vers_lequel_l_acces_partira` rejoue le scénario complet : un
client enregistré sous le nom « Claude » avec `https://claude-ai-connect.example/cb`.

Pas de liste blanche de noms « vérifiés », conformément à l'arbitrage.

`client_name` est **normalisé à l'écriture** : `\p{C}` et `\p{Z}` écrasés en espace
simple, `trim`, puis 60 caractères au plus. Un nom vide après normalisation est refusé. Le
nom normalisé est aussi celui du jeton dans la liste des assistants, donc une seule valeur
propre partout.

## 2 — IP réelle derrière le proxy — **non traité, volontairement**

Le diagnostic est exact mais le correctif vit ailleurs : branche `fix/ip-client-reelle`,
commit `dcc11f2` (« l'application voit enfin l'adresse reelle de chaque client »), qui
résout `real_ip` dans `nginx-forwarded.conf` avant `fastcgi_params` et fait retirer par le
vhost Apache tout `X-Forwarded-For` entrant. Le relecteur regardait `origin/prod`, qui ne
l'a pas encore. **Je n'ai touché ni à `TrustProxies`, ni à nginx, ni à Apache**, pour ne pas
créer de conflit avec cette branche.

Conséquence à noter : la seconde limite ajoutée au point 3 ci-dessous est celle qui protège
un compte donné **quelle que soit** l'issue de cette histoire d'IP.

## 3 — Aucune limite par compte sur la vérification des mots de passe ✔

`oauth-connexion` porte maintenant trois contraintes : 5/minute et 30/heure par IP, plus
**10/heure par compte visé**. L'adresse est normalisée (`mb_strtolower(trim(...))`, sinon
« Client@X » et « client@x » seraient deux compteurs) puis **hachée en SHA-256** : une clé de
limiteur finit en clair dans le cache, et la liste des adresses attaquées n'a rien à y faire.

Une requête sans adresse ne crée pas de compteur : lui en donner un, commun, offrirait de
quoi le saturer pour bloquer les autres.

## 4 — L'horloge trahissait ce que la page cachait ✔

`Hash::check()` s'exécute désormais dans les deux cas, contre `EMPREINTE_FACTICE` (un bcrypt
de coût 10, celui de `config('hashing.bcrypt.rounds')`) quand aucun compte n'est trouvé. Le
commentaire dit explicitement de ne pas supprimer ce calcul « inutile ».

**Mesuré**, 7 requêtes par cas, limiteurs remis à zéro entre chaque, médiane :

```
--- SANS le correctif (ancien code) ---
  adresse INCONNUE  : 0.041726 0.056482 0.051182 0.042651 0.040217 0.040737 0.042263
     -> médiane 0.042 s sur 7 mesures
  adresse EXISTANTE : 0.093890 0.102490 0.101810 0.115539 0.107494 0.099800 0.102657
     -> médiane 0.102 s sur 7 mesures

--- AVEC le correctif ---
  adresse INCONNUE  : 0.092390 0.092659 0.091217 0.090275 0.092373 0.092546 0.099222
     -> médiane 0.092 s sur 7 mesures
  adresse EXISTANTE : 0.103736 0.094986 0.092909 0.114950 0.091373 0.094019 0.094990
     -> médiane 0.095 s sur 7 mesures
```

60 ms d'écart avant, 3 ms après — dans le bruit.

## 5 — Les métadonnées dérivaient d'`APP_URL` ✔

Nouveau `config/oauth.php`, clé `oauth.origine`, `env('OAUTH_ORIGINE', 'https://app.thaliaeats.com')`,
sur le modèle exact de `FLEXPAY_CALLBACK_URL` — même raisonnement, même repli sur le domaine
de production. `.env.example` la documente avec la raison.

**Le document n'est pas servi si l'origine n'est pas en `https`** : `500` avec un message qui
dit quoi corriger. Une découverte silencieusement fausse est pire qu'une erreur.

## 6 — La protection CSRF était annoncée sans être tenue ✔

C'était le reproche le plus juste : `VerifyCsrfToken` se désactive quand
`$app['env'] === 'testing'`, donc tous mes tests postaient sans jeton et passaient.

`test_le_post_d_autorisation_exige_un_jeton_csrf` force `$this->app->instance('env', 'production')`
et attend un `419`. **Vérifié par mutation** : en ajoutant `'oauth/authorize'` aux exemptions,
le test échoue bien —

```
  ⨯ le post d autorisation exige un jeton csrf                           1.73s
  Expected response status code [419] but received 302.
  Tests:    1 failed (2 assertions)
```

`test_les_routes_machine_restent_hors_csrf` tient l'autre moitié du contrat.

## 8 — La portée demandée était stockée puis ignorée ✔

Le jeton porte l'intersection entre ce qui est demandé et `TokenAbility::agent()` ; rien de
demandé vaut les cinq. La page de consentement affiche **exactement** cette liste, via une
table `CAPACITES` indexée par capacité (les phrases restent mot pour mot celles du web et du
mobile ; `precommande:lire` et `commande:lire` partagent la même et sont dédoublonnées).
L'intitulé devient « Ce que **cette application** pourra faire ».

Le test va jusqu'au bout : avec `scope=catalogue:lire`, la page ne montre pas « Préparer une
pré-commande », le jeton porte `['catalogue:lire']`, ouvre `/api/products/search` et reçoit
`403` sur `/api/quote`.

## Mineurs ✔

- `$code->user` gardé : un compte disparu entre l'autorisation et l'échange rend
  `invalid_grant`, plus un 500 depuis l'intérieur de la transaction.
- `frame-ancestors 'none'` + `X-Frame-Options: DENY` sur les deux vues OAuth, testés.
- `'::1'` retiré de `HOTES_LOCAUX` (code mort : `parse_url` rend `[::1]`).
- Tests ajoutés : `code_challenge` absent, `redirect_uri` absent, `client_name` échappé.

## Point 7 — documenté, pas corrigé

`JetonController::ressourceConcorde()` porte maintenant un commentaire disant ce que ce
contrôle **n'est pas** : de la tenue de registre entre autorisation et échange, et non une
restriction d'audience. Le jeton reste valable partout où ses capacités le portent. Donner
une audience aux jetons Sanctum toucherait toute l'application.

## Tests rejoués

Commande et sortie réelles :

```
----- php artisan test --filter=ServeurOauthTest -----

   PASS  Tests\Feature\ServeurOauthTest
  ✓ les metadonnees n annoncent que s256                                 1.75s  
  ✓ un client s enregistre et recoit un client id                        0.06s  
  ✓ un client qui demande aussi refresh token s enregistre sans l obten… 0.04s  
  ✓ un client qui ne demande pas authorization code est refuse           0.04s  
  ✓ un client qui attend un secret est refuse                            0.04s  
  ✓ une redirection http non locale est refusee                          0.05s  
  ✓ la boucle locale reste acceptee pour un logiciel installe            0.04s  
  ✓ une redirection avec fragment est refusee                            0.04s  
  ✓ un client id inconnu affiche une erreur et ne redirige pas           0.06s  
  ✓ une redirection non enregistree affiche une erreur et ne redirige p… 0.04s  
  ✓ une redirection seulement prefixee est refusee                       0.06s  
  ✓ la methode plain est refusee                                         0.05s  
  ✓ un scope inconnu est refuse                                          0.04s  
  ✓ la page annonce les deux listes de promesses                         0.04s  
  ✓ de mauvais identifiants ne disent pas lequel des champs est faux     0.17s  
  ✓ les metadonnees ne sont pas servies si l origine n est pas en https  0.04s  
  ✓ les metadonnees ne derivent pas d app url                            0.04s  
  ✓ le nom du client est normalise a l enregistrement                    0.06s  
  ✓ un nom vide une fois normalise est refuse                            0.07s  
  ✓ le nom du client est echappe sur la page                             0.05s  
  ✓ la page affiche l hote vers lequel l acces partira                   0.05s  
  ✓ les pages oauth refusent d etre encadrees                            0.06s  
  ✓ un code challenge absent est refuse                                  0.05s  
  ✓ un redirect uri absent affiche une erreur et ne redirige pas         0.05s  
  ✓ le post d autorisation exige un jeton csrf                           0.05s  
  ✓ les routes machine restent hors csrf                                 0.07s  
  ✓ une portee restreinte est annoncee et delivree telle quelle          0.15s  
  ✓ un refus redirige avec access denied                                 0.05s  
  ✓ le code n est jamais stocke en clair                                 0.11s  
  ✓ le parcours complet delivre un jeton agent                           0.13s  
  ✓ la reponse du point de jeton porte cache control no store            0.13s  
  ✓ un code verifier faux est refuse                                     0.11s  
  ✓ un code deja consomme est refuse                                     0.10s  
  ✓ un code expire est refuse                                            0.10s  
  ✓ un code presente avec un autre client id est refuse                  0.11s  
  ✓ un code presente avec un autre redirect uri est refuse               0.10s  
  ✓ un code presente pour une autre ressource est refuse                 0.10s  
  ✓ un client inconnu au point de jeton est refuse                       0.04s  
  ✓ un autre grant type est refuse                                       0.04s  
  ✓ le jeton delivre apparait dans la liste des assistants et se revoqu… 0.12s  
  ✓ le jeton delivre ne peut pas en emettre un autre                     0.11s  

  Tests:    41 passed (193 assertions)
  Duration: 4.72s


----- php artisan test (suite complète) -----

  Tests:    1 failed, 327 passed (872 assertions)
  Duration: 39.96s


```

41 tests OAuth, 193 assertions. Suite complète : 327 passent, 1 échoue —
`ExampleTest > the application returns a successful response`, toujours le même échec
antérieur (`GET /`, route commentée).

## Parcours curl complet, après correction

Avec la tentative d'usurpation demandée, et le refus de servir des métadonnées hors https.
Sortie brute :

```
### 1. Métadonnées — l'émetteur vient de config('oauth.origine'), pas d'APP_URL
    (APP_URL locale = http://127.0.0.1:8811)
{
    "issuer": "https://app.thaliaeats.com",
    "authorization_endpoint": "https://app.thaliaeats.com/oauth/authorize",
    "token_endpoint": "https://app.thaliaeats.com/oauth/token",
    "registration_endpoint": "https://app.thaliaeats.com/oauth/register",
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

### 1b. Une origine qui n'est pas en https : le document n'est pas servi
{"error":"server_error","error_description":"L'origine publique du serveur d'autorisation n'est pas en https : corrigez OAUTH_ORIGINE avant de publier ce document."}
-> HTTP 500

### 2. TENTATIVE D'USURPATION : un client se nomme « Claude » avec une redirection étrangère
client_id: thalia-fWOA6EUGXGM9wWnJ4vpfftEmSfy0NXK5iC9PIQNY
client_name: Claude
redirect_uris: ['https://claude-ai-connect.example/cb']

    Le lien envoyé à la victime est AUTHENTIQUE (vrai domaine, vrai TLS). Voici ce qu'elle lit :
Claude demande l'accès à votre compte Thalia
Thalia n'a pas vérifié cette application. Le nom affiché ci-dessus est celui qu'elle
claude-ai-connect.example
Si vous ne reconnaissez pas cette adresse, fermez cette page sans saisir votre mot

### 3. Le vrai parcours ###
{
    "client_id": "thalia-rT8gGo54pWPbCzz7pmqRRzUhRCgS9vne8i9rXxfN",
    "client_id_issued_at": 1789735759,
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

### 3a. client_id inconnu : page d'erreur, AUCUNE redirection, et pas d'encadrement
HTTP/1.1 400 Bad Request
Content-Security-Policy: frame-ancestors 'none'
X-Frame-Options: DENY
(aucun Location = aucune redirection)

### 3b. Page d'autorisation du vrai client
Claude demande l'accès à votre compte Thalia
127.0.0.1:33418
Chercher des plats et des restaurants
Calculer le prix d&#039;une commande, livraison comprise
Préparer une pré-commande
Suivre l&#039;état de vos commandes
Déclencher un paiement
Annuler ou modifier une commande en cours
Changer une adresse de livraison
Créer un autre accès

### 4. Consentement
HTTP/1.1 302 Found
Location: http://127.0.0.1:33418/callback?code=4bb3fa4467154bf099158be519bc5dc07aeca60135f4905967d788811a668916&state=etat-123

### 5. Échange du code
HTTP/1.1 200 OK
Cache-Control: no-store, private
{
  "access_token": "24|HdyCZJ4dT…(tronqué)",
  "token_type": "Bearer",
  "expires_in": 7776000,
  "scope": "catalogue:lire devis:calculer precommande:creer precommande:lire commande:lire"
}

### 6. Rejeu du même code
{
    "error": "invalid_grant",
    "error_description": "Ce code d'autorisation n'est pas utilisable."
}

### 7. Appel API authentifié avec le jeton délivré
HTTP 200
{"data":[],"meta":{"current_page":1,"last_page":1,"per_page":20,"total":0}}

### 8. Le jeton ne peut pas en émettre un autre
HTTP 403
{"title":"Oups","message":"Cette action n'est pas accessible depuis un assistant.","error":"ability_absente"}

### 9. Énumération au chronomètre : adresse inconnue vs adresse existante (mauvais mot de passe)
  adresse inconnue   : 0.088357 s
  adresse existante  : 0.060786 s
```

Le point à lire en premier est la section 2 : le client s'appelle « Claude », le lien est
authentique, et la page dit quand même `claude-ai-connect.example`.

À noter dans la section 1 : l'émetteur annoncé est `https://app.thaliaeats.com` alors que le
serveur local tourne sur `http://127.0.0.1:8811` — c'est exactement le comportement voulu,
l'origine ne suit plus `APP_URL`. Les appels du parcours visent donc directement le serveur
local, pas les adresses publiées.

## Fichiers touchés par les corrections

**Ajouté** : `config/oauth.php`

**Modifiés** : `.env.example`, `app/Http/Controllers/Oauth/MetadonneesController.php`,
`app/Http/Controllers/Oauth/EnregistrementController.php`,
`app/Http/Controllers/Oauth/AutorisationController.php`,
`app/Http/Controllers/Oauth/JetonController.php`, `app/Rules/RedirectionOauth.php`,
`app/Providers/RouteServiceProvider.php`, `resources/views/oauth/autorisation.blade.php`,
`tests/Feature/ServeurOauthTest.php`

Toujours rien touché dans `mcp/`, ni dans `TrustProxies`, nginx ou Apache.

## Réserves après correction

1. **La destination affichée protège un client qui la lit.** C'est une défense honnête, pas
   une garantie : quelqu'un qui enregistre `https://claude.ai.assistant-connect.example/cb`
   mise sur un coup d'œil rapide. La liste de noms vérifiés, écartée de ce lot, reste la
   seule chose qui fermerait vraiment la porte — c'est une décision produit.

2. **La normalisation du nom ne bloque pas les homoglyphes.** « Clаude » avec un а cyrillique
   passe. Le filtrage retire les caractères de contrôle et la longueur, pas les alphabets
   mélangés. La destination affichée reste le vrai garde-fou.

3. **Le coût bcrypt de `EMPREINTE_FACTICE` est figé à 10** pour coller à
   `config('hashing.bcrypt.rounds')`. Si quelqu'un relève `BCRYPT_ROUNDS` sans toucher à
   cette constante, l'écart de temps revient — atténué, mais il revient. Le commentaire le
   dit ; rien ne le vérifie automatiquement.

4. **La limite par compte est de 10/heure.** C'est serré face à une attaque, et c'est aussi
   ce qu'un client maladroit peut atteindre en se trompant dix fois. Il se retrouvera bloqué
   une heure sur cette page sans que l'application mobile, elle, soit gênée.

5. **Le refus de servir les métadonnées hors https est un 500 muet pour le client final.**
   Si `OAUTH_ORIGINE` est mal réglé en production, la connexion des assistants s'arrête net
   et rien ne remonte d'alerte ailleurs que dans les logs. C'est voulu — mieux vaut ça qu'une
   découverte fausse — mais ça mérite une surveillance.

6. **Les réserves du premier rapport restent valables** : `resource` toléré absent à
   l'échange, scope inconnu refusé, concurrence non testée automatiquement, pas de
   `refresh_token`.
