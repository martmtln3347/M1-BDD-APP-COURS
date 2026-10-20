-- ============================================================
-- Nook Manager — jeu de demonstration (optionnel)
-- Trois comptes et leurs collections, pour que les stats, le classement et
-- le fil d'actu aient quelque chose a afficher des le premier demarrage.
-- Supprimer ce fichier pour une installation vierge.
-- ============================================================

-- Mot de passe des trois comptes : demo1234.
-- crypt() de pgcrypto (active dans 01-schema.sql) produit un hash bcrypt,
-- uniquement pour ces comptes de demo : en production c'est password_hash()
-- cote PHP qui produit le hash.
INSERT INTO users (pseudo, email, mot_de_passe_hash, email_verifie, visibilite) VALUES
    ('alice', 'alice@example.fr', crypt('demo1234', gen_salt('bf')), true, 'public'),
    ('bob',   'bob@example.fr',   crypt('demo1234', gen_salt('bf')), true, 'amis'),
    ('carol', 'carol@example.fr', crypt('demo1234', gen_salt('bf')), true, 'prive');

-- alice et bob sont amis : bob, dont le profil est reserve aux amis, est donc
-- visible par alice. carol reste invisible pour tout le monde.
INSERT INTO amities (demandeur_id, destinataire_id, statut, repondu_le)
SELECT a.id, b.id, 'acceptee', now()
FROM users a, users b
WHERE a.pseudo = 'alice' AND b.pseudo = 'bob';

-- Collections : une part deterministe du catalogue pour chaque compte, pour
-- obtenir trois taux de completion differents dans le classement.
-- Les contrefacons sont exclues : elles ne se collectionnent pas.
INSERT INTO collections (user_id, item_id, possede, au_musee, obtenu_le, donne_le)
SELECT
    u.id,
    i.id,
    true,
    i.id % 3 = 0,                                  -- un tiers des trouvailles au musee
    now() - (i.id % 30) * interval '1 day',
    CASE WHEN i.id % 3 = 0 THEN now() - (i.id % 15) * interval '1 day' END
FROM users u
JOIN items i ON i.est_contrefacon_de IS NULL AND i.id % 10 < CASE u.pseudo
        WHEN 'alice' THEN 6      -- ~60 %
        WHEN 'bob'   THEN 3      -- ~30 %
        ELSE 1                   -- ~10 %
    END
WHERE u.pseudo IN ('alice', 'bob', 'carol');

REFRESH MATERIALIZED VIEW mv_classement;
