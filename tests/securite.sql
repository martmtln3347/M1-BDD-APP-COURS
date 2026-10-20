-- ============================================================
-- Nook Manager - tests de securite de la base
--
-- Verifie la Row Level Security, les privileges, les procedures et les
-- triggers en agissant comme l'application : role acnh_app, identite posee
-- par set_config('app.user_id', ...).
--
-- Tout se joue dans une transaction ANNULEE a la fin : aucune donnee n'est
-- conservee, le script peut tourner sur la base de developpement.
--
-- Lancer depuis la racine du projet :
--   docker compose -f docker/docker-compose.yml exec -T db \
--       psql -U postgres -d nook_manager < tests/securite.sql
--
-- Le script s'arrete au premier echec, avec un code de sortie non nul.
-- ============================================================

\set ON_ERROR_STOP on
\set QUIET on
\pset tuples_only on
\pset format unaligned

BEGIN;

-- ---------- Outils de test ----------
-- Crees dans pg_temp : ils disparaissent avec la session.

CREATE FUNCTION pg_temp.verifier(p_condition boolean, p_libelle text)
RETURNS text LANGUAGE plpgsql AS $$
BEGIN
    IF p_condition IS NOT TRUE THEN
        RAISE EXCEPTION 'ECHEC : %', p_libelle;
    END IF;
    RETURN '  ok  ' || p_libelle;
END;
$$;

-- Execute une instruction qui DOIT etre refusee. Le bloc EXCEPTION ouvre une
-- sous-transaction : le refus attendu n'interrompt pas les tests suivants.
CREATE FUNCTION pg_temp.doit_echouer(p_sql text, p_libelle text)
RETURNS text LANGUAGE plpgsql AS $$
BEGIN
    BEGIN
        EXECUTE p_sql;
    EXCEPTION WHEN OTHERS THEN
        RETURN '  ok  ' || p_libelle || '  [' || SQLERRM || ']';
    END;
    RAISE EXCEPTION 'ECHEC : % (l''instruction aurait du etre refusee)', p_libelle;
END;
$$;

-- Change d'identite applicative, comme le fait PHP apres la connexion.
CREATE FUNCTION pg_temp.en_tant_que(p_pseudo text)
RETURNS text LANGUAGE plpgsql AS $$
BEGIN
    PERFORM set_config('app.user_id', (SELECT id::text FROM users WHERE pseudo = p_pseudo), false);
    RETURN '  [connecte : ' || p_pseudo || ']';
END;
$$;

CREATE FUNCTION pg_temp.uid(p_pseudo text) RETURNS int
LANGUAGE sql STABLE AS $$ SELECT id FROM users WHERE pseudo = p_pseudo $$;

CREATE FUNCTION pg_temp.iid(p_nom text) RETURNS int
LANGUAGE sql STABLE AS $$ SELECT id FROM items WHERE nom = p_nom $$;

-- ---------- Jeu d'essai ----------
-- Insere en tant que postgres, proprietaire des tables : la RLS ne s'applique
-- pas encore.

INSERT INTO items (categorie, nom) VALUES
    ('poisson', 'rls_item_1'),
    ('poisson', 'rls_item_2');

INSERT INTO users (pseudo, email, mot_de_passe_hash, email_verifie, visibilite) VALUES
    ('rls_moi',         'rls_moi@test.invalid',         'x', true,  'public'),
    ('rls_public',      'rls_public@test.invalid',      'x', true,  'public'),
    ('rls_amis',        'rls_amis@test.invalid',        'x', true,  'amis'),
    ('rls_prive',       'rls_prive@test.invalid',       'x', true,  'prive'),
    ('rls_non_verifie', 'rls_non_verifie@test.invalid', 'x', false, 'public');

