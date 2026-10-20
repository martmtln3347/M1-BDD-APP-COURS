<?php
declare(strict_types=1);

/**
 * Comptes utilisateurs.
 *
 * Tout ce qui cree un compte ou touche aux tokens passe par des fonctions
 * SECURITY DEFINER de la base : le role applicatif n'a aucun droit sur la
 * table tokens, ni INSERT sur users, et ne modifie que la visibilite de son
 * propre compte (voir db/init/02-vues-procedures.sql et 03-securite.sql).
 */
final class Utilisateur
{
    /**
     * Cree le compte et renvoie son token de verification d'email. Compte et
     * token sont crees ensemble par la base : jamais de compte sans moyen de
     * l'activer.
     */
    public static function creer(string $pseudo, string $email, string $motDePasse): string
    {
        $requete = Bdd::connexion()->prepare('SELECT creer_compte(:pseudo, :email, :hash)');
        $requete->execute([
            ':pseudo' => $pseudo,
            ':email'  => $email,
            // bcrypt natif PHP : le hash porte son propre sel.
            ':hash'   => password_hash($motDePasse, PASSWORD_BCRYPT),
        ]);

        return (string) $requete->fetchColumn();
    }

    public static function parEmail(string $email): ?array
    {
        // maintenant : l'heure de connexion est prise sur l'horloge de la base,
        // la meme que celle qui date les changements de mot de passe.
        // Fonction SECURITY DEFINER : le role applicatif n'a pas le droit de
        // lire le hash directement, seulement celui du compte demande.
        $requete = Bdd::connexion()->prepare(
            'SELECT id, pseudo, mot_de_passe_hash, email_verifie, visibilite, maintenant
             FROM identifiants_connexion(:email)'
        );
        $requete->execute([':email' => $email]);

        return $requete->fetch() ?: null;
    }

    /** Consomme un token de verification : valable une fois, avant expiration. */
    public static function verifierEmail(string $token): bool
    {
        $requete = Bdd::connexion()->prepare('SELECT verifier_email(:token)');
        $requete->execute([':token' => $token]);

        return $requete->fetchColumn() === true;
    }

    /**
     * Token de reinitialisation, valable une heure, ou null si l'email ne
     * correspond a aucun compte. Seul le dernier token demande fonctionne.
     */
    public static function creerTokenReinitialisation(string $email): ?string
    {
        $requete = Bdd::connexion()->prepare('SELECT demander_reinitialisation(:email)');
        $requete->execute([':email' => $email]);
        $token = $requete->fetchColumn();

        return $token === null ? null : (string) $token;
    }

    /**
     * Consomme le token et remplace le mot de passe. Le trigger
     * users_maj_mdp_modifie_le date le changement, ce qui invalide toutes les
     * sessions ouvertes avant (voir Session::toujoursValide).
     */
    public static function reinitialiserMotDePasse(string $token, string $motDePasse): bool
    {
        $requete = Bdd::connexion()->prepare('SELECT reinitialiser_mot_de_passe(:token, :hash)');
        $requete->execute([
            ':token' => $token,
            ':hash'  => password_hash($motDePasse, PASSWORD_BCRYPT),
        ]);

        return $requete->fetchColumn() === true;
    }

    /** Recherche par pseudo, pour trouver quelqu'un a ajouter en ami. */
    public static function rechercher(string $terme, int $sauf): array
    {
        $requete = Bdd::authentifiee()->prepare(
            'SELECT u.id, u.pseudo, u.visibilite, av.avatar_url
             FROM users u
             JOIN v_avatars av ON av.user_id = u.id
             WHERE u.pseudo ILIKE :terme ESCAPE chr(33) AND u.id <> :sauf
             ORDER BY u.pseudo
             LIMIT 20'
        );
        // Le % est ajoute ici, pas concatene dans la requete : le parametre
        // reste une valeur liee.
        // % et _ saisis par l'utilisateur sont des caracteres ordinaires, pas des
        // jokers : sinon "%%" ferait lister tous les comptes.
        $litteral = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $terme);
        $requete->execute([':terme' => '%' . $litteral . '%', ':sauf' => $sauf]);

        return $requete->fetchAll();
    }

    public static function changerVisibilite(int $id, string $visibilite): void
    {
        // Seule colonne de users que le role applicatif peut modifier, et la
        // policy users_modification limite l'UPDATE a son propre compte. La
        // valeur est validee par le type enum.
        $requete = Bdd::authentifiee()->prepare(
            'UPDATE users SET visibilite = :visibilite::visibilite_profil WHERE id = :id'
        );
        $requete->execute([':visibilite' => $visibilite, ':id' => $id]);
    }

    /** Photo de profil choisie (icone d'un habitant), ou null. */
    public static function avatarDe(int $id): ?string
    {
        $requete = Bdd::connexion()->prepare('SELECT avatar_url FROM v_avatars WHERE user_id = :id');
        $requete->execute([':id' => $id]);
        $url = $requete->fetchColumn();

        return is_string($url) ? $url : null;
    }

    /**
     * Choisit (ou retire, avec null) la photo de profil. La regle "c'est un
     * habitant" est une cle etrangere composee, pas du code : un item d'une
     * autre categorie est refuse par la base.
     */
    public static function changerAvatar(int $id, ?int $itemId): void
    {
        $requete = Bdd::authentifiee()->prepare('UPDATE users SET avatar_item_id = :item WHERE id = :id');
        $requete->bindValue(':item', $itemId, $itemId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $requete->bindValue(':id', $id, PDO::PARAM_INT);
        $requete->execute();
    }

    /** Habitants proposables comme photo : id, nom et icone, pour le selecteur. */
    public static function habitantsPourAvatar(): array
    {
        return Bdd::connexion()->query(
            "SELECT id, nom, image_icone FROM items
             WHERE categorie = 'habitant' AND image_icone IS NOT NULL
             ORDER BY nom"
        )->fetchAll();
    }

    /** Pseudo et photo d'un joueur (lisibles par tous : seule leur collection est protegee). */
    public static function identite(int $id): ?array
    {
        $requete = Bdd::connexion()->prepare(
            'SELECT u.id, u.pseudo, av.avatar_url
             FROM users u JOIN v_avatars av ON av.user_id = u.id
             WHERE u.id = :id'
        );
        $requete->execute([':id' => $id]);

        return $requete->fetch() ?: null;
    }
}
