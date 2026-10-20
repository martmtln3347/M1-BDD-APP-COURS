<?php
/** Vue rendue dans vues/gabarit.php par PageControleur. */
$titre = 'Créatures sous-marines — Nook Manager';
$description = 'Les 40 créatures sous-marines d\'Animal Crossing: New Horizons : photo, nom, mois et heures d\'apparition, avec suivi de collection.';
$scripts = <<<'HTML'
<script>
  window.CRITTER_CONFIG = {
    categorie: "creature_marine",
    storageKey: "acnh-sea-creatures",
    showWater: false,
    unitLabel: "créature",
    unitLabelPlural: "créatures",
    placeholderIcone: "coquillage",
    defaultBadge: "Plongée en mer",
    defaultBadgeIcone: "mer"
  };
</script>
<script src="critters.js"></script>
HTML;
?>
<div class="site-header">
  <div class="header-inner">
    <h1>Les créatures sous-marines</h1>
    <p class="subtitle">Les <strong>40 créatures</strong> à chasser en plongée&nbsp;: photo, nom, mois et heures d'apparition. Coche ce que tu as déjà attrapé ou donné au musée.</p>
  </div>
</div>

<main id="contenu" tabindex="-1">
  <section class="controls" aria-label="Filtres">
    <div class="search-wrap">
      <span class="search-icon" aria-hidden="true"><svg class="icone" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="6.6"/><path d="M15.8 15.8l4.6 4.6"/></svg></span>
      <input type="search" id="search" placeholder="Chercher une créature (nom FR ou VO)..." autocomplete="off">
    </div>

    <div class="select-wrap">
      <label for="month-filter">Mois d'apparition</label>
      <select id="month-filter">
        <option value="">Tous les mois</option>
      </select>
    </div>

    <button id="hide-museum-toggle" type="button" class="toggle-btn" aria-pressed="false">
      <svg class="icone" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 12s3.7-6.2 9-6.2S21 12 21 12s-3.7 6.2-9 6.2S3 12 3 12z"/><circle cx="12" cy="12" r="2.7"/><path d="M4.4 20L19.6 4"/></svg> Masquer celles au musée
    </button>

    <button id="missing-toggle" type="button" class="toggle-btn" aria-pressed="false">Ce qu'il me manque</button>

    <button id="now-toggle" type="button" class="toggle-btn" aria-pressed="false">Disponible maintenant</button>

    <button id="reset-filters" type="button" class="reset-btn">Réinitialiser</button>
  </section>

  <p class="result-count" id="result-count" role="status" aria-live="polite">Chargement des créatures...</p>

  <section id="grid" class="critter-grid" aria-label="Liste des créatures sous-marines"></section>

  <p id="empty-state" class="empty-state" hidden>Aucune créature ne correspond à ta recherche.</p>
</main>

<footer class="site-footer">
  <p>Données extraites du jeu Animal Crossing: New Horizons, hémisphère nord — <span id="footer-count">40</span> créatures au total, toutes incluses. Liste inchangée depuis la version 2.0, vérifiée à jour avec la version 3.0.</p>
  <p class="footer-sub">Fan-site non officiel — Animal Crossing est une marque de Nintendo.</p>
</footer>

<button id="back-to-top" class="back-to-top" type="button" aria-label="Remonter en haut de la page">↑</button>
