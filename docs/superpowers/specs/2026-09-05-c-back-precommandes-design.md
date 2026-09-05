# Sous-projet C-back — jetons agents, pré-commandes et lien de paiement

**Date :** 2026-09-05
**Statut :** design validé, prêt pour plan d'implémentation
**Périmètre :** backend Laravel uniquement
**Dépend de :** sous-projet A (fusionné, commit `73bfe32`)

---

## 1. Contexte et découpage

Objectif produit : un client parle à Claude ou ChatGPT, cherche un plat, se le fait
chiffrer tout compris, et **pré-commande**. Il reçoit un lien de paiement valide
12 heures. Quand il paie, la pré-commande devient une `Commande` ordinaire.

Règle fondatrice, posée dans `CLAUDE.md` et confirmée : **l'agent n'écrit jamais
en base**. Il consomme l'API REST avec un jeton scopé, comme n'importe quel client.

Le chantier C se décompose en trois :

| | Contenu | Où |
|---|---|---|
| **C-back** | jetons agents scopés, pré-commandes, lien signé, conversion au webhook | Laravel |
| **C-front** | écran des pré-commandes et paiement, écran « connecter un assistant » | Expo **et** Next.js |
| **C-mcp** | le serveur MCP | Python, Docker, VPS |

**Ce document couvre C-back seul.** Il est le prérequis des deux autres, et il a
de la valeur propre : l'endpoint de pré-commande sans prix sert aussi au web et au
mobile.

---

## 2. État des lieux (constaté dans le code et les données)

### 2.1 Ce que A a livré

`POST /api/quote`, `GET /api/products/search`, `POST /api/budget-suggestions`,
tous sous `auth:sanctum`, tous en lecture pure, tous alimentés par
`App\Services\QuotationService`.

### 2.2 Sanctum

Version `^4.0`. `personal_access_tokens` possède déjà **`abilities`** et
**`expires_at`**. `config/sanctum.php` a `'expiration' => null`.

`createToken()` est appelé sans abilities à deux endroits
(`AuthController`, `GoogleAuthController`), et **aucun `tokenCan()` n'existe dans
le projet**. Les **82 jetons en production** portent donc `abilities = ["*"]`.

Conséquence favorable : ajouter des contrôles d'ability sur les routes ne casse
aucune session vivante, puisque `*` autorise tout par construction.

Les colonnes `users.api_token` et `users.creation_token` (janvier 2025) sont
mortes : présentes dans `$fillable`, lues nulle part.

### 2.3 Limitation de débit

Le groupe `api` applique `throttle:api`, défini dans `RouteServiceProvider` :

```php
Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
```

Un seul limiteur, 60/min, **compté par utilisateur**. Un agent partagerait donc le
quota de l'application mobile du même client.

### 2.4 Le domaine porte déjà le destinataire

`commandes` possède `recipient_name`, `recipient_phone`, `lat`, `long`, en plus de
`adresse_delivery`, `street`, `number_street`, `reference_adresse`, `town_id`.
Le cas « c'est pour un ami » est donc déjà modélisé.

### 2.5 Les données réelles

- **50 produits actifs, tous en USD**, de 0,10 à 49. Aucun produit en CDF.
- 51 produits avec image ; `public/images` sert 102 fichiers publiquement.
- **`user_adresses` est vide** (0 ligne) : il n'existe pas de carnet d'adresses.
- 35 commandes portent une adresse, pour **25 adresses distinctes**.
- 19 utilisateurs ont un téléphone renseigné.
- 4 restaurants actifs, **aucun avec `location`**.

### 2.6 Le webhook de paiement

`PayementController::webhook` met la commande au **statut 2** et renseigne
`paied_at`. C'est l'état qu'une pré-commande convertie doit atteindre.

### 2.7 Le scheduler ne tourne pas

Aucun `schedule:run` dans `script-run.sh` ni dans une crontab visible. Un job
planifié ne se déclencherait jamais.

