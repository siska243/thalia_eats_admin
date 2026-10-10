# Tâche — Connexion en un clic pour les assistants (OAuth 2.1)

## Ce qu'on construit, et pourquoi

Aujourd'hui, un client qui veut connecter Claude à Thalia doit générer un jeton en ligne de
commande et le coller dans un fichier de configuration. **Aucun client ne fera ça.**

Ce qu'il doit voir : un bouton « connecter », une page Thalia qui lui demande son mot de
passe, une phrase qui dit ce que l'assistant pourra faire, et c'est fini. Le propriétaire a
tranché : *« fais ce qui est mieux pour l'utilisateur »*.

Le connecteur MCP (`mcp/`) est déjà prêt : il sert le document de métadonnées RFC 9728 et
répond `401` avec un défi qui désigne `https://app.thaliaeats.com` comme serveur
d'autorisation. **Il ne manque que ce serveur.**

## L'enjeu, dit franchement

Vous construisez de l'authentification sur une application de paiement en production. Un
défaut ici ne donne pas un bug : il donne l'accès au compte de quelqu'un d'autre. Prenez le
temps, et si un point du protocole vous paraît ambigu, **arrêtez-vous et demandez** plutôt
que de choisir au jugé.

## Contraintes qui vous lient

- **Application en production**, tolérance zéro régression. Vous n'avez à modifier **aucun**
  endpoint existant.
- Fautes historiques intouchables : `refernce`, `Payement`, `DelivreryDriver`, `paiement`.
- Plancher PHP `^8.1` dans `composer.json` alors que le runtime est 8.3 : **toute syntaxe
  8.2+ est un défaut que le runtime local ne signalera pas** (classes `readonly`,
  `#[\Override]`, types `null`/`false`/`true` autonomes, constantes de classe typées,
  `json_validate`).
- `Model::unguard()` est global : **aucun modèle ne protège contre l'affectation de masse.**
  Les règles de validation sont la seule liste blanche. Jamais de `create($request->all())`.
- Français : commentaires, messages de commit, et tout ce que le client lit.
- **N'installez pas Laravel Passport.** L'application utilise Sanctum ; ajouter Passport
  amène un second système d'authentification, ses tables, ses commandes et ses clés pour
  un besoin que trois routes couvrent. Le jeton délivré doit être un **jeton Sanctum
  ordinaire**, pour que la révocation existante continue de marcher.

## Ce qui existe et qu'il faut réutiliser

- `app/Enums/TokenAbility.php` — les cinq capacités d'un agent, et `TokenAbility::agent()`.
  **Ne touchez pas à cet enum** : l'application mobile et la page web l'affichent au client
  comme une promesse.
- `app/Http/Controllers/Api/AssistantTokenController.php` — émission, liste, révocation.
  Regardez comment il crée le jeton (`createToken($nom, TokenAbility::agent(), $expiration)`).
- `app/Http/Controllers/Api/AuthController::login()` — la vérification des identifiants, et
  **surtout** son message unique « Email ou mot de passe incorrect » : un message distinct
  par champ permettrait d'énumérer les comptes. Reprenez ce comportement.
- `resources/views/precommande/*.blade.php` — le style des pages publiques. Votre page de
  connexion doit leur ressembler.
- `app/Providers/RouteServiceProvider.php` — les limiteurs. Ajoutez les vôtres là.

## Le protocole, précisément

Un client MCP (Claude, ChatGPT) doit pouvoir se connecter **sans que personne ne saisisse
d'identifiant ni de secret**. C'est l'enregistrement dynamique qui le permet.

### 1. `GET /.well-known/oauth-authorization-server` (RFC 8414)

Métadonnées publiques. Au minimum : `issuer`, `authorization_endpoint`, `token_endpoint`,
`registration_endpoint`, `scopes_supported` (les cinq de `TokenAbility`),
`response_types_supported` (`["code"]`), `grant_types_supported` (`["authorization_code"]`),
`code_challenge_methods_supported` (**`["S256"]` uniquement**), et
`token_endpoint_auth_methods_supported`.

### 2. `POST /oauth/register` (RFC 7591)

Le client s'enregistre tout seul et reçoit un `client_id`. Acceptez `client_name`,
`redirect_uris`, `grant_types`, `response_types`, `token_endpoint_auth_method`.

Clients **publics** (`token_endpoint_auth_method: "none"`) : pas de secret, PKCE obligatoire.
C'est le cas de Claude. Si vous délivrez un secret, il ne sert à rien et donne une fausse
impression de sécurité.

Validez les `redirect_uris` : `https://` exigé, **sauf** `http://127.0.0.1` et
`http://localhost` avec port libre, que la spec autorise pour les clients locaux. Refusez
tout le reste. Une liste de redirections trop permissive, c'est un vol de jeton.

### 3. `GET /oauth/authorize`

Paramètres : `client_id`, `redirect_uri`, `response_type=code`, `code_challenge`,
`code_challenge_method=S256`, `state`, `scope`, et `resource` (RFC 8707 — le connecteur
l'envoie ; conservez-le et vérifiez-le à l'échange).

**Vérifiez `client_id` et `redirect_uri` AVANT d'afficher quoi que ce soit**, et en cas
d'échec affichez une erreur — **ne redirigez pas**. Rediriger vers une URI non validée, c'est
exactement la faille. Pour les autres erreurs, redirigez avec `error=` et le `state`.

Affichez une page qui dit, en français et sans jargon : quel client demande l'accès, à quel
compte, **ce que l'assistant pourra faire** et **ce qu'il ne pourra jamais faire**. Reprenez
mot pour mot les deux listes de `components/account/AssistantsConnectes.jsx` côté web et de
l'écran mobile — trois promesses différentes selon l'écran seraient pires que pas de promesse.

