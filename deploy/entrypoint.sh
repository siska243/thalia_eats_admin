#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

echo "[thalia] preparation des dossiers de travail"

# storage est monte depuis l'hote : ses sous-dossiers peuvent manquer, et
# Laravel ne les cree pas de lui-meme. Une session ou un cache de vue qui ne
# peut pas s'ecrire fait echouer la premiere requete avec une 500 opaque.
mkdir -p \
    storage/app/public/uploads/document \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

# Le lien public/storage du depot pointe vers un ancien chemin de la machine
# de developpement (« thalia_eats_admin ») qui n'existe plus : il est casse,
# et les documents du disque « uploads_document » ne sont donc pas servis. On
# le refait ici, relatif, pour que l'image ne depende d'aucun chemin d'hote.
if [ ! -e public/storage ] || [ ! -d public/storage ]; then
    rm -f public/storage
    ln -sfn ../storage/app/public public/storage
    echo "[thalia] lien public/storage refait"
fi

# La decouverte des paquets n'a pas pu avoir lieu a la construction : elle
# demarre l'application, donc exige la configuration.
php artisan package:discover --ansi

echo "[thalia] verification de la base"

if php artisan db:show --quiet >/dev/null 2>&1; then
    echo "[thalia] base joignable"
else
    cat >&2 <<'MSG'
[thalia] ATTENTION : la base n'est pas joignable.

La base vit dans le conteneur « db », sur le reseau interne du projet.
Verifier dans .env.production :
  DB_HOST      doit valoir « db », le nom du service — pas 127.0.0.1, qui
               designerait ce conteneur-ci.
  DB_USERNAME  et DB_PASSWORD doivent etre identiques a ceux de deploy/.env,
               d'ou le conteneur db tire le compte qu'il cree.

Le conteneur demarre quand meme : toute page touchant la base rendra une
erreur, mais les journaux resteront lisibles.
MSG
fi

# Les migrations ne partent JAMAIS toutes seules : la base visee est la base
# locale reelle, avec ses donnees. Pour les lancer, demarrer avec
# THALIA_RUN_MIGRATIONS=true — et jamais db:seed, que script-run.sh execute en
# production mais qui n'a rien a faire ici.
if [ "${THALIA_RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "[thalia] migrations demandees explicitement"
    php artisan migrate --force --ansi
fi

echo "[thalia] mise en cache de la configuration"

php artisan config:clear --ansi
php artisan config:cache --ansi
php artisan view:cache --ansi

# route:cache echoue si une route porte une closure ; l'application demarre
# tres bien sans, on n'en fait donc pas une condition de demarrage.
php artisan route:cache --ansi || echo "[thalia] route:cache ignore (closure dans les routes)"

# Les composants Filament sont mis en cache comme le fait script-run.sh.
php artisan filament:optimize-clear --ansi >/dev/null 2>&1 || true
php artisan filament:optimize --ansi || echo "[thalia] filament:optimize ignore"

# Le cache de permissions de spatie, vide a chaque demarrage.
#
# Ce projet n'a AUCUN Gate::before : le role super_admin n'a pas de
# laissez-passer, tous ses droits passent par ses 224 permissions, que spatie
# lit dans son cache et non en base. Or storage/framework/cache est monte
# depuis l'hote : un cache ecrit pendant un demarrage rate — base pas encore
# importee, APP_KEY manquante — survit a tous les redemarrages suivants et
# refuse l'administration pendant 24 heures, alors que la base est correcte.
#
# Le symptome est trompeur : hasRole() repond vrai, parce que les ROLES ne
# sont pas caches, seules les PERMISSIONS le sont.
php artisan permission:cache-reset --ansi >/dev/null 2>&1 \
    || echo "[thalia] cache de permissions non vide (base injoignable ?)"

# Ces deux commandes lisent la base : elles echouent si elle n'est pas
# joignable, et ne doivent pas empecher le conteneur de demarrer — sinon un
# MySQL momentanement arrete se transforme en boucle de redemarrage.
php artisan settings:clear-cache --ansi >/dev/null 2>&1 || true
php artisan storage:link --ansi >/dev/null 2>&1 || true

# db:seed n'est jamais lance ici. script-run.sh l'execute en production, mais
# sur une base qui porte deja les donnees importees, un seeder non idempotent
# dupliquerait des lignes de reference.

echo "[thalia] pret sur le port 8080"

exec "$@"
