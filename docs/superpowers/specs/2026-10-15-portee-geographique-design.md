# Portée géographique — distance de livraison, enseigne par ville, véhicule par ville

**Date :** 2026-10-15
**Ticket :** T81
**Statut :** design validé, prêt pour plan d'implémentation
**Branche :** `feat/T81-portee-geographique` (depuis `release1.0`)
**Périmètre :** backend Laravel (`back end/`) + mobile Expo (`front end/thalia-delivery/`) + web Next.js (`front end/next-app/`)

---

## 1. Contexte

Le propriétaire, le 15 octobre :

> « Le but est que l'application ne s'arrête pas seulement à Kinshasa. Le but c'est
> que ça évolue dans tout le Congo et après à l'étranger. »

Aujourd'hui la plateforme se comporte comme si tout se passait dans une seule
ville. Trois conséquences, toutes constatées dans le code :

1. Un client de Kinshasa peut remplir un panier chez un restaurant du Kasaï et
   le payer. Rien ne l'en empêche.
2. Un gérant qui possède plusieurs restaurants n'en voit qu'un seul, et
   silencieusement le premier de la liste.
3. Un véhicule de location n'appartient à aucune ville. La seule limite est un
   booléen par commune, le même pour tout le parc.

Le document couvre les trois, en trois lots livrables séparément.

### 1.1 Ce que le propriétaire a demandé, mot pour mot

| # | Demande | Traduction technique |
|---|---|---|
| 1 | « mettre la pression de limite de 20 km ou 30 km maximum » | un rayon entre l'adresse de livraison et le restaurant |
| 2 | « un message qui lui précise qu'il n'est pas autorisé de commander dans ce restaurant à cause de la distance » | un refus serveur nommé, avec la distance réelle |
| 3 | « Hunga Busta de Brazzaville flou, il peut pas accéder » | le restaurant reste visible, grisé, pas masqué |
| 4 | « le gérant de Al-Dar Kinshasa peut voir les commandes de tous ses restaurants Al-Dar de Kinshasa » | enseigne × ville |
| 5 | « moi en tant qu'admin je peux voir tout » | Filament non cloisonné |
| 6 | « il y a pas des limites des kilomètres mais il y a des limites par rapport à la ville (province) » | `vehicles.city_id`, pas de rayon en location |
| 7 | « ils doivent prévenir si c'est pour quel type de course : A, B, C » | un type de course et un commentaire sur la réservation |
| 8 | « pour les courses en dehors de la ville il est obligé de réserver pour minimum 24h et c'est 50 % de plus » | mode journée imposé + majoration |
| 9 | « courses aéroport : le véhicule ne rentre pas à l'aéroport, avec 10 $ de plus par heure pour le parking » | option facturée, au choix du client |
| 10 | « 7 places est à 20 % de plus, 4x4 au même prix que 7 places » | **saisi dans le tarif du véhicule**, pas calculé |

### 1.2 Arbitrages validés le 15 octobre

- **Rayon** : un réglage global, surchargeable par ville. Sans surcharge, le
  global s'applique.
- **Restaurant sans GPS** : repli sur le centroïde de sa commune.
- **Cloisonnement** : enseigne + ville.
- **Course B** : réservable dans l'application, minimum 24 h, +50 %.
- **+20 % des 7 places et 4x4** : saisi dans le tarif du véhicule en
  administration. Aucune règle de code. Cela laisse la porte ouverte à un tarif
  négocié, qu'un multiplicateur aurait fermée.
- **Parking aéroport** : option `+10 $/h` cochée par le client, pas une
  majoration systématique.

---

## 2. État des lieux (constaté dans le code)

### 2.1 Aucun contrôle de distance nulle part

`app/Http/Controllers/Api/RestaurantController.php::index()` renvoie tous les
restaurants actifs, sans la moindre notion de géographie :

```php
$restaurant = Restaurant::query()->where('is_active', true)
    ->when($search, …)
    ->get();
```

`app/Services/QuotationService.php::quote()` ne lit que les tranches tarifaires
de la **commune de livraison** (`DelivreryPrice` par `town_id`). La position du
restaurant n'entre jamais dans le calcul.

