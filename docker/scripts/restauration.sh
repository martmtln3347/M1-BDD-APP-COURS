#!/usr/bin/env bash
# Restaure une sauvegarde produite par sauvegarde.sh.
#
#   ./docker/scripts/restauration.sh sauvegardes/20261020-143000
#
# ATTENTION : remplace le contenu actuel de PostgreSQL. Redis est restaure a
# partir de son instantane RDB (arret, remplacement du fichier, redemarrage).
set -euo pipefail

SAUVEGARDE="${1:?Usage : restauration.sh <dossier de sauvegarde>}"
[ -f "$SAUVEGARDE/postgres.dump" ] || { echo "postgres.dump introuvable dans $SAUVEGARDE" >&2; exit 1; }

cd "$(dirname "$0")/.."
PG_BASE="${POSTGRES_DB:-nook_manager}"
PG_USER="${POSTGRES_USER:-postgres}"

echo "PostgreSQL : restauration (pg_restore --clean --if-exists)"
docker compose exec -T db pg_restore -U "$PG_USER" -d "$PG_BASE" --clean --if-exists --no-owner < "../$SAUVEGARDE/postgres.dump" \
    || echo "(des avertissements sur des objets inexistants sont normaux avec --clean)"

if [ -f "../$SAUVEGARDE/redis-dump.rdb" ]; then
    echo "Redis : remplacement de l'instantane"
    docker compose stop redis
    docker compose cp "../$SAUVEGARDE/redis-dump.rdb" redis:/data/dump.rdb
    docker compose start redis
else
    echo "Pas d'instantane Redis : on reconstruit les classements depuis PostgreSQL"
    docker compose run --rm peupler-redis
fi
echo "Restauration terminee."
