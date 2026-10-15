# Lot 1 — Comment tester la limite de distance

**Ticket :** T81 · **Branche :** `feat/T81-portee-geographique` (backend) et `integration/octobre` (mobile, web)

Rien n'est déployé. Tout ce qui suit se teste en local, ou sur une préproduction.

---

## 0. Avant tout : lancer les migrations

```bash
cd "back end"
php artisan migrate
```

Deux choses arrivent :
- `cities.max_delivery_radius_km` (nullable) ;
- les deux réglages `food.max_delivery_radius_km` (30) et `food.out_of_radius_message`.

**Sans ces migrations, la limite ne s'applique pas** — et c'est voulu : le catalogue continue de fonctionner normalement plutôt que de répondre 500. C'est exactement le scénario qui vous est arrivé en production au ticket T76.

Pour vérifier que les réglages sont bien posés :

```bash
php artisan tinker --execute="dump(app(App\Settings\FoodSettings::class)->max_delivery_radius_km);"
```

Attendu : `30.0`.

---

## 1. Tester en automatique (le plus rapide)

```bash
cd "back end"
php artisan test --filter=Portee
```

Attendu : **42 tests verts**. Le filtre attrape les six fichiers du lot, la
règle (`tests/Unit/PorteeLivraisonTest.php`) comprise.

La suite complète :

```bash
php artisan test
```

Attendu : **736 passent, 1 échoue**. L'échec est `tests/Feature/ExampleTest.php` (« the application returns a successful response ») — il échouait déjà avant ce travail, c'est le test `GET /` livré avec Laravel. C'est le seuil : s'il y en a deux, quelque chose a cassé.

Côté mobile :

```bash
cd "front end/thalia-delivery"
npx jest components/__tests__/restaurant-row.test.tsx
```

Attendu : **3 tests verts**.

---

## 2. Tester l'administration

C'est la partie à faire à la main, parce que c'est vous qui allez saisir ces données en vrai.

```bash
cd "back end"
php artisan serve
```

Puis `http://127.0.0.1:8000/admin`.

### 2.1 Le rayon d'une ville — `Paramètre > Cities`

Ouvrez une ville, par exemple Kinshasa. Un champ nouveau : **« Rayon de livraison (km) »**.

| Ce que vous faites | Ce qui doit se passer |
|---|---|
| Laisser vide | La ville applique le rayon global (30 km). C'est le cas normal. |
| Saisir `20` | Les restaurants de cette ville ne livrent plus au-delà de 20 km. |
| Saisir `0` | **Refusé**, message d'erreur sous le champ. Pour fermer une ville, désactivez-la — un zéro sur une faute de frappe fermerait toute la ville sans prévenir. |
| Saisir `600` | Refusé (plafond à 500). |

### 2.2 Le centre d'une commune — `Paramètre > Communes`

Deux champs nouveaux : **« Latitude du centre »** et **« Longitude du centre »**.

C'est la position de repli. Quand un restaurant de cette commune n'a pas ses propres coordonnées, c'est celle-ci qui sert. Approximatif suffit : il faut séparer Gombé de Lubumbashi, pas deux avenues voisines.

Pour Gombé : latitude `-4.3045`, longitude `15.3075`.
Pour le centre de Lubumbashi : latitude `-11.6600`, longitude `27.4800`.

Une latitude à `120` doit être refusée.

> **Où trouver les coordonnées d'une commune ?** Sur Google Maps, clic droit sur le point → le premier élément du menu est « -4.3045, 15.3075 », cliquez dessus, c'est copié. La latitude est le premier nombre.

### 2.3 La position d'un restaurant — `Restaurants`

Deux champs nouveaux dans « Information de base » : **« Latitude »** et **« Longitude »**.

Ils restent **facultatifs**. Un restaurant sans coordonnées utilise le centre de sa commune. Mais une position précise évite de refuser un client qui est à portée.

**Attention, défaut préexistant** : le formulaire restaurant ouvre une ligne d'horaires vide et obligatoire. Pour enregistrer, il faut soit remplir un créneau, soit supprimer la ligne avec la corbeille. Ce n'est pas lié à ce travail, mais vous allez le rencontrer en testant.

---

## 3. Tester le refus, bout en bout

### 3.1 Préparer deux restaurants

Dans l'administration :

1. Un restaurant **« Al-Dar Kinshasa »**, commune Gombé, latitude `-4.3045`, longitude `15.3075`.
2. Un restaurant **« Al-Dar Lubumbashi »**, commune de Lubumbashi, latitude `-11.6600`, longitude `27.4800`.

Donnez un plat à chacun.

### 3.2 Depuis l'application mobile

Avec une adresse de livraison à Kinshasa :

