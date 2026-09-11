# Premier déploiement du backend en production

Serveur partagé : Apache2 sert déjà d'autres applications, d'autres conteneurs
Docker y tournent, MySQL est installé sur l'hôte. Rien de tout cela n'est
modifié par cette procédure.

**Aucun de ces fichiers n'est versionné.** Ils voyagent par `rsync`, pas par
git. L'exclusion locale est posée dans `.git/info/exclude`, qui n'est jamais
poussé.

Serveur : **57.128.201.76** (Ubuntu, Apache/2.4.63).

Notation : `[local]` = votre machine, `[serveur]` = en SSH sur la production.

---

## Ce qui a été relevé sur le serveur le 10 septembre 2026

Trois constats, dont un bloquant.

**Le domaine ne pointe pas encore sur ce serveur.**
`app.thaliaeats.com` résout vers **180.149.198.82**, pas vers 57.128.201.76 —
et cette adresse ne répond pas en HTTPS. Conséquences directes :

- le vhost `ServerName app.thaliaeats.com` ne recevra aucun trafic tant que
  l'enregistrement DNS n'est pas modifié ;
- **`certbot` échouera**, car la validation HTTP-01 se fait sur l'adresse que
  le DNS désigne.

C'est une action chez votre registraire, et personne d'autre que vous ne peut
la faire. Deux ordres possibles, voir l'étape 7.

**Le serveur héberge déjà une autre application Laravel.**
Les ports 80 et 443 répondent par une redirection vers `/admin`, avec un
cookie de session sur le domaine `.sims-opportunity.com`. Notre vhost
s'**ajoute** : il ne doit surtout pas devenir le vhost par défaut, sinon il
capterait le trafic destiné à cette application. Contrôle à l'étape 7.

**MySQL n'est pas exposé.** Le port 3306 est fermé depuis l'extérieur, ce qui
est bien — et sans objet ici : la base vit dans un conteneur, sur le réseau
interne du projet, sans port publié.

---

## Ce qui va tourner

```
Internet ──443──> Apache2 (TLS, vhost) ──> 127.0.0.1:8096
                                                  │
                                    ┌─────────────┴──────────────┐
                                    │  app   nginx + php-fpm     │
                                    │   │                        │
                                    │   └── db   MySQL 8.4       │
                                    │       (aucun port publié)  │
                                    └────────────────────────────┘
                                         réseau interne du projet
```

**Aucun port public.** L'application n'écoute que sur `127.0.0.1:8096`, la
base sur rien du tout : elle n'est joignable que depuis le réseau interne du
projet, par le nom `db`. Apache est le seul chemin vers l'application, et
garde le TLS — les autres applications du serveur ne sont pas touchées, et la
bascule se fait par un simple `reload` d'Apache.

Le MySQL déjà installé sur l'hôte **n'est pas utilisé** : rien à y modifier,
aucun compte à y créer.

---

## Conventions de ce serveur

Connect RDC est déjà déployé sur `57.128.201.76`. Son `DEPLOY.md` et son
`docker-compose.prod.yml` donnent les usages de la machine, et ce déploiement
s'y aligne — sauf sur trois points, délibérés.

### Ports de boucle locale déjà pris

Son compose publie sur `127.0.0.1` :

| Port | Service |
|---|---|
| 8090 | `api` (Laravel) |
| 8091 | `landing` (Astro) |
| 8093 | `admin` |
| **8096** | **Thalia Eats — ce déploiement** |

Attention : son `DEPLOY.md` annonce 8080-8083, mais le compose dit 8090+.
**C'est le compose qui fait foi** — j'avais d'abord choisi 8091, qui aurait
collisionné avec son landing. Vérifier avant de démarrer :

```bash
ss -ltnp | grep -E '80(8|9)[0-9]'
```

### MySQL : 8.0 en local, 8.4 sur le serveur

Le dump part d'un MySQL **8.0.46** et s'importe dans un **8.4.7** : sens
supporté, et le dump ne contient aucune syntaxe retirée en 8.4. Deux points à
connaître tout de même.

