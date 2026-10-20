(function () {
  "use strict";

  var formulaire = document.getElementById("recherche");
  var champTerme = document.getElementById("terme");
  var zoneResultats = document.getElementById("resultats");
  var zoneRecues = document.getElementById("recues");
  var zoneEnvoyees = document.getElementById("envoyees");
  var zoneAmis = document.getElementById("amis");

  function api(url, options) {
    var reglages = Object.assign({ credentials: "same-origin" }, options || {});
    return fetch(url, reglages)
      .then(function (r) { return r.json(); })
      .then(function (corps) {
        if (corps.erreur) throw new Error(corps.erreur);
        return corps;
      });
  }

  function poster(corps) {
    return api("api/amis.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(corps)
    });
  }

  function vide(zone, texte) {
    zone.innerHTML = "";
    var p = document.createElement("p");
    p.className = "panneau-vide";
    p.textContent = texte;
    zone.appendChild(p);
  }

  function bouton(texte, classe, action) {
    var b = document.createElement("button");
    b.type = "button";
    b.className = classe;
    b.textContent = texte;
    b.addEventListener("click", function () {
      var envoi = action();
      if (!envoi) return; // action abandonnée, par exemple confirmation refusée
      b.disabled = true;
      envoi
        .then(function (reponse) {
          Nook.message(reponse.message);
          return charger();
        })
        .catch(function (err) {
          Nook.message(err.message, "erreur");
          b.disabled = false;
        });
    });
    return b;
  }

  function ligneJoueur(pseudo, userId, actions, avatarUrl) {
    var ligne = document.createElement("div");
    ligne.className = "joueur-ligne";

    var lien = document.createElement("a");
    lien.className = "joueur-nom";
    lien.href = "?page=profil&user_id=" + userId;
    lien.appendChild(Nook.avatar(avatarUrl, pseudo, "petit"));
    lien.appendChild(document.createTextNode(pseudo));

    var groupe = document.createElement("div");
    groupe.className = "joueur-actions";
    actions.forEach(function (a) { groupe.appendChild(a); });

    ligne.appendChild(lien);
    ligne.appendChild(groupe);
    return ligne;
  }

  function afficherListe(zone, entrees, texteVide, construireActions) {
    if (!entrees.length) {
      vide(zone, texteVide);
      return;
    }
    zone.innerHTML = "";
    entrees.forEach(function (e) {
      zone.appendChild(ligneJoueur(e.pseudo, e.user_id, construireActions(e), e.avatar_url));
    });
  }

  function charger() {
    return api("api/amis.php").then(function (relations) {
      afficherListe(zoneRecues, relations.demandes_recues,
        "Aucune demande en attente.", function (e) {
          return [
            bouton("Accepter", "reset-btn petit", function () {
              return poster({ action: "accepter", user_id: e.user_id });
            }),
            bouton("Refuser", "toggle-btn petit", function () {
              return poster({ action: "refuser", user_id: e.user_id });
            })
          ];
        });

      afficherListe(zoneEnvoyees, relations.demandes_envoyees,
        "Aucune demande envoyée.", function (e) {
          var attente = document.createElement("span");
          attente.className = "etiquette";
          attente.textContent = "En attente";
          return [
            attente,
            bouton("Annuler", "toggle-btn petit", function () {
              return poster({ action: "retirer", user_id: e.user_id });
            })
          ];
        });

      afficherListe(zoneAmis, relations.amis,
        "Pas encore d'amis : cherche un pseudo ci-dessus.", function (e) {
          var lien = document.createElement("a");
          lien.className = "toggle-btn petit";
          lien.href = "?page=profil&user_id=" + e.user_id;
          lien.textContent = "Voir son avancement";
          var comparer = document.createElement("a");
          comparer.className = "reset-btn petit";
          comparer.href = "?page=comparer&user_id=" + encodeURIComponent(e.user_id);
          comparer.textContent = "Comparer";
          return [
            comparer,
            lien,
            bouton("Retirer", "toggle-btn petit bouton-danger", function () {
              // Retirer un ami coupe aussi l'accès à son profil s'il est
              // réservé aux amis : on demande confirmation.
              if (!window.confirm("Retirer " + e.pseudo + " de tes amis ?")) {
                return null;
              }
              return poster({ action: "retirer", user_id: e.user_id });
            })
          ];
        });
    });
  }

  formulaire.addEventListener("submit", function (e) {
    e.preventDefault();
    var terme = champTerme.value.trim();

    api("api/utilisateurs.php?q=" + encodeURIComponent(terme))
      .then(function (corps) {
        afficherListe(zoneResultats, corps.utilisateurs.map(function (u) {
          return { pseudo: u.pseudo, user_id: u.id, avatar_url: u.avatar_url };
        }), "Personne ne correspond à « " + terme + " ».", function (u) {
          return [
            bouton("Ajouter", "reset-btn petit", function () {
              return poster({ action: "demander", user_id: u.user_id });
            })
          ];
        });
      })
      .catch(function (err) { Nook.message(err.message, "erreur"); });
  });

  // ---------- Notifications (Redis) ----------
  var panneauNotifs = document.getElementById("panneau-notifications");
  var zoneNotifs = document.getElementById("notifications");
  var boutonVider = document.getElementById("notifications-vider");

  function libelleNotif(n) {
    return n.type === "acceptee"
      ? n.de + " a accepté ta demande d'ami."
      : n.de + " t'a envoyé une demande d'ami.";
  }

  function chargerNotifications() {
    return api("api/notifications.php").then(function (corps) {
      panneauNotifs.hidden = false;
      zoneNotifs.replaceChildren();
      boutonVider.hidden = corps.notifications.length === 0;

      if (!corps.notifications.length) {
        var p = document.createElement("p");
        p.className = "panneau-vide";
        p.textContent = "Aucune notification.";
        zoneNotifs.appendChild(p);
        return;
      }
      corps.notifications.forEach(function (n) {
        var ligne = document.createElement("div");
        ligne.className = "joueur-ligne";
        var texte = document.createElement("span");
        texte.textContent = libelleNotif(n);
        var retirer = document.createElement("button");
        retirer.type = "button";
        retirer.className = "toggle-btn petit";
        retirer.textContent = "Supprimer";
        retirer.addEventListener("click", function () {
          poster2("api/notifications.php", { action: "supprimer", id: n.id }).then(chargerNotifications);
        });
        ligne.appendChild(texte);
        ligne.appendChild(retirer);
        zoneNotifs.appendChild(ligne);
      });
    }).catch(function () { /* Redis absent : le panneau reste cache */ });
  }

  function poster2(url, corps) {
    return fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(corps)
    }).then(function (r) { return r.json(); });
  }

  boutonVider.addEventListener("click", function () {
    poster2("api/notifications.php", { action: "tout_lire" }).then(chargerNotifications);
  });

  Nook.session().then(function (utilisateur) {
    if (!utilisateur) {
      Nook.bandeauConnexion(document.querySelector("main").firstElementChild);
      formulaire.querySelectorAll("input, button").forEach(function (c) { c.disabled = true; });
      [zoneRecues, zoneEnvoyees, zoneAmis].forEach(function (z) {
        vide(z, "Réservé aux membres connectés.");
      });
      return;
    }

    chargerNotifications();
    api("api/suggestions.php").then(function (corps) {
      if (!corps.items.length) return;
      var liste = document.getElementById("suggestions");
      corps.items.forEach(function (it) {
        var li = document.createElement("li");
        li.className = "vignette";
        li.title = it.nom + " : " + it.nb_amis + " ami(s)";
        if (it.image_icone) {
          var img = document.createElement("img");
          img.src = it.image_icone;
          img.alt = it.nom;
          img.loading = "lazy";
          li.appendChild(img);
        }
        var nom = document.createElement("span");
        nom.textContent = it.nom + " (" + it.nb_amis + ")";
        li.appendChild(nom);
        liste.appendChild(li);
      });
      document.getElementById("panneau-suggestions").hidden = false;
    }).catch(function () { /* facultatif */ });
    charger().catch(function (err) {
      Nook.message(err.message, "erreur");
      console.error("Chargement des amis :", err);
    });
  });
})();
