/**
 * Couche d'acces a l'API, partagee par toutes les pages.
 * Expose window.Nook et installe la zone "compte" dans la barre de navigation.
 */
(function () {
  "use strict";

  var BASE = "api/";
  var sessionEnCours = null;

  function requete(url, options) {
    var reglages = Object.assign({ credentials: "same-origin" }, options || {});
    return fetch(url, reglages).then(function (reponse) {
      return reponse
        .json()
        .catch(function () { return {}; })
        .then(function (corps) {
          if (!reponse.ok) {
            var erreur = new Error(corps.erreur || "Erreur " + reponse.status);
            erreur.statut = reponse.status;
            throw erreur;
          }
          return corps;
        });
    });
  }

  function envoyer(url, corps) {
    return requete(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(corps || {})
    });
  }

  var Nook = {
    utilisateur: null,

    /** Etat de session, resolu une seule fois par page. */
    session: function () {
      if (!sessionEnCours) {
        sessionEnCours = requete(BASE + "moi.php")
          .then(function (corps) {
            Nook.utilisateur = corps.utilisateur;
            return corps.utilisateur;
          })
          .catch(function () {
            Nook.utilisateur = null;
            return null;
          });
      }
      return sessionEnCours;
    },

    inscription: function (pseudo, email, motDePasse) {
      return envoyer(BASE + "inscription.php", {
        pseudo: pseudo, email: email, mot_de_passe: motDePasse
      });
    },

    connexion: function (email, motDePasse) {
      return envoyer(BASE + "connexion.php", { email: email, mot_de_passe: motDePasse });
    },

    deconnexion: function () {
      return envoyer(BASE + "deconnexion.php");
    },

    items: function (categorie) {
      return requete(BASE + "items.php?categorie=" + encodeURIComponent(categorie))
        .then(function (corps) { return corps.items; });
    },

    /** Collection de l'utilisateur connecte, indexee par identifiant d'item. */
    collection: function () {
      return requete(BASE + "collection.php").then(function (corps) {
        var parItem = new Map();
        corps.collection.forEach(function (ligne) {
          parItem.set(ligne.item_id, { possede: ligne.possede, au_musee: ligne.au_musee });
        });
        return parItem;
      });
    },

    /** etat = { possede: bool, au_musee: bool }, les deux facultatifs. */
    marquer: function (itemId, etat) {
      return envoyer(BASE + "collection.php", Object.assign({ item_id: itemId }, etat))
        .then(function (corps) { return corps.item; });
    }
  };

  // ---------- Zone "compte" dans la barre de navigation ----------
  function monterCompte(utilisateur) {
    var barre = document.querySelector(".top-nav-inner");
    if (!barre) return;

    var ancienne = barre.querySelector(".top-nav-compte");
    if (ancienne) ancienne.remove();

    var zone = document.createElement("div");
    zone.className = "top-nav-compte";

    if (utilisateur) {
      var pseudo = document.createElement("span");
      pseudo.className = "top-nav-pseudo";
      pseudo.appendChild(Nook.avatar(utilisateur.avatar_url, utilisateur.pseudo, "petit"));
      pseudo.appendChild(document.createTextNode(utilisateur.pseudo));

      var bouton = document.createElement("button");
      bouton.type = "button";
      bouton.className = "top-nav-bouton";
      bouton.textContent = "Déconnexion";
      bouton.addEventListener("click", function () {
        bouton.disabled = true;
        Nook.deconnexion().then(function () {
          try { sessionStorage.removeItem("nook-ouvert"); } catch (e) { /* ignore */ }
          window.location.href = "?page=accueil";
        });
      });

      var cloche = document.createElement("a");
      cloche.className = "top-nav-notif";
      cloche.href = "?page=amis";
      cloche.setAttribute("aria-label", "Notifications");
      cloche.appendChild(Icone("coeur"));
      cloche.hidden = true;
      var compteur = document.createElement("span");
      compteur.className = "badge-compteur";
      cloche.appendChild(compteur);

      // Les notifications vivent dans Redis ; si le service est absent, la cloche reste cachee.
      requete(BASE + "notifications.php").then(function (corps) {
        if (corps.total > 0) {
          compteur.textContent = String(corps.total);
          cloche.setAttribute("aria-label", corps.total + " notification(s)");
          cloche.hidden = false;
        }
      }).catch(function () { /* optionnel */ });

      zone.appendChild(cloche);
      zone.appendChild(pseudo);
      zone.appendChild(bouton);
    } else {
      var lien = document.createElement("a");
      lien.className = "top-nav-bouton";
      lien.href = "?page=connexion";
      lien.textContent = "Connexion";
      zone.appendChild(lien);
    }

    barre.appendChild(zone);
  }

  /**
   * Bandeau affiche au-dessus d'une liste quand personne n'est connecte :
   * sans compte, il n'y a pas de collection a enregistrer.
   */
  Nook.bandeauConnexion = function (avant) {
    if (!avant || document.querySelector(".bandeau-connexion")) return;

    var bandeau = document.createElement("p");
    bandeau.className = "bandeau-connexion";
    bandeau.appendChild(document.createTextNode("Connecte-toi pour suivre ta collection. "));

    var lien = document.createElement("a");
    lien.href = "?page=connexion";
    lien.textContent = "Se connecter ou créer un compte";
    bandeau.appendChild(lien);

    avant.parentNode.insertBefore(bandeau, avant);
  };

  /** Message flottant, pour signaler un refus du serveur sans bloquer la page. */
  var minuteurMessage = null;
  Nook.message = function (texte, type) {
    var boite = document.querySelector(".notice");
    if (!boite) {
      boite = document.createElement("div");
      boite.className = "notice";
      boite.setAttribute("role", "status");
      document.body.appendChild(boite);
    }
    boite.textContent = texte;
    boite.classList.toggle("is-erreur", type === "erreur");
    boite.classList.add("is-visible");

    clearTimeout(minuteurMessage);
    minuteurMessage = setTimeout(function () {
      boite.classList.remove("is-visible");
    }, 4000);
  };

  /** Réaffiche la zone compte quand l'état de session change sans recharger la page. */
  Nook.rafraichirCompte = monterCompte;

  /**
   * Pastille ronde d'un joueur : sa photo de profil, ou son initiale quand il
   * n'en a pas choisi. Le texte passe par textContent, jamais par innerHTML.
   */
  Nook.avatar = function (url, pseudo, taille) {
    var pastille = document.createElement("span");
    pastille.className = "avatar" + (taille ? " avatar--" + taille : "");
    pastille.setAttribute("aria-hidden", "true");
    if (url) {
      var img = document.createElement("img");
      img.src = url;
      img.alt = "";
      img.loading = "lazy";
      img.decoding = "async";
      img.addEventListener("error", function () {
        img.remove();
        pastille.textContent = (pseudo || "?").charAt(0).toUpperCase();
      });
      pastille.appendChild(img);
    } else {
      pastille.textContent = (pseudo || "?").charAt(0).toUpperCase();
    }
    return pastille;
  };

  /** Cartes fantomes affichees le temps du chargement : la page ne saute pas quand les vraies arrivent. */
  window.Squelette = {
    afficher: function (grille, classeCarte, nombre) {
      grille.setAttribute("aria-busy", "true");
      for (var i = 0; i < nombre; i++) {
        var carte = document.createElement("div");
        carte.className = classeCarte + " squelette";
        carte.setAttribute("aria-hidden", "true");
        grille.appendChild(carte);
      }
    }
  };

  window.Nook = Nook;

  Nook.session().then(monterCompte);
})();