`CREATE USER ... IDENTIFIED BY` crée un compte en `caching_sha2_password` sur
8.4, `mysql_native_password` n'y étant plus activé par défaut. PDO le gère
depuis PHP 7.4, l'image tourne en 8.3 : rien à faire.

Root MySQL a un mot de passe sur ce serveur — `sudo mysql` est refusé. Voir
l'étape 3 pour les deux accès administrateur possibles.

### Ce qui est aligné

**Aucun port public.** Comme Connect RDC, le conteneur publie sur
`127.0.0.1:<port>` et rien d'autre. C'est Apache qui expose, et lui seul.

**`./deploy.sh`.** Mêmes options (`--no-build`, `--migrate`, `--prune`,
`--help`), même sortie colorée, même récapitulatif. L'exploitation des deux
projets se ressemble.

**Environnement Laravel dans un fichier séparé et non versionné.** Connect RDC
a `api_connect/.env.docker` à partir d'un `.env.docker.example` ; ici,
`deploy/.env.production`, généré depuis le `.env` local.

### Ce qui diverge, et pourquoi

**Déploiement par git, comme Connect RDC.** Le dossier `deploy/` est
versionné : c'est par là qu'il atteint le serveur. Seuls les deux `.env`
renseignés et les dumps restent hors du dépôt, via `.gitignore` — un `git
pull` ne touche pas aux fichiers ignorés, les secrets du serveur survivent
donc à chaque livraison.

Le premier déploiement, lui, s'est fait par `rsync` (étape 2) : le dépôt
n'était pas encore prêt à porter la configuration. Le passage au clone git est
décrit à l'étape 10.

**Pas de migration automatique.** Connect RDC met `RUN_INIT=true` sur son
service `php` : migrations et caches à chaque démarrage. Sur une base neuve,
c'est confortable. Ici la base porte des commandes et des paiements réels dès
le premier jour — importés du dump local — et une migration silencieuse au
redémarrage d'un conteneur n'est pas un risque acceptable. Elle se demande :
`./deploy.sh --migrate`.

**La base est conteneurisée, comme celle de Connect RDC.** Elle vit dans le
service `db` avec un volume nommé, sans aucun port publié : seul le service
`app` la joint, par le nom `db`, sur le réseau interne du projet.

Le serveur fait déjà tourner un MySQL 8.4 pour ses autres applications. En
ajouter un second coûte de la mémoire — comptez 300 à 400 Mo au repos. C'est
le prix de l'indépendance : aucune modification du MySQL existant, aucun
compte à créer dessus, et un `docker compose down` n'emporte rien d'autre que
Thalia.

**Apache, pas Caddy.** Son `DEPLOY.md` propose Caddy ou Cloudflare, mais la
machine répond aujourd'hui en `Apache/2.4.63`. C'est donc un vhost Apache en
reverse proxy — voir `apache-thalia-http.conf`.

---

## 0. Prérequis à vérifier `[serveur]`

```bash
docker --version && docker compose version
apache2 -v

# Le port de boucle locale est-il libre ? (d'autres conteneurs tournent)
ss -ltnp | grep -E '80(8|9)[0-9]' || echo "plage 8080-8099 libre"

# Modules Apache nécessaires
sudo a2enmod proxy proxy_http headers remoteip rewrite
```

`a2enmod` conclut par « you need to run: systemctl restart apache2 ».
**Ne le faites pas maintenant.** Le message est générique, et un `restart` sur
cette machine coupe brièvement toutes les applications qu'elle héberge. Les
modules nouvellement activés seront chargés par le `reload` de l'étape 7, en
même temps que notre vhost — `apache2ctl configtest` les voit dès que
`a2enmod` a posé le lien, donc rien ne manquera au contrôle.

État relevé le 11 septembre 2026 : `proxy`, `proxy_http`, `headers` et
`rewrite` étaient déjà actifs, seul `remoteip` a été ajouté. Ports de boucle
locale déjà pris : **8080, 8090, 8091, 8093** — `8096` est libre.

La base vivant dans un conteneur, **le MySQL de l'hôte n'est plus sollicité** :
ni mot de passe root, ni compte à créer, ni socket à localiser. Le blocage
rencontré sur `sudo mysql` — root protégé par mot de passe — n'a plus d'objet.

