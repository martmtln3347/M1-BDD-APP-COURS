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
<link rel="stylesheet" href="styles.css?v=<?= (int) @filemtime(__DIR__ . "/../styles.css") ?>">
</head>
<body>
<a class="lien-evitement" href="#contenu">Aller au contenu</a>

<?php if ($pageCourante === 'accueil'): ?>
<div class="passeport" id="passeport" role="dialog" aria-modal="true" aria-labelledby="passeport-titre">
  <div class="passeport-scene">
    <div class="passeport-livre">
      <div class="passeport-page" aria-hidden="true">
        <p class="passeport-page-titre">Visa d'entrée</p>
        <p class="passeport-page-nom" data-nom></p>
        <span class="passeport-page-tampon">
          <svg viewBox="0 0 200 200" focusable="false">
            <defs>
              <path id="tampon-arc" d="M 28 100 A 72 72 0 0 0 172 100"/>
              <!-- L'encre noire du dessin devient l'encre du tampon, le blanc devient transparent -->
              <filter id="tampon-encre" x="0" y="0" width="1" height="1" color-interpolation-filters="sRGB">
                <feColorMatrix type="matrix" values="0 0 0 0 1  0 0 0 0 0.365  0 0 0 0 0.204  -0.2976 -1.0013 -0.1011 0 1.3"/>
              </filter>
            </defs>
            <circle cx="100" cy="100" r="94" fill="none" stroke="currentColor" stroke-width="6"/>
            <circle cx="100" cy="100" r="85" fill="none" stroke="currentColor" stroke-width="2"/>
            <image href="tampon-nook.jpg" x="24" y="8" width="152" height="152" filter="url(#tampon-encre)"/>
            <text font-family="Unbounded, sans-serif" font-weight="800" font-size="15" letter-spacing="3" fill="currentColor" text-anchor="middle">
              <textPath href="#tampon-arc" startOffset="50%">BIENVENUE</textPath>
            </text>
          </svg>
        </span>
      </div>
      <div class="passeport-couverture">
        <div class="passeport-face">
          <img class="passeport-logo" src="favicon.svg" alt="" width="92" height="92">
          <h1 class="passeport-titre" id="passeport-titre">Nook Manager</h1>
          <p class="passeport-sous-titre">Passeport de l'île</p>
          <p class="passeport-salut" data-salut aria-live="polite"></p>
          <div class="passeport-zone" data-zone>
            <div class="passeport-vue is-actif" data-vue="accueil">
              <div class="passeport-actions">
                <button type="button" class="passeport-bouton passeport-bouton--plein" data-aller="connexion">Se connecter</button>
                <button type="button" class="passeport-bouton" data-aller="inscription">Créer un compte</button>
                <button type="button" class="passeport-lien" data-invite>Visiter en tant qu'invité</button>
              </div>
            </div>

            <form class="passeport-vue" data-vue="connexion" novalidate hidden>
              <p class="passeport-erreur" role="alert" data-erreur hidden></p>
              <div class="champ">
                <label for="pp-email">Adresse email</label>
                <input type="email" id="pp-email" autocomplete="email" required>
              </div>
              <div class="champ">
                <label for="pp-mdp">Mot de passe</label>
                <input type="password" id="pp-mdp" autocomplete="current-password" required>
              </div>
              <button type="submit" class="passeport-bouton passeport-bouton--plein" data-envoi>Se connecter</button>
              <button type="button" class="passeport-lien" data-aller="accueil">&larr; Retour</button>
            </form>

            <form class="passeport-vue" data-vue="inscription" novalidate hidden>
              <p class="passeport-erreur" role="alert" data-erreur hidden></p>
              <div class="passeport-succes" role="status" data-succes hidden></div>
              <div class="passeport-champs">
                <div class="champ">
                  <label for="pp-pseudo">Pseudo</label>
                  <input type="text" id="pp-pseudo" maxlength="30" autocomplete="nickname" required>
                </div>
                <div class="champ">
                  <label for="pp-email2">Adresse email</label>
                  <input type="email" id="pp-email2" autocomplete="email" required>
                </div>
                <div class="champ">
                  <label for="pp-mdp2">Mot de passe</label>
                  <input type="password" id="pp-mdp2" autocomplete="new-password" minlength="8" placeholder="8 caractères minimum" required>
                </div>
              </div>
              <button type="submit" class="passeport-bouton passeport-bouton--plein" data-envoi>Créer mon compte</button>
              <button type="button" class="passeport-lien" data-aller="accueil">&larr; Retour</button>
            </form>
          </div>
        </div>
        <div class="passeport-dos" aria-hidden="true"></div>
      </div>
    </div>
  </div>
</div>
<script>try{if(sessionStorage.getItem("nook-ouvert"))document.getElementById("passeport").remove()}catch(e){}</script>

<?php endif; ?>
<header class="top-nav">
  <div class="top-nav-inner">
    <a href="?page=accueil" class="top-nav-brand">
      <img class="top-nav-feuille" src="favicon.svg" alt="" width="30" height="30">
      Nook Manager
    </a>
    <button type="button" class="top-nav-burger" id="burger" aria-label="Ouvrir le menu" aria-expanded="false" aria-controls="menu-principal">
      <span class="burger-barres" aria-hidden="true"></span>
    </button>
    <nav class="top-nav-links" id="menu-principal" aria-label="Navigation principale">
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

<script>if ("serviceWorker" in navigator) navigator.serviceWorker.register("sw.js").catch(function () { /* hors ligne optionnel */ });</script>
<script src="icones.js"></script>
<script src="api.js"></script>
<script src="nav.js"></script>
<?php if ($pageCourante === 'accueil'): ?>
<script src="passeport.js"></script>
<?php endif; ?>
<?= $scripts ?>

</body>
</html>
