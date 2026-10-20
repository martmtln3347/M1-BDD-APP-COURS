-- ============================================================
-- Nook Manager — schema (types, tables, index, triggers)
-- Execute automatiquement au premier demarrage du container.
-- ============================================================

-- gen_random_bytes() : les tokens de verification et de reinitialisation sont
-- generes par la base, avec un generateur cryptographique.
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- ---------- Types enum ----------
CREATE TYPE categorie_item AS ENUM ('habitant', 'poisson', 'creature_marine', 'insecte', 'art');
CREATE TYPE statut_amitie AS ENUM ('en_attente', 'acceptee', 'refusee');
CREATE TYPE visibilite_profil AS ENUM ('public', 'amis', 'prive');
CREATE TYPE type_token AS ENUM ('verification_email', 'reset_mdp');
CREATE TYPE type_activite AS ENUM ('obtenu', 'au_musee');

-- ---------- items ----------
-- Table generique unique : une colonne "categorie" plutot que 5 tables.
-- Compromis assume (colonnes nullable selon la categorie) : on perd la 3FN
-- stricte mais on evite les UNION partout et on garde une seule table de
-- liaison "collections".
CREATE TABLE items (
    id                 serial PRIMARY KEY,
    categorie          categorie_item NOT NULL,
    nom                text NOT NULL,          -- nom francais
    nom_vo             text,                   -- nom en version originale (anglais)
    image_url          text,
    image_icone        text,                   -- petite icone, repli si image_url ne charge pas

    -- Disponibilite (poissons / creatures / insectes)
    mois_dispo         int[],
    heure_debut        time,
    heure_fin          time,
    -- Quatre especes ont deux creneaux distincts dans la journee (le piranha
    -- par exemple : 9h-16h puis 21h-4h). heure_debut NULL = toute la journee.
    heure_debut_2      time,
    heure_fin_2        time,
    lieu               text,                   -- ou / comment l'attraper
    eau                text,                   -- 'river' ou 'ocean' : filtre lieu de peche

    -- Habitants
    race               text,
    personnalite       text,
    date_naissance     date,                   -- annee 2000 arbitraire : le jeu ne donne que jour/mois
    amiibo_serie       text,                   -- habitants obtenables uniquement par amiibo
    amiibo_exemples    text,

    prix_boutique      int,                    -- poissons / insectes / creatures

    -- Art : les contrefacons sont des lignes a part, rattachees a l'oeuvre vraie
    type_art           text,                   -- 'painting' ou 'sculpture'
    titre_reel         text,                   -- oeuvre reelle dont s'inspire l'item
    artiste            text,
    detail_contrefacon text,                   -- le detail qui trahit le faux
    est_contrefacon_de int REFERENCES items(id)
);

-- ---------- users ----------
CREATE TABLE users (
    id                serial PRIMARY KEY,
    pseudo            text UNIQUE NOT NULL,
    email             text UNIQUE NOT NULL,
    mot_de_passe_hash text NOT NULL,
    visibilite        visibilite_profil NOT NULL DEFAULT 'public',
    email_verifie     boolean NOT NULL DEFAULT false,
    mdp_modifie_le    timestamptz,
    cree_le           timestamptz NOT NULL DEFAULT now()
);

-- ---------- tokens (verification email + reset mdp) ----------
CREATE TABLE tokens (
    id         serial PRIMARY KEY,
    user_id    int NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token      text NOT NULL UNIQUE,
    type       type_token NOT NULL,
    expire_le  timestamptz NOT NULL,
    utilise_le timestamptz,
    cree_le    timestamptz NOT NULL DEFAULT now()
);

-- ---------- collections (coeur du projet) ----------
CREATE TABLE collections (
    user_id   int NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    item_id   int NOT NULL REFERENCES items(id) ON DELETE CASCADE,
    possede   boolean NOT NULL DEFAULT false,
    au_musee  boolean NOT NULL DEFAULT false,
    obtenu_le timestamptz,
    donne_le  timestamptz,
    PRIMARY KEY (user_id, item_id),
    CONSTRAINT musee_implique_possede CHECK (NOT au_musee OR possede)
);