| Écran | Attendu |
|---|---|
| Liste des restaurants | Les **deux** apparaissent. Al-Dar Lubumbashi est **grisé**, avec en bas « À 1569.4 km · hors zone de livraison ». Il n'a pas disparu, c'est voulu. |
| Appui sur Al-Dar Lubumbashi | La fiche s'ouvre quand même. On peut regarder la carte. |
| Tentative de commande | Refus, avec le message : « Al-Dar Lubumbashi est à 1569.361 km de votre adresse de livraison. Nous ne livrons que dans un rayon de 30 km. » |
| Al-Dar Kinshasa | Rien ne change. Commande normale. |

### 3.3 Sans passer par l'application (le vrai test)

C'est le test qui compte : la règle doit tenir même quand on appelle l'API directement, puisque c'est précisément la faille des règles qui ne vivent que dans les écrans.

Il vous faut un token. Depuis l'application, ou :

```bash
cd "back end"
php artisan tinker --execute="echo App\Models\User::first()->createToken('test', ['*'])->plainTextToken;"
```

Puis, en remplaçant `<TOKEN>` et les identifiants chiffrés par ceux que renvoie votre catalogue :

```bash
curl -s -X POST http://127.0.0.1:8000/api/quote \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "town": "gombe",
    "products": [{"uid": "<UID_DU_PLAT_DE_LUBUMBASHI>", "quantity": 1}]
  }'
```

Attendu :

```json
{
  "disponible": false,
  "raison": "hors_rayon",
  "message": "Al-Dar Lubumbashi est à 1569.361 km de votre adresse de livraison. Nous ne livrons que dans un rayon de 30 km."
}
```

Le même appel avec le plat de Kinshasa doit rendre `"disponible": true` et un prix.

### 3.4 Le catalogue avec une position

```bash
curl -s "http://127.0.0.1:8000/api/list-restaurant?lat=-4.3045&lng=15.3075" | head -40
```

Chaque restaurant porte trois clés nouvelles :

```json
{
  "name": "Al-Dar Lubumbashi",
  "distance_km": 1569.361,
  "livrable": false,
  "portee_message": "Al-Dar Lubumbashi est à 1569.361 km..."
}
```

**Et sans position** — c'est le test de non-régression le plus important :

```bash
curl -s "http://127.0.0.1:8000/api/list-restaurant" | head -40
```

Attendu : `"livrable": true` et `"distance_km": null` partout. Un client mobile déjà installé, qui n'envoie pas sa position, voit exactement ce qu'il voyait avant.

---

## 4. Les cas tordus à vérifier

Ce sont ceux qui cassent les fonctionnalités de ce genre.

| Cas | Comment le provoquer | Attendu |
|---|---|---|
| **GPS non fixé** | Envoyer `lat=0&lng=0` | Aucun refus. Un téléphone qui n'a pas encore accroché les satellites renvoie le point zéro, dans le golfe de Guinée. Le prendre au mot fermerait tout le catalogue. |
| **Restaurant sans coordonnées** | Vider latitude et longitude d'un restaurant, et de sa commune | Aucun refus, `distance_km: null`. Un parc mal renseigné ne doit pas fermer la plateforme. |
| **Pile sur la limite** | Mettre le rayon de la ville à la distance exacte | Autorisé. La borne est inclusive. |
| **Panier vide ou multi-restaurant** | Mettre deux plats de deux restaurants | La raison rendue doit être `multi_restaurant`, pas `hors_rayon`. Sinon on envoie le client corriger son adresse pour un problème qui n'est pas là. |
| **Réglages absents** | `DELETE FROM settings WHERE \`group\` = 'food';` puis recharger le catalogue | Le catalogue **fonctionne**, sans limite de distance. Il ne répond pas 500. Puis `php artisan migrate` les remet. |

---

## 5. Ce qui n'est pas encore là

Ce lot ne traite **que** la distance de livraison des repas. Ne cherchez pas :

- le cloisonnement des gérants par enseigne et par ville (lot 2) ;
- la ville d'origine des véhicules et les types de course A/B/C (lot 3).

Les deux ont leur place dans le spec et auront leur propre plan.

Deux limites assumées de ce lot, écrites ici pour que vous les voyiez avant de les découvrir :

- Le **catalogue web** n'envoie pas encore de position, donc ses cartes ne se grisent pas. La carte sait le faire, il manque la transmission de l'adresse. Le refus, lui, fonctionne au moment de payer.
- Un restaurant **sans commune et sans coordonnées** échappe à la limite. L'alternative — le rendre invisible — retirerait du catalogue des restaurants qui fonctionnent. Ça se corrige par la saisie, en 2.3.