Si `8096` est pris, choisissez un autre port et reportez-le **aux deux
endroits** : `deploy/.env` (`APP_PORT`) et `deploy/apache-thalia-http.conf`
(`ProxyPass`).

---

## 1. Arborescence `[serveur]`

Le code et les données sont séparés : un redéploiement remplace le code, jamais
les données.

```bash
sudo mkdir -p /srv/thalia-eats/code
sudo mkdir -p /srv/thalia-eats/data/{storage,images}
sudo chown -R "$USER":"$USER" /srv/thalia-eats
```

---

## 2. Transfert `[local]`

```bash
cd "/home/simisi/Documents/Projects/Thalia eats/back end"

# Utilisateur SSH du serveur : remplacer « ubuntu » par le vôtre.
SRV=ubuntu@57.128.201.76

# 2a. Le code. vendor/, storage/, les images et les dumps sont exclus :
#     vendor est reconstruit par Composer dans l'image, le reste voyage à part.
rsync -avz --delete \
      --exclude-from=deploy/rsync-exclude.txt \
      ./ $SRV:/srv/thalia-eats/code/

# 2b. Les images locales (29 Mo) vers le volume de données.
#     Rien pour public/documents : c'est un lien symbolique cassé du dépôt,
#     hérité d'un ancien hébergement cPanel, sans rien derrière.
rsync -avz public/images/ $SRV:/srv/thalia-eats/data/images/

# 2c. Les identifiants Firebase, que la configuration attend à
#     storage/firebase_credentials.json.
rsync -avz storage/firebase_credentials.json \
      $SRV:/srv/thalia-eats/data/storage/

# 2d. La base locale. Le dump doit être refait juste avant l'envoi : celui du
#     dossier transfert/ vieillit dès que vous utilisez l'application.
DEF=$(mktemp); chmod 600 "$DEF"
printf "[client]\nuser=%s\npassword=%s\n" \
    "$(grep -oP '(?<=DB_USERNAME=)\S+' .env)" \
    "$(grep -oP '(?<=DB_PASSWORD=)\S+' .env)" > "$DEF"
mysqldump --defaults-file="$DEF" --single-transaction --routines --triggers \
          --no-tablespaces --default-character-set=utf8mb4 thalia_eats \
    | gzip > deploy/transfert/thalia_eats.sql.gz
rm -f "$DEF"

rsync -avz deploy/transfert/thalia_eats.sql.gz $SRV:/srv/thalia-eats/
```

### Ce que le transfert représente

| Contenu | Taille | Destination |
|---|---|---|
| Code + `deploy/` — 500 fichiers | 5,3 Mo | `/srv/thalia-eats/code/` |
| Base, 42 tables | 36 Ko | importée dans le MySQL de l'hôte |
| `public/images`, 102 fichiers | 29 Mo | `/srv/thalia-eats/data/images/` |
| `firebase_credentials.json` | 4 Ko | `/srv/thalia-eats/data/storage/` |

Environ **35 Mo au total**. `vendor/` n'est pas transféré — Composer le
reconstruit dans l'image — et les deux dumps `backup.sql` et `backup2.sql`
committés dans le dépôt, 570 Mo à eux deux, sont exclus : ce sont d'anciennes
sauvegardes sans rapport avec la base actuelle.

**`--delete` n'efface pas `.env.production` sur le serveur.** rsync protège
les fichiers exclus côté destination — vérifié plutôt que supposé. Le fichier
de secrets que vous remplissez à l'étape 4 survit donc à tous les
redéploiements. Il faudrait `--delete-excluded` pour l'effacer, et cette
option n'est nulle part ici.

**Aucun secret ne part.** `deploy/.env.production` est exclu du rsync ; seul
`deploy/.env.production.example`, vidé de ses 14 valeurs sensibles, voyage.
`deploy/.env` part aussi, mais il ne contient que le port, les chemins de
données et l'étiquette d'image — rien de confidentiel.

`storage/app/public` est vide en local : rien à envoyer de ce côté. Le dossier
est recréé par l'entrypoint, avec `uploads/document` que le disque
`uploads_document` attend.