-- Chacun des autres joueurs possede deja un item (le trigger remplit aussi le
-- fil d'actu).
INSERT INTO collections (user_id, item_id, possede)
SELECT id, pg_temp.iid('rls_item_1'), true
FROM users
WHERE pseudo IN ('rls_public', 'rls_amis', 'rls_prive', 'rls_non_verifie');

-- A partir d'ici, on agit comme l'application.
SET ROLE acnh_app;

-- ============================================================
\echo ''
\echo 'Visibilite des profils'
-- ============================================================
SELECT pg_temp.en_tant_que('rls_moi');

SELECT pg_temp.verifier(
    (SELECT count(*) FROM collections WHERE user_id = pg_temp.uid('rls_public')) = 1,
    'un profil public est visible');

SELECT pg_temp.verifier(
    (SELECT count(*) FROM collections WHERE user_id = pg_temp.uid('rls_amis')) = 0,
    'un profil reserve aux amis est invisible pour un non-ami');

SELECT pg_temp.verifier(
    (SELECT count(*) FROM collections WHERE user_id = pg_temp.uid('rls_prive')) = 0,
    'un profil prive est invisible');

SELECT pg_temp.verifier(
    NOT EXISTS (SELECT 1 FROM v_stats_user WHERE user_id = pg_temp.uid('rls_prive')),
    'les stats d''un profil prive sont invisibles (vue en security_invoker)');

SELECT pg_temp.verifier(
    NOT EXISTS (SELECT 1 FROM activites WHERE user_id = pg_temp.uid('rls_prive')),
    'le fil d''actu d''un profil prive est invisible');

DO $$ BEGIN PERFORM rafraichir_classement(); END $$;

SELECT pg_temp.verifier(
    EXISTS (SELECT 1 FROM v_classement_amis WHERE user_id = pg_temp.uid('rls_public')),
    'un profil public apparait au classement');

SELECT pg_temp.verifier(
    NOT EXISTS (SELECT 1 FROM v_classement_amis WHERE user_id = pg_temp.uid('rls_prive')),
    'un profil prive est absent du classement (vue materialisee sans RLS, filtree par v_classement_amis)');

-- ============================================================
\echo ''
\echo 'Collection et fil d''actu'
-- ============================================================

SELECT pg_temp.doit_echouer(
    format('INSERT INTO collections (user_id, item_id, possede) VALUES (%s, %s, true)',
           pg_temp.uid('rls_public'), pg_temp.iid('rls_item_2')),
    'ecrire dans la collection d''un autre est refuse');

SELECT pg_temp.doit_echouer(
    format('CALL marquer_item(%s, %s, NULL, true)',
           pg_temp.uid('rls_moi'), pg_temp.iid('rls_item_1')),
    'mettre au musee un item non possede est refuse');

CALL marquer_item(pg_temp.uid('rls_moi'), pg_temp.iid('rls_item_1'), true, NULL);

SELECT pg_temp.verifier(
    (SELECT possede AND obtenu_le IS NOT NULL FROM collections
     WHERE user_id = pg_temp.uid('rls_moi') AND item_id = pg_temp.iid('rls_item_1')),
    'marquer un item possede pose obtenu_le');

CALL marquer_item(pg_temp.uid('rls_moi'), pg_temp.iid('rls_item_1'), NULL, true);

SELECT pg_temp.verifier(
    (SELECT au_musee AND donne_le IS NOT NULL FROM collections
     WHERE user_id = pg_temp.uid('rls_moi') AND item_id = pg_temp.iid('rls_item_1')),
    'mettre au musee un item deja possede fonctionne');

SELECT pg_temp.verifier(
    (SELECT array_agg(type::text ORDER BY id) FROM activites
     WHERE user_id = pg_temp.uid('rls_moi')) = ARRAY['obtenu', 'au_musee'],
    'le trigger a ecrit les activites obtenu puis au_musee');

CALL marquer_item(pg_temp.uid('rls_moi'), pg_temp.iid('rls_item_1'), false, NULL);

SELECT pg_temp.verifier(
    (SELECT NOT possede AND NOT au_musee FROM collections
     WHERE user_id = pg_temp.uid('rls_moi') AND item_id = pg_temp.iid('rls_item_1')),
    'ne plus posseder un item le retire aussi du musee');

SELECT pg_temp.doit_echouer(
    format('INSERT INTO activites (user_id, item_id, type) VALUES (%s, %s, ''obtenu'')',
           pg_temp.uid('rls_moi'), pg_temp.iid('rls_item_2')),
    'ecrire directement dans le fil d''actu est refuse');

-- ============================================================
\echo ''
\echo 'Email non verifie (policies RESTRICTIVE)'
-- ============================================================
SELECT pg_temp.en_tant_que('rls_non_verifie');

SELECT pg_temp.verifier(
    (SELECT count(*) FROM collections) = 0,
    'aucune ligne de collection visible, pas meme les siennes');

SELECT pg_temp.verifier(
    (SELECT count(*) FROM activites) = 0,
    'aucune activite visible');

SELECT pg_temp.doit_echouer(
    format('CALL marquer_item(%s, %s, true, NULL)',
           pg_temp.uid('rls_non_verifie'), pg_temp.iid('rls_item_2')),
    'ecrire dans sa propre collection est refuse');

SELECT pg_temp.doit_echouer(
    format('CALL envoyer_demande_ami(%s, %s)',
           pg_temp.uid('rls_non_verifie'), pg_temp.uid('rls_moi')),
    'envoyer une demande d''ami est refuse');

-- ============================================================
\echo ''
\echo 'Amities'
-- ============================================================
SELECT pg_temp.en_tant_que('rls_moi');

SELECT pg_temp.doit_echouer(
    format('CALL envoyer_demande_ami(%1$s, %1$s)', pg_temp.uid('rls_moi')),
    'on ne peut pas s''ajouter soi-meme');

CALL envoyer_demande_ami(pg_temp.uid('rls_moi'), pg_temp.uid('rls_amis'));

SELECT pg_temp.verifier(
    EXISTS (SELECT 1 FROM amities
            WHERE demandeur_id = pg_temp.uid('rls_moi')
              AND destinataire_id = pg_temp.uid('rls_amis')
              AND statut = 'en_attente'),
    'une demande d''ami est creee en attente');

SELECT pg_temp.doit_echouer(
    format('CALL envoyer_demande_ami(%s, %s)', pg_temp.uid('rls_moi'), pg_temp.uid('rls_amis')),
    'une demande en double est refusee');

SELECT pg_temp.doit_echouer(
    format('CALL repondre_ami(%s, %s, true)', pg_temp.uid('rls_moi'), pg_temp.uid('rls_amis')),
    'le demandeur ne peut pas accepter sa propre demande');

SELECT pg_temp.en_tant_que('rls_amis');

SELECT pg_temp.doit_echouer(
    format('INSERT INTO amities (demandeur_id, destinataire_id) VALUES (%s, %s)',
           pg_temp.uid('rls_amis'), pg_temp.uid('rls_moi')),
    'une seconde relation en sens inverse est refusee (index de paire unique)');

CALL repondre_ami(pg_temp.uid('rls_moi'), pg_temp.uid('rls_amis'), true);

SELECT pg_temp.en_tant_que('rls_moi');

SELECT pg_temp.verifier(
    (SELECT count(*) FROM collections WHERE user_id = pg_temp.uid('rls_amis')) = 1,
    'une fois amis, le profil reserve aux amis devient visible');

SELECT pg_temp.en_tant_que('rls_public');

SELECT pg_temp.verifier(
    NOT EXISTS (SELECT 1 FROM amities
                WHERE pg_temp.uid('rls_moi') IN (demandeur_id, destinataire_id)),
    'un tiers ne voit pas la relation des autres');

SELECT pg_temp.doit_echouer(
    format('CALL retirer_ami(%s, %s)', pg_temp.uid('rls_moi'), pg_temp.uid('rls_amis')),
    'un tiers ne peut pas supprimer la relation des autres');

SELECT pg_temp.en_tant_que('rls_moi');

CALL retirer_ami(pg_temp.uid('rls_moi'), pg_temp.uid('rls_amis'));

SELECT pg_temp.verifier(
    NOT EXISTS (SELECT 1 FROM amities
                WHERE pg_temp.uid('rls_amis') IN (demandeur_id, destinataire_id)),
    'retirer un ami supprime la relation');

SELECT pg_temp.verifier(
    (SELECT count(*) FROM collections WHERE user_id = pg_temp.uid('rls_amis')) = 0,
    'apres le retrait, le profil reserve aux amis redevient invisible');

SELECT pg_temp.doit_echouer(
    format('CALL retirer_ami(%s, %s)', pg_temp.uid('rls_moi'), pg_temp.uid('rls_amis')),
    'retirer une relation inexistante est refuse');

CALL envoyer_demande_ami(pg_temp.uid('rls_moi'), pg_temp.uid('rls_prive'));
SELECT pg_temp.en_tant_que('rls_prive');
CALL repondre_ami(pg_temp.uid('rls_moi'), pg_temp.uid('rls_prive'), false);
SELECT pg_temp.en_tant_que('rls_moi');
CALL envoyer_demande_ami(pg_temp.uid('rls_moi'), pg_temp.uid('rls_prive'));

SELECT pg_temp.verifier(
    (SELECT statut = 'en_attente' FROM amities
     WHERE demandeur_id = pg_temp.uid('rls_moi')
       AND destinataire_id = pg_temp.uid('rls_prive')),
    'apres un refus, une nouvelle demande est possible');

CALL retirer_ami(pg_temp.uid('rls_moi'), pg_temp.uid('rls_prive'));

SELECT pg_temp.verifier(
    NOT EXISTS (SELECT 1 FROM amities
                WHERE pg_temp.uid('rls_prive') IN (demandeur_id, destinataire_id)),
    'annuler une demande envoyee la supprime');

-- ============================================================
\echo ''
\echo 'Comptes : chacun ne modifie que le sien'
-- ============================================================
SELECT pg_temp.en_tant_que('rls_moi');

-- Une policy RLS sur UPDATE ne leve pas d'erreur : elle rend simplement les
-- lignes des autres invisibles a la modification. On verifie donc l'effet.
UPDATE users SET visibilite = 'prive' WHERE id = pg_temp.uid('rls_public');

SELECT pg_temp.verifier(
    (SELECT visibilite = 'public' FROM users WHERE id = pg_temp.uid('rls_public')),
    'modifier la visibilite d''un autre compte reste sans effet (policy users_modification)');

UPDATE users SET visibilite = 'amis' WHERE id = pg_temp.uid('rls_moi');

SELECT pg_temp.verifier(
    (SELECT visibilite = 'amis' FROM users WHERE id = pg_temp.uid('rls_moi')),
    'modifier la visibilite de son propre compte fonctionne');

SELECT pg_temp.doit_echouer(
    format('UPDATE users SET mot_de_passe_hash = ''pirate'' WHERE id = %s', pg_temp.uid('rls_public')),
    'remplacer le mot de passe d''un autre compte est refuse');

SELECT pg_temp.doit_echouer(
    format('UPDATE users SET mot_de_passe_hash = ''pirate'' WHERE id = %s', pg_temp.uid('rls_moi')),
    'meme son propre mot de passe ne se modifie pas directement');

SELECT pg_temp.doit_echouer(
    format('UPDATE users SET pseudo = ''pirate'' WHERE id = %s', pg_temp.uid('rls_moi')),
    'modifier un pseudo est refuse');

SELECT pg_temp.doit_echouer(
    'INSERT INTO users (pseudo, email, mot_de_passe_hash, email_verifie)
     VALUES (''pirate'', ''pirate@test.invalid'', ''x'', true)',
    'creer directement un compte deja verifie est refuse');

-- ============================================================
\echo ''
\echo 'Tokens : ni lisibles, ni forgeables'
-- ============================================================

SELECT pg_temp.doit_echouer(
    'SELECT count(*) FROM tokens',
    'lire la table tokens est refuse');

SELECT pg_temp.doit_echouer(
    format('INSERT INTO tokens (user_id, token, type, expire_le)
            VALUES (%s, ''token_choisi'', ''reset_mdp'', now() + interval ''1 hour'')',
           pg_temp.uid('rls_public')),
    'forger un token de reinitialisation est refuse');

-- Inscription, comme le fait l'application.
SELECT creer_compte('rls_nouveau', 'rls_nouveau@test.invalid', '$2y$10$hash_factice') AS token_verif \gset

SELECT pg_temp.verifier(
    :'token_verif' ~ '^[0-9a-f]{64}$'
    AND (SELECT NOT email_verifie FROM users WHERE pseudo = 'rls_nouveau'),
    'creer_compte cree un compte non verifie et genere un token de 64 caracteres');

SELECT pg_temp.verifier(
    NOT verifier_email('token_invente'),
    'un token de verification invente est refuse');

-- Deux instructions distinctes : une requete lit la base telle qu'elle etait
-- a son debut, elle ne verrait pas l'UPDATE fait par la fonction qu'elle
-- appelle elle-meme.
SELECT pg_temp.verifier(
    verifier_email(:'token_verif'),
    'le bon token est accepte');

SELECT pg_temp.verifier(
    (SELECT email_verifie FROM users WHERE pseudo = 'rls_nouveau'),
    'et le compte est marque verifie');

SELECT pg_temp.verifier(
    NOT verifier_email(:'token_verif'),
    'un token de verification ne sert qu''une fois');

-- Reinitialisation du mot de passe.
SELECT pg_temp.verifier(
    demander_reinitialisation('personne@test.invalid') IS NULL,
    'un email inconnu ne produit aucun token');

SELECT creer_compte('rls_autre', 'rls_autre@test.invalid', '$2y$10$hash_factice') AS token_verif_autre \gset

SELECT pg_temp.verifier(
    NOT reinitialiser_mot_de_passe(:'token_verif_autre', '$2y$10$pirate'),
    'un token de verification ne permet pas de reinitialiser un mot de passe');

SELECT demander_reinitialisation('rls_nouveau@test.invalid') AS token_1 \gset
SELECT demander_reinitialisation('rls_nouveau@test.invalid') AS token_2 \gset

SELECT pg_temp.verifier(
    NOT reinitialiser_mot_de_passe(:'token_1', '$2y$10$nouveau'),
    'un lien remplace par une demande plus recente est refuse');

SELECT pg_temp.verifier(
    reinitialiser_mot_de_passe(:'token_2', '$2y$10$nouveau'),
    'le dernier lien demande reinitialise le mot de passe');

SELECT pg_temp.verifier(
    (SELECT mot_de_passe_hash = '$2y$10$nouveau'
     FROM identifiants_connexion('rls_nouveau@test.invalid'))
    AND (SELECT mdp_modifie_le IS NOT NULL FROM users WHERE pseudo = 'rls_nouveau'),
    'le hash est remplace et mdp_modifie_le pose par le trigger');

SELECT pg_temp.verifier(
    NOT reinitialiser_mot_de_passe(:'token_2', '$2y$10$encore'),
    'un lien de reinitialisation ne sert qu''une fois');

-- Vieillir un token demande les droits du proprietaire : le role applicatif
-- n'a justement aucun acces a la table.
SELECT demander_reinitialisation('rls_nouveau@test.invalid') AS token_expire \gset
RESET ROLE;
UPDATE tokens SET expire_le = now() - interval '1 minute'
WHERE token = encode(digest(:'token_expire', 'sha256'), 'hex');
SET ROLE acnh_app;

SELECT pg_temp.verifier(
    NOT reinitialiser_mot_de_passe(:'token_expire', '$2y$10$encore'),
    'un lien expire est refuse');

-- ============================================================
\echo ''
\echo 'Durcissement : secrets, empreintes, limitation des tentatives'
-- ============================================================
SELECT pg_temp.en_tant_que('rls_moi');

SELECT pg_temp.doit_echouer(
    'SELECT email FROM users',
    'l''email des autres comptes n''est pas lisible par l''application');

SELECT pg_temp.doit_echouer(
    'SELECT mot_de_passe_hash FROM users',
    'le hash des mots de passe n''est pas lisible par l''application');

SELECT pg_temp.verifier(
    (SELECT count(*) FROM identifiants_connexion('rls_public@test.invalid')) = 1,
    'identifiants_connexion renvoie exactement le compte demande');

SELECT pg_temp.verifier(
    (SELECT count(*) FROM identifiants_connexion('%')) = 0
    AND (SELECT count(*) FROM identifiants_connexion('inconnu@test.invalid')) = 0,
    'identifiants_connexion ne sert ni a lister ni a chercher (pas de joker)');

-- Les tokens sont stockes sous forme d'empreinte : en clair, ils ne figurent nulle part.
SELECT creer_compte('rls_hash', 'rls_hash@test.invalid', '$2y$10$x') AS token_clair \gset
RESET ROLE;
SELECT pg_temp.verifier(
    NOT EXISTS (SELECT 1 FROM tokens WHERE token = :'token_clair')
    AND EXISTS (SELECT 1 FROM tokens WHERE token = encode(digest(:'token_clair', 'sha256'), 'hex')),
    'le token n''est stocke que sous forme d''empreinte SHA-256');
SET ROLE acnh_app;

-- Limitation des tentatives : quota par cle, remise a zero possible.
SELECT noter_tentative('test:cle') FROM generate_series(1, 3);
SELECT pg_temp.verifier(
    trop_de_tentatives('test:cle', 3, interval '15 minutes')
    AND NOT trop_de_tentatives('test:cle', 4, interval '15 minutes')
    AND NOT trop_de_tentatives('test:autre', 3, interval '15 minutes'),
    'trop_de_tentatives compte par cle et par fenetre');

SELECT oublier_tentatives('test:cle');
SELECT pg_temp.verifier(
    NOT trop_de_tentatives('test:cle', 1, interval '15 minutes'),
    'oublier_tentatives remet le compteur a zero');

SELECT pg_temp.doit_echouer(
    'SELECT * FROM tentatives_auth',
    'la table des tentatives n''est pas accessible a l''application');

-- ============================================================
\echo ''
\echo 'Photo de profil, classements par categorie, comparaison'
-- ============================================================
RESET ROLE;
INSERT INTO items (categorie, nom, image_icone) VALUES ('habitant', 'rls_habitant', 'https://exemple.invalid/h.png');
SET ROLE acnh_app;
SELECT pg_temp.en_tant_que('rls_moi');

SELECT pg_temp.doit_echouer(
    format('UPDATE users SET avatar_item_id = %s WHERE id = %s', pg_temp.iid('rls_item_1'), pg_temp.uid('rls_moi')),
    'une photo de profil ne peut pas etre un poisson (cle etrangere composee)');

UPDATE users SET avatar_item_id = pg_temp.iid('rls_habitant') WHERE id = pg_temp.uid('rls_moi');
SELECT pg_temp.verifier(
    (SELECT avatar_url FROM v_avatars WHERE user_id = pg_temp.uid('rls_moi')) = 'https://exemple.invalid/h.png',
    'un habitant peut etre choisi comme photo de profil');

UPDATE users SET avatar_item_id = pg_temp.iid('rls_habitant') WHERE id = pg_temp.uid('rls_public');
SELECT pg_temp.verifier(
    (SELECT avatar_url FROM v_avatars WHERE user_id = pg_temp.uid('rls_public')) IS NULL,
    'on ne change pas la photo de quelqu''un d''autre (policy users_modification)');

DO $$ BEGIN PERFORM rafraichir_classement(); END $$;
SELECT pg_temp.verifier(
    EXISTS (SELECT 1 FROM v_classement_categorie WHERE user_id = pg_temp.uid('rls_public') AND categorie = 'poisson')
    AND NOT EXISTS (SELECT 1 FROM v_classement_categorie WHERE user_id = pg_temp.uid('rls_prive')),
    'le classement par categorie respecte la visibilite des profils');

SELECT pg_temp.verifier(
    (SELECT nb_possesseurs FROM v_popularite_items WHERE item_id = pg_temp.iid('rls_item_1'))
      = (SELECT count(*) FROM collections WHERE item_id = pg_temp.iid('rls_item_1') AND possede),
    'la popularite d''un item ne compte que les profils visibles');

CALL marquer_item(pg_temp.uid('rls_moi'), pg_temp.iid('rls_item_2'), true, NULL);

SELECT pg_temp.verifier(
    (SELECT statut FROM comparer_collections(pg_temp.uid('rls_public')) WHERE item_id = pg_temp.iid('rls_item_1')) = 'lui_seulement'
    AND (SELECT statut FROM comparer_collections(pg_temp.uid('rls_public')) WHERE item_id = pg_temp.iid('rls_item_2')) = 'moi_seulement',
    'comparer_collections separe ce que j''ai, ce que l''autre a, et le commun');

SELECT pg_temp.verifier(
    (SELECT count(*) FROM comparer_collections(pg_temp.uid('rls_prive'))) = 0
    AND (SELECT count(*) FROM comparer_collections(pg_temp.uid('rls_amis'))) = 0,
    'comparer un profil prive ou reserve aux amis ne renvoie rien');


-- ============================================================

-- ============================================================
\echo ''
\echo 'Requetes avancees, CRUD complet (suppression)'
-- ============================================================
SELECT pg_temp.en_tant_que('rls_moi');

-- DELETE sur MA collection seulement
CALL marquer_item(pg_temp.uid('rls_moi'), pg_temp.iid('rls_item_2'), true, NULL);
DELETE FROM collections WHERE user_id = pg_temp.uid('rls_moi') AND item_id = pg_temp.iid('rls_item_2');
SELECT pg_temp.verifier(
    NOT EXISTS (SELECT 1 FROM collections WHERE user_id = pg_temp.uid('rls_moi') AND item_id = pg_temp.iid('rls_item_2')),
    'DELETE : je peux retirer un element de ma collection');

DELETE FROM collections WHERE user_id = pg_temp.uid('rls_public');
RESET ROLE;
SELECT pg_temp.verifier(
    EXISTS (SELECT 1 FROM collections WHERE user_id = pg_temp.uid('rls_public')),
    'DELETE : la collection de quelqu''un d''autre reste intacte (RLS)');
SET ROLE acnh_app;
SELECT pg_temp.en_tant_que('rls_moi');

-- DELETE d'un compte : le mien seulement
DELETE FROM users WHERE id = pg_temp.uid('rls_public');
RESET ROLE;
SELECT pg_temp.verifier(
    EXISTS (SELECT 1 FROM users WHERE pseudo = 'rls_public'),
    'DELETE : on ne supprime pas le compte de quelqu''un d''autre (policy users_suppression)');
SET ROLE acnh_app;
SELECT pg_temp.en_tant_que('rls_hash');
SELECT creer_compte('rls_jetable', 'rls_jetable@test.invalid', '$2y$10$x') IS NOT NULL AS ok \gset
RESET ROLE;
UPDATE users SET email_verifie = true WHERE pseudo = 'rls_jetable';
SET ROLE acnh_app;
SELECT pg_temp.en_tant_que('rls_jetable');
DELETE FROM users WHERE id = pg_temp.uid('rls_jetable');
SELECT pg_temp.verifier(
    NOT EXISTS (SELECT 1 FROM users WHERE pseudo = 'rls_jetable'),
    'DELETE : je peux supprimer mon propre compte');

-- Progression : une ligne par jour, meme sans trouvaille (CTE + LEFT JOIN + fenetre)
SELECT pg_temp.en_tant_que('rls_moi');
SELECT pg_temp.verifier(
    (SELECT count(*) FROM progression_recente(pg_temp.uid('rls_moi'), 14)) = 14,
    'progression_recente renvoie un point par jour, y compris les jours vides');

SELECT pg_temp.verifier(
    (SELECT count(*) FROM progression_recente(pg_temp.uid('rls_prive'), 14) WHERE nouveaux > 0) = 0,
    'progression_recente respecte la visibilite (profil prive : rien)');

-- Fonction de recommandation : EXISTS + HAVING + CTE sur mes amis
SELECT pg_temp.verifier(
    (SELECT count(*) FROM items_chez_les_amis(10)) >= 0,
    'items_chez_les_amis s''execute sous le role applicatif');

SELECT pg_temp.verifier(
    nb_trouvailles_recentes(48) >= 0,
    'nb_trouvailles_recentes s''execute sous le role applicatif');

SELECT pg_temp.doit_echouer(
    'SELECT * FROM tentatives_auth',
    'la table des tentatives reste inaccessible');

RESET ROLE;
ROLLBACK;

\echo ''
\echo 'Tous les tests passent. Transaction annulee : la base n''a pas ete modifiee.'