`app/Http/Controllers/Api/CommandeController.php` applique déjà un garde-fou
métier comparable — `refuserSiRestaurantFerme()`, lignes 104 à 139, appelé aux
lignes 260 et 920 — mais rien d'équivalent pour la distance.

### 2.2 Ce qui existe déjà et qu'on réutilise

| Brique | Fichier | Ce qu'elle fait déjà |
|---|---|---|
| Lecture tolérante des coordonnées | `app/Services/RestaurantGeo.php` | accepte `lat`/`latitude`/`Lat`…, valide les bornes, dégrade vers `null` plutôt que de lever |
| Haversine | `RestaurantGeo::distanceKm()` | distance en km, arrondie à 3 décimales |
| Centroïde de commune | `towns.latitude` / `towns.longitude` | migration `2026_10_09_180000`, nullables, `decimal:6` |
| Filtrage par rayon | `ProductSearchService::eligibleRestaurants()` lignes 66-95 | un `radius` **fourni par le client**, donc non contraignant |
| Hiérarchie géographique | `towns.city_id` → `cities` | migration `2025_06_22_184859`, nullable |
| Zone de location | `towns.rental_available`, `bookings.pickup_town_id` | migration `2026_10_09_120000` |
| Refus de réservation typé | `app/Exceptions/BookingRefusee.php` | code court + message optionnel venu des réglages |
| Tarification location | `app/Services/Rental/BookingPricing.php` | deux modes, bornes min/max, prix recalculé serveur |
| Réglages commerciaux | `app/Settings/RentalSettings.php` | `spatie/laravel-settings`, modifiables en admin |

`RestaurantGeo` a été écrit, documenté, et n'est branché que sur la recherche de
plats. La règle de distance n'existe nulle part.

### 2.3 Le gérant ne voit qu'un restaurant

`RestaurantController::getCurrentRestaurant()` :

```php
return Restaurant::query()->where('user_id', $this->getUser()?->id)->first();
```

`->first()`, sans `orderBy`. Un gérant propriétaire de trois succursales en voit
une, choisie par l'ordre naturel de MySQL. Les sept méthodes du module
restaurant (`currentOrderRestaurant`, `waitAcceptOrderRestaurant`,
`acceptOrderRestaurant`, `pastOrderRestaurant`, `dashRestaurant`,
`confirmOrderRestaurant`, `userRestaurant`) s'appuient toutes dessus.

C'est déjà un bug aujourd'hui, indépendamment du multi-ville.

### 2.4 Le véhicule n'a pas de ville

`database/migrations/2026_09_18_140100_create_vehicles_table.php` : ni `city_id`,
ni `town_id`. La seule contrainte géographique de la location est dans
`BookingCreator::create()` :

```php
if ($townId && ! Town::query()->whereKey($townId)->value('rental_available')) {
    throw new BookingRefusee(BookingRefusee::HORS_ZONE);
}
```

Un booléen global par commune. Rien ne rattache un véhicule à un marché.

---

## 3. Lot 1 — La portée de livraison

### 3.1 Une seule définition

Deux bugs de production sont nés du même motif : une règle écrite dans le
navigateur et absente du serveur (le prix du panier), puis une règle écrite dans
les deux applications et absente du serveur (le restaurant fermé). La règle de
distance ne sera écrite qu'une fois.

**`app/Services/PorteeLivraison.php`** répond à une seule question : ce
restaurant peut-il livrer à cet endroit.

```php
final class PorteeLivraison
{
    public function pour(Restaurant $restaurant, ?Town $livraison, ?float $lat, ?float $lng): Portee;
}
```

**`app/Services/Portee.php`**, objet figé, à l'image de `Quotation` et de
`BookingQuote` :

```php
readonly class Portee
{
    public function __construct(
        public bool    $autorise,
        public ?float  $distance_km,   // null = position inconnue d'un côté
        public ?float  $rayon_km,      // null = aucun contrôle applicable
        public ?string $message,       // null quand autorisé
    ) {}
}
```

### 3.2 Résolution des coordonnées

**Côté restaurant**, dans l'ordre :

