#!/usr/bin/env bash
# Sauvegarde des deux bases dans ./sauvegardes/AAAAMMJJ-HHMMSS/
#
#   ./docker/scripts/sauvegarde.sh            (depuis la racine du projet)
#
# - PostgreSQL : pg_dump au format "custom" (compresse, restaurable table par table)
# - Redis      : instantane RDB (BGSAVE) copie hors du conteneur
set -euo pipefail

cd "$(dirname "$0")/.."                       # dossier docker/
DOSSIER="../sauvegardes/$(date +%Y%m%d-%H%M%S)"
mkdir -p "$DOSSIER"

PG_BASE="${POSTGRES_DB:-nook_manager}"
PG_USER="${POSTGRES_USER:-postgres}"
REDIS_MDP="${REDIS_PASSWORD:-demo-redis-a-changer}"

echo "PostgreSQL -> $DOSSIER/postgres.dump"
docker compose exec -T db pg_dump -U "$PG_USER" -d "$PG_BASE" -Fc > "$DOSSIER/postgres.dump"

echo "Redis      -> $DOSSIER/redis-dump.rdb"
ANCIEN=$(docker compose exec -T redis redis-cli -a "$REDIS_MDP" --no-auth-warning LASTSAVE | tr -d '\r')
docker compose exec -T redis redis-cli -a "$REDIS_MDP" --no-auth-warning BGSAVE >/dev/null
# BGSAVE est asynchrone : on attend que LASTSAVE change.
for _ in $(seq 1 30); do
    NOUVEAU=$(docker compose exec -T redis redis-cli -a "$REDIS_MDP" --no-auth-warning LASTSAVE | tr -d '\r')
    [ "$NOUVEAU" != "$ANCIEN" ] && break
    sleep 1
done
docker compose cp redis:/data/dump.rdb "$DOSSIER/redis-dump.rdb"

echo "Termine : $(du -sh "$DOSSIER" | cut -f1) dans $DOSSIER"
