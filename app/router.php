<?php
declare(strict_types=1);

/**
 * Routeur du serveur integre de PHP (php -S ... router.php).
 *
 * Le serveur integre sert TOUT le dossier tel quel, .env et .git compris, car
 * il ne lit pas le .htaccess. Ce routeur le remplace par une liste blanche :
 * seuls l'entree des pages, les points d'entree de api/ et les fichiers
 * statiques publics sont servis, tout le reste repond 404.
 */
$chemin = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));

// Octet nul et remontee de dossier : refuses d'emblee.
if (str_contains($chemin, "\0") || str_contains($chemin, '..') || str_contains($chemin, '\\')) {
    http_response_code(404);
    exit;
}

if ($chemin === '/' || $chemin === '/index.php') {
    require __DIR__ . '/index.php';
    return true;
}

$autorise = preg_match('#^/api/[a-z0-9-]+\.php$#', $chemin) === 1
    || preg_match('#^/[A-Za-z0-9._-]+\.(css|js|svg|png|jpg|webp|ico)$#', $chemin) === 1;

if ($autorise && is_file(__DIR__ . $chemin)) {
    return false; // le serveur integre sert (ou execute) le fichier
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Introuvable';