1. `RestaurantGeo::coordinates($restaurant->location)` ;
2. `towns.latitude` / `towns.longitude` de `$restaurant->town` ;
3. rien.

**Côté livraison**, dans l'ordre :

1. `lat` / `lng` transmis par le client ;
2. centroïde de la commune de livraison ;
3. rien.

**Si un des deux côtés reste inconnu, la portée est autorisée**, avec
`distance_km = null`. Un parc mal renseigné ne doit pas fermer la plateforme le
jour du déploiement. C'est la même doctrine que `eligibleRestaurants()`, qui
écrit déjà : « on ne filtre jamais sur une donnée absente ».

Le repli par centroïde est approximatif — quelques kilomètres sur une commune de
Kinshasa — et c'est assumé : il sert à distinguer Gombé de Lubumbashi, pas deux
avenues voisines.

### 3.3 Le rayon

```php
$rayon = $restaurant->town?->city?->max_delivery_radius_km
      ?? $this->settings->max_delivery_radius_km;
```

La ville prise en compte est celle du **restaurant**, pas celle de la livraison :
c'est le restaurant qui offre le service, et c'est son marché qui définit
jusqu'où il va. Quand les deux diffèrent, la distance dépasse de toute façon
n'importe quel rayon.

**Nouveau groupe de réglages `app/Settings/FoodSettings.php`** (`group(): 'food'`) :

| Réglage | Type | Défaut | Rôle |
|---|---|---|---|
| `max_delivery_radius_km` | `float` | `30` | le rayon par défaut, toutes villes |
| `out_of_radius_message` | `string` | voir ci-dessous | la phrase montrée au client |

Phrase par défaut, avec deux emplacements nommés :

> « :restaurant est à :distance km de votre adresse de livraison. Nous ne livrons
> que dans un rayon de :rayon km. »

Elle vit dans les réglages et non dans le code, comme `RentalSettings::out_of_zone_message`.

**Migration** : `cities.max_delivery_radius_km`, `decimal(6,2)` **nullable**.
Nullable porte un sens : « cette ville n'a pas d'avis, applique le global ». Une
valeur par défaut aurait figé 30 km dans chaque ligne et rendu impossible de
changer le global après coup.

### 3.4 Où la règle s'applique

| Point | Fichier | Effet |
|---|---|---|
| Catalogue | `RestaurantController::index()` | accepte `lat`, `lng`, `town` optionnels ; expose `distance_km` et `livrable` par restaurant. **Ne masque rien.** |
| Recherche de plats | `ProductSearchService::eligibleRestaurants()` | le `radius` client est plafonné par la règle : `min($radius_client ?? INF, $rayon)` |
| Devis | `QuotationService::quote()` | nouveau refus `RAISON_HORS_RAYON` |
| Précommande | `PrecommandeService` | même refus |
| Commande | `CommandeController` | `refuserSiRestaurantTropLoin()`, jumelle de `refuserSiRestaurantFerme()`, appelée aux deux mêmes endroits |

Le **devis est le verrou réel** : les deux applications et le connecteur MCP
passent tous par lui pour obtenir un prix. Les trois autres points sont des
barrières de défense en profondeur, et le catalogue n'est qu'un affichage.

### 3.5 Évolution du contrat d'API

`POST /api/quotation` ne reçoit aujourd'hui qu'un slug de commune
(`QuoteRequest`). On y ajoute `lat` et `lng`, **optionnels** :

```php
'lat' => ['nullable', 'numeric', 'between:-90,90'],
'lng' => ['nullable', 'numeric', 'between:-180,180'],
```

Sans eux, repli sur le centroïde de la commune. Aucun client installé ne casse —
c'est la condition posée par le CLAUDE.md : les mobiles déjà déployés continuent
d'appeler les anciennes routes.

`GET /api/list-restaurant` gagne les mêmes paramètres optionnels, et
`RestaurantResource` deux clés de plus :

```php
'distance_km' => $this->whenNotNull($portee?->distance_km),
'livrable'    => $portee?->autorise ?? true,
'portee_message' => $this->whenNotNull($portee?->message),
```

