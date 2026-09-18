#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Thalia Eats — veilleur de déploiement.
#
# Lancé par thalia-deploy.timer toutes les deux minutes. Il compare le commit
# local au commit distant de la branche suivie, et n'appelle ./deploy.sh que
# s'ils diffèrent. Sans changement, il ne fait qu'un `git fetch` et sort.
#
# Le serveur interroge GitHub — GitHub ne joint jamais le serveur. Aucun port
# à ouvrir, aucune clé privée à confier à un tiers : la clé de déploiement en
# lecture seule déjà en place suffit. Sur une machine qui héberge onze autres
# applications, c'est ce qui limite le mieux la surface d'attaque.
#
# Journal : journalctl -u thalia-deploy -f
# Dernier état : /srv/thalia-eats/dernier-deploiement.txt
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

ETAT="$(dirname "$ROOT")/dernier-deploiement.txt"
VERROU="$(dirname "$ROOT")/.auto-deploy.lock"

note() { echo "[$(date '+%F %T')] $*"; }
etat() { printf '%s | %s\n' "$(date '+%F %T')" "$*" > "$ETAT"; }

# Un déploiement en cours ne doit pas en croiser un second : le build dure
# plusieurs minutes, le timer tourne toutes les deux.
exec 9>"$VERROU"
if ! flock -n 9; then
    note "déploiement déjà en cours, ce passage est ignoré"
    exit 0
fi

BRANCHE="$(git rev-parse --abbrev-ref HEAD)"

git fetch --quiet origin "$BRANCHE"

LOCAL="$(git rev-parse HEAD)"
DISTANT="$(git rev-parse "origin/$BRANCHE")"

if [ "$LOCAL" = "$DISTANT" ]; then
    exit 0
fi

note "nouveau commit sur $BRANCHE : ${LOCAL:0:8} -> ${DISTANT:0:8}"
git log --oneline "$LOCAL..$DISTANT" | sed 's/^/    /'

# Un fichier suivi modifié à la main sur le serveur ferait échouer le
# `git pull --ff-only` de deploy.sh au milieu du déploiement. Autant le voir
# avant de rien toucher.
if ! git diff --quiet || ! git diff --cached --quiet; then
    note "REFUS : des fichiers suivis sont modifiés sur le serveur"
    git status --short | sed 's/^/    /'
    note "ce refus gèle TOUS les déploiements suivants tant qu'il dure."
    note "remettez ces fichiers en l'état (git checkout -- <fichier>) ou"
    note "committez-les. Un réglage propre à cette machine n'a rien à faire"
    note "dans un fichier suivi : voir DEPLOIEMENT.md section 10c."
    etat "REFUS — arbre de travail modifié sur le serveur"
    exit 1
fi

# Une migration qui arrive change le schéma d'une base qui porte des commandes
# et des paiements réels. Ça ne se fait pas sans quelqu'un devant l'écran : on
# laisse donc tourner la version en place, et on le dit fort. L'opérateur
# livrera avec `./deploy.sh --migrate`.
MIGRATIONS="$(git diff --name-only --diff-filter=A "$LOCAL" "$DISTANT" -- database/migrations | wc -l)"

if [ "$MIGRATIONS" -gt 0 ]; then
    note "REFUS : $MIGRATIONS migration(s) dans ce lot, un déploiement automatique ne les applique pas"
    git diff --name-only --diff-filter=A "$LOCAL" "$DISTANT" -- database/migrations | sed 's/^/    /'
    note "à livrer à la main : cd $ROOT/deploy && ./deploy.sh --migrate"
    etat "EN ATTENTE — $MIGRATIONS migration(s), livraison manuelle requise"
    exit 1
fi

note "déploiement"

if ./deploy/deploy.sh; then
    note "déploiement réussi sur ${DISTANT:0:8}"
    etat "OK — ${DISTANT:0:8} $(git log -1 --format=%s "$DISTANT" | cut -c1-60)"
else
    note "ÉCHEC du déploiement"
    etat "ÉCHEC — ${DISTANT:0:8}, voir journalctl -u thalia-deploy"
    exit 1
fi
