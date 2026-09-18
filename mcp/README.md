# Connecteur MCP — Thalia Eats

Ce service permet à un assistant (Claude, ChatGPT…) de commander chez Thalia
Eats au nom d'un client : chercher un plat, calculer le prix livré, créer une
pré-commande au prix figé, et rendre un lien de paiement à usage unique.

## La règle qui commande tout le reste

**Un outil MCP = un endpoint de l'API REST Laravel.**

Le connecteur ne parle jamais à MySQL et ne contient aucune logique métier :
pas de calcul de prix, pas de règle de livraison, pas de validation de panier.
Il relaie, et il traduit les réponses en texte lisible. Le prix facturé est
calculé par `QuotationService` côté Laravel ; une seconde implémentation
divergerait, et ce projet a déjà payé ce défaut une fois.

Il ne stocke **rien** : ni jeton, ni donnée client, ni état entre deux
requêtes. Et **aucun outil ne supprime ni n'annule quoi que ce soit** — règle
absolue du propriétaire.

## Les six outils

| Outil | Endpoint | Capacité exigée |
|---|---|---|
| `chercher_un_plat` | `GET /api/products/search` | `catalogue:lire` |
| `calculer_le_prix_du_panier` | `POST /api/quote` | `devis:calculer` |
| `que_puis_je_manger_avec_ce_budget` | `POST /api/budget-suggestions` | `devis:calculer` |
| `creer_une_precommande` | `POST /api/precommandes` | `precommande:creer` |
| `lister_mes_precommandes` | `GET /api/precommandes` | `precommande:lire` |
| `voir_une_precommande` | `GET /api/precommandes/{uid}` | `precommande:lire` |

`creer_une_precommande` n'accepte **que** la commune et les produits.
L'assistant ne demande jamais l'adresse exacte, le nom du destinataire ni le
téléphone : le client les saisit lui-même sur la page de paiement. Une adresse
dictée à une machine est une adresse mal recopiée, et c'est le livreur qui en
paie le prix.

## Authentification

Le client MCP envoie son jeton Sanctum en `Authorization: Bearer <jeton>`. Le
connecteur le relaie **tel quel** à l'API Laravel et ne le garde pas. Les
capacités du jeton (`App\Enums\TokenAbility`) sont contrôlées par Laravel : le
connecteur ne réimplémente pas ce contrôle, il traduit le `403` en une phrase
qui nomme la capacité manquante.

Le jeton n'apparaît dans **aucun** journal : ni en clair, ni tronqué, ni haché.

Sans jeton, un appel à `/mcp` reçoit un `401` portant :

```
WWW-Authenticate: Bearer realm="Thalia Eats",
  resource_metadata="https://<hôte>/.well-known/oauth-protected-resource/mcp"
```

et ce document (RFC 9728) désigne `https://app.thaliaeats.com` comme serveur
d'autorisation. **Le serveur d'autorisation lui-même n'est pas ici** : la
connexion en un clic se construira côté Laravel. Ce connecteur prépare le
terrain, il n'implémente pas OAuth.

### Comment un client obtient son jeton aujourd'hui