`livrable` vaut `true` par défaut : un client qui n'envoie pas de position voit
le catalogue d'avant, inchangé.

### 3.6 Nouvelle constante de refus

Dans `QuotationService`, à côté des cinq existantes :

```php
public const RAISON_HORS_RAYON = 'hors_rayon';
```

`Quotation::refus()` porte déjà un `raison`, et `QuotationController::present()`
le renvoie déjà au client. Le message, lui, vient des réglages et accompagne le
code — même séparation que `BookingRefusee`.

---

## 4. Lot 2 — Enseigne et ville (cloisonnement des gérants)

### 4.1 Le modèle

« Al-Dar » est une **enseigne**. « Al-Dar Kinshasa » et « Al-Dar Lubumbashi » sont
deux périmètres de gestion distincts, avec deux gérants qui ne doivent rien voir
l'un de l'autre.

**Table `brands`** :

| Colonne | Type |
|---|---|
| `id` | `id` |
| `name` | `string` |
| `slug` | `string unique` |
| `is_active` | `boolean default true` |
| `timestamps`, `softDeletes` | |

**`restaurants.brand_id`** : `foreignId nullable`, `nullOnDelete`. Nullable parce
que les restaurants indépendants n'appartiennent à aucune enseigne, et qu'ils
sont majoritaires aujourd'hui.

**Table `restaurant_managers`** :

| Colonne | Type | Note |
|---|---|---|
| `user_id` | `foreignId` | cascade |
| `brand_id` | `foreignId` | cascade |
| `city_id` | `foreignId` | cascade |
| unique | `(user_id, brand_id, city_id)` | un gérant peut couvrir deux villes si l'admin le décide |

Trois colonnes plutôt qu'un pivot `restaurant_user` : l'ouverture d'une
quatrième succursale Al-Dar à Kinshasa ne demande alors aucune ligne de plus.
Le pivot par restaurant aurait exigé de penser à rattacher chaque nouveau
restaurant à son gérant, et l'oubli se serait vu le jour où une commande n'est
pas apparue.

### 4.2 Le changement de contrat interne

`getCurrentRestaurant(): ?Restaurant` devient :

```php
/** @return Collection<int, Restaurant> */
private function restaurantsDuGerant(): Collection
{
    $user = $this->getUser();

    $parEnseigne = Restaurant::query()
        ->whereIn('brand_id', fn ($q) => $q->select('brand_id')
            ->from('restaurant_managers')->where('user_id', $user?->id))
        ->whereIn('town_id', fn ($q) => $q->select('towns.id')
            ->from('towns')->whereIn('towns.city_id', fn ($q) => $q->select('city_id')
                ->from('restaurant_managers')->where('user_id', $user?->id)))
        ->get();

    // Repli : le parc actuel ne connaît que `restaurants.user_id`.
    return $parEnseigne->isNotEmpty()
        ? $parEnseigne
        : Restaurant::query()->where('user_id', $user?->id)->get();
}
```

Le repli n'est pas une transition : c'est le régime permanent du restaurateur
indépendant, qui n'aura jamais d'enseigne. Les deux chemins coexistent.

Les sept méthodes du contrôleur passent de
`->where('restaurant_id', $restaurant->id)` à
`->whereIn('restaurant_id', $ids)`. `userRestaurant()` renvoie désormais une
collection : **c'est la seule rupture de forme de réponse du chantier**, et elle
impose de livrer ce lot avec un build EAS, pas avant.

### 4.3 Ce qui ne change pas

L'administration Filament n'est pas cloisonnée. Le propriétaire voit tout, il
l'a dit explicitement. Aucun `Scope` global, aucune policy nouvelle sur les
ressources Filament.

---

## 5. Lot 3 — Le véhicule appartient à une ville

### 5.1 La ville d'origine

`vehicles.city_id` : `foreignId nullable`, `nullOnDelete`. Nullable pour le parc
existant, qui n'en a pas : un véhicule sans ville reste réservable partout, comme
aujourd'hui. Aucune régression le jour de la migration.

**La règle**, dans `BookingCreator::create()`, juste après le contrôle de
`rental_available` qui s'y trouve déjà :

