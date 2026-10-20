<?php
declare(strict_types=1);

// Point d'entree unique des pages HTML : index.php?page=poissons.
// Les donnees passent, elles, par les points d'entree de api/.
require_once __DIR__ . '/autoload.php';

(new PageControleur())->afficher();
