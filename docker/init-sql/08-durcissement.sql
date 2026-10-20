-- ============================================================
-- Nook Manager — durcissement (audit OWASP Top 10)
--
--  A02 : les tokens ne sont plus stockes en clair, seulement leur empreinte
--  A01 : le role applicatif ne lit plus l'email ni le hash des autres comptes
--  A04/A07 : limitation des tentatives (connexion, inscription, reset)
--
-- Rejouable a la main sur une base existante :
--   docker compose exec -T db psql -U postgres -d nook_manager < db/init/08-durcissement.sql
-- ============================================================

-- ---------- Tokens : empreinte SHA-256 en base ----------
-- Le token en clair n'existe que dans le lien envoye a l'utilisateur. Une
-- fuite de la table (sauvegarde, injection) ne donne donc aucun lien
-- utilisable : il faudrait inverser un SHA-256 de 256 bits aleatoires.

CREATE OR REPLACE FUNCTION creer_compte(p_pseudo text, p_email text, p_hash text)
RETURNS text  -- token de verification d'email (en clair, pour le lien)
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
DECLARE
    v_user_id int;
    v_token   text := encode(gen_random_bytes(32), 'hex');
BEGIN
    INSERT INTO users (pseudo, email, mot_de_passe_hash)
    VALUES (p_pseudo, p_email, p_hash)
    RETURNING id INTO v_user_id;

    INSERT INTO tokens (user_id, token, type, expire_le)
    VALUES (v_user_id, encode(digest(v_token, 'sha256'), 'hex'),
            'verification_email', now() + interval '24 hours');

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
    UPDATE tokens
    SET utilise_le = now()
    WHERE token = encode(digest(p_token, 'sha256'), 'hex')
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

CREATE OR REPLACE FUNCTION demander_reinitialisation(p_email text)
RETURNS text
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

    UPDATE tokens
    SET utilise_le = now()
    WHERE user_id = v_user_id AND type = 'reset_mdp' AND utilise_le IS NULL;

    v_token := encode(gen_random_bytes(32), 'hex');
    INSERT INTO tokens (user_id, token, type, expire_le)
    VALUES (v_user_id, encode(digest(v_token, 'sha256'), 'hex'),
            'reset_mdp', now() + interval '1 hour');

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
    WHERE token = encode(digest(p_token, 'sha256'), 'hex')
      AND type = 'reset_mdp'
      AND utilise_le IS NULL
      AND expire_le > now()
    RETURNING user_id INTO v_user_id;

    IF v_user_id IS NULL THEN
        RETURN false;
    END IF;

    UPDATE users
    SET mot_de_passe_hash = p_hash,
        email_verifie = true
    WHERE id = v_user_id;

    RETURN true;
END;
$$;

-- ---------- users : plus de lecture de l'email ni du hash ----------
-- Jusqu'ici la policy users_lecture laissait tout joueur lire TOUTES les
-- lignes de users, hash compris : une seule faille d'injection dans l'appli
-- aurait livre tous les emails et tous les hashes. Les privileges par colonne
-- ferment ca au niveau de la base, quoi que fasse l'application.
REVOKE SELECT ON users FROM app_user;
GRANT SELECT (id, pseudo, visibilite, email_verifie, mdp_modifie_le, cree_le,
              avatar_item_id, avatar_categorie)
    ON users TO app_user;

-- La connexion a pourtant besoin du hash : elle passe par une fonction qui ne
-- renvoie qu'UN compte, designe par son email exact. Pas de recherche, pas de
-- liste.
CREATE OR REPLACE FUNCTION identifiants_connexion(p_email text)
RETURNS TABLE (
    id int, pseudo text, mot_de_passe_hash text, email_verifie boolean,
    visibilite visibilite_profil, maintenant timestamptz
)
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
    SELECT u.id, u.pseudo, u.mot_de_passe_hash, u.email_verifie, u.visibilite, now()
    FROM users u
    WHERE u.email = p_email;
$$;
REVOKE EXECUTE ON FUNCTION identifiants_connexion(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION identifiants_connexion(text) TO app_user;

-- ---------- Limitation des tentatives ----------
-- Table sans aucun droit pour le role applicatif : tout passe par trois
-- fonctions SECURITY DEFINER. La cle est choisie par l'application
-- ("connexion:ip:1.2.3.4", "connexion:mail:<empreinte>"...).
CREATE TABLE IF NOT EXISTS tentatives_auth (
    id      bigserial PRIMARY KEY,
    cle     text NOT NULL,
    cree_le timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_tentatives_cle ON tentatives_auth (cle, cree_le);
REVOKE ALL ON tentatives_auth FROM PUBLIC;

CREATE OR REPLACE FUNCTION trop_de_tentatives(p_cle text, p_max int, p_fenetre interval)
RETURNS boolean
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
    SELECT count(*) >= p_max
    FROM tentatives_auth
    WHERE cle = p_cle AND cree_le > now() - p_fenetre;
$$;

CREATE OR REPLACE FUNCTION noter_tentative(p_cle text)
RETURNS void
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
BEGIN
    INSERT INTO tentatives_auth (cle) VALUES (p_cle);
    -- Menage au passage : deux fois sur cent, on purge ce qui date de plus d'un jour.
    IF random() < 0.02 THEN
        DELETE FROM tentatives_auth WHERE cree_le < now() - interval '1 day';
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION oublier_tentatives(p_cle text)
RETURNS void
LANGUAGE sql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
    DELETE FROM tentatives_auth WHERE cle = p_cle;
$$;

REVOKE EXECUTE ON FUNCTION trop_de_tentatives(text, int, interval) FROM PUBLIC;
REVOKE EXECUTE ON FUNCTION noter_tentative(text) FROM PUBLIC;
REVOKE EXECUTE ON FUNCTION oublier_tentatives(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION trop_de_tentatives(text, int, interval) TO app_user;
GRANT EXECUTE ON FUNCTION noter_tentative(text) TO app_user;
GRANT EXECUTE ON FUNCTION oublier_tentatives(text) TO app_user;

GRANT ALL ON tentatives_auth TO acnh_admin;
GRANT ALL ON SEQUENCE tentatives_auth_id_seq TO acnh_admin;
