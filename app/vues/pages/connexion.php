<?php
/** Vue rendue dans vues/gabarit.php par PageControleur. */
$titre = 'Connexion — Nook Manager';
$description = 'Connecte-toi à Nook Manager pour suivre ta collection Animal Crossing: New Horizons.';
$scripts = <<<'HTML'
<script>
(function () {
  "use strict";

  var formulaire = document.getElementById("formulaire");
  var erreur = document.getElementById("erreur");
  var envoi = document.getElementById("envoi");

  // Déjà connecté : rien à faire ici.
  Nook.session().then(function (utilisateur) {
    if (utilisateur) window.location.replace("?page=accueil");
  });

  formulaire.addEventListener("submit", function (e) {
    e.preventDefault();
    erreur.hidden = true;
    envoi.disabled = true;

    Nook.connexion(
      document.getElementById("email").value.trim(),
      document.getElementById("mot-de-passe").value
    )
      .then(function () {
        window.location.href = "?page=accueil";
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
    <h1>Connexion</h1>
    <p class="subtitle">Connecte-toi pour retrouver ta collection, tes amis et ton classement.</p>
  </div>
</div>

<main id="contenu" tabindex="-1">
  <form class="formulaire" id="formulaire" novalidate>
    <p class="formulaire-erreur" id="erreur" role="alert" hidden></p>

    <div class="champ">
      <label for="email">Adresse email</label>
      <input type="email" id="email" name="email" autocomplete="email" required>
    </div>

    <div class="champ">
      <label for="mot-de-passe">Mot de passe</label>
      <input type="password" id="mot-de-passe" name="mot-de-passe" autocomplete="current-password" required>
      <p class="champ-aide"><a href="?page=mot-de-passe-oublie">Mot de passe oublié&nbsp;?</a></p>
    </div>

    <button type="submit" class="reset-btn formulaire-envoi" id="envoi">Se connecter</button>

    <p class="formulaire-pied">
      Pas encore de compte&nbsp;? <a href="?page=inscription">Créer un compte</a>
    </p>
  </form>
</main>

<footer class="site-footer">
  <p>Fan-site non officiel — Animal Crossing est une marque de Nintendo.</p>
</footer>
