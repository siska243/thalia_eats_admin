# Sous-projet A — Quotation serveur, recherche de plats et suggestions par budget

**Date :** 2026-09-05
**Statut :** design validé, prêt pour plan d'implémentation
**Périmètre :** backend Laravel uniquement (`back end/`, branche `release1.0`)

---

## 1. Contexte et découpage

L'objectif produit est un agent conversationnel Thalia auquel un client peut dire
« trouve-moi un plat autour de moi », « je veux manger ça », ou « j'ai 500 FC,
propose-moi ce que je peux commander » — le montant s'entendant **tout compris :
plat + livraison + service**. S'y ajoute un connecteur MCP permettant de commander
depuis Claude ou ChatGPT et de recevoir un lien de paiement à usage unique.

L'ensemble se décompose en trois sous-projets, chacun avec sa propre spec :

| # | Sous-projet | Nature | Dépend de |
|---|---|---|---|
| **A** | Quotation serveur, recherche de plats, suggestions par budget | Laravel, dans le monolithe | — |
| **B** | Agent conversationnel | Service Python séparé, Docker sur le VPS | A |
| **C** | Connecteur MCP + commande à distance + lien de paiement éphémère | Service séparé + endpoints Laravel | A |

**Le présent document couvre A seul.**

A n'est pas un projet d'IA. C'est du backend classique, et il porte toute la
difficulté réelle de la feature budget. Sans A, B inventerait des prix et C
encaisserait ce qu'on lui dicte. A a par ailleurs de la valeur propre, même si
l'orientation IA était abandonnée : il ferme une faille de paiement et donne au
web et au mobile une recherche de plats qui n'existe pas aujourd'hui.

---

## 2. État des lieux (constaté dans le code)

### 2.1 Le serveur n'est pas autorité sur le prix

`app/Http/Controllers/Api/CommandeController.php::valide()` :

```php
$total_price = $request->input('total_price');            // vient du client
$commande->price_delivery = $pricing['frais_livraison'];  // vient du client
$commande->price_service  = $pricing['service_price'];    // vient du client
$commande->global_price   = $total_price;                 // ligne 541
```

`paiement()` (ligne 721) facture ensuite `floatval($order->global_price)`.

Le calcul réel vit côté navigateur, dans `front end/next-app/helpers/calculePrice.js`
et `hooks/useCart.tsx:159-173`. Tout porteur d'un token Sanctum peut donc poster
`total_price: 1`. Aucune ability Sanctum n'existe (`createToken()` est appelé sans
scopes dans `AuthController` et `GoogleAuthController`, aucun `tokenCan()` dans le
projet), donc tout token a `*`.

### 2.2 La règle de tarification réelle

`helpers/calculePrice.js:52-75` :

```js
const filterPricing = town_pricings?.filter(item => item.town.slug === town?.slug)
if (filterPricing?.length > 0) {
    const findPricing = filterPricing?.find(
        item => current_price >= item.interval_pricing && current_price <= item.interval_max_price
    );
    return {
        frais_livraison: findPricing?.frais_livraison ?? 0,
        service_price:   findPricing?.service_price   ?? 0,
        currency:        findPricing?.currency        ?? null
    }
}
return null   // l'appelant retombe alors sur 0 / 0
```

Points essentiels :

- Le frais de livraison est une **tranche indexée sur le sous-total du panier**,
  pas sur la distance.
- La tranche est choisie par la **town de l'adresse de livraison du client**.
  `restaurants.town_id` n'intervient jamais dans le prix : un restaurant à 30 km
  coûte la même livraison qu'un voisin.
- Quand aucune tranche ne correspond — sous-total au-dessus de toutes les bornes
  hautes, ou town sans tarif actif — **le client paie 0 de livraison et 0 de
  service, silencieusement**.
