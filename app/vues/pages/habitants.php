<?php
/** Vue rendue dans vues/gabarit.php par PageControleur. */
$titre = 'Habitants — Nook Manager';
$description = 'La liste complète et illustrée des 417 habitants d\'Animal Crossing: New Horizons, avec photo, nom et race de chacun.';
$scripts = <<<'HTML'
<script src="app.js"></script>
HTML;
?>
<div class="site-header">
  <div class="header-inner">
    <h1>Les habitants</h1>
    <p class="subtitle">Retrouve ici la liste complète des <strong>417 habitants</strong> du jeu&nbsp;: leur photo, leur nom et leur race.</p>
  </div>
</div>

<main id="contenu" tabindex="-1">
  <nav class="race-index" id="race-index" aria-label="Aller à une race">
    <span class="race-index-label">Aller à&nbsp;:</span>
    <div class="race-index-links" id="race-index-links"></div>
  </nav>

  <section class="controls" aria-label="Filtres">
    <div class="search-wrap">
      <span class="search-icon" aria-hidden="true"><svg class="icone" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="6.6"/><path d="M15.8 15.8l4.6 4.6"/></svg></span>
      <input type="search" id="search" placeholder="Chercher un habitant (nom FR ou VO)..." autocomplete="off">
    </div>

    <div class="select-wrap">
      <label for="species-filter">Race</label>
      <select id="species-filter">
        <option value="">Toutes les races</option>
      </select>
    </div>

    <div class="select-wrap">
      <label for="personality-filter">Personnalité</label>
      <select id="personality-filter">
        <option value="">Toutes les personnalités</option>
      </select>
    </div>

    <button id="favorites-toggle" type="button" class="favorites-toggle" aria-pressed="false">
      <svg class="icone icone--plein" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 20.3s-7.6-4.7-7.6-9.6a4.2 4.2 0 0 1 7.6-2.4 4.2 4.2 0 0 1 7.6 2.4c0 4.9-7.6 9.6-7.6 9.6z"/></svg> Sur mon île <span id="favorites-count">(0)</span>
    </button>

    <button id="reset-filters" type="button" class="reset-btn">Réinitialiser</button>
  </section>

  <p class="result-count" id="result-count" role="status" aria-live="polite">Chargement des habitants...</p>

  <section id="grid" class="villager-grid" aria-label="Liste des habitants"></section>

  <p id="empty-state" class="empty-state" hidden>Aucun habitant ne correspond à ta recherche.</p>
</main>

<footer class="site-footer">
  <p>Données extraites du jeu Animal Crossing: New Horizons — <span id="footer-count">417</span> habitants au total, tous inclus, dont 4 (collab Zelda/Splatoon) uniquement disponibles via amiibo — repérables sur leur carte.</p>
  <p class="footer-sub">Fan-site non officiel — Animal Crossing est une marque de Nintendo.</p>
</footer>

<button id="back-to-top" class="back-to-top" type="button" aria-label="Remonter en haut de la page">↑</button>
