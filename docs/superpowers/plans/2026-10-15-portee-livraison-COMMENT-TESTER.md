# Tester la limite de distance — par l'application, le web et l'admin

**Tickets :** T81 (limite de distance) et T83 (deux défauts du panier)

Aucun test de code ici. Tout se fait par les écrans.

---

## 0. Mettre la base à jour — une seule commande

```bash
cd "back end"
git checkout feat/T81-portee-geographique
php artisan migrate
php artisan serve
```

C'est la seule ligne de commande de tout le document. Sans `migrate`, la limite ne s'applique à rien — et c'est voulu : l'application continue de fonctionner normalement au lieu de tomber en 500, comme lors de votre incident de déploiement.

Pour le mobile, pensez au tunnel ngrok habituel vers `127.0.0.1:8000`.

---

## 1. Préparer le terrain dans l'admin

`http://127.0.0.1:8000/admin`

### 1.1 Donner un centre à deux communes

**Paramètre → Communes.** Deux champs nouveaux : **Latitude du centre** et **Longitude du centre**.

| Commune | Latitude | Longitude |
|---|---|---|
| Une commune de Kinshasa (Gombé) | `-4.3045` | `15.3075` |
| Une commune de Lubumbashi | `-11.6600` | `27.4800` |

> Pour une autre commune : sur Google Maps, clic droit sur le point, le premier élément du menu affiche `-4.3045, 15.3075`. Cliquez dessus, c'est copié. La latitude est le premier nombre.

**Essayez de saisir `120` en latitude** → le formulaire doit refuser.

### 1.2 Donner une position à deux restaurants

**Restaurants.** Deux champs nouveaux dans « Information de base » : **Latitude** et **Longitude**.

- **Al-Dar Kinshasa** → commune Gombé, `-4.3045` / `15.3075`
- **Al-Dar Lubumbashi** → commune de Lubumbashi, `-11.6600` / `27.4800`

Donnez un plat à chacun, et vérifiez qu'ils sont actifs.

> **Vous allez buter sur quelque chose** : le formulaire restaurant ouvre une ligne d'horaires vide et obligatoire. Remplissez un créneau, ou supprimez la ligne avec la corbeille. C'est un défaut qui existait déjà (ticket T82), sans rapport avec ce travail.

### 1.3 Régler le rayon d'une ville

**Paramètre → Cities.** Champ nouveau : **Rayon de livraison (km)**.

| Ce que vous faites | Ce qui doit se passer |
|---|---|
| Laisser vide sur Kinshasa | La ville applique le rayon général de 30 km. C'est le cas normal. |
| Saisir `20` | Les restaurants de cette ville ne livrent plus au-delà de 20 km. |
| Saisir `0` | **Refusé**, avec un message sous le champ. Pour fermer une ville, désactivez-la : un zéro saisi par erreur la fermerait sans prévenir personne. |
| Saisir `600` | Refusé, le plafond est à 500. |

---

## 2. Le test principal, sur mobile

**Choisissez une commune de livraison à Kinshasa** dans la barre en haut de l'accueil. C'est elle qui est envoyée au serveur — l'application n'a pas besoin du GPS.

| Écran | Ce que vous devez voir |
|---|---|
| Accueil, liste des restaurants | **Les deux restaurants sont là.** Al-Dar Lubumbashi est **grisé**, et sa troisième ligne dit « À 1569.4 km · hors zone de livraison ». Il n'a pas disparu : c'est ce que vous aviez demandé, le restaurant lointain se voit mais ne s'atteint pas. |
| Appui sur Al-Dar Lubumbashi | **La fiche s'ouvre quand même.** On peut regarder la carte. Un restaurant grisé qui ne réagit pas à l'appui donne l'impression d'un bug. |
| Essayer de commander chez lui | Refus, avec le message : « Al-Dar Lubumbashi est à 1569.361 km de votre adresse de livraison. Nous ne livrons que dans un rayon de 30 km. » |
| Al-Dar Kinshasa | Rien ne change. La commande passe comme avant. |

**Puis changez de commune pour celle de Lubumbashi**, en haut de l'accueil. Les deux restaurants échangent leurs rôles : Al-Dar Lubumbashi devient commandable, Al-Dar Kinshasa passe en gris. C'est exactement le scénario que vous décriviez — « s'il voyage à Brazzaville, il voit les restaurants de Brazzaville ».

