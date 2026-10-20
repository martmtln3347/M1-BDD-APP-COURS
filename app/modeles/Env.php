<?php
declare(strict_types=1);

/**
 * Lecture du .env partage avec Docker Compose : une seule source de verite
 * pour les identifiants de la base, et rien de sensible dans le depot.
 */
final class Env
{
    private static array $valeurs = [];
    private static bool $charge = false;

    public static function charger(?string $chemin = null): void
    {
        if (self::$charge) {
            return;
        }

        // En conteneur, la configuration arrive par variables d'environnement
        // (docker-compose) : le fichier .env est alors facultatif.
        $chemin ??= dirname(__DIR__) . '/.env';
        $lignes = is_readable($chemin) ? file($chemin, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

        foreach ($lignes as $ligne) {
            $ligne = trim($ligne);
            if ($ligne === '' || str_starts_with($ligne, '#')) {
                continue;
            }
            [$cle, $valeur] = array_pad(explode('=', $ligne, 2), 2, '');
            self::$valeurs[trim($cle)] = trim($valeur);
        }

        self::$charge = true;
    }

    /**
     * Mode developpement explicite. Tout ce qui n'est pas exactement "dev"
     * est traite comme de la production : un .env incomplet ne doit jamais
     * activer les raccourcis de test.
     */
    public static function estDev(): bool
    {
        if (self::lire('APP_ENV', 'production') !== 'dev') {
            return false;
        }
        // Garde-fou : meme avec APP_ENV=dev oublie dans un .env de serveur, les
        // liens de reinitialisation ne sortent que sur la machine locale.
        $hote = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
        return PHP_SAPI === 'cli' || in_array($hote, ['localhost', '127.0.0.1', '[::1]'], true);
    }

    public static function lire(string $cle, ?string $defaut = null): string
    {
        self::charger();
        $systeme = getenv($cle);
        $valeur = ($systeme !== false && $systeme !== '') ? $systeme : (self::$valeurs[$cle] ?? $defaut);
        if ($valeur === null) {
            throw new RuntimeException("Variable d'environnement manquante : $cle");
        }
        return $valeur;
    }
}
