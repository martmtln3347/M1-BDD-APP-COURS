<?php
declare(strict_types=1);

// Jamais de trace PHP dans une reponse : elle va dans le journal du serveur.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

/**
 * Chargement automatique des classes : une classe = un fichier du meme nom,
 * cherche dans les trois couches de l'application.
 */
// RedisErreur partage le fichier de RedisClient.
require_once __DIR__ . '/modeles/RedisClient.php';

spl_autoload_register(static function (string $classe): void {
    foreach (['modeles', 'controleurs', 'vues'] as $couche) {
        $chemin = __DIR__ . '/' . $couche . '/' . $classe . '.php';
        if (is_readable($chemin)) {
            require_once $chemin;
            return;
        }
    }
});