```php
$town = $townId ? Town::query()->find($townId) : null;

if ($town && $vehicle->city_id && (int) $town->city_id !== (int) $vehicle->city_id) {
    throw new BookingRefusee(
        BookingRefusee::HORS_VILLE,
        "Ce véhicule effectue uniquement des courses à {$vehicle->city->title}.",
    );
}
```

Nouveau code dans `BookingRefusee` :

```php
/** Le véhicule n'opère pas dans la ville de prise en charge demandée. */
public const HORS_VILLE = 'hors_ville';
```

Distinct de `HORS_ZONE` : « cette commune n'est pas desservie » et « ce véhicule
n'est pas d'ici » n'appellent pas la même suite. Le premier envoie vers WhatsApp,
le second vers un autre véhicule.

`towns.rental_available` reste au-dessus : une commune peut être fermée même dans
la bonne ville.

### 5.2 Le catalogue reste mondial

« Ils peuvent voir les véhicules disponibles qui se trouvent dans tous les pays
mais ils peuvent commander que pour la course dans le pays d'origine de la
voiture. »

`VehicleController::index()` ne filtre donc pas par ville. `VehicleResource`
expose `city` (titre + slug) et, quand la requête porte un `pickup_town_id`, un
booléen `reservable`. Même traitement que `available`, déjà documenté dans le
contrôleur : « les indisponibles sont rendus quand même, pour être grisés plutôt
que cachés ».

### 5.3 Types de course

Trois colonnes sur `bookings` :

| Colonne | Type | Défaut | Rôle |
|---|---|---|---|
| `course_type` | `string(1)` | `'A'` | A urbaine, B hors agglomération, C aéroport |
| `course_note` | `text nullable` | — | le commentaire du client |
| `airport_entry` | `boolean` | `false` | le véhicule entre dans l'enceinte de l'aéroport |

`'A'` par défaut : les applications installées n'envoient pas de type, et leurs
réservations sont des courses urbaines. Refuser faute de type casserait le parc
pour un contrôle qu'il ne sait pas encore satisfaire — même raisonnement que
`pickup_town_id`.

`course_note` est **requis à la saisie pour B et C**, pas en base. Le propriétaire
demande que le client prévienne ; c'est une règle de formulaire et de
`FormRequest`, pas une contrainte de colonne. Une réservation administrative
prise au comptoir ne doit pas être bloquée par un commentaire manquant.

Une énumération `App\Enums\TypeCourse` porte les trois valeurs et leurs libellés,
à l'image de `App\Enums\TypeUser`.

### 5.4 Tarification

Les règles vivent dans `BookingPricing`, là où le prix est déjà calculé une seule
fois et où le client n'envoie jamais de montant.

**A — urbaine** : rien de plus.

**B — hors agglomération** :
- mode `daily` imposé. Un `BookingRefusee::MODE_IMPOSE` si le client demande
  `hourly`, plutôt qu'une bascule silencieuse : « le client a choisi un mode et
  un prix, et un autre prix n'est pas le sien » — la doctrine déjà écrite dans
  `BookingCreator` ;
- minimum `course_b_minimum_days` journées (défaut 1, soit 24 h) ;
- majoration `course_b_surcharge_percentage` (défaut 50) sur le total.

**C — aéroport** :
- si `airport_entry`, `airport_hourly_fee` (défaut 10) × heures facturées, ajouté
  au total ;
- sinon, rien : le chauffeur attend à l'extérieur.

Vérification sur l'exemple du propriétaire : berline à 150 $ la journée, course B
de 24 h → `150 × 1 journée × 1,5 = 225 $`. Conforme.

Le 4x4 à 180 $ la journée (150 + 20 %, **saisi en administration**) donne
`180 × 1,5 = 270 $`. Aucun multiplicateur dans le code.

**Quatre réglages de plus dans `RentalSettings`** :

| Réglage | Type | Défaut |
|---|---|---|
| `course_b_surcharge_percentage` | `float` | `50` |
| `course_b_minimum_days` | `int` | `1` |
| `airport_hourly_fee` | `float` | `10` |
| `airport_entry_message` | `string` | « Le véhicule n'entre pas dans l'enceinte de l'aéroport. Le parking est à votre charge. » |

