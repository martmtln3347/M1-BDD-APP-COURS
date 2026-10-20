(function () {
  "use strict";

  var grid = document.getElementById("grid");
  var searchInput = document.getElementById("search");
  var categorySelect = document.getElementById("category-filter");
  var hideMuseumToggle = document.getElementById("hide-museum-toggle");
  var resetBtn = document.getElementById("reset-filters");
  var resultCount = document.getElementById("result-count");
  var emptyState = document.getElementById("empty-state");
  var footerCount = document.getElementById("footer-count");
  var backToTop = document.getElementById("back-to-top");

  var HIDE_KEY = "acnh-art-hide-museum";

  function normalize(str) {
    return (str || "")
      .toString()
      .toLowerCase()
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "");
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

  var hideMuseum = loadFlag(HIDE_KEY);
  var allItems = [];
  var auMusee = new Set();
  var connecte = false;

  // L'API renvoie les colonnes de la base ; les cartes attendent les noms
  // utilisés par le front depuis le début.
  function normaliser(item) {
    return {
      id: item.id,
      name: item.nom_vo,
      nameFr: item.nom,
      category: item.type_art,
      realTitle: item.titre_reel,
      artist: item.artiste,
      realImage: item.image_url,
      hasFake: item.a_contrefacon,
      fakeImage: item.image_contrefacon,
      fakeDetail: item.detail_contrefacon
    };
  }

  function buildImageBlock(label, labelClass, src) {
    var block = document.createElement("div");
    block.className = "art-image-block";

    var tag = document.createElement("span");
    tag.className = "art-image-label " + labelClass;
    tag.textContent = label;
    block.appendChild(tag);

    var photo = document.createElement("div");
    photo.className = "art-photo";
    var img = document.createElement("img");
    img.loading = "lazy";
    img.decoding = "async";
    img.alt = label + " — " + src;
    img.src = src;
    img.onerror = function () {
      photo.replaceChildren(Icone("cadre"));
      photo.classList.add("no-photo");
    };
    photo.appendChild(img);
    block.appendChild(photo);

    return block;
  }

  function buildCard(item) {
    var card = document.createElement("article");
    card.className = "art-card";
    card.dataset.id = item.id;
    card.dataset.name = normalize(item.nameFr) + " " + normalize(item.name) + " " + normalize(item.realTitle);
    card.dataset.category = item.category;

    var isMuseum = auMusee.has(item.id);
    if (isMuseum) card.classList.add("is-museum");

    var images = document.createElement("div");
    images.className = "art-images";
    images.appendChild(buildImageBlock("Réel", "real", item.realImage));
    if (item.hasFake) {
      images.appendChild(buildImageBlock("Faux", "fake", item.fakeImage));
    }
    card.appendChild(images);

    var name = document.createElement("h2");
    name.className = "art-name";
    name.textContent = item.nameFr;
    card.appendChild(name);

    var subtitle = document.createElement("p");
    subtitle.className = "art-subtitle";
    subtitle.textContent = item.realTitle + (item.artist ? " — " + item.artist : "");
    card.appendChild(subtitle);

    if (item.hasFake) {
      var detail = document.createElement("p");
      detail.className = "art-detail";
      detail.appendChild(Icone.avecTexte("loupe", "Ce qui trahit le faux : " + item.fakeDetail));
      card.appendChild(detail);
    } else {
      var badge = document.createElement("span");
      badge.className = "art-badge";
      badge.appendChild(Icone.avecTexte("coche", "Toujours authentique, pas de faux possible"));
      card.appendChild(badge);
    }

    var museumLabel = document.createElement("label");
    museumLabel.className = "check-pill museum-pill" + (isMuseum ? " is-checked" : "");
    var museumInput = document.createElement("input");
    museumInput.type = "checkbox";
    museumInput.checked = isMuseum;
    museumInput.disabled = !connecte;
    if (!connecte) museumLabel.title = "Connecte-toi pour suivre ta collection";
    museumInput.addEventListener("change", function () {
      var checked = museumInput.checked;
      museumInput.disabled = true;

      // Donner une œuvre au musée suppose de l'avoir : la contrainte
      // musee_implique_possede refuserait l'un sans l'autre.
      Nook.marquer(item.id, checked ? { possede: true, au_musee: true } : { possede: false })
        .then(function (ligne) {
          if (ligne && ligne.au_musee) auMusee.add(item.id); else auMusee.delete(item.id);
        })
        .catch(function (erreur) {
          Nook.message(erreur.message, "erreur");
        })
        .then(function () {
          var etat = auMusee.has(item.id);
          museumInput.disabled = false;
          museumInput.checked = etat;
          museumLabel.classList.toggle("is-checked", etat);
          card.classList.toggle("is-museum", etat);
          if (hideMuseum) applyFilters();
        });
    });
    museumLabel.appendChild(museumInput);
    museumLabel.appendChild(document.createTextNode("Au musée"));
    card.appendChild(museumLabel);

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
    var category = categorySelect.value;
    var visible = 0;
    var cards = grid.children;

    for (var i = 0; i < cards.length; i++) {
      var card = cards[i];
      var matchesQuery = !query || card.dataset.name.indexOf(query) !== -1;
      var matchesCategory = !category || card.dataset.category === category;
      var matchesHide = !hideMuseum || !card.classList.contains("is-museum");
      var show = matchesQuery && matchesCategory && matchesHide;
      card.style.display = show ? "" : "none";
      if (show) visible++;
    }

    resultCount.textContent = visible + " / " + allItems.length + " œuvre" + (allItems.length > 1 ? "s" : "") + (visible !== allItems.length ? " affichées" : "");
    emptyState.hidden = visible !== 0;
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

    hideMuseumToggle.classList.toggle("is-active", hideMuseum);
    hideMuseumToggle.setAttribute("aria-pressed", hideMuseum ? "true" : "false");

    applyFilters();
    footerCount.textContent = allItems.length;

    searchInput.addEventListener("input", debounce(applyFilters, 120));
    categorySelect.addEventListener("change", applyFilters);

    hideMuseumToggle.addEventListener("click", function () {
      hideMuseum = !hideMuseum;
      saveFlag(HIDE_KEY, hideMuseum);
      hideMuseumToggle.classList.toggle("is-active", hideMuseum);
      hideMuseumToggle.setAttribute("aria-pressed", hideMuseum ? "true" : "false");
      applyFilters();
    });

    resetBtn.addEventListener("click", function () {
      searchInput.value = "";
      categorySelect.value = "";
      hideMuseum = false;
      saveFlag(HIDE_KEY, false);
      hideMuseumToggle.classList.remove("is-active");
      hideMuseumToggle.setAttribute("aria-pressed", "false");
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

  Squelette.afficher(grid, "art-card", 8);

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
      if (collection) {
        collection.forEach(function (etat, itemId) {
          if (etat.au_musee) auMusee.add(itemId);
        });
      }
      return Nook.items("art");
    })
    .then(function (items) {
      init(items.map(normaliser));
    })
    .catch(function (err) {
      resultCount.textContent = "Erreur de chargement des données.";
      console.error("Chargement des œuvres d'art :", err);
    });
})();
