<?php
/** Vue rendue dans vues/gabarit.php par PageControleur. */
$titre = 'Mot de passe oublié — Nook Manager';
$description = 'Réinitialise le mot de passe de ton compte Nook Manager.';
$scripts = <<<'HTML'
<script>
(function () {
  "use strict";

  var formulaire = document.getElementById("formulaire");
  var erreur = document.getElementById("erreur");
  var succes = document.getElementById("succes");
  var envoi = document.getElementById("envoi");

  formulaire.addEventListener("submit", function (e) {
    e.preventDefault();
    erreur.hidden = true;
    envoi.disabled = true;

    fetch("api/mot-de-passe-oublie.php", {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ email: document.getElementById("email").value.trim() })
    })
      .then(function (r) { return r.json(); })
      .then(function (reponse) {
        if (reponse.erreur) throw new Error(reponse.erreur);

        formulaire.querySelector(".champ").hidden = true;
        envoi.hidden = true;
        succes.hidden = false;
        succes.textContent = reponse.message;

        // Mode dev uniquement : l'API ne renvoie ce lien que si APP_ENV=dev.
        if (reponse.lien_de_reinitialisation) {
          var lien = document.createElement("a");
          lien.href = reponse.lien_de_reinitialisation;
          lien.textContent = "Choisir un nouveau mot de passe (lien de dev)";
          succes.appendChild(document.createElement("br"));
          succes.appendChild(lien);
        }
      })
      .catch(function (err) {
        erreur.textContent = err.message;
        erreur.hidden = false;
        envoi.disabled = false;
      });
  });
})();
</script>
HTML;
?>
<div class="site-header">
  <div class="header-inner">
    <h1>Mot de passe oublié</h1>
    <p class="subtitle">Indique l'adresse de ton compte : on t'envoie un lien pour en choisir un nouveau.</p>
  </div>
</div>

<main id="contenu" tabindex="-1">
  <form class="formulaire" id="formulaire" novalidate>
    <p class="formulaire-erreur" id="erreur" role="alert" hidden></p>
    <p class="formulaire-succes" id="succes" role="status" hidden></p>

    <div class="champ">
      <label for="email">Adresse email</label>
      <input type="email" id="email" name="email" autocomplete="email" required>
    </div>

    <button type="submit" class="reset-btn formulaire-envoi" id="envoi">Recevoir un lien</button>

    <p class="formulaire-pied">
      <a href="?page=connexion">Retour à la connexion</a>
    </p>
  </form>
</main>

<footer class="site-footer">
  <p class="footer-sub">Fan-site non officiel — Animal Crossing est une marque de Nintendo.</p>
</footer>
