-- ============================================================
-- Nook Manager — jeu de donnees volumineux
--
-- Le catalogue (05-seed-items.sql) compte ~1 100 items ; ce script y ajoute
-- 1 500 joueurs, ~250 000 lignes de collection et ~4 000 amities, pour que
-- classements, agregats et index aient quelque chose a mesurer.
--
-- Tout est deterministe (pas de random()) : deux demarrages donnent la meme
-- base, donc des mesures EXPLAIN reproductibles.
-- Mot de passe de tous les comptes generes : demo1234.
-- ============================================================

-- Le trigger de fil d'actu ecrirait une activite par ligne (250 000 INSERT en
-- plus) : on le suspend pour la charge, puis on ne garde qu'un echantillon recent.
ALTER TABLE collections DISABLE TRIGGER collections_log_activite;

-- ---------- Joueurs ----------
-- crypt() n'est evalue qu'une fois (sous-requete scalaire non correlee) : le
-- meme hash bcrypt sert a tous les comptes de demonstration.
INSERT INTO users (pseudo, email, mot_de_passe_hash, email_verifie, visibilite, cree_le)
SELECT
    'joueur' || lpad(g::text, 4, '0'),
    'joueur' || lpad(g::text, 4, '0') || '@demo.example',
    (SELECT crypt('demo1234', gen_salt('bf', 6))),
    true,
    -- 70 % publics, 20 % reserves aux amis, 10 % prives
    CASE WHEN g % 10 < 7 THEN 'public' WHEN g % 10 < 9 THEN 'amis' ELSE 'prive' END::visibilite_profil,
    now() - (g % 200) * interval '1 day'
FROM generate_series(1, 1500) AS g;

-- ---------- Collections ----------
-- Chaque joueur a un taux de completion propre (entre ~5 % et ~50 %), derive de
-- son numero ; un item est possede si une empreinte du couple (joueur, item)
-- tombe sous ce taux. Un tiers des trouvailles part au musee.
INSERT INTO collections (user_id, item_id, possede, au_musee, obtenu_le, donne_le)
SELECT
    u.id,
    i.id,
    true,
    h % 3 = 0,
    now() - (h % 180) * interval '1 day',
    CASE WHEN h % 3 = 0 THEN now() - (h % 90) * interval '1 day' END
FROM users u
JOIN items i ON i.est_contrefacon_de IS NULL
CROSS JOIN LATERAL (SELECT abs(hashtext(u.id::text || '-' || i.id::text)) AS h) AS e
WHERE u.pseudo LIKE 'joueur%'
  AND (e.h % 100) < 5 + (u.id * 7) % 46;

-- ---------- Amities ----------
-- Chaque joueur envoie 3 demandes vers des numeros voisins deterministes ;
-- la plupart sont acceptees. ON CONFLICT ignore les doublons (A->B et B->A).
INSERT INTO amities (demandeur_id, destinataire_id, statut, demande_le, repondu_le)
SELECT DISTINCT ON (LEAST(a.id, b.id), GREATEST(a.id, b.id))
    a.id, b.id,
    CASE WHEN (a.id + b.id) % 5 = 0 THEN 'en_attente' ELSE 'acceptee' END::statut_amitie,
    now() - ((a.id + b.id) % 120) * interval '1 day',
    CASE WHEN (a.id + b.id) % 5 = 0 THEN NULL ELSE now() - ((a.id + b.id) % 100) * interval '1 day' END
FROM users a
CROSS JOIN generate_series(1, 3) AS k
JOIN users b ON b.id = (SELECT min(id) FROM users WHERE pseudo LIKE 'joueur%') + ((a.id * 37 + k * 101) % 1500)
WHERE a.pseudo LIKE 'joueur%' AND a.id <> b.id
ORDER BY LEAST(a.id, b.id), GREATEST(a.id, b.id), a.id
ON CONFLICT DO NOTHING;

ALTER TABLE collections ENABLE TRIGGER collections_log_activite;

-- Fil d'actu : un echantillon recent, comme si le trigger avait tourne.
INSERT INTO activites (user_id, item_id, type, cree_le)
SELECT user_id, item_id, 'obtenu', obtenu_le
FROM collections
WHERE obtenu_le > now() - interval '7 days'
ORDER BY obtenu_le DESC
LIMIT 3000;

REFRESH MATERIALIZED VIEW mv_classement;
REFRESH MATERIALIZED VIEW mv_classement_categorie;
ANALYZE;
