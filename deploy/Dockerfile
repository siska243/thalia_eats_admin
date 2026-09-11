# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Etape 1 : socle
#
# PHP et ses extensions, partages par l'etape des dependances et par l'image
# finale. Composer resout donc ses contraintes sur exactement la plateforme
# d'execution : une extension presente dans l'image officielle composer mais
# absente ici — ou l'inverse — ne peut plus faire divergier l'installation.
#
# Les versions suivent la machine de developpement : PHP 8.3, et les
# extensions effectivement chargees par le serveur local. imagick et redis en
# sont absents, aucun code ne les utilise et le cache est en fichier.
# ---------------------------------------------------------------------------
FROM php:8.3-fpm-bookworm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        default-mysql-client \
        libicu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libwebp-dev \
        libonig-dev \
        libxml2-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        sockets \
        zip \
    && rm -rf /var/lib/apt/lists/*

# Les paquets -dev ne sont volontairement pas purges.
#
# `apt-get purge --auto-remove libicu-dev` emporte libicu des que plus aucun
# paquet ne le reclame — or l'extension intl compilee s'y lie sans qu'apt en
# sache rien. L'image se construirait, puis toute page touchant intl
# tomberait a l'execution. Les images officielles PHP contournent ca en
# relisant les dependances des .so avec ldd avant de purger ; c'est efficace
# mais delicat, et cette image n'a pas pu etre construite ici pour le
# verifier. Soixante megaoctets valent mieux qu'une extension manquante
# decouverte en production.


# ---------------------------------------------------------------------------
# Etape 2 : dependances PHP
#
# Les scripts Composer sont desactives : post-autoload-dump lance
# package:discover, qui demarre l'application et exige donc une configuration
# valide — ce qu'une image en construction n'a pas. La decouverte a lieu au
# demarrage du conteneur, dans l'entrypoint.
#
# --classmap-authoritative est volontairement absent : il fait echouer toute
# resolution de classe absente du classmap, ce qui casse le chargement
# dynamique sans prevenir. --optimize suffit.
# ---------------------------------------------------------------------------
FROM base AS vendor

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress

COPY . .

# --no-scripts ici AUSSI, et c'est le piege : dump-autoload declenche
# post-autoload-dump, donc `artisan package:discover`, qui demarre
# l'application. Elle echoue avec « Please provide a valid cache path » parce
# que storage/ est exclu du contexte de build — il est monte depuis l'hote au
# demarrage. La decouverte des paquets a lieu dans l'entrypoint, ou storage
# existe.
RUN composer dump-autoload --no-dev --optimize --no-scripts


# ---------------------------------------------------------------------------
# Etape 3 : image applicative
#
# nginx et php-fpm dans un seul conteneur, sous supervisord. Deux conteneurs
# seraient plus orthodoxes, mais l'application joint MySQL par le socket Unix
# de l'hote : un seul conteneur, donc un seul montage de ce socket.
# ---------------------------------------------------------------------------
FROM base AS app

COPY deploy/php.ini /usr/local/etc/php/conf.d/zz-thalia.ini
COPY deploy/nginx.conf /etc/nginx/sites-available/default
# Les directives map vivent dans le contexte http, pas dans un server.
COPY deploy/nginx-forwarded.conf /etc/nginx/conf.d/thalia-forwarded.conf
COPY deploy/supervisord.conf /etc/supervisor/conf.d/thalia.conf
COPY deploy/entrypoint.sh /usr/local/bin/entrypoint

RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www/html

COPY --from=vendor /app /var/www/html

# storage, public/images et public/documents sont montes depuis l'hote au
# demarrage : ce chown ne concerne que ce qui reste dans l'image.
RUN chown -R www-data:www-data /var/www/html

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf", "-n"]
