<?php
/** Vue rendue dans vues/gabarit.php par PageControleur. */
$titre = 'Vérification de l\'adresse — Nook Manager';
$description = 'Vérification de ton adresse email sur Nook Manager.';
$scripts = <<<'HTML'
<script>
(function () {
  "use strict";

  var boite = document.getElementById("resultat");
  var token = new URLSearchParams(window.location.search).get("token") || "";

  function afficher(classe, texte, lienTexte) {
    boite.className = classe;
    boite.textContent = texte + " ";
    if (lienTexte) {
      var lien = document.createElement("a");
      lien.href = "?page=connexion";
      lien.textContent = lienTexte;
      boite.appendChild(document.createElement("br"));
      boite.appendChild(lien);
    }
  }

  fetch("api/verifier-email.php?token=" + encodeURIComponent(token), { credentials: "same-origin" })
    .then(function (r) { return r.json(); })
    .then(function (corps) {
      if (corps.erreur) {
        afficher("formulaire-erreur", corps.erreur);
      } else {
        afficher("formulaire-succes", corps.message, "Se connecter");
      }
    })
    .catch(function () {
      afficher("formulaire-erreur", "Impossible de joindre le serveur.");
    });
})();
</script>
HTML;
?>
<div class="site-header">
  <div class="header-inner">
    <h1>Vérification de l'adresse</h1>
    <p class="subtitle">Un dernier clic et ton compte est prêt.</p>
  </div>
</div>

<main id="contenu" tabindex="-1">
  <div class="formulaire">
    <p class="formulaire-succes" id="resultat" role="status">Vérification en cours…</p>
  </div>
</main>

<footer class="site-footer">
  <p class="footer-sub">Fan-site non officiel — Animal Crossing est une marque de Nintendo.</p>
</footer>
