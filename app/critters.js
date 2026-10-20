(function () {
  "use strict";

  var config = window.CRITTER_CONFIG;
  if (!config) return;

  var MONTHS_FULL_FR = [
    "Janvier", "Février", "Mars", "Avril", "Mai", "Juin",
    "Juillet", "Août", "Septembre", "Octobre", "Novembre", "Décembre"
  ];
  var MONTHS_SHORT_FR = [
    "Jan", "Fév", "Mar", "Avr", "Mai", "Juin",
    "Juil", "Août", "Sep", "Oct", "Nov", "Déc"
  ];

  var WHERE_FR = {
    // Fish
    "Pier": "Jetée (mer)",
    "Pond": "Mare",
    "River": "Rivière",
    "River (clifftop)": "Rivière (falaise)",
    "River (mouth)": "Embouchure",
    "Sea": "Mer",
    "Sea (rainy days)": "Mer (jours de pluie)",
    // Insects
    "Disguised on shoreline": "Déguisé sur le rivage",
    "Disguised under trees": "Déguisé sous les arbres",
    "Flying": "En vol",
    "Flying near blue/purple/black flowers": "Vole près des fleurs bleues/violettes/noires",
    "Flying near flowers": "Vole près des fleurs",
    "Flying near light sources": "Vole près des lumières",
    "Flying near trash (boots, tires, cans, used fountain fireworks) or rotten turnips": "Vole près des déchets ou navets pourris",
    "Flying near water": "Vole près de l'eau",
    "From hitting rocks": "En tapant les rochers",
    "On beach rocks": "Sur les rochers de plage",
    "On flowers": "Sur les fleurs",
    "On hardwood/cedar trees": "Sur les feuillus/cèdres",
    "On palm trees": "Sur les palmiers",
    "On rivers/ponds": "Sur les rivières/mares",
    "On rocks/bushes": "Sur les rochers/buissons",
    "On rotten turnips or candy": "Sur navets pourris ou bonbons",
    "On the ground": "Au sol",
    "On tree stumps": "Sur les souches",
    "On trees (any kind)": "Sur les arbres",
    "On villagers": "Sur les habitants",
    "On white flowers": "Sur les fleurs blanches",
    "Pushing snowballs": "En poussant des boules de neige",
    "Shaking trees": "En secouant les arbres",
    "Shaking trees (hardwood or cedar only)": "En secouant feuillus/cèdres",
    "Underground (dig where noise is loudest)": "Sous terre (creuser où le bruit est fort)"
  };

  // Chaque lieu de pêche a son tracé ; les insectes et les créatures marines,
  // dont les lieux sont des phrases, partagent l'épingle.
  var LIEU_ICONE = {
    "Pier": "mer",
    "Pond": "mare",
    "River": "riviere",
    "River (clifftop)": "riviere",
    "River (mouth)": "riviere",
    "Sea": "mer",
    "Sea (rainy days)": "mer"
  };

  function whereFr(whereHow) {
    return WHERE_FR[whereHow] || whereHow;
  }

  function iconeLieu(whereHow) {
    return LIEU_ICONE[whereHow] || "lieu";
  }

  function normalize(str) {
    return (str || "")
      .toString()
      .toLowerCase()
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "");
  }

  function formatMonths(monthsArray) {
    if (!monthsArray || monthsArray.length === 0) return "";
    if (monthsArray.length === 12) return "Toute l'année";

    var present = {};
    monthsArray.forEach(function (m) { present[m] = true; });

    // Start the scan right after a "gap" so a run that wraps across
    // the new year (e.g. Nov-Apr) isn't split into two pieces.
    var start = 1;
    for (var m = 1; m <= 12; m++) {
      var prev = m === 1 ? 12 : m - 1;
      if (present[m] && !present[prev]) { start = m; break; }
    }

    var ranges = [];
    var runStart = null;
    var runEnd = null;

    function pushRange() {
      ranges.push(runStart === runEnd
        ? MONTHS_SHORT_FR[runStart - 1]
        : MONTHS_SHORT_FR[runStart - 1] + " - " + MONTHS_SHORT_FR[runEnd - 1]);
    }

    for (var i = 0; i < 12; i++) {
      var month = ((start - 1 + i) % 12) + 1;
      if (present[month]) {
        if (runStart === null) runStart = month;
        runEnd = month;
      } else if (runStart !== null) {
        pushRange();
        runStart = null;
        runEnd = null;
      }
    }
    if (runStart !== null) pushRange();

    return ranges.join(", ");
  }

  function formatHour(sqlTime) {
    return parseInt(sqlTime.split(":")[0], 10) + "h";
  }

  // heure_debut vide = aucune restriction horaire. Quelques espèces ont un
  // second créneau dans la journée.
  function formatTime(item) {
    if (!item.heure_debut) return "Toute la journée";
    var plages = [formatHour(item.heure_debut) + " – " + formatHour(item.heure_fin)];
    if (item.heure_debut_2) {
      plages.push(formatHour(item.heure_debut_2) + " – " + formatHour(item.heure_fin_2));
    }
    return plages.join(" et ");
  }

  function heureEntiere(sqlTime) {
    return parseInt(sqlTime.split(":")[0], 10);
  }

  // Creneaux [debut, fin[ en heures entieres ; liste vide = toute la journee.
  function plagesHoraires(item) {
    if (!item.heure_debut) return [];
    var plages = [[heureEntiere(item.heure_debut), heureEntiere(item.heure_fin)]];
    if (item.heure_debut_2) {
      plages.push([heureEntiere(item.heure_debut_2), heureEntiere(item.heure_fin_2)]);
    }
    return plages;
  }

  // Un creneau 21h - 4h enjambe minuit.
  function heureDansPlage(heure, plage) {
    return plage[0] <= plage[1]
      ? heure >= plage[0] && heure < plage[1]
      : heure >= plage[0] || heure < plage[1];
  }

  function disponibleMaintenant(card) {
    var maintenant = new Date();
    var mois = maintenant.getMonth() + 1;
    if (("," + card.dataset.months + ",").indexOf("," + mois + ",") === -1) return false;
    var plages = JSON.parse(card.dataset.plages || "[]");
    if (plages.length === 0) return true;
    return plages.some(function (p) { return heureDansPlage(maintenant.getHours(), p); });
  }

  // L'API renvoie les colonnes de la base ; les cartes attendent les noms
  // utilisés par le front depuis le début.
  function normaliser(item) {
    return {
      id: item.id,
      name: item.nom_vo,
      nameFr: item.nom,
      image: item.image_url,
      icon: item.image_icone,
      whereHow: item.lieu,
      water: item.eau,
      months: item.mois_dispo,
      plages: plagesHoraires(item),
      heures: formatTime(item)
    };
  }

  // ---- DOM refs ----
  var grid = document.getElementById("grid");
  var searchInput = document.getElementById("search");
  var monthSelect = document.getElementById("month-filter");
  var waterSelect = document.getElementById("water-filter");
  var hideMuseumToggle = document.getElementById("hide-museum-toggle");
  var missingToggle = document.getElementById("missing-toggle");
  var nowToggle = document.getElementById("now-toggle");
  var resetBtn = document.getElementById("reset-filters");
  var resultCount = document.getElementById("result-count");
  var emptyState = document.getElementById("empty-state");
  var footerCount = document.getElementById("footer-count");
  var backToTop = document.getElementById("back-to-top");

  var hideMuseumKey = config.storageKey + "-hide-museum";
  var missingKey = config.storageKey + "-missing";
  var nowKey = config.storageKey + "-now";

  // La collection vit en base ; seul le repli d'affichage "masquer ceux au
  // musée" reste une préférence locale au navigateur.
  var etats = new Map();
  var connecte = false;

  function etatDe(id) {
    return etats.get(id) || { possede: false, au_musee: false };
  }

  function loadFlag(key) {
    try {
      return window.localStorage.getItem(key) === "1";
    } catch (e) {
      return false;
    }
  }

  function saveFlag(key, value) {
    try {
      window.localStorage.setItem(key, value ? "1" : "0");
    } catch (e) { /* ignore */ }
  }

  var hideMuseum = loadFlag(hideMuseumKey);
  var missingOnly = loadFlag(missingKey);
  var nowOnly = loadFlag(nowKey);
  var allItems = [];

  function buildCheckPill(labelText, extraClass, onToggle) {
    var label = document.createElement("label");
    label.className = "check-pill" + (extraClass ? " " + extraClass : "");

    var input = document.createElement("input");
    input.type = "checkbox";
    input.disabled = !connecte;
    if (!connecte) label.title = "Connecte-toi pour suivre ta collection";
    input.addEventListener("change", function () {
      onToggle(input.checked);
    });

    label.appendChild(input);
    label.appendChild(document.createTextNode(labelText));
    return { label: label, input: input };
  }

  function buildCard(item) {
    var card = document.createElement("article");
    card.className = "critter-card";
    card.dataset.id = item.id;
    card.dataset.name = normalize(item.name) + " " + normalize(item.nameFr);
    card.dataset.months = item.months.join(",");
    card.dataset.plages = JSON.stringify(item.plages);
    if (config.showWater) card.dataset.water = item.water;

    var photo = document.createElement("div");
    photo.className = "critter-photo";
    var img = document.createElement("img");
    img.loading = "lazy";
    img.decoding = "async";
    img.alt = item.nameFr;
    img.src = item.image;
    img.onerror = function () {
      if (img.src !== item.icon) {
        img.src = item.icon;
      } else {
        photo.removeChild(img);
        photo.classList.add("no-photo");
        photo.appendChild(Icone(config.placeholderIcone));
      }
    };
    photo.appendChild(img);
    card.appendChild(photo);

    var name = document.createElement("h2");
    name.className = "critter-name";
    name.textContent = item.nameFr;
    card.appendChild(name);

    var nameEn = document.createElement("p");
    nameEn.className = "critter-name-en";
    nameEn.textContent = item.name !== item.nameFr ? "VO : " + item.name : " ";
    card.appendChild(nameEn);

    if (item.whereHow) {
      var badge = document.createElement("span");
      badge.className = "critter-badge" + (config.showWater ? " water-" + item.water : "");
      badge.appendChild(Icone(iconeLieu(item.whereHow)));
      badge.appendChild(document.createTextNode(whereFr(item.whereHow)));
      card.appendChild(badge);
    } else if (config.defaultBadge) {
      var defaultBadge = document.createElement("span");
      defaultBadge.className = "critter-badge";
      defaultBadge.appendChild(Icone(config.defaultBadgeIcone || "lieu"));
      defaultBadge.appendChild(document.createTextNode(config.defaultBadge));
      card.appendChild(defaultBadge);
    }

    var months = document.createElement("p");
    months.className = "critter-meta";
    months.appendChild(Icone.avecTexte("calendrier", formatMonths(item.months)));
    card.appendChild(months);

    var time = document.createElement("p");
    time.className = "critter-meta";
    time.appendChild(Icone.avecTexte("horloge", item.heures));
    card.appendChild(time);

    var checks = document.createElement("div");
    checks.className = "critter-checks";

    var caught = buildCheckPill("Je l'ai", "", function (checked) {
      enregistrer(item, { possede: checked });
    });
    var museum = buildCheckPill("Au musée", "museum-pill", function (checked) {
      enregistrer(item, { au_musee: checked });
    });

    // Les deux cases dependent l'une de l'autre (pas de musee sans
    // possession) : on les reaffiche toujours ensemble, depuis l'etat que la
    // base vient de confirmer.
    function synchroniser() {
      var etat = etatDe(item.id);
      caught.input.checked = etat.possede;
      museum.input.checked = etat.au_musee;
      caught.label.classList.toggle("is-checked", etat.possede);
      museum.label.classList.toggle("is-checked", etat.au_musee);
      card.classList.toggle("is-museum", etat.au_musee);
      card.classList.toggle("is-caught", etat.possede);
    }

    function enregistrer(item, demande) {
      caught.input.disabled = true;
      museum.input.disabled = true;

      Nook.marquer(item.id, demande)
        .then(function (ligne) {
          etats.set(item.id, ligne || { possede: false, au_musee: false });
        })
        .catch(function (erreur) {
          Nook.message(erreur.message, "erreur");
        })
        .then(function () {
          caught.input.disabled = false;
          museum.input.disabled = false;
          synchroniser();
          if (hideMuseum || missingOnly) applyFilters();
        });
    }

    checks.appendChild(caught.label);
    checks.appendChild(museum.label);
    card.appendChild(checks);
    synchroniser();

    return card;
  }

  function renderAll(items) {
    grid.replaceChildren();
    grid.removeAttribute("aria-busy");
    var fragment = document.createDocumentFragment();
    items.forEach(function (item) {
      fragment.appendChild(buildCard(item));
    });
    grid.appendChild(fragment);
  }

  function applyFilters() {
    var query = normalize(searchInput.value.trim());
    var month = monthSelect.value;
    var water = waterSelect ? waterSelect.value : "";
    var visible = 0;
    var cards = grid.children;

    for (var i = 0; i < cards.length; i++) {
      var card = cards[i];
      var matchesQuery = !query || card.dataset.name.indexOf(query) !== -1;
      var matchesMonth = !month || ("," + card.dataset.months + ",").indexOf("," + month + ",") !== -1;
      var matchesWater = !water || card.dataset.water === water;
      var matchesHide = !hideMuseum || !card.classList.contains("is-museum");
      var matchesMissing = !missingOnly || !card.classList.contains("is-caught");
      var matchesNow = !nowOnly || disponibleMaintenant(card);
      var show = matchesQuery && matchesMonth && matchesWater && matchesHide && matchesMissing && matchesNow;
      card.style.display = show ? "" : "none";
      if (show) visible++;
    }

    var unit = visible > 1 ? config.unitLabelPlural : config.unitLabel;
    resultCount.textContent = visible + " / " + allItems.length + " " + unit + (visible !== allItems.length ? " affichés" : "");
    emptyState.hidden = visible !== 0;
  }

  /** Bouton a bascule : icone + libelle, etat persistant, rafraichit la liste. */
  function brancherBascule(bouton, icone, libelle, lire, ecrire) {
    if (!bouton) return;
    bouton.replaceChildren(Icone.avecTexte(icone, libelle));
    var actif = lire();
    bouton.classList.toggle("is-active", actif);
    bouton.setAttribute("aria-pressed", actif ? "true" : "false");
    bouton.addEventListener("click", function () {
      var nouveau = !lire();
      ecrire(nouveau);
      bouton.classList.toggle("is-active", nouveau);
      bouton.setAttribute("aria-pressed", nouveau ? "true" : "false");
      applyFilters();
    });
  }

  function debounce(fn, delay) {
    var timer = null;
    return function () {
      clearTimeout(timer);
      timer = setTimeout(fn, delay);
    };
  }

  function init(items) {
    allItems = items.slice().sort(function (a, b) {
      return a.nameFr.localeCompare(b.nameFr, "fr");
    });

    renderAll(allItems);

    MONTHS_FULL_FR.forEach(function (label, index) {
      var opt = document.createElement("option");
      opt.value = String(index + 1);
      opt.textContent = label;
      monthSelect.appendChild(opt);
    });

    if (waterSelect) {
      [["river", "Rivière"], ["ocean", "Océan"]].forEach(function (pair) {
        var opt = document.createElement("option");
        opt.value = pair[0];
        opt.textContent = pair[1];
        waterSelect.appendChild(opt);
      });
    }

    hideMuseumToggle.classList.toggle("is-active", hideMuseum);
    hideMuseumToggle.setAttribute("aria-pressed", hideMuseum ? "true" : "false");

    brancherBascule(missingToggle, "coche", "Ce qu'il me manque", function () { return missingOnly; }, function (v) {
      missingOnly = v;
      saveFlag(missingKey, v);
    });
    brancherBascule(nowToggle, "horloge", "Disponible maintenant", function () { return nowOnly; }, function (v) {
      nowOnly = v;
      saveFlag(nowKey, v);
    });
    if (missingToggle && !connecte) {
      missingToggle.disabled = true;
      missingToggle.title = "Connecte-toi pour suivre ta collection";
    }

    applyFilters();
    footerCount.textContent = allItems.length;

    searchInput.addEventListener("input", debounce(applyFilters, 120));
    monthSelect.addEventListener("change", applyFilters);
    if (waterSelect) waterSelect.addEventListener("change", applyFilters);

    hideMuseumToggle.addEventListener("click", function () {
      hideMuseum = !hideMuseum;
      saveFlag(hideMuseumKey, hideMuseum);
      hideMuseumToggle.classList.toggle("is-active", hideMuseum);
      hideMuseumToggle.setAttribute("aria-pressed", hideMuseum ? "true" : "false");
      applyFilters();
    });

    resetBtn.addEventListener("click", function () {
      searchInput.value = "";
      monthSelect.value = "";
      if (waterSelect) waterSelect.value = "";
      hideMuseum = false;
      saveFlag(hideMuseumKey, false);
      hideMuseumToggle.classList.remove("is-active");
      hideMuseumToggle.setAttribute("aria-pressed", "false");
      [[missingToggle, missingKey, function () { missingOnly = false; }],
       [nowToggle, nowKey, function () { nowOnly = false; }]].forEach(function (b) {
        if (!b[0]) return;
        b[2]();
        saveFlag(b[1], false);
        b[0].classList.remove("is-active");
        b[0].setAttribute("aria-pressed", "false");
      });
      applyFilters();
    });

    if (backToTop) {
      var toggleBackToTop = function () {
        backToTop.classList.toggle("is-visible", window.scrollY > 400);
      };
      window.addEventListener("scroll", toggleBackToTop, { passive: true });
      toggleBackToTop();
      backToTop.addEventListener("click", function () {
        window.scrollTo({ top: 0, behavior: "smooth" });
      });
    }
  }

  Squelette.afficher(grid, "critter-card", 10);

  // La collection n'est chargee que si quelqu'un est connecte ; sinon les
  // cases restent desactivees et un bandeau invite a se connecter.
  Nook.session()
    .then(function (utilisateur) {
      connecte = !!utilisateur;
      if (!connecte) {
        Nook.bandeauConnexion(grid);
        return null;
      }
      return Nook.collection();
    })
    .then(function (collection) {
      if (collection) etats = collection;
      return Nook.items(config.categorie);
    })
    .then(function (items) {
      init(items.map(normaliser));
    })
    .catch(function (err) {
      resultCount.textContent = "Erreur de chargement des données.";
      console.error("Chargement de la catégorie " + config.categorie + " :", err);
    });
})();