- `delivrery_prices.interval_max_price` a été ajouté le 2025-05-25 avec
  `default(0)`. Toute ligne antérieure jamais rééditée a donc l'intervalle
  `[interval_pricing, 0]`, qui ne matche aucun sous-total positif.

Les deux derniers points constituent une fuite de revenu active en production.

### 2.3 Absence de recherche

`RestaurantController::index()` filtre sur `restaurants.name` et `description`
uniquement, sans pagination. Rien n'interroge `products` — ni par texte, ni par
prix, ni par catégorie, ni entre restaurants.

### 2.4 Géolocalisation disponible

- `user_adresses` : `lat`, `long` (nullable)
- `restaurants.location` : `json` nullable (ajouté 2024-11-30), casté en array
  par le modèle, exposé tel quel par `RestaurantResource`, **jamais lu ni filtré**
- `restaurants.town_id` : nullable
- `towns` : aucune coordonnée

### 2.5 Devises

`currencies` = `title`, `code`, `icon`, `slug`, `is_active`. **Aucune colonne de
taux de change**, aucune table de taux. `products.currency_id` et
`delivrery_prices.currency_id` sont indépendants.

### 2.6 Multi-restaurant

`commandes` n'a pas de `restaurant_id`. Un restaurant voit une commande via
`whereHas('commande_products' → 'product.restaurant_id')`. Le schéma autorise donc
un panier multi-restaurants, avec un seul `price_delivery`, chaque restaurant
voyant la commande entière — produits des concurrents inclus. Rien n'encadre ce cas.

---

## 3. Décisions actées

| Sujet | Décision | Conséquence |
|---|---|---|
| Granularité géo | Distance quand `restaurants.location` **et** l'adresse client sont renseignés ; repli sur la town sinon | La distance ne sert qu'à la pertinence, jamais au prix |
| Devises | **Mono-devise stricte** — pas de taux de change introduit | Des produits de devises différentes sont refusés ; une tranche d'une autre devise est signalée mais appliquée, comme le fait le client |
| Multi-restaurant | **Une commande = un restaurant** | Première application effective de la règle ; la quotation refuse un panier multi-restaurants |
| Comportement hors tranche | **Conservé tel quel** : `frais = 0`, `service = 0` | Cohérence entre canaux préservée ; la fuite est mesurée, pas corrigée, dans A |
| Prix facturé | `products.price`, **jamais** `promotionnalPrice` | Bug-compatible avec `valide()`. Le web affiche `promotionnalPrice` mais fait payer `price` — signalé, hors périmètre |
| Suggestions budget | **Plats seuls** (une ligne par suggestion) | Structure de sortie dimensionnée pour accueillir les combinaisons plus tard sans changer le contrat |
| Stratégie de migration | Moteur unique, exposé en lecture, branché en observation, bascule sur flag | Aucun changement de comportement à la livraison de A |

---

## 4. Architecture

### 4.1 `App\Services\QuotationService`

Classe unique, sans HTTP, sans `auth()`, testable isolément.

```php
QuotationService::quote(
    Restaurant $restaurant,
    array $lines,     // [['product' => Product, 'quantity' => int], ...]
    Town $town        // town de l'adresse de LIVRAISON
): Quotation
```

`Quotation` est un objet de valeur immuable :

```
sous_total       float
frais_livraison  float
service_price    float
total            float
currency         Currency
bracket          ?DelivreryPrice   // la tranche retenue, null si hors tranche
warnings         string[]
disponible       bool
raison           ?string
```

**Règles appliquées, dans l'ordre :**

1. **Un seul restaurant** — si les produits ne partagent pas le même
   `restaurant_id` : `disponible = false`, `raison = 'multi_restaurant'`.
2. **Mono-devise** — tous les produits doivent partager le même `currency_id`.
   Sinon `raison = 'devises_melangees'` : aucun total sensé n'existe.
   En revanche, une **tranche** dont la devise diffère de celle des produits
   ne provoque **pas** de refus : elle ajoute un warning
   `devise_tranche_differente` et ses frais sont appliqués tels quels.
   `calculePrice.js` filtre les tranches par town uniquement, jamais par
   devise ; refuser ici créerait un écart systématique dans le journal
   d'observation.
