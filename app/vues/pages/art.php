<?php
/** Vue rendue dans vues/gabarit.php par PageControleur. */
$titre = 'Art — Nook Manager';
$description = 'Les 43 œuvres d\'art de Rounard dans Animal Crossing: New Horizons : photo réelle et contrefaçon côte à côte, avec le détail qui trahit chaque faux.';
$scripts = <<<'HTML'
<script src="art.js"></script>
HTML;
?>
<div class="site-header">
  <div class="header-inner">
    <h1>Les œuvres d'art</h1>
    <p class="subtitle">Les <strong>43 œuvres</strong> vendues par Rounard&nbsp;: photo réelle et contrefaçon côte à côte, avec le détail qui trahit chaque faux. Coche celles que tu as déjà données au musée.</p>
  </div>
</div>

<main id="contenu" tabindex="-1">
  <section class="controls" aria-label="Filtres">
    <div class="search-wrap">
      <span class="search-icon" aria-hidden="true"><svg class="icone" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="6.6"/><path d="M15.8 15.8l4.6 4.6"/></svg></span>
      <input type="search" id="search" placeholder="Chercher une œuvre (nom ou artiste)..." autocomplete="off">
    </div>

    <div class="select-wrap">
      <label for="category-filter">Type</label>
      <select id="category-filter">
        <option value="">Tableaux et sculptures</option>
        <option value="painting">Tableaux</option>
        <option value="sculpture">Sculptures</option>
      </select>
    </div>

    <button id="hide-museum-toggle" type="button" class="toggle-btn" aria-pressed="false">
      <svg class="icone" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 12s3.7-6.2 9-6.2S21 12 21 12s-3.7 6.2-9 6.2S3 12 3 12z"/><circle cx="12" cy="12" r="2.7"/><path d="M4.4 20L19.6 4"/></svg> Masquer celles au musée
    </button>

    <button id="reset-filters" type="button" class="reset-btn">Réinitialiser</button>
  </section>

  <p class="result-count" id="result-count" role="status" aria-live="polite">Chargement des œuvres...</p>

  <section id="grid" class="art-grid" aria-label="Liste des œuvres d'art"></section>

  <p id="empty-state" class="empty-state" hidden>Aucune œuvre ne correspond à ta recherche.</p>
</main>

<footer class="site-footer">
  <p>Données extraites du jeu Animal Crossing: New Horizons — <span id="footer-count">43</span> œuvres au total, tous les faux répertoriés.</p>
  <p class="footer-sub">Fan-site non officiel — Animal Crossing est une marque de Nintendo.</p>
</footer>

<button id="back-to-top" class="back-to-top" type="button" aria-label="Remonter en haut de la page">↑</button>