Si vous avez un alias dans `~/.ssh/config`, `SRV=mon-alias` suffit.

---

## 3. Base de données `[serveur]` — rien à faire

L'import est automatique, et c'est le seul endroit du déploiement où quelque
chose se fait tout seul.

L'image officielle MySQL exécute les fichiers de
`/docker-entrypoint-initdb.d` **uniquement si son volume de données est
vide** — donc au tout premier démarrage, et jamais ensuite. Le compose y monte
le dump transféré à l'étape 2d, en `.sql.gz` que l'entrypoint de l'image
décompresse lui-même.

Le conteneur crée aussi la base et l'utilisateur, depuis `DB_DATABASE`,
`DB_USERNAME` et `DB_PASSWORD` de `deploy/.env`. Il n'y a donc ni `CREATE
DATABASE`, ni `GRANT`, ni `gunzip | mysql` à lancer.

Deux conséquences à connaître.

**Le premier démarrage est plus long.** La base ne répond pas pendant
l'import ; `deploy.sh` attend sa sonde de santé, dont la période de grâce est
de deux minutes. Ce n'est pas une panne.

**Un second `up` ne réimporte rien.** Si vous devez repartir d'une base
vierge — et seulement dans ce cas, car cela détruit les données :

```bash
docker compose down
docker volume rm thalia-eats-db-data
docker compose up -d
```

Le contrôle des 42 tables se fait à l'étape 5, une fois les conteneurs
démarrés.

---

## 4. Configuration `[serveur]`

```bash
cd /srv/thalia-eats/code/deploy
```

Deux fichiers à créer depuis leurs modèles. Aucun des deux ne voyage : ils
portent des secrets.

```bash
cp .env.example .env                       && chmod 600 .env
cp .env.production.example .env.production && chmod 600 .env.production
```

**`deploy/.env`** — variables de Compose. `DB_PASSWORD` et
`DB_ROOT_PASSWORD` y sont vides, et `deploy.sh` refuse de démarrer tant
qu'elles le sont.

```bash
openssl rand -hex 24    # DB_PASSWORD
openssl rand -hex 24    # DB_ROOT_PASSWORD
```

**En hexadécimal, pas en base64.** Ce fichier est lu par Docker Compose, et
ses valeurs sont injectées dans `docker-compose.yml` — dont la sonde de la
base, qui passe le mot de passe root à `mysqladmin`. Un `$` y déclencherait
une interpolation, un `#` une fin de ligne, une apostrophe casserait la
commande. L'hexadécimal n'a aucun de ces caractères, et 24 octets font 48
caractères : largement assez.

`DB_USERNAME` et `DB_PASSWORD` devront être **identiques** dans
`.env.production`.

| Clé | Valeur |
|---|---|
| `APP_PORT` | `8096`, ou le port libre choisi à l'étape 0 |
| `DATA_DIR` | `/srv/thalia-eats/data` |
| `DB_DATABASE` | `thalia_eats` |
| `DB_USERNAME` | `thalia` |
| `DB_PASSWORD` | **à générer** — le même que dans `.env.production` |
| `DB_ROOT_PASSWORD` | **à générer** — administration de la base uniquement |
| `DUMP_FILE` | `/srv/thalia-eats/thalia_eats.sql.gz` (étape 2d) |
| `IMAGE_TAG` | une date, `2026-09-11`, pour pouvoir revenir en arrière |
| `THALIA_RUN_MIGRATIONS` | `false` — on migre à la main à l'étape 6 |

**`deploy/.env.production`** — environnement de Laravel. Le modèle contient toutes les clés, avec **14 valeurs vides** à reporter
depuis votre gestionnaire de mots de passe — la liste est dans son en-tête :
`DB_PASSWORD`, `FlEX_PAY_TOKEN`, les trois clés Pusher, les deux
Google, les deux AWS, `GOOGLE_MAPS_API_KEY`, `MAIL_PASSWORD`,
`REDIS_PASSWORD`.

