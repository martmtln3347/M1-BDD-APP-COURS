<?php
/** Vue rendue dans vues/gabarit.php par PageControleur. */
$titre = 'Nook Manager — Animal Crossing: New Horizons';
$description = 'Nook Manager : guide illustré d\'Animal Crossing: New Horizons — habitants, poissons, créatures sous-marines et insectes, avec suivi de collection.';
$scripts = <<<'HTML'

HTML;
?>
<div class="site-header">
  <div class="header-inner">
    <h1>Le guide de l'île</h1>
    <p class="subtitle">Cinq destinations, une seule collection&nbsp;: coche ce que tu as trouvé, tampon au musée pour ce que tu as donné.</p>
  </div>
</div>

<main id="contenu" tabindex="-1">
  <div class="feature-grid">
    <a class="feature-card feature-card--habitants" href="?page=habitants">
      <img class="feature-card-portrait" src="https://acnhcdn.com/latest/NpcBromide/NpcNmlCat23.png" alt="" width="220" height="300" decoding="async">
      <h2>Habitants</h2>
      <p>Les 417 habitants du jeu, avec photo, nom, race, personnalité et date de naissance. Recherche, filtres et suivi de ceux présents sur ton île.</p>
      <span class="feature-card-pied"><span class="feature-card-qte"><b>417</b> habitants</span><span class="feature-card-arrow">Entrer <i aria-hidden="true">→</i></span></span>
    </a>

    <a class="feature-card feature-card--poissons" href="?page=poissons">
      <span class="feature-card-icon icon-poissons" aria-hidden="true"><img src="https://acnhcdn.com/latest/BookFishIcon/FishNishikigoiCropped.png" alt="" width="96" height="96" loading="lazy" decoding="async"></span>
      <h2>Poissons</h2>
      <p>Les 80 poissons à pêcher, en rivière ou en mer. Mois et heures d'apparition, suivi "je l'ai" / "au musée".</p>
      <span class="feature-card-pied"><span class="feature-card-qte"><b>80</b> poissons</span><span class="feature-card-arrow">Entrer <i aria-hidden="true">→</i></span></span>
    </a>

    <a class="feature-card feature-card--creatures" href="?page=creatures">
      <span class="feature-card-icon icon-creatures" aria-hidden="true"><img src="https://acnhcdn.com/latest/BookDiveFishIcon/DiveFishHitodeCropped.png" alt="" width="96" height="96" loading="lazy" decoding="async"></span>
      <h2>Créatures sous-marines</h2>
      <p>Les 40 créatures à chasser en plongée. Mois et heures d'apparition, suivi "je l'ai" / "au musée".</p>
      <span class="feature-card-pied"><span class="feature-card-qte"><b>40</b> créatures</span><span class="feature-card-arrow">Entrer <i aria-hidden="true">→</i></span></span>
    </a>

    <a class="feature-card feature-card--insectes" href="?page=insectes">
      <span class="feature-card-icon icon-insectes" aria-hidden="true"><img src="https://acnhcdn.com/latest/BookInsectIcon/InsectOhkabamadaraCropped.png" alt="" width="96" height="96" loading="lazy" decoding="async"></span>
      <h2>Insectes</h2>
      <p>Les 80 insectes à attraper. Mois et heures d'apparition, suivi "je l'ai" / "au musée".</p>
      <span class="feature-card-pied"><span class="feature-card-qte"><b>80</b> insectes</span><span class="feature-card-arrow">Entrer <i aria-hidden="true">→</i></span></span>
    </a>

    <a class="feature-card feature-card--art" href="?page=art">
      <span class="feature-card-icon icon-art" aria-hidden="true"><img src="https://acnhcdn.com/latest/FtrIcon/FtrArtMonaLisa.png" alt="" width="96" height="96" loading="lazy" decoding="async"></span>
      <h2>Art</h2>
      <p>Les 43 œuvres de Rounard : photo réelle et contrefaçon côte à côte, avec le détail qui trahit chaque faux. Suivi "au musée".</p>
      <span class="feature-card-pied"><span class="feature-card-qte"><b>43</b> œuvres</span><span class="feature-card-arrow">Entrer <i aria-hidden="true">→</i></span></span>
    </a>
  </div>
</main>

<footer class="site-footer">
  <p>Données extraites du jeu Animal Crossing: New Horizons, hémisphère nord — vérifiées à jour avec la version 3.0.</p>
  <p class="footer-sub">Fan-site non officiel — Animal Crossing est une marque de Nintendo.</p>
</footer>
