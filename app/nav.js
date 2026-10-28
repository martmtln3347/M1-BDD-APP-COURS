/**
 * Menu burger de la barre de navigation (petits ecrans). Sans ce script la
 * barre reste utilisable : les liens s'affichent en colonne.
 */
(function () {
  "use strict";

  var entete = document.querySelector(".top-nav");
  var bouton = document.getElementById("burger");
  if (!entete || !bouton) return;

  var etroit = window.matchMedia("(max-width: 900px)");

  function definir(ouvert) {
    entete.classList.toggle("is-ouvert", ouvert);
    bouton.setAttribute("aria-expanded", ouvert ? "true" : "false");
    bouton.setAttribute("aria-label", ouvert ? "Fermer le menu" : "Ouvrir le menu");
    document.body.classList.toggle("menu-ouvert", ouvert && etroit.matches);
  }

  bouton.addEventListener("click", function () {
    definir(!entete.classList.contains("is-ouvert"));
  });

  // Un lien suivi, un clic a cote ou Echap referment le menu.
  entete.addEventListener("click", function (e) {
    if (e.target.closest(".top-nav-link, .top-nav-bouton")) definir(false);
  });
  document.addEventListener("click", function (e) {
    if (entete.classList.contains("is-ouvert") && !entete.contains(e.target)) definir(false);
  });
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && entete.classList.contains("is-ouvert")) {
      definir(false);
      bouton.focus();
    }
  });

  // Repasse en grand ecran : on referme, pour ne pas laisser un etat ouvert cache.
  var surChangement = function () { if (!etroit.matches) definir(false); };
  if (etroit.addEventListener) etroit.addEventListener("change", surChangement);
  else if (etroit.addListener) etroit.addListener(surChangement);
})();
