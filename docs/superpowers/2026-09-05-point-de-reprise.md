# Point de reprise — 5 septembre 2026

Où en est la branche `feature/precommandes-mcp`, ce qui est décidé, ce qui ne l'est pas,
et ce qu'il ne faut surtout pas refaire.

---

## 1. Ce qu'on construit

Un client parle à Claude ou ChatGPT. Il dit *« trouve-moi un plat autour de moi »*,
*« j'ai 500 francs, propose-moi ce que je peux commander »*. L'assistant propose, le client
choisit. L'assistant crée une **pré-commande** — un panier au prix figé, valable 12 heures —
puis envoie un **lien de paiement signé, à usage unique, qui expire**. Le client paie, la
pré-commande devient une `Commande` réelle.

Le budget annoncé par le client couvre **plat + livraison + frais de service**. Si rien ne
rentre dans le budget, l'assistant doit le dire.

### Les règles posées par le propriétaire, qui ne se négocient pas

- **L'agent n'écrit jamais en base directement.** Tout passe par l'API REST Laravel.
- **Aucune suppression de donnée**, jamais, par personne.
- **L'agent ne fait que ce qui est explicitement autorisé** — liste blanche, pas liste noire.
- **On ne touche plus à la logique FlexPay du webhook.** Consigne donnée explicitement le
  5 septembre après que le propriétaire ait vu les modifications. Il fonctionne.

---

## 2. État au moment de l'arrêt

Branche `feature/precommandes-mcp`, 36 commits au-dessus de `release1.0`.

### Terminé et validé

**Sous-projet A — moteur de devis** (fusionné) : `/api/quote`,
`/api/products/search`, `/api/budget-suggestions`. `QuotationService` reproduit
`calculePrice.js` **à l'identique, bugs compris** — `sprintf('%.2F')` et non `round()`,
pas d'arrondi sur le total. C'est délibéré : une divergence d'un centime entre le prix
annoncé au client et le prix facturé vaut pire qu'un défaut partagé.

**Sous-projet C-back — pré-commandes** : les 9 tâches. Jetons Sanctum limités par
capacité (`app/Enums/TokenAbility.php`), refus par défaut sur toute route ne déclarant pas
de capacité (`RefuserAgentSansAbility`), création et lecture de pré-commandes, lien de
paiement signé, conversion au webhook, limiteurs de débit comptés par jeton.

**Vague de sécurité** (relue et validée) : quatre correctifs. Le webhook confronte la
référence annoncée à celle de la transaction vérifiée. Adresse et destinataire masqués sur
la page signée. Un jeton d'assistant n'épuise plus le quota API de son client. Un canal de
journal `paiement` séparé, avec des capteurs.

**Le changement de flux** — terminé après l'arrêt, trois commits : `6a93438` (l'assistant
ne collecte plus que la commune), `bb96576` (coordonnées saisies par le client, paiement par
carte) et `5e8dd2a` (la case « même numéro » survit à une erreur de saisie). Suite complète
254 passés, un seul échec préexistant et sans rapport (`ExampleTest`, `GET /` en 404 : il n'y
a pas de route racine). `LienPaiementTest` passe de 20 à 34 tests.

**Rien n'est en vol. L'arbre ne contient plus que le travail de la session parallèle**
(voir §6). Cahier des charges de la tâche :
`.superpowers/sdd/2026-09-05-c-back-precommandes/task-flux-brief.md` — dossier ignoré par
git, il peut disparaître.

### À reprendre en premier demain

**Un trou ouvert par ce changement, à trancher avant d'aller plus loin.**
`POST /api/precommandes/{uid}/paiement` — le paiement depuis l'application, sans passer par
le lien — peut désormais payer une pré-commande **sans coordonnées de livraison**. Rendre
les colonnes nullables était nécessaire au nouveau flux, mais ce second point d'entrée n'a
pas reçu le formulaire qui les remplit. FlexPay recevrait `name => null` et la conversion
produirait une `Commande` sans adresse — donc une commande que personne ne peut livrer,
déjà payée.