Le client n'a pas de session web (l'application est une API) : la page porte donc le
formulaire de connexion **et** le consentement. Un seul écran, deux champs, deux boutons.

### 4. `POST /oauth/authorize`

Vérifie les identifiants, puis génère un **code d'autorisation** : aléatoire, au moins 32
octets, **stocké haché** (jamais en clair), **à usage unique**, valable **60 secondes**, lié
au `client_id`, au `redirect_uri`, à l'utilisateur, au `code_challenge` et au `resource`.

Redirige vers `redirect_uri` avec `code` et `state`.

Protégez ce POST : CSRF (il est dans le groupe `web`), et un limiteur par IP contre la force
brute sur les mots de passe.

### 5. `POST /oauth/token`

`grant_type=authorization_code`, `code`, `redirect_uri`, `client_id`, `code_verifier`.

Vérifiez, dans cet ordre, et **refusez sans détailler lequel a échoué** : le code existe,
n'est pas expiré, n'a jamais servi, appartient à ce `client_id`, le `redirect_uri` est
identique à celui de l'autorisation, et
`base64url(sha256(code_verifier)) === code_challenge`.

**Marquez le code consommé dans la même transaction que la délivrance du jeton.** Deux
requêtes simultanées avec le même code ne doivent pas produire deux jetons.

Puis délivrez un jeton Sanctum via `createToken()` avec `TokenAbility::agent()` et une
expiration, nommé d'après le `client_name` pour que le client le reconnaisse dans sa liste.
Réponse JSON standard : `access_token`, `token_type: "Bearer"`, `expires_in`, `scope`.

**Le corps d'une réponse de jeton ne doit jamais être mis en cache** :
`Cache-Control: no-store`.

## Ce qu'il faut stocker

Deux tables, à vous de les nommer en cohérence avec le projet. L'une pour les clients
enregistrés, l'autre pour les codes d'autorisation. Les codes sont hachés, portent leur
expiration et leur consommation.

**Rien ne se supprime jamais** — règle absolue du propriétaire. Un code consommé se marque,
il ne s'efface pas.

⚠️ **Ces migrations bloqueront le déploiement automatique**, qui refuse tout lot contenant
une migration. C'est voulu : un schéma qui change sur une base portant des commandes et des
paiements réels se livre avec quelqu'un devant l'écran. **Signalez-le dans votre rapport** :
la livraison se fera par `./deploy.sh --migrate`.

## Limiteurs

Ajoutez-les dans `RouteServiceProvider` à côté des existants, en réutilisant leur forme :
l'enregistrement dynamique (sinon n'importe qui crée des milliers de clients), la page
d'autorisation, et surtout le POST qui vérifie les mots de passe.

## Tests

Le filet de sécurité de ce projet est mince — écrivez-les sérieusement. Au minimum :

- les métadonnées annoncent `S256` et rien d'autre
- un client s'enregistre et reçoit un `client_id`
- une `redirect_uri` en `http://` non locale est refusée
- un `client_id` inconnu affiche une erreur et **ne redirige pas**
- une `redirect_uri` non enregistrée affiche une erreur et **ne redirige pas**
- de mauvais identifiants ne disent pas lequel des deux champs est faux
- le parcours complet délivre un jeton portant exactement `TokenAbility::agent()`
- un `code_verifier` faux est refusé
- `code_challenge_method=plain` est refusé
- un code déjà consommé est refusé
- un code expiré est refusé
- un code présenté avec un autre `client_id` est refusé
- un code présenté avec un autre `redirect_uri` est refusé
- le jeton délivré **apparaît dans `GET /api/user/assistants`** et se révoque par
  `DELETE` — c'est ce qui rend la connexion réversible depuis le téléphone
- le jeton délivré ne peut pas émettre un autre jeton (`403`)
- la réponse du `token_endpoint` porte `Cache-Control: no-store`

Base de test : `DB_DATABASE=thalia_eats_oauth_test`, à créer
(`mysql -h127.0.0.1 -uroot -ppassword -e "CREATE DATABASE ..."`). Le garde de
`tests/CreatesApplication.php` refuse tout nom qui ne finit pas par `_test`.

## Vérifiez avant de livrer

Les tests ne prouvent pas qu'un client réel se connecte. Lancez `php artisan serve`, puis
déroulez le parcours à la main avec `curl` : enregistrement, autorisation, échange, et un
appel authentifié avec le jeton obtenu. **Collez la sortie réelle dans votre rapport.**

## Attention

Vous travaillez dans un **arbre git séparé** (`git worktree`), branche
`feature/oauth-assistants`. Le checkout principal sert à d'autres sessions — n'y allez pas.
Ici vous êtes seul : `git add -A` est sûr dans cet arbre.

Ne modifiez pas `mcp/` : le connecteur est déjà prêt.

## Si vous êtes en difficulté

Il est toujours acceptable de dire « c'est trop dur pour moi ». Un travail bâclé vaut moins
que pas de travail, et vous ne serez pas pénalisé pour avoir remonté un blocage. Arrêtez-vous
si un point du protocole est ambigu, si vous hésitez sur une conception, ou si vous vous
surprenez à deviner.

## Livraison

Commits séparés par sujet, en français, expliquant **pourquoi**. Terminez par
`Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`.

Rapport dans `.superpowers/sdd/2026-09-18-oauth/rapport-oauth.md` : ce que vous avez
construit, la sortie réelle des tests avec la commande, le parcours `curl` complet, les
fichiers touchés, vos réserves.

Puis répondez en moins de quinze lignes : statut, commits, une ligne de tests, réserves,
chemin du rapport.
