#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Thalia Eats — déploiement / mise à jour du backend (Docker).
#
# Usage, depuis /srv/thalia-eats/code/deploy sur le serveur :
#   ./deploy.sh                 # pull + build + up -d + health + récap
#   ./deploy.sh --no-pull       # ne pas faire de git pull
#   ./deploy.sh --no-build      # redémarrer sans reconstruire l'image
#   ./deploy.sh --migrate       # forcer artisan migrate --force
#   ./deploy.sh --prune         # nettoyer les images orphelines à la fin
#   ./deploy.sh -h | --help
#
# Pas de migration automatique non plus. connectDRC met RUN_INIT=true sur son
# service php ; ici la base du premier déploiement arrive par import d'un dump
# qui porte déjà ses migrations, et une migration silencieuse sur des
# commandes et des paiements réels n'est pas un défaut acceptable. Elle se
# demande : --migrate, ou THALIA_RUN_MIGRATIONS=true dans .env.
#
# La base vit dans le service `db`, importée automatiquement au tout premier
# démarrage : l'image MySQL n'exécute /docker-entrypoint-initdb.d que si son
# volume est vide. Les démarrages suivants ne réimportent rien.
#
# JAMAIS db:seed. script-run.sh l'exécute en production, mais sur une base qui
# porte déjà les données importées un seeder non idempotent dupliquerait les
# lignes de référence (statuts, communes, tarifs de livraison).
#
# Pré-requis : Docker + Compose v2, deploy/.env et deploy/.env.production.
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Chemin absolu du script, retenu AVANT le cd : --help relit ses propres
# commentaires, et $0 relatif ne resoudrait plus depuis le nouveau dossier.
SELF="$ROOT/$(basename "${BASH_SOURCE[0]}")"
cd "$ROOT"

COMPOSE_FILE="docker-compose.yml"
ENV_FILE=".env"

DO_PULL=true
DO_BUILD=true
DO_MIGRATE=false
DO_PRUNE=false

# ── Couleurs / logs ─────────────────────────────────────────────────────────
if [ -t 1 ]; then C_B='\033[1m'; C_G='\033[0;32m'; C_Y='\033[0;33m'; C_R='\033[0;31m'; C_0='\033[0m'; else C_B=''; C_G=''; C_Y=''; C_R=''; C_0=''; fi
log()  { echo -e "${C_B}▶ $*${C_0}"; }
ok()   { echo -e "${C_G}✓ $*${C_0}"; }
warn() { echo -e "${C_Y}! $*${C_0}"; }
die()  { echo -e "${C_R}✗ $*${C_0}" >&2; exit 1; }

for arg in "$@"; do
  case "$arg" in
    --no-pull)  DO_PULL=false ;;
    --no-build) DO_BUILD=false ;;
    --migrate)  DO_MIGRATE=true ;;
    --prune)    DO_PRUNE=true ;;
    -h|--help)  grep -E '^#' "$SELF" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) die "Option inconnue : $arg (voir --help)" ;;
  esac
done

# ── Pré-checks ──────────────────────────────────────────────────────────────
command -v docker >/dev/null 2>&1 || die "Docker n'est pas installé."
docker compose version >/dev/null 2>&1 || die "Docker Compose v2 requis (docker compose)."
[ -f "$COMPOSE_FILE" ] || die "$COMPOSE_FILE introuvable."
[ -f "$ENV_FILE" ] || die "$ENV_FILE introuvable — copiez .env.example et renseignez DB_PASSWORD et DB_ROOT_PASSWORD."
[ -f ".env.production" ] || die ".env.production introuvable — copiez .env.production.example et renseignez les 14 clés vides (voir son en-tête)."

DC=(docker compose -f "$COMPOSE_FILE")

APP_PORT="$(grep -E '^APP_PORT=' "$ENV_FILE" | cut -d= -f2 || true)"; APP_PORT="${APP_PORT:-8096}"
DATA_DIR="$(grep -E '^DATA_DIR=' "$ENV_FILE" | cut -d= -f2 || true)"

# ── 1. Les données doivent préexister ───────────────────────────────────────
# Les volumes sont des montages liés : si un dossier manque, Docker le crée en
# root et l'application se retrouve avec un storage vide dont elle n'est pas
# propriétaire — 500 opaque à la première requête.
log "Vérification des dossiers de données"
[ -n "$DATA_DIR" ] || die "DATA_DIR absent de $ENV_FILE."
for d in storage images; do
  [ -d "$DATA_DIR/$d" ] || die "$DATA_DIR/$d manquant (voir DEPLOIEMENT.md, étape 1)."
done
[ -f "$DATA_DIR/storage/firebase_credentials.json" ] \
  || warn "firebase_credentials.json absent : les notifications push échoueront."
ok "Données en place"

# ── 2. Le dump doit exister au tout premier démarrage ───────────────────────
# L'image MySQL ne lit /docker-entrypoint-initdb.d que si son volume est vide.
# Passé ce premier démarrage, le dump ne sert plus : son absence n'est alors
# qu'une remarque.
for v in DB_PASSWORD DB_ROOT_PASSWORD; do
  grep -qE "^${v}=.+" "$ENV_FILE" || die "$v est vide dans $ENV_FILE — le conteneur db refuserait de démarrer."
