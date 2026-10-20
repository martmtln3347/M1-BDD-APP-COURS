/**
 * Jeu d'icônes du site : des tracés dessinés à la main, en une seule couleur
 * héritée du texte. Elles remplacent les emoji, dont le rendu change d'un
 * système à l'autre et casse l'unité visuelle.
 */
(function () {
  "use strict";

  var TRACES = {
    calendrier:
      '<rect x="3" y="5" width="18" height="16" rx="3"/>' +
      '<path d="M8 3v4M16 3v4M3 10h18"/>',

    horloge:
      '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.4 2"/>',

    // Rivière et mer se distinguent par le nombre de vagues : deux tracés
    // trop détaillés deviendraient illisibles à la taille du texte.
    riviere:
      '<path d="M2.5 10c2-2 4-2 6 0s4 2 6 0 4-2 5.5 0"/>' +
      '<path d="M2.5 15.5c2-2 4-2 6 0s4 2 6 0 4-2 5.5 0"/>',

    mer:
      '<path d="M2.5 7.5c2-2 4-2 6 0s4 2 6 0 4-2 5.5 0"/>' +
      '<path d="M2.5 13c2-2 4-2 6 0s4 2 6 0 4-2 5.5 0"/>' +
      '<path d="M2.5 18.5c2-2 4-2 6 0s4 2 6 0 4-2 5.5 0"/>',

    mare:
      '<ellipse cx="12" cy="13.5" rx="8.5" ry="5.5"/>' +
      '<path d="M9 11.5c1.3-.9 2.6-.9 3.9 0"/>',

    lieu:
      '<path d="M12 21.5s7.2-6.6 7.2-11.4a7.2 7.2 0 1 0-14.4 0c0 4.8 7.2 11.4 7.2 11.4z"/>' +
      '<circle cx="12" cy="10" r="2.6"/>',

    gateau:
      '<path d="M4 20.5h16v-5.2a2.8 2.8 0 0 0-2.8-2.8H6.8A2.8 2.8 0 0 0 4 15.3z"/>' +
      '<path d="M12 12.5V9M8.3 12.5v-2.2M15.7 12.5v-2.2"/>' +
      '<path d="M4 17.2c2.7 1.6 5.3-1.6 8-0s5.3-1.6 8 0"/>',

    patte:
      '<circle cx="6.9" cy="9.6" r="1.9"/><circle cx="11.7" cy="7.3" r="2"/>' +
      '<circle cx="16.5" cy="9.6" r="1.9"/>' +
      '<path d="M12 12.6c3.1 0 5.2 1.9 5.2 4.1 0 1.8-1.5 3-3.4 3h-3.6c-1.9 0-3.4-1.2-3.4-3 0-2.2 2.1-4.1 5.2-4.1z"/>',

    poisson:
      '<path d="M21 12s-3.6 4.7-8.1 4.7S6.3 12 6.3 12s2.7-4.7 7.2-4.7S21 12 21 12z"/>' +
      '<path d="M6.3 12L2.6 8.6v6.8z"/><circle cx="16.6" cy="10.7" r=".9"/>',

    coquillage:
      '<path d="M12 20.5 4.6 9.6a8.7 8.7 0 0 1 14.8 0z"/>' +
      '<path d="M12 20 8.7 10.4M12 20V9.8M12 20l3.3-9.6"/>',

    papillon:
      '<path d="M12 8.4v9.2"/>' +
      '<path d="M12 9.6C10.6 6.7 7.8 5.1 5.8 5.9 3.9 6.7 3.7 9 5 10.6c-1.4 1.3-1.2 3.7.6 4.6 2 1 4.7-.6 5.9-3.2"/>' +
      '<path d="M12 9.6c1.4-2.9 4.2-4.5 6.2-3.7 1.9.8 2.1 3.1.8 4.7 1.4 1.3 1.2 3.7-.6 4.6-2 1-4.7-.6-5.9-3.2"/>' +
      '<path d="M12 8.6c-.5-1.5-1.5-2.4-2.8-2.7M12 8.6c.5-1.5 1.5-2.4 2.8-2.7"/>',

    cadre:
      '<rect x="3" y="4.5" width="18" height="15" rx="2.5"/>' +
      '<path d="M3.4 16.2l4.8-4.8 3.6 3.6 2.8-2.8 6 6"/><circle cx="8.8" cy="9" r="1.4"/>',

    loupe:
      '<circle cx="11" cy="11" r="6.6"/><path d="M15.8 15.8l4.6 4.6"/>',

    coche:
      '<circle cx="12" cy="12" r="8.8"/><path d="M8 12.4l2.8 2.8 5.4-5.6"/>',

    etoile:
      '<path d="M12 3l2.1 6.1L20 11l-5.9 1.9L12 19l-2.1-6.1L4 11l5.9-1.9z"/>',

    musee:
      '<path d="M3 9.6L12 4.2l9 5.4"/><path d="M5.6 10.4v8.2M9.9 10.4v8.2M14.1 10.4v8.2M18.4 10.4v8.2"/>' +
      '<path d="M3 20.4h18"/>',

    joueur:
      '<circle cx="12" cy="8.3" r="3.8"/>' +
      '<path d="M4.6 20.2c0-4 3.3-6.5 7.4-6.5s7.4 2.5 7.4 6.5"/>',

    coeur:
      '<path d="M12 20.3s-7.6-4.7-7.6-9.6a4.2 4.2 0 0 1 7.6-2.4 4.2 4.2 0 0 1 7.6 2.4c0 4.9-7.6 9.6-7.6 9.6z"/>',

    oeilBarre:
      '<path d="M3 12s3.7-6.2 9-6.2S21 12 21 12s-3.7 6.2-9 6.2S3 12 3 12z"/>' +
      '<circle cx="12" cy="12" r="2.7"/><path d="M4.4 20L19.6 4"/>',

    feuille:
      '<path d="M20.5 3.5C9.4 3.5 4 9 4 15.4c0 1.7.4 3.2 1.1 4.3.8-6.2 4.8-10.3 11.7-11.9-4.9 2-8.3 5.3-9.6 10.6 1.4 1.1 3.2 1.7 5.1 1.7 5.2 0 8.2-5.5 8.2-16.6z"/>'
  };

  /**
   * @param {string} nom   une clé de TRACES
   * @param {string} [modificateur]  par exemple "icone--plein"
   * @returns {SVGElement}
   */
  function Icone(nom, modificateur) {
    var svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    svg.setAttribute("viewBox", "0 0 24 24");
    svg.setAttribute("aria-hidden", "true");
    svg.setAttribute("focusable", "false");
    svg.setAttribute("class", "icone" + (modificateur ? " " + modificateur : ""));
    svg.innerHTML = TRACES[nom] || "";
    return svg;
  }

  /** Icône suivie d'un texte, le tout dans un conteneur aligné. */
  Icone.avecTexte = function (nom, texte, classe) {
    var hote = document.createElement("span");
    hote.className = "avec-icone" + (classe ? " " + classe : "");
    hote.appendChild(Icone(nom));
    hote.appendChild(document.createTextNode(texte));
    return hote;
  };

  window.Icone = Icone;
})();
