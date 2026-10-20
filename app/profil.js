(function () {
  "use strict";

  var CATEGORIES = {
    habitant: { libelle: "Habitants", icone: "patte" },
    poisson: { libelle: "Poissons", icone: "poisson" },
    creature_marine: { libelle: "Créatures sous-marines", icone: "coquillage" },
    insecte: { libelle: "Insectes", icone: "papillon" },
    art: { libelle: "Œuvres d'art", icone: "cadre" }
  };

  var titre = document.getElementById("titre");
  var sousTitre = document.getElementById("sous-titre");
  var zoneAvatar = document.getElementById("profil-avatar");
  var zoneActions = document.getElementById("profil-actions");
  var panneauVisibilite = document.getElementById("panneau-visibilite");
  var choixVisibilite = document.getElementById("choix-visibilite");
  var panneauAvatar = document.getElementById("panneau-avatar");
  var zoneStats = document.getElementById("stats");
  var zoneBadges = document.getElementById("badges");
  var zoneRecentes = document.getElementById("recentes");
  var zoneClassement = document.getElementById("classement");
  var choixCategorie = document.getElementById("classement-categorie");
  var zoneCommunaute = document.getElementById("communaute");
  var zoneProgression = document.getElementById("progression");
  var zoneActivites = document.getElementById("activites");

  // ?user_id= permet de regarder le profil de quelqu'un d'autre ; c'est la
  // base qui décide de ce qui est visible.
  var cible = new URLSearchParams(window.location.search).get("user_id");
  var moi = null;

  function vide(zone, texte) {
    zone.replaceChildren();
    var p = document.createElement("p");
    p.className = "panneau-vide";
    p.textContent = texte;
    zone.appendChild(p);
  }

  function appeler(url, options) {
    return fetch(url, Object.assign({ credentials: "same-origin" }, options || {}))
      .then(function (r) { return r.json(); })
      .then(function (corps) {
        if (corps.erreur) throw new Error(corps.erreur);
        return corps;
      });
  }

  // ---------- Avancement ----------
  function barre(stat) {
    var ligne = document.createElement("div");
    ligne.className = "stat-ligne";

    var entete = document.createElement("div");
    entete.className = "stat-entete";

    var categorie = CATEGORIES[stat.categorie] || { libelle: stat.categorie, icone: "lieu" };
    var chiffres = document.createElement("span");
    chiffres.className = "stat-chiffres";
    chiffres.textContent = stat.nb_possedes + " / " + stat.nb_total_categorie
      + " · " + stat.nb_au_musee + " au musée";

    entete.appendChild(Icone.avecTexte(categorie.icone, categorie.libelle));
    entete.appendChild(chiffres);

    var jauge = document.createElement("div");
    jauge.className = "jauge";
    jauge.setAttribute("role", "progressbar");
    jauge.setAttribute("aria-label", categorie.libelle);
    jauge.setAttribute("aria-valuemin", "0");
    jauge.setAttribute("aria-valuemax", "100");
    jauge.setAttribute("aria-valuenow", String(parseFloat(stat.pct_possede) || 0));
    var remplissage = document.createElement("div");
    remplissage.className = "jauge-remplissage";
    remplissage.style.width = Math.min(100, parseFloat(stat.pct_possede) || 0) + "%";
    jauge.appendChild(remplissage);

    var pourcent = document.createElement("span");
    pourcent.className = "jauge-valeur";
    pourcent.textContent = stat.pct_possede + " %";

    ligne.appendChild(entete);
    ligne.appendChild(jauge);
    ligne.appendChild(pourcent);
    return ligne;
  }

  function afficherStats(stats) {
    if (!stats.length) {
      vide(zoneStats, "Ce profil n'est pas visible pour toi.");
      return;
    }
    zoneStats.replaceChildren();
    stats.forEach(function (s) { zoneStats.appendChild(barre(s)); });
  }

  // ---------- Badges ----------
  function afficherBadges(badges) {
    if (!badges.length) {
      vide(zoneBadges, "Aucun badge à afficher.");
      return;
    }
    var liste = document.createElement("ul");
    liste.className = "badges";
    badges.forEach(function (b) {
      var li = document.createElement("li");
      li.className = "badge-carte" + (b.obtenu ? " is-obtenu" : "");

      var icone = document.createElement("span");
      icone.className = "badge-icone";
      icone.appendChild(Icone(b.obtenu ? "etoile" : "lieu"));

      var texte = document.createElement("span");
      var nom = document.createElement("strong");
      nom.textContent = b.titre;
      var detail = document.createElement("small");
      detail.textContent = b.obtenu ? "Débloqué" : b.detail;
      texte.appendChild(nom);
      texte.appendChild(detail);

      li.appendChild(icone);
      li.appendChild(texte);
      liste.appendChild(li);
    });
    zoneBadges.replaceChildren(liste);
  }

  // ---------- Progression (requete SQL a CTE) ----------
  function afficherProgression(points) {
    zoneProgression.replaceChildren();
    var max = Math.max.apply(null, points.map(function (p) { return p.nouveaux; }).concat([1]));
    var total = points.length ? points[points.length - 1].cumul : 0;

    var resume = document.createElement("p");
    resume.className = "progression-resume";
    resume.textContent = total + " élément" + (total > 1 ? "s" : "") + " obtenu" + (total > 1 ? "s" : "") + " sur la période.";
    zoneProgression.appendChild(resume);

    var barres = document.createElement("div");
    barres.className = "progression";
    barres.setAttribute("role", "img");
    barres.setAttribute("aria-label", "Éléments obtenus par jour, " + total + " au total");
    points.forEach(function (p) {
      var b = document.createElement("span");
      b.className = "progression-barre";
      b.style.height = Math.max(4, Math.round(100 * p.nouveaux / max)) + "%";
      b.title = p.jour + " : " + p.nouveaux + " (cumul " + p.cumul + ")";
      barres.appendChild(b);
    });
    zoneProgression.appendChild(barres);
  }

  // ---------- Dernières trouvailles ----------
  function afficherRecentes(items) {
    if (!items.length) {
      vide(zoneRecentes, "Rien pour l'instant.");
      return;
    }
    var grille = document.createElement("ul");
    grille.className = "vignettes";
    items.forEach(function (it) {
      var li = document.createElement("li");
      li.className = "vignette" + (it.au_musee ? " is-musee" : "");
      li.title = it.nom + (it.au_musee ? " (au musée)" : "");
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
      grille.appendChild(li);
    });
    zoneRecentes.replaceChildren(grille);
  }

  // ---------- Classement ----------
  function afficherClassement(lignes, moi, global) {
    if (!lignes.length) {
      vide(zoneClassement, "Personne à classer pour l'instant.");
      return;
    }

    var table = document.createElement("table");
    table.className = "tableau";
    var legende = document.createElement("caption");
    legende.className = "sr-only";
    legende.textContent = "Classement des joueurs";
    table.appendChild(legende);
    table.innerHTML += "<thead><tr><th scope=\"col\">#</th><th scope=\"col\">Joueur</th><th scope=\"col\">Trouvés</th><th scope=\"col\">Complétion</th></tr></thead>";

    var corps = document.createElement("tbody");
    lignes.forEach(function (ligne, index) {
      var tr = document.createElement("tr");
      if (moi && ligne.user_id === moi.id) tr.className = "est-moi";

      var rang = document.createElement("td");
      rang.textContent = index + 1;

      var joueur = document.createElement("td");
      var lien = document.createElement("a");
      lien.className = "joueur-nom";
      lien.href = "?page=profil&user_id=" + encodeURIComponent(ligne.user_id);
      lien.appendChild(Nook.avatar(ligne.avatar_url, ligne.pseudo, "petit"));
      lien.appendChild(document.createTextNode(ligne.pseudo));
      joueur.appendChild(lien);

      var trouves = document.createElement("td");
      trouves.textContent = ligne.nb_possedes + " / " + ligne.nb_total;

      var pourcent = document.createElement("td");
      pourcent.textContent = (global ? ligne.pct_global : ligne.pct) + " %";

      [rang, joueur, trouves, pourcent].forEach(function (c) { tr.appendChild(c); });
      corps.appendChild(tr);
    });

    table.appendChild(corps);
    zoneClassement.replaceChildren(table);
  }

  var classementGlobal = [];

  function chargerClassement() {
    var categorie = choixCategorie.value;
    if (!categorie) {
      afficherClassement(classementGlobal, moi, true);
      return;
    }
    appeler("api/classement-categorie.php?categorie=" + encodeURIComponent(categorie))
      .then(function (corps) { afficherClassement(corps.classement, moi, false); })
      .catch(function (err) { vide(zoneClassement, err.message); });
  }

  // ---------- Records de l'île ----------
  function listeRecords(titreListe, items) {
    var bloc = document.createElement("div");
    bloc.className = "record";
    var h3 = document.createElement("h3");
    h3.textContent = titreListe;
    bloc.appendChild(h3);

    var ol = document.createElement("ol");
    items.forEach(function (it) {
      var li = document.createElement("li");
      if (it.image_icone) {
        var img = document.createElement("img");
        img.src = it.image_icone;
        img.alt = "";
        img.loading = "lazy";
        li.appendChild(img);
      }
      var nom = document.createElement("span");
      nom.textContent = it.nom;
      var n = document.createElement("small");
      n.textContent = it.nb_possesseurs + (Number(it.nb_possesseurs) > 1 ? " joueurs" : " joueur");
      li.appendChild(nom);
      li.appendChild(n);
      ol.appendChild(li);
    });
    bloc.appendChild(ol);
    return bloc;
  }

  function afficherCommunaute(c) {
    zoneCommunaute.replaceChildren();
    if (!c.plus_rares.length && !c.plus_courants.length) {
      vide(zoneCommunaute, "Pas encore assez de profils visibles pour établir des records.");
      return;
    }
    var grille = document.createElement("div");
    grille.className = "records";
    grille.appendChild(listeRecords("Les plus rares", c.plus_rares));
    grille.appendChild(listeRecords("Les plus courants", c.plus_courants));
    zoneCommunaute.appendChild(grille);

    if (typeof c.trouvailles_48h === "number") {
      var recentes = document.createElement("p");
      recentes.className = "record-champion";
      var tr = document.createElement("span");
      tr.textContent = c.trouvailles_48h + " trouvailles sur les profils visibles ces 48 dernières heures.";
      recentes.appendChild(tr);
      zoneCommunaute.appendChild(recentes);
    }

    if (c.aujourdhui && Object.keys(c.aujourdhui).length) {
      var jour = document.createElement("p");
      jour.className = "record-champion";
      var t = document.createElement("span");
      t.textContent = "Aujourd'hui : " + (c.aujourdhui.connexions || 0) + " connexions, "
        + (c.aujourdhui.marquages || 0) + " trouvailles cochées, " + (c.aujourdhui.inscriptions || 0) + " inscriptions.";
      jour.appendChild(t);
      zoneCommunaute.appendChild(jour);
    }

    if (c.plus_gros_musee) {
      var champion = document.createElement("p");
      champion.className = "record-champion";
      champion.appendChild(Nook.avatar(c.plus_gros_musee.avatar_url, c.plus_gros_musee.pseudo, "petit"));
      var texte = document.createElement("span");
      texte.textContent = c.plus_gros_musee.pseudo + " a le plus gros musée : "
        + c.plus_gros_musee.nb_au_musee + " pièces.";
      champion.appendChild(texte);
      zoneCommunaute.appendChild(champion);
    }
  }

  // ---------- Fil d'actu ----------
  function dateCourte(valeur) {
    var d = new Date(valeur.replace(" ", "T"));
    if (isNaN(d)) return "";
    return d.toLocaleDateString("fr-FR", { day: "numeric", month: "short" })
      + " à " + d.toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit" });
  }

  function afficherActivites(activites) {
    if (!activites.length) {
      vide(zoneActivites, "Rien pour l'instant : coche un poisson ou une œuvre pour lancer le fil.");
      return;
    }

    var liste = document.createElement("ul");
    liste.className = "fil";

    activites.forEach(function (a) {
      var item = document.createElement("li");
      item.className = "fil-ligne";

      var icone = document.createElement("span");
      icone.className = "fil-icone";
      icone.appendChild(Nook.avatar(a.avatar_url, a.pseudo));

      var texte = document.createElement("p");
      texte.className = "fil-texte";
      var qui = document.createElement("strong");
      qui.textContent = a.pseudo;
      texte.appendChild(qui);
      texte.appendChild(document.createTextNode(
        a.type === "au_musee" ? " a donné " : " a trouvé "
      ));
      var quoi = document.createElement("em");
      quoi.textContent = a.item;
      texte.appendChild(quoi);
      if (a.type === "au_musee") texte.appendChild(document.createTextNode(" au musée"));

      var quand = document.createElement("span");
      quand.className = "fil-date";
      quand.textContent = dateCourte(a.cree_le);

      item.appendChild(icone);
      item.appendChild(texte);
      item.appendChild(quand);
      liste.appendChild(item);
    });

    zoneActivites.replaceChildren(liste);
  }

  // ---------- Visibilité ----------
  function brancherVisibilite(utilisateur) {
    panneauVisibilite.hidden = false;
    var choisi = choixVisibilite.querySelector('input[value="' + utilisateur.visibilite + '"]');
    if (choisi) choisi.checked = true;

    choixVisibilite.addEventListener("change", function (e) {
      var boutons = choixVisibilite.querySelectorAll("input");
      boutons.forEach(function (b) { b.disabled = true; });

      appeler("api/visibilite.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ visibilite: e.target.value })
      })
        .then(function () {
          Nook.message("Visibilité mise à jour.");
          return charger();
        })
        .catch(function (err) { Nook.message(err.message, "erreur"); })
        .then(function () { boutons.forEach(function (b) { b.disabled = false; }); });
    });
  }

  // ---------- Photo de profil ----------
  function brancherAvatar() {
    panneauAvatar.hidden = false;
    var ouvrir = document.getElementById("avatar-ouvrir");
    var retirer = document.getElementById("avatar-retirer");
    var selecteur = document.getElementById("avatar-selecteur");
    var recherche = document.getElementById("avatar-recherche");
    var grille = document.getElementById("avatar-grille");
    var habitants = null;

    function normaliser(t) {
      return (t || "").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "");
    }

    function appliquer(itemId) {
      return appeler("api/avatar.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ item_id: itemId })
      }).then(function (corps) {
        moi.avatar_url = corps.avatar_url;
        Nook.rafraichirCompte(moi);
        majEntete(moi.pseudo, corps.avatar_url);
        retirer.hidden = !corps.avatar_url;
        Nook.message(corps.avatar_url ? "Photo de profil mise à jour." : "Photo retirée.");
        charger();
      }).catch(function (err) { Nook.message(err.message, "erreur"); });
    }

    function afficherGrille() {
      var terme = normaliser(recherche.value.trim());
      grille.replaceChildren();
      var n = 0;
      habitants.forEach(function (h) {
        if (terme && normaliser(h.nom).indexOf(terme) === -1) return;
        if (++n > 120) return; // la recherche affine, inutile d'afficher 417 boutons d'un coup
        var b = document.createElement("button");
        b.type = "button";
        b.className = "avatar-choix";
        b.setAttribute("role", "option");
        b.title = h.nom;
        var img = document.createElement("img");
        img.src = h.image_icone;
        img.alt = h.nom;
        img.loading = "lazy";
        img.decoding = "async";
        b.appendChild(img);
        b.addEventListener("click", function () { appliquer(h.id); });
        grille.appendChild(b);
      });
      if (n === 0) {
        var vide = document.createElement("p");
        vide.className = "panneau-vide";
        vide.textContent = "Aucun habitant ne correspond.";
        grille.appendChild(vide);
      }
    }

    ouvrir.addEventListener("click", function () {
      var ouvert = selecteur.hidden;
      selecteur.hidden = !ouvert;
      ouvrir.setAttribute("aria-expanded", ouvert ? "true" : "false");
      if (!ouvert) return;
      if (habitants) { recherche.focus(); return; }
      grille.textContent = "Chargement…";
      appeler("api/avatar.php").then(function (corps) {
        habitants = corps.habitants;
        afficherGrille();
        recherche.focus();
      }).catch(function (err) { grille.textContent = err.message; });
    });

    recherche.addEventListener("input", function () { if (habitants) afficherGrille(); });
    retirer.addEventListener("click", function () { appliquer(null); });
    retirer.hidden = !moi.avatar_url;
  }

  // ---------- Suppression du compte (DELETE) ----------
  function brancherSuppression() {
    document.getElementById("panneau-compte").hidden = false;
    document.getElementById("compte-supprimer").addEventListener("click", function () {
      if (!window.confirm("Supprimer définitivement ton compte et toutes tes données ?")) return;
      appeler("api/compte.php", { method: "DELETE", headers: { "Content-Type": "application/json" } })
        .then(function () { window.location.href = "?page=accueil"; })
        .catch(function (err) { Nook.message(err.message, "erreur"); });
    });
  }

  // ---------- En-tête ----------
  function majEntete(pseudo, avatarUrl) {
    titre.textContent = pseudo || "Profil";
    zoneAvatar.replaceChildren(Nook.avatar(avatarUrl, pseudo, "grand"));
  }

  function charger() {
    return appeler("api/profil.php" + (cible ? "?user_id=" + encodeURIComponent(cible) : ""))
      .then(function (donnees) {
        majEntete(donnees.pseudo, donnees.avatar_url);

        afficherStats(donnees.stats);
        if (donnees.visible) {
          afficherBadges(donnees.badges);
          afficherRecentes(donnees.recentes);
        } else {
          vide(zoneBadges, "Ce profil n'est pas visible pour toi.");
          vide(zoneRecentes, "Ce profil n'est pas visible pour toi.");
        }
        classementGlobal = donnees.classement;
        chargerClassement();
        afficherActivites(donnees.activites);

        zoneActions.replaceChildren();
        if (!donnees.c_est_moi && donnees.visible) {
          var comparer = document.createElement("a");
          comparer.className = "reset-btn petit";
          comparer.href = "?page=comparer&user_id=" + encodeURIComponent(donnees.user_id);
          comparer.textContent = "Comparer nos collections";
          zoneActions.appendChild(comparer);
        }

        sousTitre.textContent = donnees.c_est_moi
          ? "Voici où tu en es dans ta collection."
          : (donnees.visible
              ? "Avancement de ce joueur."
              : "Ce joueur garde son profil pour lui.");
      });
  }

  Nook.session().then(function (utilisateur) {
    if (!utilisateur) {
      titre.textContent = "Profil";
      sousTitre.textContent = "Connecte-toi pour voir ton avancement.";
      Nook.bandeauConnexion(document.querySelector("main").firstElementChild);
      [zoneStats, zoneBadges, zoneRecentes, zoneClassement, zoneCommunaute, zoneActivites].forEach(function (z) {
        vide(z, "Réservé aux membres connectés.");
      });
      choixCategorie.disabled = true;
      return;
    }

    moi = utilisateur;
    var monProfil = !cible || Number(cible) === utilisateur.id;
    if (monProfil) {
      brancherVisibilite(utilisateur);
      brancherAvatar();
      brancherSuppression();
    }

    choixCategorie.addEventListener("change", chargerClassement);

    charger().catch(function (err) {
      sousTitre.textContent = "Erreur de chargement.";
      console.error("Chargement du profil :", err);
    });

    appeler("api/progression.php?jours=30" + (cible ? "&user_id=" + encodeURIComponent(cible) : ""))
      .then(function (corps) { afficherProgression(corps.points); })
      .catch(function () { vide(zoneProgression, "Progression non disponible."); });

    appeler("api/communaute.php")
      .then(afficherCommunaute)
      .catch(function () { vide(zoneCommunaute, "Records indisponibles pour le moment."); });
  });
})();