Ces clés sont repérées par règle et non par liste : un nom évoquant un
identifiant, ou une valeur ressemblant à un secret. Une liste écrite à la main
avait laissé passer `FlEX_PAY_TOKEN` — le jeton marchand FlexPay — à cause de
sa casse inhabituelle.

**Quatre valeurs sont posées d'office et méritent une vérification :**

| Clé | Valeur posée | À vérifier |
|---|---|---|
| `APP_URL` | `https://app.thaliaeats.com` | base des URL d'images et des liens de paiement signés |
| `DB_USERNAME` | `thalia` | jamais `root` |
| `DB_SOCKET` | `/var/run/mysqld/mysqld.sock` | chemin réel relevé à l'étape 0 |
| `SANCTUM_STATEFUL_DOMAINS` | `app.thaliaeats.com` | le domaine public |

`DB_PASSWORD` est vide dans le modèle : y mettre celui de l'étape 3a.

**`APP_KEY` : générez-en une nouvelle**, ne reprenez pas celle du poste de
développement.

```bash
echo "base64:$(openssl rand -base64 32)"
```

Vérifié le 11 septembre 2026 : rien dans cette base n'est chiffré avec
`APP_KEY` — aucun cast `encrypted`, aucun appel à `Crypt::`, `encrypt()` ou
`decrypt()`, aucun réglage Spatie chiffré. `Cipher`, qui produit les `uid`,
a sa propre clé en dur et n'en dépend pas.

Recopier la clé locale était donc inutile, et moins sûr : une clé de
production n'a pas à vivre sur un portable. Recopier un secret de 44
caractères dans un terminal échoue par ailleurs facilement — `nano` replie les
longues lignes, et une sélection à la souris s'arrête au bord de l'écran.

Ce qu'une nouvelle clé invalide : les sessions et cookies en cours, et les
liens de paiement signés déjà émis. **Pas les jetons Sanctum** de
l'application mobile, hachés en SHA-256 : personne n'est déconnecté.

---

## 5. Construction et démarrage `[serveur]`

```bash
cd /srv/thalia-eats/code/deploy

./deploy.sh                   # ~3 à 5 min au premier passage
```

Le script vérifie les dossiers de données, refuse de partir si un mot de passe
MySQL est vide, contrôle la présence du dump, construit, démarre les deux
conteneurs, attend que la base réponde, teste la connexion applicative, puis
récapitule.

**Le premier démarrage est plus long** : MySQL importe le dump avant de
répondre. La sonde a deux minutes de grâce ; ce n'est pas une panne.

Contrôle des données importées, une fois démarré :

```bash
docker compose exec -T db sh -c \
  'mysql -u root -p"$MYSQL_ROOT_PASSWORD" -e \
   "SELECT COUNT(*) AS tables FROM information_schema.tables \
    WHERE table_schema=\"$MYSQL_DATABASE\";"'
```

→ doit afficher **42**.

En cas de doute :

```bash
docker compose logs -f app    # Ctrl+C pour sortir
```

Au démarrage, l'entrypoint annonce chaque étape. Ce qu'il faut voir :

```
[thalia] preparation des dossiers de travail
[thalia] lien public/storage refait
[thalia] base joignable
[thalia] mise en cache de la configuration
[thalia] pret sur le port 8080
```

Si vous lisez `ATTENTION : la base n'est pas joignable`, le conteneur démarre
quand même : reprenez `DB_SOCKET`, `DB_USERNAME` et `DB_PASSWORD`, puis
`docker compose up -d --force-recreate`.

Contrôle immédiat, avant même Apache :

```bash
curl -s http://127.0.0.1:8096/healthz     # doit rendre : ok
```

---

## 6. La migration en attente `[serveur]`

La base locale a **une migration de retard** sur le code :
`2026_09_05_190000_rendre_coordonnees_livraison_nullables_sur_precommandes`.
Elle rend trois colonnes de `precommandes` nullables — additive, sans perte.

```bash
docker compose exec app php artisan migrate:status | grep -i pending
docker compose exec app php artisan migrate --force
```

**Ne jamais lancer `db:seed`.** `script-run.sh` le fait en production, mais sur
une base qui porte déjà les données importées un seeder non idempotent
dupliquerait les lignes de référence (statuts, communes, tarifs).

