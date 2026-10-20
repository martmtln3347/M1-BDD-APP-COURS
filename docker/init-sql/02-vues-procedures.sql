-- ============================================================
-- Nook Manager — fonctions, vues et procedures metier
-- ============================================================

-- ---------- Identite de la session ----------
-- L'appli PHP appelle set_config('app.user_id', :id, false) juste apres le
-- login. Le 2e argument "true" de current_setting = missing_ok : renvoie NULL
-- au lieu de lever une erreur quand le parametre n'est pas encore pose
-- (seed, psql d'admin, requetes avant login).
CREATE OR REPLACE FUNCTION app_user_id()
RETURNS int
LANGUAGE sql STABLE AS $$
    SELECT nullif(current_setting('app.user_id', true), '')::int;
$$;

-- ---------- Regle de visibilite ----------
-- Factorisee ici : toutes les policies RLS l'appellent, et la regle produit
-- (public / amis / prive) s'applique a TOUT le profil d'un coup — stats,
-- collection et fil d'actu — pas de granularite plus fine.
CREATE OR REPLACE FUNCTION peut_voir_profil(p_proprietaire_id int)
RETURNS boolean
LANGUAGE sql STABLE AS $$
    SELECT p_proprietaire_id = app_user_id()
        OR EXISTS (
            SELECT 1
            FROM users u
            WHERE u.id = p_proprietaire_id
              AND (
                  u.visibilite = 'public'
                  OR (
                      u.visibilite = 'amis'
                      AND EXISTS (
                          SELECT 1
                          FROM amities a
                          WHERE a.statut = 'acceptee'
                            AND (
                                (a.demandeur_id = app_user_id() AND a.destinataire_id = u.id)
                             OR (a.destinataire_id = app_user_id() AND a.demandeur_id = u.id)
                            )
                      )
                  )
              )
        );
$$;

-- ============================================================
-- VUES
-- ============================================================

-- Stats par user et par categorie.
-- security_invoker : sans ca la vue s'executerait avec les droits de son
-- proprietaire et court-circuiterait la RLS de "collections" — chacun verrait
-- les stats de tout le monde.
CREATE VIEW v_stats_user WITH (security_invoker = true) AS
SELECT
    u.id AS user_id,
    i.categorie,
    count(*) FILTER (WHERE c.possede)  AS nb_possedes,
    count(*) FILTER (WHERE c.au_musee) AS nb_au_musee,
    count(*)                           AS nb_total_categorie,
    round(100.0 * count(*) FILTER (WHERE c.possede) / count(*), 1) AS pct_possede
FROM users u
CROSS JOIN items i
LEFT JOIN collections c ON c.user_id = u.id AND c.item_id = i.id
WHERE peut_voir_profil(u.id)
  -- Les contrefacons sont des lignes d'items mais ne se collectionnent pas :
  -- elles ne doivent pas gonfler le denominateur des pourcentages.
  AND i.est_contrefacon_de IS NULL
GROUP BY u.id, i.categorie;

-- Classement global en vue MATERIALISEE : le calcul balaie toute la table
-- collections, trop lourd a refaire a chaque affichage.
-- nullif(..., 0) : evite une division par zero tant que "items" est vide
-- (la vue est creee avant le seed).
CREATE MATERIALIZED VIEW mv_classement AS
SELECT
    u.id     AS user_id,
    u.pseudo,
    count(*) FILTER (WHERE c.possede) AS nb_possedes,
    (SELECT count(*) FROM items WHERE est_contrefacon_de IS NULL) AS nb_total,
    round(
        100.0 * count(*) FILTER (WHERE c.possede)
        / nullif((SELECT count(*) FROM items WHERE est_contrefacon_de IS NULL), 0),
        1
    ) AS pct_global
FROM users u
LEFT JOIN collections c ON c.user_id = u.id
GROUP BY u.id, u.pseudo;

-- Index unique obligatoire pour pouvoir rafraichir en CONCURRENTLY.
CREATE UNIQUE INDEX mv_classement_pk ON mv_classement (user_id);

-- Piege du module 05 : une vue materialisee ne peut PAS porter de RLS.
-- D'ou cette vue normale par-dessus, qui re-applique la visibilite.
CREATE VIEW v_classement_amis AS
SELECT mc.*
FROM mv_classement mc
WHERE peut_voir_profil(mc.user_id);

-- Rafraichissement : reserve au proprietaire de la vue materialisee, donc
-- SECURITY DEFINER pour que l'appli puisse le declencher apres une action
-- significative (sinon : cron / pg_cron).
CREATE OR REPLACE FUNCTION rafraichir_classement()
RETURNS void
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
BEGIN
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_classement;
END;
$$;

-- ============================================================
-- COMPTES ET TOKENS
-- ============================================================
-- Le role applicatif n'a aucun droit sur la table tokens, ni INSERT ni UPDATE
-- sur users : tout ce qui cree un compte ou touche au mot de passe passe par
-- ces quatre fonctions. En SECURITY DEFINER, car ces operations se jouent
-- AVANT la connexion : il n'y a pas encore d'identite a controler, c'est la
-- possession d'un token valide qui autorise l'operation.
--
-- Les tokens sont generes ici, jamais fournis par l'appelant : un token dont
-- on choisit la valeur ne prouve rien. Limite assumee : la fonction renvoie le
-- token a l'application, qui doit bien l'envoyer par email ; quelqu'un qui
-- controle l'application peut donc toujours reinitialiser un compte. Ce qui
-- est garanti, c'est qu'il ne peut ni lire un token deja emis, ni en forger.
--
-- search_path fixe : sans lui, un appelant pourrait placer ses propres objets
-- devant ceux de public et les faire executer avec les droits du proprietaire.

CREATE OR REPLACE FUNCTION creer_compte(p_pseudo text, p_email text, p_hash text)
RETURNS text  -- token de verification d'email
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
    v_user_id int;
    v_token   text := encode(gen_random_bytes(32), 'hex');
BEGIN
    -- email_verifie garde sa valeur par defaut (false) : l'appelant ne peut
    -- pas creer un compte deja verifie.
    INSERT INTO users (pseudo, email, mot_de_passe_hash)
    VALUES (p_pseudo, p_email, p_hash)
    RETURNING id INTO v_user_id;

    INSERT INTO tokens (user_id, token, type, expire_le)
    VALUES (v_user_id, v_token, 'verification_email', now() + interval '24 hours');

    RETURN v_token;
END;
$$;

CREATE OR REPLACE FUNCTION verifier_email(p_token text)
RETURNS boolean
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
    v_user_id int;
BEGIN
    -- Consommer et verifier en une seule instruction : deux appels simultanes
    -- avec le meme token ne peuvent pas reussir tous les deux.
    UPDATE tokens
    SET utilise_le = now()
    WHERE token = p_token
      AND type = 'verification_email'
      AND utilise_le IS NULL
      AND expire_le > now()
    RETURNING user_id INTO v_user_id;

    IF v_user_id IS NULL THEN
        RETURN false;
    END IF;

    UPDATE users SET email_verifie = true WHERE id = v_user_id;
    RETURN true;
END;
$$;

-- Renvoie NULL si aucun compte ne correspond : a l'application de repondre la
-- meme chose dans les deux cas.
CREATE OR REPLACE FUNCTION demander_reinitialisation(p_email text)
RETURNS text  -- token de reinitialisation, valable une heure
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
    v_user_id int;
    v_token   text;
BEGIN
    SELECT id INTO v_user_id FROM users WHERE email = p_email;
    IF v_user_id IS NULL THEN
        RETURN NULL;
    END IF;

    -- Seul le dernier lien demande fonctionne.
    UPDATE tokens
    SET utilise_le = now()
    WHERE user_id = v_user_id AND type = 'reset_mdp' AND utilise_le IS NULL;

    v_token := encode(gen_random_bytes(32), 'hex');
    INSERT INTO tokens (user_id, token, type, expire_le)
    VALUES (v_user_id, v_token, 'reset_mdp', now() + interval '1 hour');

    RETURN v_token;
END;
$$;

CREATE OR REPLACE FUNCTION reinitialiser_mot_de_passe(p_token text, p_hash text)
RETURNS boolean
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
    v_user_id int;
BEGIN
    UPDATE tokens
    SET utilise_le = now()
    WHERE token = p_token
      AND type = 'reset_mdp'
      AND utilise_le IS NULL
      AND expire_le > now()
    RETURNING user_id INTO v_user_id;

    IF v_user_id IS NULL THEN
        RETURN false;
    END IF;

    -- Recevoir ce lien prouve qu'on detient l'adresse : l'email est verifie du
    -- meme coup. Le trigger users_maj_mdp_modifie_le date le changement, ce qui
    -- invalide les sessions ouvertes avant.
    UPDATE users
    SET mot_de_passe_hash = p_hash,
        email_verifie = true
    WHERE id = v_user_id;

    RETURN true;
END;
$$;

-- ============================================================
-- PROCEDURES METIER
-- ============================================================

-- Marquer un item possede / au musee. Upsert, avec obtenu_le / donne_le
-- poses automatiquement au premier passage a true.
-- Laissee en SECURITY INVOKER : la RLS de "collections" s'applique donc, et
-- un appel avec le p_user_id de quelqu'un d'autre est rejete par le WITH CHECK.
CREATE OR REPLACE PROCEDURE marquer_item(
    p_user_id int,
    p_item_id int,
    p_possede boolean DEFAULT NULL,
    p_au_musee boolean DEFAULT NULL
)
LANGUAGE plpgsql AS $$
DECLARE
    v_possede_actuel  boolean;
    v_au_musee_actuel boolean;
    v_possede         boolean;
    v_au_musee        boolean;
BEGIN
    SELECT possede, au_musee
      INTO v_possede_actuel, v_au_musee_actuel
      FROM collections
     WHERE user_id = p_user_id AND item_id = p_item_id;

    -- On calcule d'abord l'etat final complet. PostgreSQL verifie la
    -- contrainte musee_implique_possede sur la ligne PROPOSEE a l'INSERT,
    -- avant d'appliquer le ON CONFLICT DO UPDATE : construire cette ligne a
    -- partir des seuls parametres recus ferait echouer un simple passage au
    -- musee sur un item deja possede.
    v_possede  := coalesce(p_possede,  v_possede_actuel,  false);
    v_au_musee := coalesce(p_au_musee, v_au_musee_actuel, false);

    -- Retirer un item de sa collection le retire aussi du musee.
    IF p_possede = false AND p_au_musee IS NULL THEN
        v_au_musee := false;
    END IF;

    IF v_au_musee AND NOT v_possede THEN
        RAISE EXCEPTION 'Impossible de mettre au musee un item non possede';
    END IF;

    INSERT INTO collections (user_id, item_id, possede, au_musee, obtenu_le, donne_le)
    VALUES (
        p_user_id, p_item_id, v_possede, v_au_musee,
        CASE WHEN v_possede THEN now() END,
        CASE WHEN v_au_musee THEN now() END
    )
    ON CONFLICT (user_id, item_id) DO UPDATE SET
        possede   = v_possede,
        au_musee  = v_au_musee,
        obtenu_le = CASE WHEN v_possede  AND NOT collections.possede  THEN now() ELSE collections.obtenu_le END,
        donne_le  = CASE WHEN v_au_musee AND NOT collections.au_musee THEN now() ELSE collections.donne_le  END;
END;
$$;

CREATE OR REPLACE PROCEDURE envoyer_demande_ami(p_demandeur_id int, p_destinataire_id int)
LANGUAGE plpgsql AS $$
DECLARE
    v_statut statut_amitie;
BEGIN
    IF p_demandeur_id = p_destinataire_id THEN
        RAISE EXCEPTION 'Impossible de s''ajouter soi-meme';
    END IF;

    -- Une seule relation par paire, quel que soit le sens de la demande
    -- (garantie par l'index amities_paire_unique).
    SELECT statut INTO v_statut
    FROM amities
    WHERE (demandeur_id = p_demandeur_id AND destinataire_id = p_destinataire_id)
       OR (demandeur_id = p_destinataire_id AND destinataire_id = p_demandeur_id);

    IF v_statut = 'acceptee' THEN
        RAISE EXCEPTION 'Vous etes deja amis';
    ELSIF v_statut = 'en_attente' THEN
        RAISE EXCEPTION 'Une demande est deja en attente entre vous';
    ELSIF v_statut = 'refusee' THEN
        -- Un refus n'est pas definitif : la nouvelle demande remplace
        -- l'ancienne, dans le sens ou elle est faite cette fois-ci.
        DELETE FROM amities
        WHERE (demandeur_id = p_demandeur_id AND destinataire_id = p_destinataire_id)
           OR (demandeur_id = p_destinataire_id AND destinataire_id = p_demandeur_id);
    END IF;

    INSERT INTO amities (demandeur_id, destinataire_id, statut)
    VALUES (p_demandeur_id, p_destinataire_id, 'en_attente');
END;
$$;

-- Supprime la relation entre deux joueurs, quel que soit son etat : retirer
-- un ami, annuler une demande envoyee. En SECURITY INVOKER comme les autres :
-- la policy DELETE d'amities limite la suppression aux relations dont
-- l'appelant fait partie, un tiers ne trouve donc rien a supprimer.
CREATE OR REPLACE PROCEDURE retirer_ami(p_moi int, p_autre int)
LANGUAGE plpgsql AS $$
BEGIN
    DELETE FROM amities
    WHERE (demandeur_id = p_moi AND destinataire_id = p_autre)
       OR (demandeur_id = p_autre AND destinataire_id = p_moi);

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Aucune relation avec ce joueur';
    END IF;
END;
$$;

CREATE OR REPLACE PROCEDURE repondre_ami(
    p_demandeur_id int,
    p_destinataire_id int,
    p_accepter boolean
)
LANGUAGE plpgsql AS $$
BEGIN
    -- Cast explicite : les litteraux d'un CASE sont resolus en text, que
    -- PostgreSQL n'affecte pas implicitement a une colonne de type enum.
    UPDATE amities
    SET statut = (CASE WHEN p_accepter THEN 'acceptee' ELSE 'refusee' END)::statut_amitie,
        repondu_le = now()
    WHERE demandeur_id = p_demandeur_id
      AND destinataire_id = p_destinataire_id
      AND statut = 'en_attente';

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Aucune demande en attente trouvee';
    END IF;
END;
$$;
