<?php
/** Vue rendue dans vues/gabarit.php par PageControleur. */
$titre = 'Nouveau mot de passe — Nook Manager';
$description = 'Choisis un nouveau mot de passe pour ton compte Nook Manager.';
$scripts = <<<'HTML'
<script>
(function () {
  "use strict";

  var formulaire = document.getElementById("formulaire");
  var erreur = document.getElementById("erreur");
  var succes = document.getElementById("succes");
  var envoi = document.getElementById("envoi");
  var token = new URLSearchParams(window.location.search).get("token") || "";

  function afficherErreur(texte) {
    erreur.textContent = texte;
    erreur.hidden = false;
  }

  if (!token) {
    afficherErreur("Ce lien est incomplet : redemande un lien de réinitialisation.");
    envoi.disabled = true;
  }

  formulaire.addEventListener("submit", function (e) {
    e.preventDefault();
    erreur.hidden = true;

    var motDePasse = document.getElementById("mot-de-passe").value;
    if (motDePasse !== document.getElementById("confirmation").value) {
      afficherErreur("Les deux mots de passe ne correspondent pas.");
      return;
    }

    envoi.disabled = true;

    fetch("api/reinitialiser-mot-de-passe.php", {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ token: token, mot_de_passe: motDePasse })
    })
      .then(function (r) { return r.json(); })
      .then(function (reponse) {
        if (reponse.erreur) throw new Error(reponse.erreur);

        formulaire.querySelectorAll(".champ").forEach(function (c) { c.hidden = true; });
        envoi.hidden = true;
        succes.hidden = false;
        succes.textContent = reponse.message + " ";

        var lien = document.createElement("a");
        lien.href = "?page=connexion";
        lien.textContent = "Se connecter";
        succes.appendChild(document.createElement("br"));
        succes.appendChild(lien);
      })
      .catch(function (err) {
        afficherErreur(err.message);
        envoi.disabled = false;
      });
  });
})();
</script>
HTML;
?>
<div class="site-header">
  <div class="header-inner">
    <h1>Nouveau mot de passe</h1>
    <p class="subtitle">Toutes tes sessions ouvertes ailleurs seront déconnectées.</p>
  </div>
</div>

<main id="contenu" tabindex="-1">
  <form class="formulaire" id="formulaire" novalidate>
    <p class="formulaire-erreur" id="erreur" role="alert" hidden></p>
    <p class="formulaire-succes" id="succes" role="status" hidden></p>

    <div class="champ">
      <label for="mot-de-passe">Nouveau mot de passe</label>
      <input type="password" id="mot-de-passe" autocomplete="new-password" minlength="8" required>
      <p class="champ-aide">8 caractères minimum.</p>
    </div>

    <div class="champ">
      <label for="confirmation">Confirmation</label>
      <input type="password" id="confirmation" autocomplete="new-password" minlength="8" required>
    </div>

    <button type="submit" class="reset-btn formulaire-envoi" id="envoi">Enregistrer</button>
  </form>
</main>

<footer class="site-footer">
  <p class="footer-sub">Fan-site non officiel — Animal Crossing est une marque de Nintendo.</p>
</footer>
