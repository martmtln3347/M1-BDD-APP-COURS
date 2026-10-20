<?php
declare(strict_types=1);

final class Session
{
    public static function demarrer(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }
        // Sessions dans Redis (TTL natif) quand il est joignable ; a defaut, le
        // stockage par fichiers de PHP prend le relais.
        if (RedisClient::disponible()) {
            session_set_save_handler(new SessionRedis(), true);
        }

        // Mode strict : un identifiant de session que le serveur n'a pas
        // lui-meme emis est refuse (fixation de session). Cookies uniquement,
        // jamais d'identifiant dans l'URL.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');

        session_set_cookie_params([
            'httponly' => true,
            // Strict : le cookie ne part jamais avec une requete venue d'un
            // autre site, ce qui ferme le CSRF. Le site fonctionne quand meme
            // apres un lien externe : ses propres appels API sont same-site.
            'samesite' => 'Strict',
            // Secure des que la connexion est en HTTPS : le cookie ne circule
            // jamais en clair.
            'secure'   => Securite::estHttps(),
        ]);
        session_name(Securite::estHttps() ? '__Host-nook' : 'nook');
        if (Securite::estHttps()) {
            // Le prefixe __Host- impose Secure, chemin / et pas de Domain.
            session_set_cookie_params(['path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => true]);
        }
        session_start();

        // Une session inactive depuis 2 h, ou ouverte depuis plus de 12 h, expire.
        $maintenant = time();
        if (isset($_SESSION['derniere_activite'])
            && ($maintenant - $_SESSION['derniere_activite'] > 7200
                || $maintenant - ($_SESSION['ouverte_le'] ?? $maintenant) > 43200)) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['derniere_activite'] = $maintenant;
    }

    /** Verification deja faite pendant cette requete HTTP. */
    private static bool $verifiee = false;

    public static function utilisateur(): ?array
    {
        self::demarrer();
        if (!isset($_SESSION['utilisateur'])) {
            return null;
        }

        if (!self::$verifiee) {
            self::$verifiee = true;
            if (!self::toujoursValide()) {
                self::fermer();
                return null;
            }
        }

        return $_SESSION['utilisateur'];
    }

    /**
     * Une session ouverte avant le dernier changement de mot de passe ne vaut
     * plus rien : quelqu'un qui a vole une session la perd des que le vrai
     * proprietaire reinitialise son mot de passe.
     *
     * Les deux dates viennent de l'horloge de PostgreSQL (mdp_modifie_le est
     * pose par trigger, connecte_le par now() a la connexion) : pas de
     * decalage possible avec l'horloge de PHP.
     */
    private static function toujoursValide(): bool
    {
        $requete = Bdd::connexion()->prepare(
            'SELECT mdp_modifie_le IS NULL
                 OR mdp_modifie_le <= CAST(:connecte_le AS timestamptz) AS valide
             FROM users WHERE id = :id'
        );
        $requete->execute([
            ':id'          => $_SESSION['utilisateur']['id'],
            ':connecte_le' => $_SESSION['connecte_le'] ?? '-infinity',
        ]);
        $ligne = $requete->fetch();

        // Aucune ligne : le compte a ete supprime entre-temps.
        return $ligne !== false && $ligne['valide'] === true;
    }

    public static function ouvrir(array $utilisateur, string $connecteLe): void
    {
        self::demarrer();
        // Nouvel identifiant apres authentification : empeche la fixation de
        // session.
        session_regenerate_id(true);
        $_SESSION['utilisateur'] = $utilisateur;
        $_SESSION['connecte_le'] = $connecteLe;
        $_SESSION['ouverte_le'] = time();
        self::$verifiee = true;
    }

    /**
     * Met a jour le profil en session sans toucher a l'identifiant : seule la
     * connexion regenere l'id, sinon on deconnecterait l'utilisateur a chaque
     * changement de preference.
     */
    public static function mettreAJour(array $utilisateur): void
    {
        self::demarrer();
        $_SESSION['utilisateur'] = $utilisateur;
    }

    public static function fermer(): void
    {
        self::demarrer();
        $_SESSION = [];
        // Le cookie est aussi supprime cote navigateur, pas seulement la session cote serveur.
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'],
                'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict',
            ]);
        }
        session_destroy();
    }

    /**
     * Pose l'identite de la session sur la connexion PostgreSQL. Les policies
     * RLS la lisent via app_user_id().
     *
     * Le 3e argument false de set_config = valable toute la session de
     * connexion, pas seulement la requete en cours.
     */
    public static function appliquerIdentite(PDO $pdo): void
    {
        $utilisateur = self::utilisateur();
        if ($utilisateur === null) {
            return;
        }
        $pdo->prepare('SELECT set_config(:cle, :valeur, false)')
            ->execute([':cle' => 'app.user_id', ':valeur' => (string) $utilisateur['id']]);
    }
}