---

## 3. Décisions actées

| Sujet | Décision | Conséquence |
|---|---|---|
| Ce que l'agent crée | Une **pré-commande** dans sa propre table, pas une `Commande` | Rien de ce que l'agent crée ne pollue le back-office ni les tableaux de bord restaurant |
| Validité | **12 heures** | Borne l'exposition au prix figé à une demi-journée |
| Prix | **Figé à la création** | Un devis engage ; le client paie ce que Claude lui a annoncé |
| Expiration | **Paresseuse**, vérifiée à la lecture et à l'usage | Imposé par l'absence de scheduler ; un cron ne serait jamais qu'un bonus |
| Suppression | **Aucune, jamais, pour personne** | Une pré-commande expirée devient inerte et reste lisible |
| Modification | **Aucune** — pas d'endpoint de mise à jour | Changer d'adresse = nouvelle pré-commande. C'est ce qui rend le prix figé honnête |
| Connexion de l'assistant | Jeton généré dans l'app, copié-collé | Révocable, expirable, affiché une seule fois |
| Durée du jeton | **90 jours** | La révocation est le vrai contrôle, pas la durée |
| Écran de connexion | Mobile **et** web | Même endpoint des deux côtés |
| Source des adresses proposées | Les commandes passées du client | `user_adresses` est vide ; l'historique fait carnet |
| Chemins de paiement | **Deux** : lien signé, et bouton dans l'app | Le client peut reprendre son téléphone sans retrouver le message |

---

## 4. Le jeton agent

### 4.1 Abilities

```
catalogue:lire        recherche de plats et de restaurants
devis:calculer        /api/quote, /api/budget-suggestions
precommande:creer     création d'une pré-commande
precommande:lire      relecture de ses propres pré-commandes
commande:lire         suivi de l'état de ses commandes
```

**Jamais accordé à un agent :** annuler une commande, en modifier une en cours,
changer une adresse de livraison, déclencher un paiement, émettre un jeton.

Un jeton applicatif (mobile, web) continue de recevoir `["*"]` : le comportement
actuel ne change pas.

### 4.2 Entropie

Sanctum génère `Str::random(40)`, soit environ 238 bits. Hors de portée d'une
attaque par force brute et intranscriptible à la main. **On ne l'allonge pas** :
ce serait cosmétique. Ce qui protège, c'est que le jeton soit étroit, expirable et
révocable.

### 4.3 Émission et révocation

```
POST   /api/user/assistants          crée un jeton agent (nom, durée)
GET    /api/user/assistants          liste les connexions : nom, last_used_at, expires_at
DELETE /api/user/assistants/{uid}    révoque
```

Sous `auth:sanctum`, et **refusés à un jeton agent** : un agent ne s'auto-délivre
pas de pouvoirs. Le jeton en clair n'est renvoyé qu'à la création, jamais ensuite.

`config/sanctum.php` passe à une expiration par défaut. Le middleware `abilities`
de Sanctum est enregistré dans `Kernel.php` et appliqué aux routes livrées en A.

---

## 5. La pré-commande

### 5.1 Schéma

