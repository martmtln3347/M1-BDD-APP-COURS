<?php
/**
 * Gabarit commun a toutes les pages : <head>, barre de navigation, decor du
 * ciel et scripts partages. La vue de chaque page n'apporte que son contenu.
 *
 * Variables fournies par PageControleur et par la vue :
 * $pageCourante, $titre, $description, $contenu, $scripts.
 */

$navigation = [
    'accueil'   => 'Accueil',
    'habitants' => 'Habitants',
    'poissons'  => 'Poissons',
    'creatures' => 'Créatures',
    'insectes'  => 'Insectes',
    'art'       => 'Art',
    'profil'    => 'Profil',
    'amis'      => 'Amis',
];

$echapper = static fn (string $texte): string => htmlspecialchars($texte, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $echapper($titre) ?></title>
<meta name="description" content="<?= $echapper($description) ?>">
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="icon" type="image/png" sizes="512x512" href="icon-512.png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="apple-mobile-web-app-title" content="Nook Manager">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#3d2414">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Unbounded:wght@500;700;800&family=Figtree:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="styles.css">
</head>
<body>
<a class="lien-evitement" href="#contenu">Aller au contenu</a>

<header class="top-nav">
  <div class="top-nav-inner">
    <a href="?page=accueil" class="top-nav-brand">
      <img class="top-nav-feuille" src="favicon.svg" alt="" width="30" height="30">
      Nook Manager
    </a>
    <nav class="top-nav-links" aria-label="Navigation principale">
<?php foreach ($navigation as $cle => $libelle): ?>
<?php $active = $cle === $pageCourante; ?>
      <a href="?page=<?= $cle ?>" class="top-nav-link<?= $active ? ' is-active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?>>
        <span class="nav-label"><?= $echapper($libelle) ?></span>
      </a>
<?php endforeach; ?>
    </nav>
  </div>
</header>

<?= $contenu ?>

<script src="icones.js"></script>
<script src="api.js"></script>
<?= $scripts ?>

</body>
</html>