### 5.5 Le devis doit montrer d'où vient le prix

`BookingQuote` gagne trois champs et les colonnes correspondantes sur `bookings` :

```php
public float $base,          // unitPrice × billedUnits, avant toute majoration
public float $surcharge,     // la majoration B, 0 sinon
public float $airportFee,    // les frais d'entrée aéroport, 0 sinon
```

`total = base + surcharge + airportFee`. L'acompte continue de dériver du total,
et le solde par soustraction : deux arrondis indépendants ne se rejoignent pas
toujours, et le client paierait un centime de trop.

Sans ce détail, une course B afficherait 225 $ là où le catalogue annonce 150 $,
et le client croirait qu'on a changé le prix derrière son dos.

---

## 6. UI/UX

### 6.1 La charte existe, on n'en sort pas

Source de vérité : `front end/thalia-delivery/DESIGN.md`, avec
`tailwind.config.js` et `theme/tokens.ts`.

Orange `brand-500` **#F99D1C** sous 10 % de la surface, thème clair uniquement,
rayons `card` 20 / `control` 14 / `pill` 999, échelle typographique
`caption → display` dont on ne sort pas, mouvement en `transform` et `opacity`
avec `cubicBezier(0.23, 1, 0.32, 1)` et `scale(0.97)` à l'appui, `useReducedMotion`
respecté.

**Ce chantier n'ajoute aucun token et aucun composant de base.**

### 6.2 Composants réutilisés, avec une prop de plus

DESIGN.md : « un composant qui existe se réutilise avec une prop de plus, il ne
se duplique pas ».

| Composant | Fichier | Ce qu'on ajoute |
|---|---|---|
| Refus hors zone | `components/rental/hors-zone.tsx` | une prop de service, pour couvrir les trois refus : restaurant trop loin, véhicule hors ville, commune non desservie |
| Champ de saisie | `ui/text-field.tsx` | `multiline` — il passe déjà ses props au `TextInput` |
| Carte restaurant | `components/home/restaurant-row.tsx` | l'état « hors rayon » |

`components/forms/Textarea.tsx` **n'est pas utilisé** : il écrit encore
`text-gray-900` et `text-red-600` en dur et sort de la charte.

Un seul composant est à écrire : la carte de type de course.

### 6.3 Le restaurant inaccessible est flou, pas caché

Sur `restaurant-row.tsx`, un restaurant hors rayon reste dans la liste à
`opacity: 0.45`, avec une pastille `warning` portant la distance réelle :
« à 412 km · hors zone de livraison ».

Il **reste tactile**. L'appui ouvre `hors-zone` au lieu du menu. Un élément grisé
qui ne répond pas à l'appui laisse croire à un bug ; celui-ci explique.

L'adresse qui sert au calcul est celle de `components/home/delivery-address-bar.tsx`,
déjà en haut de l'écran. Pas de nouvelle barre, pas de nouveau sélecteur.

### 6.4 Le clavier ne cache jamais un champ

La seule saisie nouvelle de tout le chantier est le commentaire de course.

Le patron de l'application existe : `components/auth/auth-shell.tsx` utilise
`KeyboardAwareScrollView` avec `keyboardShouldPersistTaps="handled"`, et
`react-native-keyboard-aware-scroll-view` est déjà en dépendance.

**Le piège réel** : `@gorhom/bottom-sheet` v5 est utilisé
(`vehicle-filters-sheet.tsx`, `town-picker-sheet.tsx`) et `BottomSheetTextInput`
n'apparaît **nulle part** dans le dépôt. Un `TextInput` ordinaire dans une de ces
feuilles ne la fait pas remonter : le clavier recouvre le champ.

**Décision : le commentaire ne va pas dans une feuille.** Il vit dans le flux de
l'écran de réservation, au-dessus du récapitulatif de prix, dans le
`KeyboardAwareScrollView` de l'écran. On supprime le problème au lieu de le
rattraper.

### 6.5 Trois décisions, trois formes distinctes