---

## 7. Apache `[serveur]`

```bash
sudo cp /srv/thalia-eats/code/deploy/apache-thalia-http.conf \
        /etc/apache2/sites-available/thalia-eats.conf

# Adapter ServerName et le port du ProxyPass. Aucune directive SSL a toucher :
# certbot ecrira le vhost 443 lui-meme.
sudo nano /etc/apache2/sites-available/thalia-eats.conf

sudo a2ensite thalia-eats
sudo apache2ctl configtest        # doit dire : Syntax OK
                                  # erreur sur RemoteIPHeader ? le module
                                  # remoteip manque : sudo a2enmod remoteip
sudo apache2ctl -S                # « default server » ne doit PAS être thalia
sudo systemctl reload apache2     # reload, JAMAIS restart
```

Ce vhost ne declare **aucun certificat**, et c'est delibere : un
`SSLCertificateFile` pointant vers un fichier absent fait echouer
`configtest`. Un `reload` refuserait alors de s'appliquer — sans degat — mais
un `restart` empecherait Apache de demarrer, et **toutes** les applications du
serveur tomberaient. Le vhost 443 est ecrit par certbot, une fois le
certificat obtenu. Voir `apache-apres-certbot.md` : trois en-tetes y restent a
ajouter a la main.

### Le vhost ne doit pas devenir celui par défaut

Une autre application occupe déjà les ports 80 et 443. Après activation :

```bash
sudo apache2ctl -S
```

La ligne `default server` doit continuer de désigner l'application existante,
pas `thalia-eats.conf`. Apache retient comme défaut le **premier** vhost
chargé, dans l'ordre alphabétique des fichiers de `sites-enabled` : un nom
commençant par `thalia-` passe après `000-default` et après
`sims-opportunity`. Si ce n'était pas le cas, renommez le fichier avec un
préfixe plus tardif (`zz-thalia-eats.conf`).

### Le certificat, et l'ordre des opérations

`certbot` valide en HTTP-01 : il faut donc que le DNS désigne déjà ce serveur.
Deux ordres possibles.

**Option A — basculer le DNS d'abord.** Chez votre registraire, faites
pointer `app.thaliaeats.com` (enregistrement A) sur `57.128.201.76`, attendez
la propagation, puis :

```bash
dig +short app.thaliaeats.com          # doit rendre 57.128.201.76
sudo certbot --apache -d app.thaliaeats.com
```

Puis suivre `deploy/apache-apres-certbot.md`.

Le site est indisponible entre la bascule DNS et l'obtention du certificat.
Court, mais réel.

**Option B — recette sur un sous-domaine, puis bascule.** Pointez d'abord un
nom neuf, par exemple `api.thaliaeats.com`, sur `57.128.201.76`, déployez et
recettez dessus, puis basculez `app.thaliaeats.com` une fois tout vérifié.
Aucune coupure : le domaine de production ne bouge qu'à la fin. C'est l'ordre
que je recommande pour un premier déploiement.

### Recetter avant même de toucher au DNS

`--resolve` fait croire à curl que le domaine pointe ici, sans rien changer
nulle part :

```bash
curl -sI --resolve app.thaliaeats.com:443:57.128.201.76 \
     https://app.thaliaeats.com/healthz | head -1
```

Le certificat ne correspondra pas encore : ajoutez `-k` pour ignorer cet
avertissement précis, il est attendu à ce stade.

---

## 8. Recette `[serveur]` puis `[local]`

```bash
# Le conteneur répond derrière Apache, en https.
curl -sI https://app.thaliaeats.com/healthz | head -1

# L'API répond et parle à la base.
curl -s "https://app.thaliaeats.com/api/products/search?q=a" | head -c 300

# Les images locales sont servies. Prendre un nom réel :
ls /srv/thalia-eats/data/images | head -3
curl -sI https://app.thaliaeats.com/images/UN_FICHIER.jpg | head -1   # 200 attendu

# L'administration se charge.
curl -sI https://app.thaliaeats.com/admin | head -1
```

