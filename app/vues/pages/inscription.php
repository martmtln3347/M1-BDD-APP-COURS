<?php
/** Vue rendue dans vues/gabarit.php par PageControleur. */
$titre = 'Créer un compte — Nook Manager';
$description = 'Crée ton compte Nook Manager pour suivre ta collection Animal Crossing: New Horizons.';
$scripts = <<<'HTML'
<script>
(function () {
  "use strict";

  var formulaire = document.getElementById("formulaire");
  var erreur = document.getElementById("erreur");
  var succes = document.getElementById("succes");
  var envoi = document.getElementById("envoi");

  Nook.session().then(function (utilisateur) {
    if (utilisateur) window.location.replace("?page=accueil");
  });

  formulaire.addEventListener("submit", function (e) {
    e.preventDefault();
    erreur.hidden = true;
    envoi.disabled = true;

    Nook.inscription(
      document.getElementById("pseudo").value.trim(),
      document.getElementById("email").value.trim(),
      document.getElementById("mot-de-passe").value
    )
      .then(function (reponse) {
        formulaire.querySelectorAll(".champ").forEach(function (champ) { champ.hidden = true; });
        envoi.hidden = true;

        succes.hidden = false;
        succes.appendChild(document.createTextNode(reponse.message + " "));

        // Mode dev uniquement (APP_ENV=dev) : faute de serveur mail, l'API
        // renvoie le lien pour qu'on puisse le suivre directement.
        if (reponse.lien_de_verification) {
          var lien = document.createElement("a");
          lien.href = reponse.lien_de_verification;
          lien.textContent = "Vérifier mon adresse maintenant (lien de dev)";
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
    <h1>Créer un compte</h1>
    <p class="subtitle">Un compte te permet d'enregistrer ta collection, d'ajouter des amis et d'apparaître au classement.</p>
  </div>
</div>

<main id="contenu" tabindex="-1">
  <form class="formulaire" id="formulaire" novalidate>
    <p class="formulaire-erreur" id="erreur" role="alert" hidden></p>
    <div class="formulaire-succes" id="succes" role="status" hidden></div>

    <div class="champ">
      <label for="pseudo">Pseudo</label>
      <input type="text" id="pseudo" name="pseudo" maxlength="30" autocomplete="nickname" required>
    </div>

    <div class="champ">
      <label for="email">Adresse email</label>
      <input type="email" id="email" name="email" autocomplete="email" required>
    </div>

    <div class="champ">
      <label for="mot-de-passe">Mot de passe</label>
      <input type="password" id="mot-de-passe" name="mot-de-passe" autocomplete="new-password" minlength="8" required>
      <p class="champ-aide">8 caractères minimum.</p>
    </div>

    <button type="submit" class="reset-btn formulaire-envoi" id="envoi">Créer mon compte</button>

    <p class="formulaire-pied">
      Déjà inscrit&nbsp;? <a href="?page=connexion">Se connecter</a>
    </p>
  </form>
</main>

<footer class="site-footer">
  <p>Fan-site non officiel — Animal Crossing est une marque de Nintendo.</p>
</footer>