`components/rental/bascule-courses.tsx` porte **déjà** le choix heure / journée
sur l'écran véhicule, avec le prix en seconde ligne. Poser le type de course dans
une seconde bascule identique juste en dessous donnerait deux barres jumelles à
trois centimètres d'écart.

| Décision | Forme | Pourquoi |
|---|---|---|
| Heure / journée | `BasculeCourses`, inchangée | un choix de **prix** : deux options, le montant sous chaque segment |
| Type A / B / C | trois cartes en colonne, sur `ui/Card.tsx`, `accessibilityRole="radio"` | un choix de **nature** : chacune porte une explication et une conséquence de prix, qu'un segment de 40 px ne peut pas contenir |
| Entrée à l'aéroport | un `Switch` | un **oui/non facturé**, et le seul de l'écran. Même usage que `components/checkout/recipient-fields.tsx`, seul endroit où un `Switch` existe aujourd'hui |

**Choisir B verrouille la bascule sur « journée »**, affichée désactivée avec une
phrase : « les courses hors agglomération se réservent à la journée, 24 h
minimum ». Plutôt que de la laisser cliquable et de refuser au paiement.

Chaque carte porte sa conséquence en toutes lettres, avant le paiement :

- **A · Course urbaine** — en agglomération · 10 $/h
- **B · Hors agglomération** — voyage · 24 h minimum, +50 % · 225 $
- **C · Course aéroport** — le chauffeur attend à l'extérieur · +10 $/h si le véhicule entre

Le commentaire apparaît sous la carte choisie pour B et C, avec le libellé
« Décrivez votre trajet » et l'astérisque de champ requis que `ui/text-field.tsx`
rend déjà.

### 6.6 Le détail du prix

`components/rental/vue-a-payer.tsx` affiche les trois lignes du devis : base,
majoration, frais aéroport. C'est la raison d'être des champs ajoutés en 5.5.

### 6.7 Le web

