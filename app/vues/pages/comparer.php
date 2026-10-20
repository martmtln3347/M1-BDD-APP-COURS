<?php
/** Vue rendue dans vues/gabarit.php par PageControleur. */
$titre = 'Comparer — Nook Manager';
$description = 'Compare ta collection à celle d\'un ami : ce que tu as en plus, ce qu\'il a en plus, ce que vous avez en commun.';
$scripts = <<<'HTML'
<script src="comparer.js"></script>
HTML;
?>
<div class="site-header">
  <div class="header-inner profil-entete">
    <span id="comparer-avatars" class="comparer-avatars"></span>
    <div class="profil-entete-texte">
      <h1 id="titre">Comparer</h1>
      <p class="subtitle" id="sous-titre">Chargement…</p>
    </div>
  </div>
</div>

<main id="contenu" tabindex="-1">
  <section class="controls" aria-label="Filtres" id="comparer-filtres" hidden>
    <div class="select-wrap">
      <label for="comparer-categorie">Catégorie</label>
      <select id="comparer-categorie">
        <option value="">Toutes</option>
        <option value="habitant">Habitants</option>
        <option value="poisson">Poissons</option>
        <option value="creature_marine">Créatures sous-marines</option>
        <option value="insecte">Insectes</option>
        <option value="art">Œuvres d'art</option>
      </select>
    </div>
  </section>

  <div class="comparer-colonnes" id="comparer-colonnes" hidden>
    <section class="panneau" aria-labelledby="titre-moi">
      <h2 id="titre-moi">Tu as, pas lui</h2>
      <p class="panneau-aide" id="compte-moi"></p>
      <ul class="vignettes" id="liste-moi"></ul>
    </section>
    <section class="panneau" aria-labelledby="titre-deux">
      <h2 id="titre-deux">Vous avez tous les deux</h2>
      <p class="panneau-aide" id="compte-deux"></p>
      <ul class="vignettes" id="liste-deux"></ul>
    </section>
    <section class="panneau" aria-labelledby="titre-lui">
      <h2 id="titre-lui">Il a, pas toi</h2>
      <p class="panneau-aide" id="compte-lui"></p>
      <ul class="vignettes" id="liste-lui"></ul>
    </section>
  </div>

  <p class="empty-state" id="comparer-vide" hidden></p>
</main>

<footer class="site-footer">
  <p class="footer-sub">Fan-site non officiel — Animal Crossing est une marque de Nintendo.</p>
</footer>
