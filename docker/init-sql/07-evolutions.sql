-- ============================================================
-- Nook Manager — evolutions : photo de profil, classements par categorie,
-- popularite des items, comparaison de collections.
--
-- Rejouable a la main sur une base existante (psql -U postgres -d nook_manager
-- -f db/init/07-evolutions.sql) : tout est idempotent.
-- ============================================================

-- ---------- Photo de profil ----------
-- La contrainte "l'avatar est un habitant" s'exprime sans trigger : une cle
-- etrangere composee (id, categorie) vers items, dont la categorie est une
-- colonne generee, toujours 'habitant' des qu'un avatar est choisi.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'items_id_categorie_uq') THEN
        ALTER TABLE items ADD CONSTRAINT items_id_categorie_uq UNIQUE (id, categorie);
    END IF;
END $$;

ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_item_id int;
ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_categorie categorie_item
    GENERATED ALWAYS AS (CASE WHEN avatar_item_id IS NULL THEN NULL ELSE 'habitant'::categorie_item END) STORED;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'users_avatar_habitant_fk') THEN
        ALTER TABLE users ADD CONSTRAINT users_avatar_habitant_fk
            FOREIGN KEY (avatar_item_id, avatar_categorie) REFERENCES items (id, categorie)
            ON DELETE NO ACTION;
    END IF;
END $$;

-- Seule colonne modifiable en plus de la visibilite ; la policy
-- users_modification limite deja l'UPDATE au compte de la session.
GRANT UPDATE (avatar_item_id) ON users TO app_user;

-- Photo affichee a cote de chaque pseudo : une vue evite de rejoindre items
-- partout. Rien de sensible, les pseudos sont deja lisibles par tous.
CREATE OR REPLACE VIEW v_avatars AS
SELECT u.id AS user_id, i.image_icone AS avatar_url
FROM users u
LEFT JOIN items i ON i.id = u.avatar_item_id;
GRANT SELECT ON v_avatars TO app_user;

-- ---------- Classement par categorie ----------
-- Meme principe que mv_classement : vue materialisee pour le calcul, vue
-- normale par-dessus pour re-appliquer la visibilite (une vue materialisee ne
-- porte pas de RLS).
-- Deux CTE : la taille de chaque catalogue, puis le nombre d'elements possedes
-- par joueur et par categorie, agreges UNE fois. Le produit joueurs x categories
-- se fait ensuite sur ces petits resultats (LEFT JOIN : un joueur sans rien a
-- 0, pas absent). Un CROSS JOIN direct joueurs x items re-parcourait la table
-- collections pour chaque couple : plusieurs dizaines de secondes a 270 000 lignes.
CREATE MATERIALIZED VIEW IF NOT EXISTS mv_classement_categorie AS
WITH totaux AS (
    SELECT categorie, count(*) AS nb
    FROM items
    WHERE est_contrefacon_de IS NULL
    GROUP BY categorie
),
possedes AS (
    SELECT c.user_id, i.categorie, count(*) AS nb
    FROM collections c
    JOIN items i ON i.id = c.item_id
    WHERE c.possede AND i.est_contrefacon_de IS NULL
    GROUP BY c.user_id, i.categorie
)
SELECT
    u.id AS user_id,
    u.pseudo,
    t.categorie,
    coalesce(p.nb, 0)             AS nb_possedes,
    t.nb                          AS nb_total,
    round(100.0 * coalesce(p.nb, 0) / t.nb, 1) AS pct
FROM users u
CROSS JOIN totaux t
LEFT JOIN possedes p ON p.user_id = u.id AND p.categorie = t.categorie;

CREATE UNIQUE INDEX IF NOT EXISTS mv_classement_categorie_pk
    ON mv_classement_categorie (user_id, categorie);

CREATE OR REPLACE VIEW v_classement_categorie AS
SELECT mc.*
FROM mv_classement_categorie mc
WHERE peut_voir_profil(mc.user_id);
GRANT SELECT ON v_classement_categorie TO app_user;

-- Un seul point d'entree rafraichit les deux classements.
CREATE OR REPLACE FUNCTION rafraichir_classement()
RETURNS void
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
BEGIN
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_classement;
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_classement_categorie;
END;
$$;
REVOKE EXECUTE ON FUNCTION rafraichir_classement() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION rafraichir_classement() TO app_user;

-- ---------- Popularite des items ----------
-- security_invoker : la vue lit "collections" avec les droits de l'appelant,
-- donc la RLS ne laisse passer que les profils visibles pour lui. Un profil
-- prive ne pese jamais dans la rarete d'un item.
CREATE OR REPLACE VIEW v_popularite_items WITH (security_invoker = true) AS
SELECT
    i.id AS item_id,
    i.nom,
    i.categorie,
    i.image_url,
    i.image_icone,
    count(*) FILTER (WHERE c.possede)  AS nb_possesseurs,
    count(*) FILTER (WHERE c.au_musee) AS nb_au_musee
FROM items i
LEFT JOIN collections c ON c.item_id = i.id
WHERE i.est_contrefacon_de IS NULL
GROUP BY i.id, i.nom, i.categorie, i.image_url, i.image_icone;
GRANT SELECT ON v_popularite_items TO app_user;

-- ---------- Comparer deux collections ----------
-- Une fonction SQL en security invoker : les lignes de l'autre joueur ne
-- ressortent que si la RLS l'autorise. Si son profil est invisible, le
-- WHERE peut_voir_profil() renvoie zero ligne plutot qu'un resultat faussement
-- "tout est a toi seul".
CREATE OR REPLACE FUNCTION comparer_collections(p_autre int)
RETURNS TABLE (
    item_id int, nom text, categorie categorie_item, image_icone text,
    moi_possede boolean, lui_possede boolean, statut text
)
LANGUAGE sql STABLE AS $$
    SELECT i.id, i.nom, i.categorie, i.image_icone,
           coalesce(m.possede, false), coalesce(a.possede, false),
           CASE
               WHEN coalesce(m.possede, false) AND coalesce(a.possede, false) THEN 'les_deux'
               WHEN coalesce(m.possede, false) THEN 'moi_seulement'
               ELSE 'lui_seulement'
           END
    FROM items i
    LEFT JOIN collections m ON m.item_id = i.id AND m.user_id = app_user_id()
    LEFT JOIN collections a ON a.item_id = i.id AND a.user_id = p_autre
    WHERE peut_voir_profil(p_autre)
      AND i.est_contrefacon_de IS NULL
      AND (coalesce(m.possede, false) OR coalesce(a.possede, false))
    ORDER BY i.categorie, i.nom;
$$;
REVOKE EXECUTE ON FUNCTION comparer_collections(int) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION comparer_collections(int) TO app_user;

REFRESH MATERIALIZED VIEW mv_classement_categorie;
