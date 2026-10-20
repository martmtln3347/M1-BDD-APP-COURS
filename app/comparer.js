/**
 * Compare ma collection à celle d'un autre joueur. Le tri en trois tas est
 * fait par la base (fonction comparer_collections) : ici, on ne fait que
 * l'afficher. Si le profil de l'autre m'est invisible, la base ne renvoie
 * rien et la page le dit, au lieu de laisser croire que tout est « à moi seul ».
 */
(function () {
  "use strict";

  var cible = new URLSearchParams(window.location.search).get("user_id");

  var titre = document.getElementById("titre");
  var sousTitre = document.getElementById("sous-titre");
  var zoneAvatars = document.getElementById("comparer-avatars");
  var filtres = document.getElementById("comparer-filtres");
  var choixCategorie = document.getElementById("comparer-categorie");
  var colonnes = document.getElementById("comparer-colonnes");
  var vide = document.getElementById("comparer-vide");

  var tas = {
    moi_seulement: { liste: document.getElementById("liste-moi"), compte: document.getElementById("compte-moi") },
    les_deux: { liste: document.getElementById("liste-deux"), compte: document.getElementById("compte-deux") },
    lui_seulement: { liste: document.getElementById("liste-lui"), compte: document.getElementById("compte-lui") }
  };

  var elements = [];
  var autre = null;

  function vignette(it) {
    var li = document.createElement("li");
    li.className = "vignette";
    li.title = it.nom;
    if (it.image_icone) {
      var img = document.createElement("img");
      img.src = it.image_icone;
      img.alt = it.nom;
      img.loading = "lazy";
      img.decoding = "async";
      li.appendChild(img);
    } else {
      li.appendChild(Icone("lieu"));
    }
    var nom = document.createElement("span");
    nom.textContent = it.nom;
    li.appendChild(nom);
    return li;
  }

  function afficher() {
    var categorie = choixCategorie.value;
    var comptes = { moi_seulement: 0, les_deux: 0, lui_seulement: 0 };

    Object.keys(tas).forEach(function (cle) { tas[cle].liste.replaceChildren(); });

    elements.forEach(function (it) {
      if (categorie && it.categorie !== categorie) return;
      comptes[it.statut]++;
      tas[it.statut].liste.appendChild(vignette(it));
    });

    Object.keys(tas).forEach(function (cle) {
      var n = comptes[cle];
      tas[cle].compte.textContent = n === 0 ? "Aucun élément." : n + (n > 1 ? " éléments" : " élément");
    });
  }

  function erreur(message) {
    sousTitre.textContent = message;
    colonnes.hidden = true;
    filtres.hidden = true;
  }

  Nook.session().then(function (utilisateur) {
    if (!utilisateur) {
      sousTitre.textContent = "Connecte-toi pour comparer vos collections.";
      Nook.bandeauConnexion(document.querySelector("main").firstElementChild);
      return;
    }
    if (!cible) {
      erreur("Choisis un ami à comparer depuis la page Amis.");
      return;
    }

    fetch("api/comparer.php?user_id=" + encodeURIComponent(cible), { credentials: "same-origin" })
      .then(function (r) { return r.json(); })
      .then(function (corps) {
        if (corps.erreur) throw new Error(corps.erreur);

        autre = corps.autre;
        titre.textContent = "Toi et " + autre.pseudo;
        zoneAvatars.replaceChildren(
          Nook.avatar(utilisateur.avatar_url, utilisateur.pseudo, "grand"),
          Nook.avatar(autre.avatar_url, autre.pseudo, "grand")
        );

        if (!corps.visible) {
          sousTitre.textContent = autre.pseudo + " garde sa collection pour lui.";
          return;
        }

        sousTitre.textContent = "Ce que vous avez chacun, et ce qu'il vous manque.";
        document.getElementById("titre-lui").textContent = autre.pseudo + " a, pas toi";
        elements = corps.elements;
        filtres.hidden = false;
        colonnes.hidden = false;
        afficher();
        choixCategorie.addEventListener("change", afficher);
      })
      .catch(function (err) { erreur(err.message || "Erreur de chargement."); });
  });
})();