3. **Sous-total** — `Σ (products.price × quantity)`, arrondi à 2 décimales
   (aligné sur le `toFixed(2)` de `calcul_price`). `promotionnalPrice` ignoré.
4. **Tranche** — parmi les `delivrery_prices` de `town_id` **et**
   `is_active = true` (aligné sur le filtre de `DefaultDataController::index()`),
   la première telle que `interval_pricing ≤ sous_total ≤ interval_max_price`.
   « Première » signifie **dans l'ordre de clé primaire**, car c'est l'ordre que
   `DefaultDataController::index()` renvoie au client (aucun `orderBy`) et donc
   celui sur lequel le `.find()` de `calculePrice.js` s'appuie. Cet ordre doit
   être reproduit explicitement (`orderBy('id')`) : s'en remettre à l'ordre
   naturel de MySQL exposerait à une divergence silencieuse avec le client.
5. **Hors tranche** — `frais_livraison = 0`, `service_price = 0`,
   `bracket = null`, et un warning `hors_tranche` ou `aucun_tarif_actif`.
   Comportement identique au client, bug compris.
6. **Total** = `sous_total + frais_livraison + service_price`.

Le tableau `warnings` ne change aucun comportement. Il sert exclusivement à la
mesure : il rend visible, sans rien casser, combien de commandes passent hors
tranche et pour quel manque à gagner.

### 4.2 Endpoints

Tous sous `auth:sanctum`, tous enveloppés dans `App\Wrappers\ApiResponse`,
messages en français. Aucun ne modifie de données.

#### `POST /api/quote`

```
entrée : { products: [{uid, quantity}], town: slug, restaurant?: slug }
sortie : { sous_total, frais_livraison, service_price, total,
           currency, disponible, raison? }
```

Le restaurant est **déduit des produits**. Le paramètre `restaurant` est
facultatif : fourni, il sert de garde-fou — si les produits n'appartiennent pas
à ce restaurant, la quotation est refusée. C'est utile à C, où l'agent a annoncé
un restaurant à l'utilisateur et où l'on veut vérifier que le panier n'a pas
dérivé entre la suggestion et la commande.

Lecture pure. Brique consommée par B et C.

#### `GET /api/products/search`

Paramètres : `q` (FULLTEXT sur `title` + `description`), `category`,
`sub_category`, `price_min`, `price_max`, `currency`, `town`, `lat`, `lng`,
`radius`, `sort`, `per_page`.

**Note sur la clé étrangère des sous-catégories.** Le fichier de migration
`2023_10_31_115717` crée `sub_category_products.category_id`, alors que
`SubCategoryProduct::category_product()` et `SubCategoryProductResource.php:39`
référencent `category_product_id`. Vérification faite sur la base réelle : la
colonne est **`category_product_id`** — c'est le fichier de migration qui est
périmé, la base ayant dérivé de son historique. Le filtre `category` s'appuie
donc sans risque sur la relation existante.

Filtre systématiquement `products.is_active` et `restaurants.is_active`.
**Pagine** — contrairement à `list-restaurant` qui renvoie tout le parc d'un bloc.

#### `POST /api/budget-suggestions`

```
entrée : { budget, currency, town: slug, q?, category?, lat?, lng? }
sortie : { suggestions: [...], disponible, raison?, option_la_moins_chere? }
```

Chaque suggestion est **déjà chiffrée** par `QuotationService`, groupée par
restaurant, avec le détail `sous_total / frais_livraison / service_price / total`.

### 4.3 Algorithme du budget

Le frais de livraison étant une tranche indexée sur le sous-total, il y a
circularité : les frais dépendent du panier, le panier dépend des frais.