Puis, depuis l'application mobile et le web, en conditions réelles :
connexion, liste des restaurants, affichage des images, une commande de bout
en bout.

**Le webhook FlexPay** est le point à vérifier en dernier et le plus
important : il est appelé par le prestataire, depuis l'extérieur. Confirmez
que `https://app.thaliaeats.com/api/webhook-paiement-flexpay` est joignable
publiquement et que l'URL déclarée chez FlexPay pointe bien là.

---

## 9. Exploitation

### Les droits sur `storage` après le premier démarrage

L'entrypoint fait `chown -R www-data` sur `storage`, qui est un montage lié :
la propriété change donc **aussi sur le serveur**, au profit de l'uid 33.
Votre utilisateur ne pourra plus y écrire directement. Ce n'est pas un
problème — les journaux se lisent par Docker, pas par le système de fichiers :

```bash
cd /srv/thalia-eats/code/deploy

docker compose logs -f app              # journaux applicatifs (LOG_CHANNEL=stderr)
docker compose ps                       # état, dont la santé
docker compose restart app
docker compose exec app php artisan tinker
```

**Redéployer une nouvelle version** — depuis `[local]`, refaire l'étape 2a,
puis sur le serveur :

```bash
cd /srv/thalia-eats/code/deploy
./deploy.sh                    # build + up + health + récap
./deploy.sh --migrate          # si la livraison apporte des migrations
```

Le code est remplacé, `/srv/thalia-eats/data` ne l'est jamais.

**Revenir en arrière.** Les images précédentes restent locales :

```bash
docker images thalia-eats-backend
# éditer IMAGE_TAG dans deploy/.env vers l'étiquette précédente, puis
docker compose up -d
```

**Sauvegarder la base** — à mettre en tâche planifiée dès le premier jour :

La base n'est plus celle de l'hôte : le dump passe par le conteneur. Et par
`mysqldump`, jamais par une copie du volume — copier `/var/lib/mysql` à chaud
produit une sauvegarde incohérente.

```bash
mkdir -p /srv/thalia-eats/sauvegardes
cd /srv/thalia-eats/code/deploy

docker compose exec -T db sh -c \
  'mysqldump --single-transaction --routines --triggers --no-tablespaces \
             -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
  | gzip > /srv/thalia-eats/sauvegardes/$(date +%F).sql.gz
```

Le mot de passe est lu dans l'environnement du conteneur : il n'apparaît ni
dans la ligne de commande, ni dans l'historique du shell, ni dans la liste des
processus de l'hôte.

À mettre en tâche planifiée **dès le premier jour**, et à recopier hors du
serveur : une sauvegarde qui vit sur la machine qu'elle protège n'en est pas
une.

---

## 10. Passer au déploiement par git

Le dossier `/srv/thalia-eats/code` a été rempli par `rsync` : ce n'est pas un
clone git, et `deploy.sh` le détecte et ignore le `pull`. Voici la bascule.

### 10a. Le serveur doit pouvoir lire le dépôt `[serveur]`

Le remote est en HTTPS (`https://github.com/siska243/thalia_eats_admin.git`) :
sur un dépôt privé, un `git pull` demanderait un mot de passe à chaque fois.
Une **clé de déploiement** en lecture seule règle ça proprement.

```bash
ssh-keygen -t ed25519 -C "thalia-eats@57.128.201.76" -f ~/.ssh/thalia_deploy -N ""
cat ~/.ssh/thalia_deploy.pub
```

Collez cette clé publique dans GitHub → dépôt `thalia_eats_admin` →
Settings → Deploy keys → Add deploy key. **Ne cochez pas « Allow write
access »** : le serveur n'a aucune raison de pouvoir pousser.

```bash
cat >> ~/.ssh/config <<'CONF'
Host github-thalia
    HostName github.com
    User git
    IdentityFile ~/.ssh/thalia_deploy
    IdentitiesOnly yes
CONF
chmod 600 ~/.ssh/config

ssh -T github-thalia    # « successfully authenticated » attendu
```

### 10b. Cloner à côté, puis basculer `[serveur]`

On clone dans un dossier neuf et on ne remplace l'ancien qu'à la fin : si le
clone échoue, rien n'est cassé, et l'application continue de tourner.