**`precommandes`** — `user_id`, `restaurant_id`, `town_id`, l'adresse recopiée
(`adresse_delivery`, `street`, `number_street`, `reference_adresse`), les coordonnées
`lat` et `long` **nullables** — un agent conversationnel n'en a pas, et rien dans ce
sous-projet ne géocode ; elles existent pour que la conversion vers `Commande` reste
symétrique et pour un remplissage ultérieur,
le destinataire (`recipient_name`, `recipient_phone`), le chiffrage figé
(`sous_total`, `frais_livraison`, `service_price`, `total`, `currency_id`), la
tranche retenue (`delivrery_price_id`, pour l'audit), `expires_at`, `status`,
`reference_paiement`, `commande_id` nullable, `paied_at`, horodatages.

**`precommande_products`** — `precommande_id`, `product_id`, `quantity`, et le
`price` **au moment du devis**. C'est ce qui rend le prix figé réel.

Statuts : `en_attente`, `payee`, `expiree`.

Pas de statut « annulée » : rien ne peut annuler une pré-commande, puisqu'aucun
endpoint ne la modifie. Une pré-commande dont on ne veut plus expire d'elle-même en
12 heures. Ajouter un statut que rien ne produit inviterait quelqu'un à écrire le
chemin qui le produit.

`expiree` n'est pas davantage écrit par un processus : c'est une lecture dérivée de
`expires_at`, constatée à la lecture. Aucun balayage n'en dépend.

### 5.2 Immuabilité

Aucun endpoint de mise à jour ni de suppression, pour personne. Une pré-commande
expirée n'est pas effacée : son statut devient inerte à la lecture. Elle reste
visible **30 jours** côté API, pour qu'un agent puisse dire « ta commande d'hier
a expiré, je te la refais à l'identique ? ». Au-delà, elle sort des listes
mais **n'est jamais supprimée** : elle reste accessible par son identifiant.

### 5.3 Création

```
POST /api/precommandes            ability precommande:creer
```

Entrée : `products[{uid, quantity}]`, l'adresse, le destinataire.
**Aucun prix.** Le serveur appelle `QuotationService` et fige le résultat — il est
structurellement impossible à un agent de dicter un montant.

Refus : panier vide, multi-restaurant, devises mélangées (ce que le moteur refuse
déjà), plus **town sans tarif de livraison actif**. Là où l'observation de A tolère
le zéro pour rester fidèle au web, une pré-commande créée par une machine ne doit
pas promettre une livraison gratuite par accident.

Refus également si le destinataire ou son téléphone manquent. **Les règles vivent
dans l'API, pas dans le prompt de l'agent** : un agent mal écrit ne peut pas créer
une pré-commande bancale.

Sortie : le récapitulatif chiffré, `expires_at`, et le lien de paiement.

### 5.4 Lecture

```
GET /api/precommandes             ability precommande:lire — les siennes
GET /api/precommandes/{uid}       une, avec son état et son lien s'il est encore valide
GET /api/user/adresses-recentes   les adresses distinctes de ses commandes passées
```

`adresses-recentes` alimente la question « on livre au même endroit ? ». Il lit les
commandes du client, pas `user_adresses`, qui est vide.

---

## 6. Les deux chemins de paiement

**Depuis la conversation** — un lien signé temporaire
(`URL::temporarySignedRoute`), à usage unique, valide 12h. Il ne demande pas
d'authentification : la signature est l'autorisation. À usage unique au sens strict :
la pré-commande porte l'état, et un lien rejoué sur une pré-commande déjà payée ou
expirée n'initie rien.

**Depuis l'application** — la pré-commande apparaît dans les commandes en cours,
avec un bouton payer, sous authentification normale.

Les deux mènent au même endpoint d'initiation FlexPay et au même webhook.

### 6.1 La conversion

Au webhook de paiement réussi : création d'une `Commande` au **statut 2 avec
`paied_at`**, recopie des lignes dans `commande_products`, `commande_id` renseigné
sur la pré-commande, statut `payee`.

**C'est le point le plus sensible du sous-projet.** `PayementController::webhook`
traite aujourd'hui des paiements réels. Le chemin existant doit rester
rigoureusement identique ; le nouveau ne branche que lorsque la référence
correspond à une pré-commande. Même prudence qu'à la tâche 6 de A : un test de
non-régression prouve que le chemin `Commande` est inchangé.

---

## 7. Sécurité

### 7.1 Limitation de débit

Le limiteur `api` actuel compte **par utilisateur**. Un agent partagerait donc le
quota de l'application mobile du même client, qu'il pourrait épuiser sans que le
client comprenne pourquoi son app se bloque.

Nouveaux limiteurs, **comptés par jeton** (`currentAccessToken()->id`) quand la
requête vient d'un jeton agent, par utilisateur sinon :

| Limiteur | Portée | Débit |
|---|---|---|
| `agent-lecture` | `products/search`, `quote`, `commande:lire`, `precommande:lire` | 60/min |
| `agent-devis` | `budget-suggestions` | 20/min — jusqu'à 50 chiffrages par appel |
| `agent-ecriture` | `POST /api/precommandes` | 10/min et 60/heure |
| `assistants` | émission et révocation de jetons | 5/min |
| `lien-paiement` | la route signée | 10/min **par IP** — la signature n'identifie pas l'appelant |

Le limiteur `api` existant reste inchangé pour tout le reste : aucune régression sur
les routes actuelles.

### 7.2 Le lien signé

Signature Laravel (`hasValidSignature`), expiration portée par l'URL **et**
revérifiée contre `expires_at` en base — la signature seule ne suffit pas, car
l'état de la pré-commande peut avoir changé. Limité par IP pour rendre inutile toute
tentative de forge par essais. Aucune donnée personnelle dans l'URL : seulement
l'identifiant chiffré par `Cipher`.

### 7.3 Surface d'écriture

Un jeton agent ne peut atteindre qu'**un seul endpoint d'écriture** :
`POST /api/precommandes`. Toutes les autres routes mutantes exigent une ability
qu'il n'a pas. Ce n'est pas une convention, c'est le middleware qui refuse.

### 7.4 Journalisation

Aucun téléphone, aucune adresse, aucun nom de destinataire dans les logs. Les
identifiants sortants restent chiffrés par `Cipher`. Le jeton en clair n'est
journalisé nulle part et n'est renvoyé qu'à sa création.

### 7.5 Ce que ce sous-projet ne corrige pas

- `POST /api/ia-model-llama3` — proxy Ollama public avec `Http::timeout(3600)`.
  Il hérite du `throttle:api` à 60/min, ce qui n'empêche pas 60 connexions d'une
  heure par minute. À neutraliser indépendamment.
- `GET /api/roles` — route publique qui crée des rôles Spatie.
- L'absence de ligne `StatusPayement` par défaut ferait échouer `valide()` **après**
  l'appel à FlexPay : paiement initié, aucune ligne `Payement`, client en erreur.

---

## 8. Tests

- **Abilities** : un jeton agent est refusé sur l'annulation, la modification, le
  paiement direct et l'émission de jetons ; accepté sur les cinq abilities prévues.
  Un jeton `["*"]` existant continue de tout pouvoir — non-régression sur les 82.
- **Pré-commande** : le prix ne peut pas venir de l'entrée ; refus sur panier vide,
  multi-restaurant, devises mélangées, town sans tarif, destinataire manquant ;
  le prix reste figé quand le tarif du produit change après création.
- **Expiration** : une pré-commande de plus de 12h est inerte, mais toujours
  lisible ; son lien n'initie rien.
- **Lien** : signature invalide refusée ; lien rejoué après paiement refusé ;
  lien d'une pré-commande expirée refusé.
- **Conversion** : la `Commande` produite est au statut 2 avec `paied_at` et porte
  les mêmes lignes ; **le chemin `Commande` du webhook est inchangé**.
- **Limitation** : chaque limiteur déclenche au seuil prévu ; un agent n'entame pas
  le quota de l'utilisateur.

Base MySQL dédiée et dump de schéma, comme établi en A.

---

## 9. Hors périmètre

- **C-front** : écrans de pré-commandes et de connexion d'assistant, dans les deux
  clients.
- **C-mcp** : le serveur MCP.
- **Le catalogue est en dollars** : « j'ai 500 FC » ne renverra rien tant qu'aucun
  produit n'est en CDF. Décision produit, pas technique.
- **`restaurants.location` est vide** : la recherche par distance reste inerte.