La résolution consiste à **inverser le calcul** — on n'énumère pas les paniers,
on énumère les tranches. Pour chaque tranche active de la town du client, dans la
devise du budget, de bornes `[i, m]` et de frais `f + s`, avec un budget `B` :

```
budget_plat_max = min(m, B − f − s)
tranche exploitable  ⟺  budget_plat_max ≥ i
```

S'y ajoute le cas hors tranche, imposé par la décision de conserver le
comportement actuel : si le sous-total dépasse toutes les bornes hautes, alors
`f = s = 0` et donc `budget_plat_max = B`. Autrement dit, au-delà d'un certain
budget la livraison devient gratuite. Le moteur doit le refléter — c'est la fuite
documentée en 2.2, et A la mesure sans la corriger.

On obtient ainsi une poignée de **plafonds candidats** sur le prix des plats. On
sélectionne les produits sous le plus élevé, puis on requalifie chaque candidat
par `QuotationService::quote()`. Le chiffre annoncé est par construction celui qui
sera facturé : aucun calcul de prix n'est dupliqué.

### 4.4 Le cas « il n'y a rien »

Quand rien ne rentre dans le budget, l'endpoint ne renvoie pas une liste vide. Il
renvoie de quoi expliquer :

```json
{
  "suggestions": [],
  "disponible": false,
  "raison": "budget_insuffisant",
  "option_la_moins_chere": {
    "restaurant": "…", "produit": "…",
    "total": 3200, "manque": 700
  }
}
```

L'agent peut alors répondre « rien à 500 FC livré ; le moins cher est à 3 200 FC,
il te manque 700 » plutôt que « je n'ai rien trouvé ».

Raisons distinctes et exploitables : `budget_insuffisant`,
`aucun_produit_dans_cette_devise`, `aucun_restaurant_dans_cette_zone`,
`multi_restaurant`, `devises_melangees`, `panier_vide`, `quantite_invalide`,
`restaurant_inattendu`.

Une town sans tarif de livraison actif n'est **pas** une raison de refus :
le comportement conservé donne alors une livraison à 0, donc un chiffrage
valide. Elle produit un warning, pas un blocage.

### 4.5 Géolocalisation

```
si adresse_utilisateur.lat/long ET restaurants.location renseignés
   → haversine en SQL, tri par distance, filtre optionnel sur radius
sinon
   → filtre sur restaurants.town_id = town du client
```

Appliqué **restaurant par restaurant**, pas globalement : un restaurant
géolocalisé est trié par distance, un restaurant sans coordonnées reste éligible
via sa town et arrive après. Aucun restaurant ne disparaît du catalogue parce que
ses données sont incomplètes.

Ce principe s'étend au filtre par town lui-même : `restaurants.town_id` étant
nullable et probablement peu renseigné, un restaurant **sans** town reste
éligible. On ne filtre jamais sur une donnée absente — sinon un parc mal
renseigné rendrait la recherche vide. De même, un rayon n'exclut que les
restaurants dont la position est connue.

`restaurants.location` étant un `json` nullable jamais lu jusqu'ici, le parsing
est défensif : une valeur absente ou malformée dégrade vers la town, elle ne lève
pas d'exception.

---

## 5. Branchement en observation et bascule

### 5.1 Observation

`valide()` gagne trois lignes, sans aucun changement de comportement :

```php
$quotation = QuotationService::quote($restaurant, $lines, $town);

if (abs($quotation->total - floatval($total_price)) > 0.01) {
    Log::channel('quotation')->warning('ecart_quotation', [
        'commande' => $commande->refernce,
        'client'   => [
            'total'   => $total_price,
            'frais'   => $pricing['frais_livraison'],
            'service' => $pricing['service_price'],
        ],
        'serveur'  => $quotation->toArray(),
        'warnings' => $quotation->warnings,
    ]);
}

$commande->global_price = $total_price;   // inchangé
```

