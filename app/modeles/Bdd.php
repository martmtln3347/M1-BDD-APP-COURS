<?php
declare(strict_types=1);

/**
 * Connexion PostgreSQL, ouverte une seule fois par requete HTTP.
 *
 * Le role est toujours acnh_app, jamais postgres : c'est lui qui porte les
 * GRANT et sur lequel s'applique la Row Level Security.
 */
final class Bdd
{
    private static ?PDO $pdo = null;

    public static function connexion(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            Env::lire('DB_HOST', '127.0.0.1'),
            Env::lire('POSTGRES_PORT', '5432'),
            Env::lire('POSTGRES_DB', 'nook_manager')
        );

        self::$pdo = new PDO($dsn, 'acnh_app', Env::lire('ACNH_APP_PASSWORD'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Vraies requetes preparees cote serveur, pas d'echappement simule.
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return self::$pdo;
    }

    /**
     * Connexion avec l'identite de l'utilisateur connecte deja posee : c'est
     * elle que lisent toutes les policies RLS.
     */
    public static function authentifiee(): PDO
    {
        $pdo = self::connexion();
        Session::appliquerIdentite($pdo);
        return $pdo;
    }
}