Trois options : exiger que la pré-commande soit complète avant d'accepter ce paiement (le
plus sûr, et cohérent avec « refuser sur du code neuf ») ; faire porter les coordonnées par
cette requête ; ou renvoyer l'application vers le lien signé. **Ne pas laisser en l'état.**

Deux autres points, plus petits : `Api\PrecommandeController::payer()` garde son appel
`LibPhoneNumber` non protégé — même piège `TypeError` que celui corrigé côté web, un
numéro mal tapé y rend un 500. Et le changement n'a pas encore été relu par un tiers.

### Pas commencé

- **C-front** — la page de pré-commande dans l'application mobile, pour qu'un client qui a
  commandé depuis l'assistant puisse reprendre sur son téléphone.
- **C-mcp** — le serveur MCP lui-même. Service **Python, en Docker sur le VPS**
  (contrainte du propriétaire). Un outil MCP = un endpoint de l'API publique, jamais un
  second chemin d'accès aux données.
- **La revue finale de toute la branche**, avant fusion.

---

## 3. À décider par le propriétaire

Ces points sont ouverts et bloquent des choses réelles.

**Le catalogue est entièrement en USD.** Un client qui dit *« j'ai 5 000 francs »*
n'obtient rien. C'est la première chose qui cassera l'expérience promise.

**`restaurants.location` est vide sur les quatre restaurants.** La recherche par distance —
*« autour de moi »* — est donc inerte. Le code est là, la donnée manque.

**Qui lit `storage/logs/paiement-*.log` ?** Toute la sécurité résiduelle du webhook repose
sur ce journal. Si personne ne le regarde, on a construit un capteur pour rien. À brancher
sur Sentry, qui est déjà installé.

**Plafonner les jetons d'assistant par compte.** Chaque jeton a son propre quota de 60
requêtes/minute et rien ne limite leur nombre : dix jetons valent dix fois l'accès. Le bon
chiffre est le sien.

**Les commandes déjà incohérentes en base.** `cancel_at` renseigné, `status_id` resté à 2.
Aucune migration de données n'a été faite — décision prise et assumée. Elles se réparent
opportunément quand quelque chose les sauvegarde.

---

## 4. Pièges de ce dépôt — lire avant de coder

**`Model::unguard()` est appelé globalement** dans `AppServiceProvider.php:28`. Aucun modèle
ne protège contre l'affectation de masse. **Les règles de validation sont la seule liste
blanche.** Ne jamais passer un tableau de requête entier à `create()` ou `update()`.

**Le plancher PHP est `^8.1` alors que le runtime local est 8.3.** Toute syntaxe 8.2+ passe
en local et casse ailleurs : classes `readonly`, `#[\Override]`, types `null`/`false`/`true`
autonomes, constantes de classe typées, `json_validate`.

**`catch (Exception)` ne rattrape pas `Error`.** Un appel de méthode inexistante rendait
`GET /api/user/delivery-dash` en 500 **depuis toujours**, sans que personne ne le voie.
`LibPhoneNumber` lève une `TypeError` sur entrée invalide. Viser `catch (\Throwable)` dans
le code neuf.

**`Sanctum::actingAs($user)` donne un tableau de capacités VIDE**, alors que
`createToken($name)` donne `['*']`. Cette asymétrie a cassé 24 tests. Une fixture de test
qui modélise un jeton applicatif doit passer `['*']` explicitement.

**`TransientToken::can()` renvoie `true` pour n'importe quelle capacité.** Une requête
authentifiée par session contourne donc toutes les protections d'assistant.

**L'historique des migrations n'est pas rejouable depuis zéro.** Il y a un dump de schéma
dans `database/schema/mysql-schema.sql`. Et `schema:dump` lit la connexion **par défaut**,
pas celle de `--env=testing`.

**`Http::fake()` empile les stubs.** Un `fake()` posé dans `setUp()` masque celui qu'un test
pose ensuite, et le test passe pour la mauvaise raison. Rencontré aujourd'hui sur les
réponses carte de FlexPay : le test était vert alors qu'il ne testait rien.

**Les index FULLTEXT sont invisibles dans une transaction non validée** — `MATCH() AGAINST()`
ne trouve rien sous `RefreshDatabase`. Utiliser `DatabaseTruncation`.

