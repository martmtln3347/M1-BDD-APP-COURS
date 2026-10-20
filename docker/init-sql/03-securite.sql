-- ============================================================
-- Nook Manager — roles, privileges et Row Level Security
-- Les mots de passe des roles de connexion sont poses par 04-mots-de-passe.sh
-- (variables d'environnement, jamais en dur dans le depot).
-- ============================================================

-- ---------- Groupe de droits de l'application ----------
CREATE ROLE app_user NOLOGIN;

GRANT USAGE ON SCHEMA public TO app_user;

-- Donnees du jeu : lecture seule, elles sont seedees une fois.
GRANT SELECT ON items TO app_user;

-- Collection : pas de DELETE, on repasse possede/au_musee a false, ce qui
-- garde les dates d'obtention et de don.
GRANT SELECT, INSERT, UPDATE ON collections TO app_user;

-- Amities : le DELETE sert a retirer un ami ou annuler une demande. La policy
-- amities_suppression le limite aux relations dont on fait partie.
GRANT SELECT, INSERT, UPDATE, DELETE ON amities TO app_user;

-- Fil d'actu : lecture seule cote appli, c'est le trigger qui ecrit.
GRANT SELECT ON activites, v_stats_user, v_classement_amis TO app_user;

-- users : lecture pour la connexion (PHP lit le hash pour password_verify,
-- compromis assume), la recherche de joueurs et l'affichage des pseudos.
-- Seule la visibilite est modifiable directement, et la RLS limite cet UPDATE
-- a son propre compte. Creation de compte, verification d'email et
-- changement de mot de passe passent par les fonctions SECURITY DEFINER.
GRANT SELECT ON users TO app_user;
GRANT UPDATE (visibilite) ON users TO app_user;

-- tokens : AUCUN droit, pas meme en lecture. Le role applicatif ne peut ni
-- lire un token en attente, ni en fabriquer un avec la valeur de son choix.

-- ---------- Fonctions et procedures ----------
-- Module 05 : toute nouvelle fonction est EXECUTE par PUBLIC par defaut.
-- On revoque d'abord, on accorde ensuite explicitement.
REVOKE EXECUTE ON FUNCTION app_user_id() FROM PUBLIC;
REVOKE EXECUTE ON FUNCTION peut_voir_profil(int) FROM PUBLIC;
REVOKE EXECUTE ON FUNCTION rafraichir_classement() FROM PUBLIC;
REVOKE EXECUTE ON FUNCTION trg_log_activite() FROM PUBLIC;
REVOKE EXECUTE ON FUNCTION trg_maj_mdp_modifie_le() FROM PUBLIC;
REVOKE EXECUTE ON PROCEDURE marquer_item(int, int, boolean, boolean) FROM PUBLIC;
REVOKE EXECUTE ON PROCEDURE envoyer_demande_ami(int, int) FROM PUBLIC;
REVOKE EXECUTE ON PROCEDURE repondre_ami(int, int, boolean) FROM PUBLIC;
REVOKE EXECUTE ON PROCEDURE retirer_ami(int, int) FROM PUBLIC;
REVOKE EXECUTE ON FUNCTION creer_compte(text, text, text) FROM PUBLIC;
REVOKE EXECUTE ON FUNCTION verifier_email(text) FROM PUBLIC;
REVOKE EXECUTE ON FUNCTION demander_reinitialisation(text) FROM PUBLIC;
REVOKE EXECUTE ON FUNCTION reinitialiser_mot_de_passe(text, text) FROM PUBLIC;

-- app_user a besoin d'EXECUTE sur les deux fonctions de visibilite : les
-- policies RLS les evaluent avec les droits de l'appelant.
GRANT EXECUTE ON FUNCTION app_user_id() TO app_user;
GRANT EXECUTE ON FUNCTION peut_voir_profil(int) TO app_user;
GRANT EXECUTE ON FUNCTION rafraichir_classement() TO app_user;
GRANT EXECUTE ON PROCEDURE marquer_item(int, int, boolean, boolean) TO app_user;
GRANT EXECUTE ON PROCEDURE envoyer_demande_ami(int, int) TO app_user;
GRANT EXECUTE ON PROCEDURE repondre_ami(int, int, boolean) TO app_user;
GRANT EXECUTE ON PROCEDURE retirer_ami(int, int) TO app_user;
GRANT EXECUTE ON FUNCTION creer_compte(text, text, text) TO app_user;
GRANT EXECUTE ON FUNCTION verifier_email(text) TO app_user;
GRANT EXECUTE ON FUNCTION demander_reinitialisation(text) TO app_user;
GRANT EXECUTE ON FUNCTION reinitialiser_mot_de_passe(text, text) TO app_user;

-- ============================================================
-- ROW LEVEL SECURITY
-- ============================================================

-- ---------- users ----------
ALTER TABLE users ENABLE ROW LEVEL SECURITY;

-- Lecture ouverte : la connexion cherche un compte par email avant de savoir
-- qui est l'utilisateur, et les pseudos s'affichent partout. Ce qui est
-- protege, ce sont les donnees d'un profil, portees par les autres tables.
CREATE POLICY users_lecture ON users
FOR SELECT TO app_user
USING (true);

-- Modification : strictement son propre compte. Sans cette policy, une
-- session identifiee comme bob pouvait modifier le compte d'alice.
CREATE POLICY users_modification ON users
FOR UPDATE TO app_user
USING (id = app_user_id())
WITH CHECK (id = app_user_id());

-- ---------- collections ----------
ALTER TABLE collections ENABLE ROW LEVEL SECURITY;

-- Lecture : soi-meme, ou selon la visibilite du proprietaire du profil.
CREATE POLICY collections_lecture ON collections
FOR SELECT TO app_user
USING (peut_voir_profil(user_id));

-- Ecriture : strictement soi-meme.
CREATE POLICY collections_ecriture ON collections
FOR ALL TO app_user
USING (user_id = app_user_id())
WITH CHECK (user_id = app_user_id());

-- Filet de securite : email non verifie = zero acces, lecture comme ecriture.
-- RESTRICTIVE => combinee en AND avec les policies permissives ci-dessus.
CREATE POLICY email_verifie_requis ON collections
AS RESTRICTIVE FOR ALL TO app_user
USING (EXISTS (
    SELECT 1 FROM users u
    WHERE u.id = app_user_id() AND u.email_verifie
));

-- ---------- activites ----------
ALTER TABLE activites ENABLE ROW LEVEL SECURITY;

-- Lecture seule : l'appli n'ecrit jamais directement, c'est le trigger
-- (SECURITY DEFINER) qui insere.
CREATE POLICY activites_lecture ON activites
FOR SELECT TO app_user
USING (peut_voir_profil(user_id));

CREATE POLICY email_verifie_requis ON activites
AS RESTRICTIVE FOR ALL TO app_user
USING (EXISTS (
    SELECT 1 FROM users u
    WHERE u.id = app_user_id() AND u.email_verifie
));

-- ---------- amities ----------
ALTER TABLE amities ENABLE ROW LEVEL SECURITY;

-- Volontairement sans peut_voir_profil() ici : cette fonction interroge
-- elle-meme "amities", une policy qui l'appellerait tournerait en rond.
-- Une relation ne concerne que ses deux participants, la regle suffit.
CREATE POLICY amities_lecture ON amities
FOR SELECT TO app_user
USING (demandeur_id = app_user_id() OR destinataire_id = app_user_id());

-- On n'envoie une demande qu'en son propre nom.
CREATE POLICY amities_creation ON amities
FOR INSERT TO app_user
WITH CHECK (demandeur_id = app_user_id());

-- Retirer un ami ou annuler une demande : chacun des deux participants peut
-- supprimer la relation, un tiers jamais.
CREATE POLICY amities_suppression ON amities
FOR DELETE TO app_user
USING (demandeur_id = app_user_id() OR destinataire_id = app_user_id());

-- Et on ne repond qu'aux demandes qu'on a recues.
CREATE POLICY amities_reponse ON amities
FOR UPDATE TO app_user
USING (destinataire_id = app_user_id())
WITH CHECK (destinataire_id = app_user_id());

CREATE POLICY email_verifie_requis ON amities
AS RESTRICTIVE FOR ALL TO app_user
USING (EXISTS (
    SELECT 1 FROM users u
    WHERE u.id = app_user_id() AND u.email_verifie
));

-- ============================================================
-- ROLES DE CONNEXION
-- ============================================================

-- Role utilise par PHP (PDO) — jamais "postgres".
CREATE ROLE acnh_app LOGIN IN ROLE app_user;

-- Role d'administration (psql, pgAdmin), jamais utilise par l'appli.
CREATE ROLE acnh_admin LOGIN BYPASSRLS;
GRANT USAGE ON SCHEMA public TO acnh_admin;
GRANT ALL ON ALL TABLES IN SCHEMA public TO acnh_admin;
GRANT ALL ON ALL SEQUENCES IN SCHEMA public TO acnh_admin;

-- Pas de role applicatif d'admin (app_admin) : rien n'est prevu en
-- moderation / gestion pour l'instant.