-- ---------- amities ----------
CREATE TABLE amities (
    demandeur_id    int NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    destinataire_id int NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    statut          statut_amitie NOT NULL DEFAULT 'en_attente',
    demande_le      timestamptz NOT NULL DEFAULT now(),
    repondu_le      timestamptz,
    PRIMARY KEY (demandeur_id, destinataire_id),
    CONSTRAINT pas_ami_avec_soi_meme CHECK (demandeur_id <> destinataire_id)
);

-- ---------- activites (fil d'actu, remplie par trigger) ----------
CREATE TABLE activites (
    id      serial PRIMARY KEY,
    user_id int NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    item_id int NOT NULL REFERENCES items(id) ON DELETE CASCADE,
    type    type_activite NOT NULL,
    cree_le timestamptz NOT NULL DEFAULT now()
);

-- ============================================================
-- INDEX
-- ============================================================
-- Pas d'index sur collections(user_id) ni amities(demandeur_id) : ce sont les
-- colonnes de tete de leur PRIMARY KEY, l'index de la PK les couvre deja.
-- Pas d'index sur users(email) / users(pseudo) : la contrainte UNIQUE cree
-- deja un index unique sur chacune.
CREATE INDEX idx_collections_item ON collections (item_id);
CREATE INDEX idx_amities_destinataire ON amities (destinataire_id);

-- Une seule relation par paire de joueurs, quel que soit le sens : (A, B) et
-- (B, A) designent la meme amitie, et la cle primaire seule ne l'empeche pas.
-- La contrainte vit dans le schema, pas seulement dans la procedure.
CREATE UNIQUE INDEX amities_paire_unique ON amities (
    LEAST(demandeur_id, destinataire_id),
    GREATEST(demandeur_id, destinataire_id)
);
CREATE INDEX idx_tokens_user ON tokens (user_id);
CREATE INDEX idx_items_categorie ON items (categorie);
CREATE INDEX idx_activites_user ON activites (user_id);
CREATE INDEX idx_activites_date ON activites (cree_le DESC);

-- Volontairement NON indexe : visibilite, possede, au_musee, statut.
-- Colonnes a 2-3 valeurs possibles : trop peu selectives, le planificateur
-- prefererait de toute facon un seq scan.

-- ============================================================
-- TRIGGERS
-- ============================================================

-- Fil d'actu : une ligne dans "activites" quand un item passe a possede
-- ou au musee (jamais sur un simple re-enregistrement de la meme valeur).
-- SECURITY DEFINER : l'appli (role app_user) n'a pas le droit d'INSERT dans
-- "activites" et la RLS n'y autorise que la lecture. Le trigger doit donc
-- s'executer avec les droits de son proprietaire pour ecrire la ligne.
CREATE OR REPLACE FUNCTION trg_log_activite()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, pg_temp
AS $$
BEGIN
    IF NEW.possede AND (TG_OP = 'INSERT' OR NOT OLD.possede) THEN
        INSERT INTO activites (user_id, item_id, type)
        VALUES (NEW.user_id, NEW.item_id, 'obtenu');
    END IF;

    IF NEW.au_musee AND (TG_OP = 'INSERT' OR NOT OLD.au_musee) THEN
        INSERT INTO activites (user_id, item_id, type)
        VALUES (NEW.user_id, NEW.item_id, 'au_musee');
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER collections_log_activite
AFTER INSERT OR UPDATE ON collections
FOR EACH ROW
EXECUTE FUNCTION trg_log_activite();

-- Horodatage automatique du changement de mot de passe (modele trg_maj_le,
-- module 04) : evite que l'appli oublie de mettre mdp_modifie_le a jour.
CREATE OR REPLACE FUNCTION trg_maj_mdp_modifie_le()
RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.mot_de_passe_hash IS DISTINCT FROM OLD.mot_de_passe_hash THEN
        NEW.mdp_modifie_le := now();
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER users_maj_mdp_modifie_le
BEFORE UPDATE ON users
FOR EACH ROW
EXECUTE FUNCTION trg_maj_mdp_modifie_le();