**Aucun ordonnanceur ne tourne en production** (pas de `schedule:run`). L'expiration des
pré-commandes est donc paresseuse, dérivée de `expires_at`, jamais balayée.

**Le garde de base de test** (`tests/CreatesApplication.php`) refuse toute base dont le nom
ne finit pas par `_test`. Bases en usage : `thalia_eats_test`, `thalia_eats_mobile_test`,
`thalia_eats_securite_test`, `thalia_eats_flux_test`.

---

## 5. Le piège qui a coûté le plus de temps aujourd'hui

**Des échecs de tests erratiques, non reproductibles, sur des classes sans rapport.**

Deux fausses pistes ont été suivies avant la bonne. D'abord : le compteur du limiteur de
débit fuirait entre les tests. **Mesuré et faux** — une sonde de 80 requêtes sur deux
méthodes déclenche le refus à la requête 61 dans chacune ; le compteur meurt avec
l'instance d'application. Ensuite : « ça disparaît quand je neutralise le limiteur » —
coïncidence de calendrier, pas mesure.

**La vraie cause : deux sessions Claude écrivaient des fichiers PHP dans le même arbre
pendant qu'un test tournait.** Un fichier remplacé au milieu d'un run donne exactement ça.

La règle qui en découle : **on se prévient avant chaque run long**, et chacun travaille sur
sa propre base. Et surtout, on ne pose pas d'exemption globale contre le limiteur dans les
tests : un refus pour débit excessif pendant un test signale qu'un vrai client sera refusé
aussi. Le masquer supprime l'avertissement, pas le problème.

---

## 6. Session parallèle

Une seconde session travaille dans le même checkout, côté livreur et restaurant. Son
travail non committé au moment de l'arrêt : `CommandeController`, `DeliveryController`,
`RestaurantController`, `CommandeResource`, le modèle `Commande`, `ApiResponse`,
`config/sse.php`, et quatre classes de test (`AccesCommandeTest`, `AnnulationCoherenteTest`,
`CourseLivreurTest`, `DriverCodeLockoutTest`).

**Ne rien committer qui ne soit pas à soi.** Toujours `git add` avec des chemins explicites,
jamais `git add -A` ni `git add .` — cinq collisions ont eu lieu aujourd'hui avant que la
règle ne soit posée.

### Ce qu'elle a corrigé, et qui vaut d'être connu

Une commande annulée s'affichait comme course en cours du livreur : `cancel_at` renseigné
mais `status_id` resté à 2. Le vrai correctif n'est pas dans les écrans — c'est un hook
`saving` sur `Commande` qui rend les deux marqueurs indissociables, plus un scope
`nonAnnulee()` que les cinq lectures consomment. **Une définition unique plutôt que cinq
lectures remises d'accord** : c'est la même leçon que `courseEnCours()`, qui a fusionné deux
définitions concurrentes de « course en cours » écrites à deux endroits.

Elle a aussi corrigé deux failles que la revue de sécurité adverse avait manquées :
`/commande/update-track` n'était pas filtré — n'importe quel compte authentifié pouvait
pousser une fausse position de livreur — et `CommandeResource` donnait **les deux codes de
confirmation au livreur**, ce qui annulait l'attestation de remise et rendait décoratif le
verrou anti-force-brute.

---

## 7. Enseignements de méthode

**Un `where('user_id', ...)` seul sur une commande casse la production.** Le livreur affecté
et le restaurant propriétaire des plats sont des lecteurs légitimes. Vérifier les trois
applications clientes avant de restreindre un accès.

**Mesurer avant d'expliquer.** Deux diagnostics confiants ont été faux aujourd'hui, tous
deux corrigés par une mesure de dix lignes.

**Reproduire un défaut existant plutôt que de le corriger d'un seul côté.** Le contrôle du
minimum carte à 2 USD ne regarde pas la devise ; il est reproduit tel quel dans le chemin
pré-commande, avec un commentaire. Deux chemins de paiement qui divergent valent pire qu'un
défaut partagé et documenté.