---

## 3. Le même test sur le web

Le catalogue web n'affiche les distances **que si une adresse de livraison a déjà été choisie** : elle se saisit au moment du paiement, pas en parcourant le catalogue.

1. Passez une commande jusqu'à l'écran de paiement, en renseignant une adresse à Kinshasa. Vous pouvez abandonner le paiement, l'adresse est retenue.
2. Revenez sur la liste des restaurants.

| Ce que vous regardez | Ce que vous devez voir |
|---|---|
| La carte d'Al-Dar Lubumbashi | Grisée, pastille **« Hors zone »** en haut à gauche, et en bas « À 1569.4 km · hors zone de livraison ». |
| La carte reste cliquable | Oui, comme sur mobile. |
| Al-Dar Kinshasa | Inchangé : pastille « Ouvert » ou « Fermé » selon l'heure. |
| Payer une commande chez Al-Dar Lubumbashi | Refusée, avec le message du serveur affiché. |

Tant qu'aucune adresse n'a été choisie, le catalogue s'affiche exactement comme avant. C'est normal.

---

## 4. Tester T83 — les deux défauts du panier

Branche séparée, elle peut être testée indépendamment :

```bash
cd "back end"
git checkout fix/T83-panier
php artisan serve
```

**Sur le web uniquement** — le mobile n'est pas concerné, son panier reste dans l'application jusqu'au paiement.

| Étape | Avant la correction | Après |
|---|---|---|
| Videz votre panier, ajoutez **un seul** plat, allez au panier | La commande est créée **vide**, total à 0. Le plat n'y est pas. | Le plat est là, total correct. |
| Ajoutez un deuxième plat | Les deux apparaissent enfin — mais le premier a laissé une ligne fantôme en base. | Les deux apparaissent, sans ligne fantôme. |

Dans l'admin, **Commandes** : ouvrez la commande en attente après avoir ajouté un seul plat. Avant la correction elle est vide. Après, elle contient le plat.

---

## 5. Les cas tordus, toujours par les écrans

Ce sont ceux qui cassent ce genre de fonctionnalité.

| Cas | Comment le provoquer | Ce qui doit se passer |
|---|---|---|
| **Restaurant sans coordonnées** | Dans l'admin, videz latitude et longitude d'Al-Dar Lubumbashi, **et** celles de sa commune | Il redevient commandable depuis Kinshasa. Sans position, on ne refuse pas : un parc mal renseigné ne doit pas fermer la plateforme. |
| **Coordonnées laissées à zéro** | Mettez `0` en latitude **et** longitude sur un restaurant | Il doit se comporter comme s'il n'avait aucune position, et retomber sur le centre de sa commune. Il ne doit **pas** disparaître du catalogue. |
| **Rayon resserré** | Mettez `5` km sur Kinshasa, avec deux restaurants kinois éloignés de 9 km | Le plus lointain passe en gris. Remettez vide pour revenir à 30 km. |
| **Aucune commune choisie** | Sur mobile, avant de choisir une commune de livraison | Aucune distance affichée, tout est commandable. C'est le comportement d'avant : une application déjà installée ne doit rien voir changer. |

---

## 6. Ce qui n'est pas encore là

Ce travail ne couvre **que** la distance de livraison des repas. Ne cherchez pas :

- le cas d'un gérant qui possède **plusieurs** restaurants (lot 2).

  > **Précision, parce que ce document disait le contraire et c'était faux.**
  > Deux restaurants portés par **deux comptes gérants différents** sont déjà
  > cloisonnés aujourd'hui : chaque écran filtre sur le restaurant du compte
  > connecté. Al-Dar Kinshasa ne voit pas les commandes d'Al-Dar Lubumbashi, et
  > réciproquement. Ce qui manque est autre chose : un gérant qui possède
  > **plusieurs succursales sous un seul compte** n'en voit qu'une seule, et
  > sans ordre défini — c'est ce que le lot 2 corrige.

- la ville d'origine des véhicules et les types de course A, B, C (lot 3).

Les deux sont écrits dans le dossier de conception et auront leur propre livraison.

Une limite assumée : un restaurant **sans commune et sans coordonnées** échappe à la limite. L'autre choix aurait été de le retirer du catalogue alors qu'il fonctionne. Ça se corrige par la saisie, en 1.2.
