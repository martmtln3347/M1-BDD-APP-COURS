#!/usr/bin/env bash
# Test de fumee de l'API et du lien PostgreSQL <-> Redis.
#
#   ./tests/smoke-api.sh                      (pile demarree avec docker compose up)
#   BASE_URL=http://127.0.0.1:8081 ./tests/smoke-api.sh
#
# Verifie, avec de vraies requetes HTTP : lecture et ecriture cote PostgreSQL,
# lecture et ecriture cote Redis, un endpoint qui croise les deux bases, et la
# gestion d'erreurs. Compte de demo utilise : alice@example.fr / demo1234.
set -uo pipefail

BASE="${BASE_URL:-http://127.0.0.1:8080}"
COMPOSE="docker compose -f $(dirname "$0")/../docker/docker-compose.yml"
REDIS_MDP="${REDIS_PASSWORD:-demo-redis-a-changer}"
JAR="$(mktemp)"
ECHECS=0

redis() { $COMPOSE exec -T redis redis-cli -a "$REDIS_MDP" --no-auth-warning "$@" | tr -d '\r'; }
api()   { curl -s -b "$JAR" -c "$JAR" -H 'Content-Type: application/json' "$@"; }
code()  { curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" -H 'Content-Type: application/json' "$@"; }

verifier() { # verifier "libelle" "valeur attendue" "valeur obtenue"
    if [[ "$3" == *"$2"* ]]; then echo "  ok   $1"; else echo "  ECHEC $1 (attendu : $2, obtenu : ${3:0:120})"; ECHECS=$((ECHECS + 1)); fi
}

echo "Page d'accueil et API joignables"
verifier "page d'accueil" "200" "$(code "$BASE/index.php?page=accueil")"
verifier "API sans connexion : 401" "401" "$(code "$BASE/api/collection.php")"

echo "Connexion (PostgreSQL) et session (Redis)"
verifier "connexion alice" "Connecte" "$(api -X POST -d '{"email":"alice@example.fr","mot_de_passe":"demo1234"}' "$BASE/api/connexion.php")"
verifier "la session est une cle Redis" "nook:session:" "$(redis KEYS 'nook:session:*')"
verifier "mauvais mot de passe : 401" "401" "$(code -X POST -d '{"email":"alice@example.fr","mot_de_passe":"faux"}' "$BASE/api/connexion.php")"

echo "Base relationnelle : lecture, ecriture, suppression"
verifier "GET items (poissons)" '"categorie":"poisson"' "$(api "$BASE/api/items.php?categorie=poisson")"
verifier "GET collection" '"collection"' "$(api "$BASE/api/collection.php")"
ITEM=$(api "$BASE/api/items.php?categorie=insecte" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
# Les corps JSON sont prepares dans des variables : l'imbrication de guillemets
# dans $( ) se comporte mal avec le bash 3.2 de macOS.
CORPS_ITEM="{\"item_id\":$ITEM}"
CORPS_MARQUER="{\"item_id\":$ITEM,\"possede\":true}"
api -X DELETE -d "$CORPS_ITEM" "$BASE/api/collection.php" >/dev/null   # repart d'un etat connu
REP=$(api -X POST -d "$CORPS_MARQUER" "$BASE/api/collection.php")
verifier "POST marquer un element" '"possede":true' "$REP"
REP=$(api -X DELETE -d "$CORPS_ITEM" "$BASE/api/collection.php")
verifier "DELETE retirer l'element" "retire" "$REP"
CODE=$(code -X DELETE -d "$CORPS_ITEM" "$BASE/api/collection.php")
verifier "DELETE d'un element absent : 404" "404" "$CODE"
verifier "item_id invalide : 400" "400" "$(code -X POST -d '{"item_id":"abc"}' "$BASE/api/collection.php")"

echo "Redis : l'ecriture PostgreSQL se repercute dans le classement"
AVANT=$(redis ZSCORE nook:classement:global 1)
api -X POST -d "$CORPS_MARQUER" "$BASE/api/collection.php" >/dev/null
APRES=$(redis ZSCORE nook:classement:global 1)
[[ "$APRES" -eq $((AVANT + 1)) ]] && echo "  ok   ZSCORE alice : $AVANT -> $APRES" || { echo "  ECHEC ZSCORE alice : $AVANT -> $APRES"; ECHECS=$((ECHECS + 1)); }
api -X DELETE -d "$CORPS_ITEM" "$BASE/api/collection.php" >/dev/null
verifier "retrait : le score redescend" "$AVANT" "$(redis ZSCORE nook:classement:global 1)"

echo "Redis : lecture, notifications (CRUD), compteurs"
verifier "GET classement par categorie" '"classement"' "$(api "$BASE/api/classement-categorie.php?categorie=poisson")"
verifier "categorie inconnue : 400" "400" "$(code "$BASE/api/classement-categorie.php?categorie=nimporte")"
api -X POST -d '{"action":"demander","user_id":99}' "$BASE/api/amis.php" >/dev/null
verifier "la demande d'ami cree une notification Redis" '"type":"demande"' "$(redis LRANGE nook:notif:99 0 -1)"
verifier "la notification expire (TTL 7 jours)" "6" "$(redis TTL nook:notif:99 | cut -c1-1)"
api -X POST -d '{"action":"retirer","user_id":99}' "$BASE/api/amis.php" >/dev/null
verifier "compteurs du jour (HASH)" "connexions" "$(redis HGETALL "nook:stats:jour:$(date +%Y%m%d)")"

echo "Endpoint croisant les deux bases (classement Redis + visibilite PostgreSQL)"
REPONSE=$(api "$BASE/api/profil.php")
verifier "classement present" '"pseudo":"alice"' "$REPONSE"
if echo "$REPONSE" | grep -q '"pseudo":"carol"'; then
    echo "  ECHEC carol (profil prive) apparait dans le classement"; ECHECS=$((ECHECS + 1))
else
    echo "  ok   carol (profil prive) est absente : PostgreSQL filtre ce que Redis a classe"
fi

echo "Limitation des tentatives (compteur Redis)"
for _ in 1 2 3 4 5 6 7 8; do code -X POST -d '{"email":"limite@example.fr","mot_de_passe":"x"}' "$BASE/api/connexion.php" >/dev/null; done
verifier "9e tentative : 429" "429" "$(code -X POST -d '{"email":"limite@example.fr","mot_de_passe":"x"}' "$BASE/api/connexion.php")"
redis DEL "nook:limite:connexion:mail:$(printf 'limite@example.fr' | shasum -a 256 | cut -c1-32)" >/dev/null
redis KEYS 'nook:limite:connexion:ip:*' | while read -r cle; do [ -n "$cle" ] && redis DEL "$cle" >/dev/null; done

echo "Protection CSRF"
verifier "origine etrangere refusee" "403" "$(code -X POST -H 'Sec-Fetch-Site: cross-site' -d '{}' "$BASE/api/deconnexion.php")"

rm -f "$JAR"
echo
if [ "$ECHECS" -eq 0 ]; then echo "Tous les controles passent."; else echo "$ECHECS controle(s) en echec."; exit 1; fi