done

DUMP_FILE="$(grep -E '^DUMP_FILE=' "$ENV_FILE" | cut -d= -f2 || true)"
if [ -n "$DUMP_FILE" ] && [ -f "$DUMP_FILE" ]; then
  ok "Dump trouvé ($DUMP_FILE, $(du -h "$DUMP_FILE" | cut -f1))"
elif docker volume inspect thalia-eats-db-data >/dev/null 2>&1; then
  echo "  • base déjà initialisée, le dump n'est plus lu"
else
  die "DUMP_FILE introuvable ($DUMP_FILE) et base non initialisée : l'import du premier démarrage n'aurait pas lieu."
fi

# ── 3. Mise à jour du code ──────────────────────────────────────────────────
# Le dossier de code est un clone git : deploy/ est versionné, c'est par là
# qu'il arrive. Les deux .env renseignés sont ignorés par git, donc un pull ne
# les touche pas — et --ff-only refuse de fusionner quoi que ce soit : si
# l'historique a divergé, le déploiement s'arrête au lieu de créer un merge
# sur le serveur.
if $DO_PULL; then
  if [ -d "$ROOT/../.git" ]; then
    log "Mise à jour du code (git pull --ff-only)"
    branche="$(git -C "$ROOT/.." rev-parse --abbrev-ref HEAD)"
    echo "  • branche $branche"
    git -C "$ROOT/.." pull --ff-only || die "pull impossible — historique divergent, ou modification locale non commitée sur le serveur."
    ok "Code à jour"
  else
    warn "pas un clone git : pull ignoré (code transféré par rsync ?)"
  fi
else
  warn "git pull ignoré (--no-pull)"
fi

# ── 4. Build + (re)démarrage ────────────────────────────────────────────────
log "Déploiement du conteneur"

# --force-recreate sur `app`, systématiquement.
#
# Sans lui, `up -d` ne recrée rien tant que l'image et la définition du
# service n'ont pas changé. Or .env.production est un fichier MONTÉ : modifier
# son contenu ne change ni l'un ni l'autre. Le conteneur restait donc en
# place, l'entrypoint ne rejouait pas, et `config:cache` gardait l'ancienne
# configuration — une APP_KEY corrigée n'était jamais prise en compte, ce qui
# donne une 500 identique après édition.
#
# `db` n'est pas recréé : son volume porte les données, et le relancer pour
# rien allonge le déploiement.
if $DO_BUILD; then
  "${DC[@]}" up -d --build --remove-orphans
  "${DC[@]}" up -d --force-recreate --no-deps app
else
  "${DC[@]}" up -d --remove-orphans
  "${DC[@]}" up -d --force-recreate --no-deps app
fi
ok "Conteneurs démarrés (app + db)"

# ── 5. Migrations, seulement si demandées ───────────────────────────────────
if $DO_MIGRATE; then
  log "Migrations Laravel (forcées)"
  "${DC[@]}" exec -T app php artisan migrate --force || warn "migrate a échoué"
  ok "Migrations appliquées"
else
  pending="$("${DC[@]}" exec -T app php artisan migrate:status 2>/dev/null | grep -ci pending || true)"
  [ "${pending:-0}" -gt 0 ] && warn "$pending migration(s) en attente — relancer avec --migrate"
fi

# ── 6. Health check ─────────────────────────────────────────────────────────
log "Vérification du conteneur (http://127.0.0.1:${APP_PORT}/healthz)"
healthy=false
for _ in $(seq 1 30); do
  code="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${APP_PORT}/healthz" 2>/dev/null || echo 000)"
  [ "$code" = "200" ] && { healthy=true; break; }
  sleep 2
done
if $healthy; then
  ok "nginx répond (HTTP 200)"
else
  warn "pas de réponse — journaux : ${DC[*]} logs -f app"
fi

# /healthz ne prouve que nginx. La base est le second point de défaillance, et
# le plus fréquent : identifiants divergents entre .env et .env.production.
if "${DC[@]}" exec -T app php artisan db:show --quiet >/dev/null 2>&1; then
  ok "Base de données joignable"
else
  warn "Base injoignable — DB_HOST doit valoir « db », et DB_USERNAME/DB_PASSWORD être identiques dans .env et .env.production"
fi

# ── 7. Nettoyage ────────────────────────────────────────────────────────────
if $DO_PRUNE; then
  log "Nettoyage des images orphelines"
  docker image prune -f >/dev/null
  ok "Images orphelines supprimées"
fi

# ── 8. Récapitulatif ────────────────────────────────────────────────────────
echo
log "Récapitulatif"
"${DC[@]}" ps
echo
echo "  Application : http://127.0.0.1:${APP_PORT}  (boucle locale uniquement)"
echo "  Base        : service « db », aucun port publié"
echo "  Données     : ${DATA_DIR} + volume thalia-eats-db-data"
echo "  Journaux    : ${DC[*]} logs -f app"
echo
echo "  Apache doit relayer vers 127.0.0.1:${APP_PORT}."
echo "  Avant tout reload : sudo apache2ctl configtest && sudo apache2ctl -S"
echo "  « default server » ne doit PAS être thalia — sinon le trafic des autres"
echo "  applications de ce serveur serait capté."
