#!/bin/bash
# Pose les mots de passe des roles de connexion a partir des variables
# d'environnement du container. Ils ne figurent donc nulle part dans le depot.
# psql :'variable' produit un litteral SQL correctement quote.
set -euo pipefail

: "${ACNH_APP_PASSWORD:?ACNH_APP_PASSWORD manquant (voir .env)}"
: "${ACNH_ADMIN_PASSWORD:?ACNH_ADMIN_PASSWORD manquant (voir .env)}"

psql -v ON_ERROR_STOP=1 \
     --username "$POSTGRES_USER" \
     --dbname "$POSTGRES_DB" \
     --set app_pwd="$ACNH_APP_PASSWORD" \
     --set admin_pwd="$ACNH_ADMIN_PASSWORD" <<'EOSQL'
ALTER ROLE acnh_app   WITH PASSWORD :'app_pwd';
ALTER ROLE acnh_admin WITH PASSWORD :'admin_pwd';
EOSQL