Canal `quotation` dédié, donc lisible dans `opcodesio/log-viewer` depuis l'admin
sans accès serveur. La tolérance de 0,01 absorbe les flottants (`products.price`
est un `float`, `calcul_price` applique `toFixed(2)`).

**Trois** signaux distincts, à ne pas confondre :

- **`ecart_quotation`** — le moteur diverge du client. Bug du moteur, à corriger
  avant toute bascule.
- **`quotation_conforme_avec_warnings`** — les deux calculs concordent et valent
  tous deux 0. C'est la fuite `delivrery_prices` : problème de données,
  indépendant de la bascule.
- **`refus_quotation`** — le moteur refuse de chiffrer là où le client, lui, a
  produit un nombre. Ce n'est **pas** un écart de calcul : c'est un panier que le
  serveur juge invalide (multi-restaurant, devises mélangées) et que la production
  accepte pourtant aujourd'hui. Comparer les totaux dans ce cas crierait à l'écart
  sur chaque commande concernée, puisqu'un refus a un total de 0. Le volume de ces
  entrées dit combien de commandes réelles violent des règles que personne
  n'applique — **à lire avant la bascule**, car après elle ces commandes seront
  refusées.

### 5.2 Bascule

Sur flag de configuration, pas sur du code :

```php
$montant_facture = config('quotation.authoritative')
    ? $quotation->total
    : $total_price;
```

**`global_price` ne suffit pas.** `valide()` n'envoie pas `$commande->global_price`
à FlexPay : elle envoie `floatval($total_price)` (`CommandeController.php:578`) et
enregistre ce même montant dans `Payement.amount` et `amount_customer`. Basculer
le seul `global_price` laisserait donc le client dicter ce qui est réellement
encaissé sur ce chemin. Les trois emplacements utilisent la même variable
`$montant_facture` ; tant que le flag vaut `false`, elle est égale à
`$total_price` et rien ne change.

