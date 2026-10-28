/**
 * Écran d'arrivée : le passeport, fermé. Ses trois entrées (connexion,
 * inscription, invité) se jouent sur la couverture même : les boutons
 * s'effacent en fondu et laissent la place aux champs. Une fois connecté
 * ou invité, il s'ouvre sur le site.
 *
 * Une session de navigation ne le voit qu'une fois : le drapeau
 * "nook-ouvert" est effacé à la connexion et à la déconnexion pour que
 * l'ouverture se rejoue au bon moment.
 */
(function () {
  "use strict";

  var racine = document.getElementById("passeport");
  if (!racine) return;

  var CLE = "nook-ouvert";
  var DUREE_FONDU = 280;

  var salut = racine.querySelector("[data-salut]");
  var zone = racine.querySelector("[data-zone]");
  var nom = racine.querySelector("[data-nom]");
  var sansAnimation = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  var ouvert = false;
  var enTransition = false;

  function vue(cle) {
    return zone.querySelector('[data-vue="' + cle + '"]');
  }

  /** Fondu : l'ancienne vue s'efface, la mise en page change à l'abri, la nouvelle apparaît. */
  function aller(cle) {
    var courante = zone.querySelector(".is-actif");
    var cible = vue(cle);
    if (!cible || cible === courante || enTransition) return;
    enTransition = true;

    courante.classList.remove("is-actif");

    setTimeout(function () {
      courante.hidden = true;
      racine.classList.toggle("is-formulaire", cle !== "accueil");
      racine.setAttribute("data-vue", cle);
      cible.hidden = false;
      void cible.offsetWidth; // fixe l'état de départ avant le fondu d'entrée
      cible.classList.add("is-actif");
      enTransition = false;

      var champ = cible.querySelector("input");
      var cibleFocus = champ || cible.querySelector("button");
      if (cibleFocus) cibleFocus.focus({ preventScroll: true });
    }, sansAnimation ? 0 : DUREE_FONDU);
  }

  function ouvrir(libelle) {
    if (ouvert) return;
    ouvert = true;

    try { sessionStorage.setItem(CLE, "1"); } catch (e) { /* ignore */ }
    nom.textContent = libelle;
    zone.hidden = true;
    racine.classList.remove("is-formulaire");
    racine.removeAttribute("data-vue");
    racine.classList.add("is-opening");

    setTimeout(function () {
      racine.remove();
    }, sansAnimation ? 250 : 2300);
  }

  racine.addEventListener("click", function (e) {
    var bouton = e.target.closest("[data-aller], [data-invite]");
    if (!bouton) return;
    if (bouton.hasAttribute("data-invite")) {
      ouvrir("invité");
    } else {
      aller(bouton.getAttribute("data-aller"));
    }
  });

  racine.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && !zone.querySelector('[data-vue="accueil"].is-actif')) aller("accueil");
  });

  /** Gestion commune d'envoi : message d'erreur, bouton verrouillé pendant l'appel. */
  function brancher(formulaire, action) {
    var erreur = formulaire.querySelector("[data-erreur]");
    var envoi = formulaire.querySelector("[data-envoi]");
    var libelle = envoi.textContent;

    formulaire.addEventListener("submit", function (e) {
      e.preventDefault();
      erreur.hidden = true;
      envoi.disabled = true;
      envoi.textContent = "Un instant…";

      action()
        .catch(function (err) {
          erreur.textContent = err.message || "Impossible de joindre le serveur.";
          erreur.hidden = false;
        })
        .then(function () {
          envoi.disabled = false;
          envoi.textContent = libelle;
        });
    });
  }

  brancher(vue("connexion"), function () {
    return Nook.connexion(
      document.getElementById("pp-email").value.trim(),
      document.getElementById("pp-mdp").value
    ).then(function (reponse) {
      var utilisateur = reponse.utilisateur;
      Nook.utilisateur = utilisateur;
      Nook.rafraichirCompte(utilisateur);
      ouvrir(utilisateur.pseudo);
    });
  });

  brancher(vue("inscription"), function () {
    var formulaire = vue("inscription");
    return Nook.inscription(
      document.getElementById("pp-pseudo").value.trim(),
      document.getElementById("pp-email2").value.trim(),
      document.getElementById("pp-mdp2").value
    ).then(function (reponse) {
      var succes = formulaire.querySelector("[data-succes]");
      formulaire.querySelector(".passeport-champs").hidden = true;
      formulaire.querySelector("[data-envoi]").hidden = true;
      succes.textContent = reponse.message + " ";

      // Mode dev uniquement (APP_ENV=dev) : faute de serveur mail, l'API
      // renvoie le lien pour qu'on puisse le suivre directement.
      if (reponse.lien_de_verification) {
        var lien = document.createElement("a");
        lien.href = reponse.lien_de_verification;
        lien.textContent = "Vérifier mon adresse maintenant (lien de dev)";
        succes.appendChild(document.createElement("br"));
        succes.appendChild(lien);
      }
      succes.hidden = false;
    });
  });

  Nook.session().then(function (utilisateur) {
    if (utilisateur) {
      zone.hidden = true;
      salut.textContent = "Bon retour, " + utilisateur.pseudo;
      racine.classList.add("is-pret");
      setTimeout(function () { ouvrir(utilisateur.pseudo); }, sansAnimation ? 200 : 1500);
    } else {
      racine.classList.add("is-pret");
      var premier = zone.querySelector("button");
      if (premier) premier.focus({ preventScroll: true });
    }
  });
})();
