-- ============================================================
-- Mesure de l'index partiel idx_collections_obtenu_le : EXPLAIN avant / apres.
--
--   docker compose -f docker/docker-compose.yml exec -T db \
--       psql -U postgres -d nook_manager < docs/perf/index-explain.sql
--
-- La requete : les trouvailles des dernieres 48 h. C'est le coeur de la fonction
-- nb_trouvailles_recentes() appelee par l'application (records de l'ile).
-- Le script s'execute dans une transaction annulee (rien n'est conserve).
-- ============================================================
\set ON_ERROR_STOP on
\pset format unaligned
\pset tuples_only on

BEGIN;
ANALYZE collections;

\echo '=== AVANT : sans index ==='
DROP INDEX idx_collections_obtenu_le;
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF, SUMMARY ON)
SELECT count(*)
FROM collections
WHERE possede AND obtenu_le >= now() - interval '48 hours';

\echo ''
\echo '=== APRES : index partiel (obtenu_le DESC) WHERE possede ==='
CREATE INDEX idx_collections_obtenu_le ON collections (obtenu_le DESC) WHERE possede;
ANALYZE collections;
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF, SUMMARY ON)
SELECT count(*)
FROM collections
WHERE possede AND obtenu_le >= now() - interval '48 hours';

\echo ''
\echo '=== Taille de l\'index vs taille de la table ==='
SELECT 'index partiel : ' || pg_size_pretty(pg_relation_size('idx_collections_obtenu_le')) ||
       ' | table collections : ' || pg_size_pretty(pg_relation_size('collections'));

ROLLBACK;