En attendant OAuth, le jeton se demande à l'API, authentifié par un jeton de
session normal (celui de l'application web ou mobile) :

```bash
curl -X POST https://app.thaliaeats.com/api/user/assistants \
     -H "Authorization: Bearer <jeton de session de l'utilisateur>" \
     -H "Accept: application/json" \
     -H "Content-Type: application/json" \
     -d '{"name": "Claude Desktop", "jours": 90}'
```

Réponse (`201`) :

```json
{
  "data": {
    "uid": "…",
    "name": "Claude Desktop",
    "token": "42|le-jeton-en-clair",
    "expires_at": "2026-12-17T…"
  },
  "title": "Connexion créée",
  "message": "Copiez ce jeton maintenant : il ne sera plus jamais affiché."
}
```

`data.token` est le jeton à donner au client MCP. Il porte les cinq capacités
d'agent et **rien d'autre** : il ne peut ni annuler une commande, ni déclencher
un paiement, ni émettre un autre jeton. `GET /api/user/assistants` liste les
connexions, `DELETE /api/user/assistants/{uid}` en révoque une — depuis l'API, pas
depuis ce connecteur.

## Lancer en local

```bash
cd "back end/mcp"
python3 -m venv .venv
.venv/bin/pip install -e ".[dev]"

THALIA_API_URL=http://127.0.0.1:8000 \
THALIA_MCP_URL_PUBLIQUE=http://127.0.0.1:8097 \
.venv/bin/python -m thalia_mcp
```

Vérifications rapides :

```bash
curl http://127.0.0.1:8097/healthz                                   # ok
curl http://127.0.0.1:8097/.well-known/oauth-protected-resource/mcp  # métadonnées
curl -i -X POST http://127.0.0.1:8097/mcp -d '{}'                    # 401 + défi
```

### Tests

```bash
.venv/bin/python -m pytest
```

L'API Laravel est simulée : aucun test n'appelle la production.

## Configuration

Tout par variables d'environnement, rien en dur, aucun secret.

| Variable | Défaut | Rôle |
|---|---|---|
| `THALIA_API_URL` | `https://app.thaliaeats.com` | L'API REST appelée. |
| `THALIA_MCP_URL_PUBLIQUE` | `http://127.0.0.1:8097` | L'URL publique du connecteur. Part dans les métadonnées et le défi 401 : une valeur fausse rend la découverte impossible. |
| `THALIA_MCP_DELAI_HTTP` | `15` | Délai d'expiration des appels HTTP, en secondes. |
| `THALIA_MCP_HOTE` | `0.0.0.0` | Interface d'écoute. |
| `THALIA_MCP_PORT` | `8097` | Port d'écoute. |
| `THALIA_MCP_HOTES_AUTORISES` | *(vide)* | Liste blanche d'hôtes (anti-DNS-rebinding), séparés par des virgules. Vide = désactivée, le bon réglage derrière un proxy. |

## Configuration Claude Desktop

Claude Desktop parle stdio ; le connecteur parle HTTP. Le pont `mcp-remote`
fait la jonction et porte l'en-tête `Authorization`. À coller dans
`claude_desktop_config.json` :

```json
{
  "mcpServers": {
    "thalia-eats": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "https://mcp.thaliaeats.com/mcp",
        "--header",
        "Authorization:Bearer ${THALIA_JETON}"
      ],
      "env": {
        "THALIA_JETON": "42|le-jeton-rendu-par-POST-/api/user/assistants"
      }
    }
  }
}
```

Le jeton vit dans ce fichier, sur la machine du client. Il n'est jamais envoyé
au connecteur autrement que par cet en-tête, et le connecteur ne le conserve
pas.

Un client qui gère nativement le transport HTTP (ChatGPT, l'API Claude, un
agent maison) n'a pas besoin du pont : il pointe directement sur
`https://mcp.thaliaeats.com/mcp` avec l'en-tête `Authorization`.

## Docker

```bash
docker build -t thalia-eats-mcp .
docker run --rm -p 127.0.0.1:8097:8097 \
  -e THALIA_API_URL=https://app.thaliaeats.com \
  -e THALIA_MCP_URL_PUBLIQUE=https://mcp.thaliaeats.com \
  thalia-eats-mcp
```

En production, le service `mcp` de `deploy/docker-compose.yml` s'en charge :
aucun port public, tout passe par Apache sur la boucle locale. Voir la section
« Connecteur MCP » de `deploy/DEPLOIEMENT.md`.

## Pourquoi ce dossier vit dans le dépôt du backend

Il n'y a pas de dépôt git à la racine du monorepo : un dossier `mcp/` posé à
la racine ne serait versionné nulle part. C'est un **service distinct** malgré
le dépôt partagé — il n'importe aucun code PHP et ne connaît de Thalia que son
API publique.
