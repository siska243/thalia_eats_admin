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

### En cours au moment de l'arrêt

**Le changement de flux.** Deux commits posés : `6a93438` (l'assistant ne collecte plus que
la commune) et `bb96576` (coordonnées saisies par le client, paiement par carte).
Un agent finissait de peaufiner `resources/views/precommande/paiement.blade.php` et
`tests/Feature/Api/LienPaiementTest.php` — ces deux fichiers peuvent être non committés au
redémarrage. **Vérifier `git status` et `git diff` avant toute chose**, puis lancer
`tests/Feature/Api/LienPaiementTest.php` pour savoir où ça en est.

**Cahier des charges de cette tâche** :
`.superpowers/sdd/2026-09-05-c-back-precommandes/task-flux-brief.md` — attention, ce dossier
est ignoré par git, il peut disparaître.

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