Mêmes libellés, mêmes messages serveur, mêmes règles. Le web n'a pas de
`DESIGN.md` et **T60 (« porter Thalia Trans, à l'identique du mobile »)** est
encore ouvert : les écrans de location web se calquent sur le mobile, comme ce
ticket le demande déjà.

Aucune règle de distance n'est recopiée dans un composant, ni web ni mobile : ils
lisent `livrable`, `distance_km` et le message du serveur.

---

## 7. Contrat d'API — récapitulatif

Les deux `Route.ts` divergents (`front end/next-app/helpers/Route.ts` et
`front end/thalia-delivery/helpers/Route.ts` + `Routes.ts`) reçoivent les mêmes
ajouts. Aucune URL en dur dans un composant.

| Endpoint | Changement | Rupture ? |
|---|---|---|
| `GET /api/list-restaurant` | `lat`, `lng`, `town` optionnels ; `distance_km`, `livrable`, `portee_message` en sortie | non, additif |
| `POST /api/quotation` | `lat`, `lng` optionnels ; `raison: hors_rayon` possible | non, additif |
| `POST /api/commande` (et `valide`) | refus `hors_rayon` possible | non, nouveau cas d'erreur |
| `GET /api/rental/vehicles` | `city` en sortie ; `pickup_town_id` optionnel → `reservable` | non, additif |
| `POST /api/rental/bookings` | `course_type`, `course_note`, `airport_entry` optionnels ; refus `hors_ville`, `mode_impose` | non, additif |
| `GET /api/restaurant` (`userRestaurant`) | **renvoie une collection au lieu d'un objet** | **oui** — livré avec un build EAS |

---

## 8. Tests

`tests/` ne contient que les `ExampleTest` : le filet est manuel. Chaque lot
arrive avec ses tests, écrits avant le code.

### Lot 1
- `PorteeLivraisonTest` (unitaire) : restaurant géolocalisé dans le rayon ;
  hors rayon ; sans `location` avec commune géolocalisée ; sans `location` ni
  commune géolocalisée (autorisé, `distance_km = null`) ; livraison sans
  coordonnées (repli centroïde) ; surcharge de ville prioritaire sur le global ;
  ville sans surcharge → global.
- `QuotationHorsRayonTest` (feature) : `POST /api/quotation` refuse avec
  `raison: hors_rayon` et le message des réglages ; accepte dans le rayon ;
  accepte sans coordonnées exploitables.
- `CommandeHorsRayonTest` (feature) : les deux points d'entrée refusent, comme
  `refuserSiRestaurantFerme` le fait déjà pour l'ouverture.
- `ListRestaurantPorteeTest` (feature) : `livrable: false` et `distance_km`
  présents ; le restaurant **reste dans la réponse** ; sans `lat`/`lng`, la
  réponse est celle d'avant.

### Lot 2
- `RestaurantsDuGerantTest` (feature) : un gérant d'enseigne voit les commandes
  de ses trois succursales de sa ville ; ne voit pas celles de l'autre ville ;
  un restaurateur indépendant sans enseigne voit toujours son restaurant unique.

### Lot 3
- `BookingPricingCourseTest` (unitaire) : A sans majoration ; B à 225 $ sur
  l'exemple du propriétaire ; B en `hourly` refusé ; C sans entrée au même prix
  que A ; C avec entrée à +10 $/h ; `base + surcharge + airportFee == total`.
- `BookingVilleTest` (feature) : réservation refusée avec `hors_ville` quand la
  commune de départ n'est pas dans la ville du véhicule ; acceptée sinon ;
  véhicule sans `city_id` accepté partout.

`phpunit.xml` ne bascule pas sur SQLite en mémoire : vérifier la connexion `.env`
avant de lancer la suite. Et ne pas lancer deux sessions d'écriture en parallèle
pendant un run — c'est la cause des résultats erratiques déjà constatés.

---

## 9. Migrations et seeders

| Lot | Migration | Idempotence |
|---|---|---|
| 1 | `cities.max_delivery_radius_km` nullable | — |
| 2 | `brands`, `restaurants.brand_id`, `restaurant_managers` | — |
| 3 | `vehicles.city_id`, `bookings.course_type/course_note/airport_entry`, `bookings.base/surcharge/airport_fee` | — |

Les réglages (`FoodSettings`, les quatre `RentalSettings` nouveaux) passent par
des migrations `spatie/laravel-settings`, comme les existants.

`script-run.sh` lance `db:seed --force` en production : aucun seeder nouveau
n'est branché dans ce chantier. Les coordonnées des communes sont déjà
alimentées par le seeder existant, et les villes se saisissent en administration.

---

## 10. Ordre de livraison

1. **Lot 1 — distance.** Tient la promesse principale, aucune rupture de
   contrat, visible immédiatement.
2. **Lot 3 — véhicule.** Additif de bout en bout, isolé dans le module location.
3. **Lot 2 — gérant.** En dernier : c'est le seul qui touche tous les écrans du
   module restaurant en production, et le seul qui change une forme de réponse.
   Il part sur une base déjà stabilisée, avec un build EAS.

Chaque lot a sa branche depuis `release1.0`, ses tests, et son passage en revue
avant fusion. Aucun commit sur `prod` : la production se déploie seule depuis sa
branche.

---

## 11. Risques et limites assumées

| Risque | Décision |
|---|---|
| `restaurants.location` n'a jamais été lu en production : sa forme réelle est inconnue | `RestaurantGeo` l'accepte sous six orthographes et dégrade vers `null`. Le repli centroïde couvre le reste. |
| Le centroïde de commune est approximatif | Assumé : il sépare les villes, pas les avenues. Un restaurant mal placé à l'intérieur de sa commune reste livrable. |
| Un restaurant sans commune **et** sans GPS échappe au contrôle | Assumé. L'alternative — le rendre invisible — retirerait du catalogue des restaurants qui fonctionnent. À corriger par la saisie, pas par le code. |
| `userRestaurant()` change de forme | Lot livré en dernier, avec build EAS, et le web mis à jour dans la même fenêtre. |
| Le +20 % des 7 places repose sur une saisie humaine | Choix explicite du propriétaire. Une erreur de saisie se voit et se corrige en administration ; un multiplicateur figé aurait interdit tout tarif négocié. |