```bash
cd /srv/thalia-eats

# Les deux .env renseignés ne sont pas dans le dépôt : on les met de côté.
cp code/deploy/.env            env-compose.sauv
cp code/deploy/.env.production env-laravel.sauv
chmod 600 env-*.sauv

git clone -b LA_BRANCHE github-thalia:siska243/thalia_eats_admin.git code-git

cp env-compose.sauv code-git/deploy/.env
cp env-laravel.sauv code-git/deploy/.env.production
chmod 600 code-git/deploy/.env code-git/deploy/.env.production
```

Vérifiez que le clone est complet **avant** de basculer :

```bash
ls code-git/deploy/          # doit contenir Dockerfile, docker-compose.yml, deploy.sh…
test -x code-git/deploy/deploy.sh && echo "deploy.sh exécutable"
```

La bascule, puis le redémarrage :

```bash
cd /srv/thalia-eats
mv code code-rsync-$(date +%F)      # conservé, au cas où
mv code-git code

cd code/deploy
./deploy.sh --no-pull               # déjà à jour, inutile de tirer
```

Une fois l'application confirmée en ligne, l'ancien dossier peut partir :

```bash
rm -rf /srv/thalia-eats/code-rsync-*
rm -f  /srv/thalia-eats/env-*.sauv
```

### 10c. Les livraisons suivantes `[serveur]`

```bash
cd /srv/thalia-eats/code/deploy
./deploy.sh                    # pull + build + up + health
./deploy.sh --migrate          # si la livraison apporte des migrations
```

`deploy.sh` tire avec `--ff-only` : si l'historique a divergé, ou si quelqu'un
a modifié un fichier suivi directement sur le serveur, le déploiement
s'arrête au lieu de créer un merge sur la machine de production.

### Ce qui ne passera jamais par git

| Fichier | Où il vit | Comment il arrive |
|---|---|---|
| `deploy/.env` | dans le clone, ignoré par git | à la main, une fois |
| `deploy/.env.production` | dans le clone, ignoré par git | à la main, une fois |
| `storage/firebase_credentials.json` | `/srv/thalia-eats/data/storage/` | `rsync`, hors du dossier de code |
| le dump de base | `/srv/thalia-eats/` | `rsync`, premier déploiement seulement |

---

## Ce qui a été corrigé au passage, et pourquoi

**Deux liens symboliques du dépôt sont cassés**, tous deux hérités
d'hébergements précédents.

`public/storage` pointe vers `…/thalia_eats_admin/storage/app/public`, un
chemin d'une ancienne machine de développement. `public/documents` pointe vers
`/home/c2176201c/public_html/thalia/storage/app/public/uploads/document`, un
chemin cPanel. Les documents du disque `uploads_document` ne sont donc pas
servis aujourd'hui, sur aucun des deux chemins.

L'entrypoint refait `public/storage` en relatif à chaque démarrage — l'image
ne dépend ainsi d'aucun chemin d'hôte — et crée
`storage/app/public/uploads/document`, la vraie racine de ce disque.
`public/documents` n'est pas recréé : la configuration ne l'utilise pas, son
`url` étant `APP_URL . '/storage/uploads/document'`.

**Les URL seraient sorties en `http://`.**
`app/Http/Middleware/TrustProxies.php` a `$proxies` à `null` : Laravel
n'accorde aucune confiance à l'en-tête d'Apache. Derrière un TLS terminé par
un proxy, il aurait construit toutes ses URL en clair — liens de paiement
signés invalides, admin Filament en contenu mixte. C'est nginx qui traduit
l'en-tête en variables serveur (`deploy/nginx-forwarded.conf`), afin de ne pas
modifier un fichier versionné. Si vous préférez le corriger dans
l'application, `protected $proxies = '*';` fait la même chose et rend ce
fichier de configuration inutile.

**Le pipeline d'assets n'est pas construit.** Aucune vue n'appelle `@vite`, et
les assets de Filament sont déjà publiés dans `public/css` et `public/js` :
Node n'entre pas dans l'image, ce qui la garde légère.