`paiement()` (l'autre chemin de paiement, pour une commande déjà créée) facture
déjà `$order->global_price` : la bascule l'atteint sans modification.

`QUOTATION_AUTHORITATIVE=false` à la livraison de A.

La bascule intervient quand `ecart_quotation` est silencieux sur une période
couvrant les cas réels — plusieurs towns, plusieurs tranches, des paniers hors
tranche. Elle est **réversible par variable d'environnement**, sans redéploiement
de code.

La bascule ne fait pas partie de la livraison de A : elle sera proposée
séparément, chiffres du journal à l'appui.

### 5.3 Position de B et C vis-à-vis de la bascule

B et C n'écrivent jamais via `valide()`. Ils passeront par les endpoints dédiés de
C, qui ne prennent aucun prix en entrée dès le premier jour. Les nouveaux chemins
sont donc sûrs par construction, sans attendre la bascule et sans imposer le coût
d'un double contrat sur l'ancien.

---

## 6. Impact sur l'existant

- **Migration** : index `FULLTEXT` sur `products (title, description)`. Additif,
  réversible, sans verrou long à ce volume.
- **`valide()`** : le bloc d'observation, et le remplacement de `$total_price` par
  la variable `$montant_facture` aux trois endroits qui facturent (`global_price`,
  la charge utile FlexPay, `Payement.amount` / `amount_customer`). Tant que le
  flag vaut `false`, `$montant_facture === $total_price` : comportement identique.
- **Aucun nouveau seeder** — rien à rendre idempotent pour `script-run.sh`.
- **`helpers/Route.ts`** côté web **et** côté mobile : ajout des trois clés
  `quote`, `products_search`, `budget_suggestions`. Les deux fichiers ont déjà
  divergé ; on ajoute la même chose des deux côtés sans aggraver.
- **Aucune route existante renommée**, aucun champ de réponse modifié, aucune
  forme `ApiResponse` touchée. Web, mobile et Filament ne voient aucune différence.

---

## 7. Tests

`tests/` ne contient aujourd'hui que les `ExampleTest`. Le filet est posé en même
temps que le code.

### 7.1 Unitaires — `QuotationService`

C'est là que vit le risque, et cela se teste sans base :

- sous-total exactement égal à `interval_pricing` → tranche retenue
- sous-total exactement égal à `interval_max_price` → tranche retenue
- sous-total au-dessus de toutes les tranches → `0 / 0` + warning `hors_tranche`
- town sans tarif actif → `0 / 0` + warning `aucun_tarif_actif`
- ligne avec `interval_max_price = 0` → jamais retenue
- tranche `is_active = false` → ignorée
- produits de restaurants différents → refus `multi_restaurant`
- devises mélangées → refus `devises_melangees`
- `promotionnalPrice` renseigné → ignoré, `price` utilisé

### 7.2 Feature — nouveaux endpoints

- `/api/quote`, `/api/products/search`, `/api/budget-suggestions` exigent
  l'authentification
- le cas « rien ne rentre » renvoie `option_la_moins_chere` et la bonne `raison`
- la géo dégrade vers la town quand `location` est nul ou malformé
- `products/search` pagine et exclut les produits et restaurants inactifs

### 7.3 Feature — non-régression sur `valide()`

Le test le plus important. Il prouve qu'avec `QUOTATION_AUTHORITATIVE=false`,
`global_price` vaut toujours exactement le `total_price` envoyé par le client,
**y compris quand le moteur calcule autre chose**. C'est ce test qui garantit que
les sections 4 et 5 sont réellement inertes en production.

### 7.4 Infrastructure de test

`phpunit.xml` a ses lignes SQLite commentées : les tests tapent la base du `.env`.
Décommenter n'est pas une option — l'index `FULLTEXT` est spécifique à MySQL et
ferait échouer les migrations sous SQLite.

Il faut donc une **base MySQL de test dédiée** (`thalia_eats_test`) et un
`.env.testing`. Sans cela, lancer la suite écrase la base de développement.

**L'historique des migrations n'est pas rejouable à zéro.** Vérifié : un
`migrate` sur une base vide échoue successivement sur

1. `2023_11_01_201908_currencies_to_product_column` — doublon de `currency_id`
   avec `2023_11_01_193157_currency_to_product_column` ;
2. `2023_11_08_102129_rename_table_roles` — `roles_user` n'existe pas ;
3. `2023_11_08_121208_create_permission_tables` — la table `roles` existe déjà.

`RefreshDatabase` est donc inutilisable en l'état. Ces trois migrations ne sont
**pas** corrigées : elles sont déjà enregistrées comme exécutées en production,
les modifier ne répare rien là-bas et risque d'en casser le rejeu. La réponse
retenue est le **dump de schéma** (`php artisan schema:dump`), le mécanisme
prévu par Laravel pour ce cas : `migrate` le charge quand la table `migrations`
est vide, puis n'applique que les migrations postérieures. Aucun effet sur la
production, dont la table `migrations` est pleine.

---

## 8. Hors périmètre de A

Signalé ici pour mémoire, à traiter séparément :

- **Nettoyage des données `delivrery_prices`** — les lignes à
  `interval_max_price = 0` et les towns sans tarif actif. A fournit la mesure ;
  la correction est un chantier données.
- **`promotionnalPrice` affiché mais non facturé** — incohérence produit
  préexistante entre le web et `valide()`.
- **Abilities Sanctum** — aucun token n'est scopé aujourd'hui. Prérequis de C,
  pas de A.
- **`POST /api/ia-model-llama3`** — proxy Ollama public, hors `auth:sanctum`,
  sans throttle, `Http::timeout(3600)`. À neutraliser, indépendamment de A.
- **`GET /api/roles`** — route publique qui crée des rôles Spatie.
- **La bascule `QUOTATION_AUTHORITATIVE=true`** — proposée séparément, après
  lecture du journal.
