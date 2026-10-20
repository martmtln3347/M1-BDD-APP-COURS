<?php
/** Vue rendue dans vues/gabarit.php par PageControleur. */
$titre = 'Amis — Nook Manager';
$description = 'Retrouve tes amis sur Nook Manager, envoie des demandes et compare vos collections.';
$scripts = <<<'HTML'
<script src="amis.js"></script>
HTML;
?>
<div class="site-header">
  <div class="header-inner">
    <h1>Amis</h1>
    <p class="subtitle">Ajoute des joueurs pour voir leur avancement et suivre leurs trouvailles dans ton fil d'actu.</p>
  </div>
</div>

<main id="contenu" tabindex="-1">
  <section class="panneau">
    <h2>Chercher quelqu'un</h2>
    <form class="recherche-amis" id="recherche">
      <input type="search" id="terme" placeholder="Pseudo (2 caractères minimum)…" autocomplete="off">
      <button type="submit" class="reset-btn">Chercher</button>
    </form>
    <div id="resultats"></div>
  </section>

  <section class="panneau" id="panneau-notifications" hidden>
    <h2>Notifications</h2>
    <p class="panneau-aide">Stockées dans Redis avec une durée de vie de 7 jours : elles disparaissent d'elles-mêmes.</p>
    <div id="notifications"></div>
    <button type="button" class="toggle-btn petit" id="notifications-vider" hidden>Tout effacer</button>
  </section>

  <section class="panneau" id="panneau-recues">
    <h2>Demandes reçues</h2>
    <div id="recues"></div>
  </section>

  <section class="panneau" id="panneau-envoyees">
    <h2>Demandes envoyées</h2>
    <div id="envoyees"></div>
  </section>

  <section class="panneau" id="panneau-suggestions" hidden>
    <h2>Chez tes amis</h2>
    <p class="panneau-aide">Ce que tes amis ont et que tu n'as pas encore, du plus répandu au moins répandu.</p>
    <ul class="vignettes" id="suggestions"></ul>
  </section>

  <section class="panneau">
    <h2>Mes amis</h2>
    <div id="amis"></div>
  </section>
</main>

<footer class="site-footer">
  <p class="footer-sub">Fan-site non officiel — Animal Crossing est une marque de Nintendo.</p>
</footer>
