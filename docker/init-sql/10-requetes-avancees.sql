-- ============================================================
-- Nook Manager — requetes avancees utilisees par l'application
--
-- Chaque objet ci-dessous est appele par une route de l'API (voir README) et
-- illustre une notion du cours : agregat avec HAVING, CTE, EXISTS, jointure
-- externe, fonction fenetre, index partiel.
-- ============================================================

-- ---------- Index volontaire (mesure dans docs/perf/index-explain.sql) ----------
-- Les « trouvailles recentes » (fonction nb_trouvailles_recentes, ci-dessous)
-- ne concernent qu'une infime partie de la table : quelques jours sur six mois
-- d'historique. Sans index, PostgreSQL parcourt les ~270 000 lignes pour en
-- garder 1 %. L'index PARTIEL ne contient que les lignes possedees, triees par
-- date : la requete lit directement la queue de l'index.
CREATE INDEX IF NOT EXISTS idx_collections_obtenu_le
    ON collections (obtenu_le DESC) WHERE possede;

-- Trouvailles des dernieres heures, sur les profils visibles pour l'appelant
-- (fonction en security invoker : la RLS de collections s'applique).
CREATE OR REPLACE FUNCTION nb_trouvailles_recentes(p_heures int DEFAULT 48)
RETURNS bigint
LANGUAGE sql
STABLE
AS $$
    SELECT count(*)
    FROM collections
    WHERE possede AND obtenu_le >= now() - make_interval(hours => p_heures);
$$;
REVOKE EXECUTE ON FUNCTION nb_trouvailles_recentes(int) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION nb_trouvailles_recentes(int) TO app_user;

-- ---------- Agregat avec GROUP BY / HAVING ----------
-- Classement des musees : joueurs ayant donne au moins 5 elements.
-- security_invoker : la vue lit collections avec les droits de l'appelant, donc
-- la RLS ne laisse passer que les profils visibles pour lui.
CREATE OR REPLACE VIEW v_classement_musee WITH (security_invoker = true) AS
SELECT
    u.id AS user_id,
    u.pseudo,
    count(*)                                             AS nb_au_musee,
    min(c.donne_le)                                      AS premier_don,
    max(c.donne_le)                                      AS dernier_don
FROM collections c
JOIN users u ON u.id = c.user_id
WHERE c.au_musee
GROUP BY u.id, u.pseudo
HAVING count(*) >= 5;
GRANT SELECT ON v_classement_musee TO app_user;

-- ---------- CTE + fonction fenetre + jointure externe ----------
-- Progression d'un joueur sur les N derniers jours : un point par jour, meme
-- les jours sans trouvaille (LEFT JOIN sur une serie generee), avec le cumul.
CREATE OR REPLACE FUNCTION progression_recente(p_user int, p_jours int DEFAULT 30)
RETURNS TABLE (jour date, nouveaux int, cumul int)
LANGUAGE sql
STABLE
AS $$
    WITH jours AS (
        SELECT d::date AS jour
        FROM generate_series(current_date - (p_jours - 1), current_date, interval '1 day') AS d
    ),
    par_jour AS (
        SELECT c.obtenu_le::date AS jour, count(*)::int AS nb
        FROM collections c
        WHERE c.user_id = p_user AND c.possede
          AND c.obtenu_le >= current_date - (p_jours - 1)
          AND peut_voir_profil(p_user)
        GROUP BY c.obtenu_le::date
    )
    SELECT j.jour,
           coalesce(p.nb, 0),
           (sum(coalesce(p.nb, 0)) OVER (ORDER BY j.jour))::int
    FROM jours j
    LEFT JOIN par_jour p USING (jour)
    ORDER BY j.jour;
$$;
REVOKE EXECUTE ON FUNCTION progression_recente(int, int) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION progression_recente(int, int) TO app_user;

-- ---------- CTE + EXISTS + GROUP BY / HAVING ----------
-- Ce que mes amis ont et que je n'ai pas encore, du plus repandu au moins
-- repandu chez eux.
CREATE OR REPLACE FUNCTION items_chez_les_amis(p_limite int DEFAULT 20)
RETURNS TABLE (item_id int, nom text, categorie categorie_item, image_icone text, nb_amis int)
LANGUAGE sql
STABLE
AS $$
    WITH mes_amis AS (
        SELECT CASE WHEN a.demandeur_id = app_user_id() THEN a.destinataire_id ELSE a.demandeur_id END AS ami_id
        FROM amities a
        WHERE a.statut = 'acceptee'
          AND app_user_id() IN (a.demandeur_id, a.destinataire_id)
    )
    SELECT i.id, i.nom, i.categorie, i.image_icone, count(*)::int
    FROM mes_amis m
    JOIN collections c ON c.user_id = m.ami_id AND c.possede
    JOIN items i ON i.id = c.item_id AND i.est_contrefacon_de IS NULL
    WHERE NOT EXISTS (
        SELECT 1 FROM collections moi
        WHERE moi.user_id = app_user_id() AND moi.item_id = i.id AND moi.possede
    )
    GROUP BY i.id, i.nom, i.categorie, i.image_icone
    HAVING count(*) >= 1
    ORDER BY count(*) DESC, i.nom
    LIMIT p_limite;
$$;
REVOKE EXECUTE ON FUNCTION items_chez_les_amis(int) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION items_chez_les_amis(int) TO app_user;

-- ---------- CRUD complet : suppression ----------
-- Retirer un element de sa collection (DELETE) et supprimer son compte. Les
-- policies collections_ecriture (FOR ALL) et users_suppression limitent ces
-- suppressions a SES lignes.
GRANT DELETE ON collections TO app_user;

DROP POLICY IF EXISTS users_suppression ON users;
CREATE POLICY users_suppression ON users
FOR DELETE TO app_user
USING (id = app_user_id());
GRANT DELETE ON users TO app_user;
