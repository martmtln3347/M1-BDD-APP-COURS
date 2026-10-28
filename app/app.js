(function () {
  "use strict";

  var SPECIES_FR = {
    "Alligator": "Alligator", "Anteater": "Fourmilier", "Bear": "Ours", "Bear cub": "Ourson",
    "Bird": "Oiseau", "Bull": "Taureau", "Cat": "Chat", "Chicken": "Poule", "Cow": "Vache",
    "Deer": "Cerf", "Dog": "Chien", "Duck": "Canard", "Eagle": "Aigle", "Elephant": "Éléphant",
    "Frog": "Grenouille", "Goat": "Chèvre", "Gorilla": "Gorille", "Hamster": "Hamster",
    "Hippo": "Hippopotame", "Horse": "Cheval", "Kangaroo": "Kangourou", "Koala": "Koala",
    "Lion": "Lion", "Monkey": "Singe", "Mouse": "Souris", "Octopus": "Poulpe",
    "Ostrich": "Autruche", "Penguin": "Manchot", "Pig": "Cochon", "Rabbit": "Lapin",
    "Rhinoceros": "Rhinocéros", "Sheep": "Mouton", "Squirrel": "Écureuil", "Tiger": "Tigre",
    "Wolf": "Loup"
  };

  var PERSONALITY_FR = {
    "Lazy": "Cool", "Jock": "Sportif(ve)", "Cranky": "Ronchon", "Snooty": "Chic",
    "Normal": "Normal", "Peppy": "Enjoué(e)", "Smug": "Charmeur(se)", "Big Sister": "Costaud(e)"
  };

  var SPECIES_ICONE = "patte";

  var MONTHS_FR = [
    "janvier", "février", "mars", "avril", "mai", "juin",
    "juillet", "août", "septembre", "octobre", "novembre", "décembre"
  ];

  // La base stocke une vraie date ; l'année (2000) est arbitraire, le jeu ne
  // donne que le jour et le mois.
  function formatBirthday(dateIso) {
    if (!dateIso) return "";
    var parts = dateIso.split("-");
    var month = parseInt(parts[1], 10);
    var day = parseInt(parts[2], 10);
    if (!month || !day || !MONTHS_FR[month - 1]) return "";
    return day + " " + MONTHS_FR[month - 1];
  }

  // L'API renvoie les colonnes de la base ; les cartes attendent les noms
  // utilisés par le front depuis le début.
  function normaliser(item) {
    return {
      id: item.id,
      name: item.nom_vo,
      nameFr: item.nom,
      species: item.race,
      personality: item.personnalite,
      birthday: item.date_naissance,
      photo: item.image_url,
      icon: item.image_icone,
      amiiboSeries: item.amiibo_serie,
      amiiboExamples: item.amiibo_exemples
    };
  }

  var grid = document.getElementById("grid");
  var raceIndexLinks = document.getElementById("race-index-links");
  var searchInput = document.getElementById("search");
  var speciesSelect = document.getElementById("species-filter");
  var personalitySelect = document.getElementById("personality-filter");
  var resetBtn = document.getElementById("reset-filters");
  var favoritesToggle = document.getElementById("favorites-toggle");
  var favoritesCountEl = document.getElementById("favorites-count");
  var resultCount = document.getElementById("result-count");
  var emptyState = document.getElementById("empty-state");
  var footerCount = document.getElementById("footer-count");

  var allVillagers = [];
  var searchDebounce = null;
  // Les habitants presents sur l'ile : la colonne possede de la table
  // collections, comme pour les poissons ou les insectes.
  var surMonIle = new Set();
  var connecte = false;
  var favoritesOnly = false;

  function updateFavoritesCount() {
    favoritesCountEl.textContent = "(" + surMonIle.size + ")";
  }

  function speciesFr(species) {
    return SPECIES_FR[species] || species;
  }

  function personalityFr(personality) {
    return PERSONALITY_FR[personality] || personality;
  }

  function normalize(str) {
    return (str || "")
      .toString()
      .toLowerCase()
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "");
  }

  function buildCard(villager) {
    var card = document.createElement("article");
    card.className = "villager-card";
    card.dataset.id = villager.id;
    card.dataset.name = normalize(villager.name) + " " + normalize(villager.nameFr);
    card.dataset.species = villager.species;
    card.dataset.personality = villager.personality;

    var isFavorite = surMonIle.has(villager.id);
    if (isFavorite) card.classList.add("is-favorite");

    var favoriteBtn = document.createElement("button");
    favoriteBtn.type = "button";
    favoriteBtn.className = "favorite-btn";
    favoriteBtn.disabled = !connecte;
    favoriteBtn.setAttribute("aria-pressed", isFavorite ? "true" : "false");
    favoriteBtn.setAttribute("aria-label", villager.nameFr + " est sur mon île");
    favoriteBtn.title = connecte
      ? villager.nameFr + " est sur mon île"
      : "Connecte-toi pour suivre ta collection";
    favoriteBtn.appendChild(Icone("coeur", isFavorite ? "icone--plein" : ""));

    function rafraichir() {
      var present = surMonIle.has(villager.id);
      card.classList.toggle("is-favorite", present);
      favoriteBtn.replaceChildren(Icone("coeur", present ? "icone--plein" : ""));
      favoriteBtn.setAttribute("aria-pressed", present ? "true" : "false");
      updateFavoritesCount();
      if (favoritesOnly) applyFilters();
    }

    favoriteBtn.addEventListener("click", function () {
      var vise = !surMonIle.has(villager.id);
      favoriteBtn.disabled = true;

      Nook.marquer(villager.id, { possede: vise })
        .then(function (ligne) {
          if (ligne && ligne.possede) surMonIle.add(villager.id); else surMonIle.delete(villager.id);
        })
        .catch(function (erreur) {
          Nook.message(erreur.message, "erreur");
        })
        .then(function () {
          favoriteBtn.disabled = false;
          rafraichir();
        });
    });
    card.appendChild(favoriteBtn);

    var frame = document.createElement("div");
    frame.className = "photo-frame";

    function showPlaceholder() {
      frame.innerHTML = "";
      frame.classList.add("no-photo");
      frame.appendChild(Icone(SPECIES_ICONE));
    }

    var firstSrc = villager.photo || villager.icon;
    if (firstSrc) {
      var img = document.createElement("img");
      img.loading = "lazy";
      img.decoding = "async";
      img.alt = villager.nameFr + " (" + speciesFr(villager.species) + ")";
      img.src = firstSrc;
      img.onerror = function () {
        if (villager.icon && img.src !== villager.icon) {
          img.src = villager.icon;
        } else {
          showPlaceholder();
        }
      };
      frame.appendChild(img);
    } else {
      showPlaceholder();
    }

    var name = document.createElement("h2");
    name.className = "villager-name";
    name.textContent = villager.nameFr;

    var nameEn = document.createElement("p");
    nameEn.className = "villager-name-en";
    nameEn.textContent = villager.name !== villager.nameFr ? "VO : " + villager.name : " ";

    var species = document.createElement("span");
    species.className = "villager-species";
    species.appendChild(Icone(SPECIES_ICONE));
    species.appendChild(document.createTextNode(speciesFr(villager.species)));

    var personality = document.createElement("span");
    personality.className = "villager-personality";
    personality.textContent = personalityFr(villager.personality);

    card.appendChild(frame);
    card.appendChild(name);
    card.appendChild(nameEn);
    card.appendChild(species);
    card.appendChild(personality);

    var birthdayText = formatBirthday(villager.birthday);
    if (birthdayText) {
      var birthday = document.createElement("p");
      birthday.className = "villager-birthday";
      birthday.appendChild(Icone.avecTexte("gateau", birthdayText));
      card.appendChild(birthday);
    }

    if (villager.amiiboSeries) {
      var amiibo = document.createElement("p");
      amiibo.className = "villager-amiibo";
      amiibo.appendChild(Icone.avecTexte("etoile", "Amiibo " + villager.amiiboSeries + " requis"));
      var amiiboExamples = document.createElement("span");
      amiiboExamples.className = "villager-amiibo-examples";
      amiiboExamples.textContent = villager.amiiboExamples;
      amiibo.appendChild(document.createElement("br"));
      amiibo.appendChild(amiiboExamples);
      card.appendChild(amiibo);
    }

    return card;
  }

  function populateFilters(villagers) {
    var speciesSet = {};
    var personalitySet = {};

    villagers.forEach(function (v) {
      speciesSet[v.species] = true;
      personalitySet[v.personality] = true;
    });

    var speciesList = Object.keys(speciesSet).sort(function (a, b) {
      return speciesFr(a).localeCompare(speciesFr(b), "fr");
    });
    var personalityList = Object.keys(personalitySet).sort(function (a, b) {
      return personalityFr(a).localeCompare(personalityFr(b), "fr");
    });

    speciesList.forEach(function (s) {
      var opt = document.createElement("option");
      opt.value = s;
      opt.textContent = speciesFr(s);
      speciesSelect.appendChild(opt);
    });

    personalityList.forEach(function (p) {
      var opt = document.createElement("option");
      opt.value = p;
      opt.textContent = personalityFr(p);
      personalitySelect.appendChild(opt);
    });
  }

  function slugify(species) {
    return "race-" + species.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/(^-|-$)/g, "");
  }

  function buildSpeciesHeader(species) {
    var header = document.createElement("h2");
    header.className = "species-header";
    header.id = slugify(species);
    header.dataset.speciesHeader = species;
    header.appendChild(Icone(SPECIES_ICONE));
    header.appendChild(document.createTextNode(speciesFr(species)));
    return header;
  }

  function renderAll(villagers) {
    grid.replaceChildren();
    grid.removeAttribute("aria-busy");
    var fragment = document.createDocumentFragment();
    var lastSpecies = null;
    villagers.forEach(function (v) {
      if (v.species !== lastSpecies) {
        fragment.appendChild(buildSpeciesHeader(v.species));
        lastSpecies = v.species;
      }
      fragment.appendChild(buildCard(v));
    });
    grid.appendChild(fragment);
  }

  function populateRaceIndex(orderedSpeciesList) {
    var fragment = document.createDocumentFragment();
    orderedSpeciesList.forEach(function (species) {
      var link = document.createElement("a");
      link.href = "#" + slugify(species);
      link.className = "race-index-link";
      link.textContent = speciesFr(species);
      fragment.appendChild(link);
    });
    raceIndexLinks.appendChild(fragment);
  }

  function applyFilters() {
    var query = normalize(searchInput.value.trim());
    var species = speciesSelect.value;
    var personality = personalitySelect.value;
    var visible = 0;
    var children = grid.children;
    var pendingHeader = null;
    var pendingHeaderHasVisibleCard = false;

    function flushHeader() {
      if (pendingHeader) {
        pendingHeader.style.display = pendingHeaderHasVisibleCard ? "" : "none";
      }
    }

    for (var i = 0; i < children.length; i++) {
      var el = children[i];

      if (el.classList.contains("species-header")) {
        flushHeader();
        pendingHeader = el;
        pendingHeaderHasVisibleCard = false;
        continue;
      }

      var matchesQuery = !query || el.dataset.name.indexOf(query) !== -1;
      var matchesSpecies = !species || el.dataset.species === species;
      var matchesPersonality = !personality || el.dataset.personality === personality;
      var matchesFavorite = !favoritesOnly || surMonIle.has(Number(el.dataset.id));
      var show = matchesQuery && matchesSpecies && matchesPersonality && matchesFavorite;
      el.style.display = show ? "" : "none";
      if (show) {
        visible++;
        pendingHeaderHasVisibleCard = true;
      }
    }
    flushHeader();

    resultCount.textContent = visible + " / " + allVillagers.length + " habitant" + (allVillagers.length > 1 ? "s" : "") + (visible !== allVillagers.length ? " affichés" : "");
    emptyState.hidden = visible !== 0;
    emptyState.textContent = (favoritesOnly && surMonIle.size === 0)
      ? "Aucun habitant sur ton île pour l'instant : clique sur le cœur d'une carte pour l'ajouter."
      : "Aucun habitant ne correspond à ta recherche.";
  }

  function debouncedApplyFilters() {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(applyFilters, 120);
  }

  function init(villagers) {
    // La collection renvoyée par l'API couvre toutes les catégories : on ne
    // garde ici que les habitants, sinon le compteur compterait aussi les
    // poissons et les œuvres.
    var idsHabitants = new Set(villagers.map(function (v) { return v.id; }));
    surMonIle.forEach(function (id) {
      if (!idsHabitants.has(id)) surMonIle.delete(id);
    });

    allVillagers = villagers.slice().sort(function (a, b) {
      var speciesCompare = speciesFr(a.species).localeCompare(speciesFr(b.species), "fr");
      if (speciesCompare !== 0) return speciesCompare;
      return a.nameFr.localeCompare(b.nameFr, "fr");
    });

    var orderedSpecies = [];
    var seenSpecies = {};
    allVillagers.forEach(function (v) {
      if (!seenSpecies[v.species]) {
        seenSpecies[v.species] = true;
        orderedSpecies.push(v.species);
      }
    });

    populateFilters(allVillagers);
    populateRaceIndex(orderedSpecies);
    renderAll(allVillagers);
    updateFavoritesCount();
    applyFilters();

    footerCount.textContent = allVillagers.length;

    searchInput.addEventListener("input", debouncedApplyFilters);
    speciesSelect.addEventListener("change", applyFilters);
    personalitySelect.addEventListener("change", applyFilters);
    favoritesToggle.addEventListener("click", function () {
      favoritesOnly = !favoritesOnly;
      favoritesToggle.classList.toggle("is-active", favoritesOnly);
      favoritesToggle.setAttribute("aria-pressed", favoritesOnly ? "true" : "false");
      applyFilters();
    });
    resetBtn.addEventListener("click", function () {
      searchInput.value = "";
      speciesSelect.value = "";
      personalitySelect.value = "";
      favoritesOnly = false;
      favoritesToggle.classList.remove("is-active");
      favoritesToggle.setAttribute("aria-pressed", "false");
      applyFilters();
    });

    raceIndexLinks.addEventListener("click", function (e) {
      var link = e.target.closest(".race-index-link");
      if (!link) return;
      e.preventDefault();

      var targetId = link.getAttribute("href").slice(1);
      var target = document.getElementById(targetId);
      if (!target) return;

      // Un filtre actif peut cacher (display: none) la section visée : on
      // repart d'une liste complète pour garantir que l'ancre existe bien.
      searchInput.value = "";
      speciesSelect.value = "";
      personalitySelect.value = "";
      favoritesOnly = false;
      favoritesToggle.classList.remove("is-active");
      favoritesToggle.setAttribute("aria-pressed", "false");
      applyFilters();

      target.scrollIntoView({ behavior: "smooth", block: "start" });
      if (window.history && history.pushState) {
        history.pushState(null, "", "#" + targetId);
      }
    });
  }

  var backToTop = document.getElementById("back-to-top");
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

  Squelette.afficher(grid, "villager-card", 12);

  Nook.session()
    .then(function (utilisateur) {
      connecte = !!utilisateur;
      if (!connecte) {
        Nook.bandeauConnexion(document.querySelector(".controls"));
        return null;
      }
      return Nook.collection();
    })
    .then(function (collection) {
      if (collection) {
        collection.forEach(function (etat, itemId) {
          if (etat.possede) surMonIle.add(itemId);
        });
      }
      return Nook.items("habitant");
    })
    .then(function (items) {
      init(items.map(normaliser));
    })
    .catch(function (err) {
      resultCount.textContent = "Erreur de chargement des données.";
      console.error("Chargement des habitants :", err);
    });
})();