**Sur un chemin de production vivant : observer, ne pas refuser.** Sur du code neuf sans
trafic : refuser. C'est la doctrine appliquée partout dans la vague de sécurité, et elle a
été validée par le propriétaire.

---

## 8. Session mobile — interface livreur et lot des annulations

**Rien de cette section n'est committé.** L'arbre backend contient ces modifications ; le dépôt mobile (`front end/thalia-delivery`) aussi. Le propriétaire n'a pas encore tranché sur le commit.

### Backend touché

`CommandeController`, `DeliveryController`, `RestaurantController`, `CommandeResource`, `Commande`, `ApiResponse`, `config/sse.php` (nouveau), et quatre classes de test : `AccesCommandeTest`, `AnnulationCoherenteTest`, `CourseLivreurTest`, `DriverCodeLockoutTest`.

### Ce qui est structurant

**Une seule définition, pas N lectures.** Deux bugs de production sont nés du même motif, et les deux correctifs suivent la même forme.

- « Course en cours » était écrite dans `currentOrderDelivery` **et** dans le contrôle d'acceptation, avec des critères différents. Assainir la première a enfermé le livreur dans un écran vide sans sortie : il était refusé à l'acceptation par une commande que l'écran n'affichait plus. Fusionnées en `courseEnCours()`.
- « Commande annulée » était supposée par six lectures (`status_id` seul), alors que le formulaire Filament expose `status_id`, `cancel_at` et `delivery_at` comme trois champs indépendants. Un administrateur pose la date sans toucher au statut. Correctif : scope `nonAnnulee()` **et** garantie au niveau du modèle (`Commande::booted()`, hook `saving`), pour que la septième lecture n'hérite pas du défaut.

Ajouter la clause manquante à un seul appelant aurait donné un vert le jour même et le même bug plus tard sur un site pas encore écrit.

### Deux fautes de frappe qui masquaient des bugs

- `CommandeResource` lisait `$this->resource->delivrery_at` : attribut inexistant, donc `delivery_at` partait **à null dans toutes les réponses depuis toujours**. Invisible tant qu'un écran retombait sur `created_at`. Seule la lecture était fautive, la clé de réponse est correcte et ne bouge pas.
- `DeliveryController::dashRestaurant` appelait `getCurrentRestaurant()`, défini uniquement dans `RestaurantController`. Un appel de méthode inexistante lève une `Error`, que `catch (Exception)` ne rattrape pas : `GET /api/user/delivery-dash` répondait **500 à chaque appel**, depuis toujours, sans que personne le sache — aucun client ne l'utilisait. Reconstruit en tableau de bord livreur, borné à ses propres courses.

Dans le code neuf, viser `catch (\Throwable)`.

### Sécurité

Verrou anti-force-brute sur les codes de confirmation : trois codes refusés bloquent la saisie 25 minutes, **côté serveur** (`DeliveryController`, cache, clé livreur + commande + étape). Une coupure réseau ne consomme aucune tentative. Le verrou côté application n'est qu'un confort.

Ce verrou n'a de sens qu'avec le correctif de `CommandeResource` : le livreur recevait les **deux** codes dans sa propre réponse et pouvait donc confirmer retrait et livraison sans rencontrer personne.

### Piège de domaine, à connaître avant de toucher au flux livreur

Une commande porte **deux codes distincts** : `code_confirmation` est celui du **client** (remise finale), `code_confirmation_restaurant` celui du **restaurant** (retrait). L'étape en cours se lit sur `time_delivery` : nul = retrait à faire. Saisir le mauvais donne « code incorrect » sans autre explication — l'écran nomme désormais l'interlocuteur.

### Décisions de données en attente

Voir la section 3. S'y ajoutent : la commande 14 (affectée, en cours, **sans aucune ligne `commande_products`** — invisible partout et impossible à terminer) et la commande 41 / réf 1040 (porte **à la fois** `cancel_at` et `delivery_at`).

### Suite prévue

Refonte de l'interface restaurant. `app/(restaurant)/` est encore largement le gabarit Expo : `home.tsx` affiche « Welcome! », les onglets sont en anglais, et les quatre écrans `[slug]` sont des coquilles de sept lignes.
