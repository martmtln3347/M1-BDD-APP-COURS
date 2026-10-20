<?php
/** Vue rendue dans vues/gabarit.php par PageControleur. */
$titre = 'Profil — Nook Manager';
$description = 'Ton avancement de collection, le classement et le fil d\'actu de tes amis sur Nook Manager.';
$scripts = <<<'HTML'
<script src="profil.js"></script>
HTML;
?>
<div class="site-header">
  <div class="header-inner profil-entete">
    <span id="profil-avatar" class="profil-avatar"></span>
    <div class="profil-entete-texte">
      <h1 id="titre">Profil</h1>
      <p class="subtitle" id="sous-titre">Chargement…</p>
    </div>
    <div class="profil-actions" id="profil-actions"></div>
  </div>
</div>

<main id="contenu" tabindex="-1">
  <section class="panneau" id="panneau-visibilite" hidden>
    <h2>Qui peut voir ton profil&nbsp;?</h2>
    <p class="panneau-aide">Le réglage s'applique d'un bloc à ton avancement, ta collection et ton fil d'actu.</p>
    <div class="choix-visibilite" id="choix-visibilite">
      <label><input type="radio" name="visibilite" value="public"> Tout le monde</label>
      <label><input type="radio" name="visibilite" value="amis"> Mes amis seulement</label>
      <label><input type="radio" name="visibilite" value="prive"> Personne</label>
    </div>
  </section>

  <section class="panneau" id="panneau-avatar" hidden>
    <h2>Photo de profil</h2>
    <p class="panneau-aide">Choisis l'un des habitants du jeu. Elle apparaît à côté de ton pseudo, dans le classement et chez tes amis.</p>
    <div class="avatar-actions">
      <button type="button" class="reset-btn petit" id="avatar-ouvrir" aria-expanded="false" aria-controls="avatar-selecteur">Choisir une photo</button>
      <button type="button" class="toggle-btn petit" id="avatar-retirer" hidden>Retirer ma photo</button>
    </div>
    <div id="avatar-selecteur" class="avatar-selecteur" hidden>
      <label class="sr-only" for="avatar-recherche">Chercher un habitant</label>
      <input type="search" id="avatar-recherche" placeholder="Chercher un habitant…" autocomplete="off">
      <div class="avatar-grille" id="avatar-grille" role="listbox" aria-label="Habitants"></div>
    </div>
  </section>

  <section class="panneau" id="panneau-stats">
    <h2>Avancement</h2>
    <div id="stats"><p class="panneau-vide">Chargement…</p></div>
  </section>

  <section class="panneau" id="panneau-progression">
    <h2>Progression</h2>
    <p class="panneau-aide">Éléments obtenus par jour sur les 30 derniers jours, avec le cumul. Calculé par une requête SQL à CTE et fonction fenêtre.</p>
    <div id="progression"></div>
  </section>

  <section class="panneau" id="panneau-badges">
    <h2>Badges</h2>
    <p class="panneau-aide">Calculés à partir de l'avancement : rien à réclamer, ils se débloquent tout seuls.</p>
    <div id="badges"></div>
  </section>

  <section class="panneau" id="panneau-recentes">
    <h2>Dernières trouvailles</h2>
    <div id="recentes"></div>
  </section>

  <section class="panneau" id="panneau-classement">
    <h2>Classement</h2>
    <p class="panneau-aide">Les profils privés n'y figurent pas, et ceux réservés aux amis n'apparaissent que pour leurs amis.</p>
    <div class="classement-filtre">
      <label for="classement-categorie">Catégorie</label>
      <select id="classement-categorie">
        <option value="">Toutes catégories</option>
        <option value="habitant">Habitants</option>
        <option value="poisson">Poissons</option>
        <option value="creature_marine">Créatures sous-marines</option>
        <option value="insecte">Insectes</option>
        <option value="art">Œuvres d'art</option>
      </select>
    </div>
    <div id="classement"></div>
  </section>

  <section class="panneau" id="panneau-communaute">
    <h2>Records de l'île</h2>
    <p class="panneau-aide">Calculés sur les profils que tu as le droit de voir.</p>
    <div id="communaute"></div>
  </section>

  <section class="panneau" id="panneau-compte" hidden>
    <h2>Supprimer mon compte</h2>
    <p class="panneau-aide">Efface ton compte, ta collection, tes amitiés et tes notifications. Cette action est définitive.</p>
    <button type="button" class="toggle-btn petit bouton-danger" id="compte-supprimer">Supprimer mon compte</button>
  </section>

  <section class="panneau" id="panneau-activites">
    <h2>Fil d'actu</h2>
    <p class="panneau-aide">Alimenté automatiquement par la base à chaque prise ou don au musée.</p>
    <div id="activites"></div>
  </section>
</main>

<footer class="site-footer">
  <p class="footer-sub">Fan-site non officiel — Animal Crossing est une marque de Nintendo.</p>
</footer>
