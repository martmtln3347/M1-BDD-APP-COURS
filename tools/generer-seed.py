#!/usr/bin/env python3
"""Genere docker/init-sql/05-seed-items.sql a partir des JSON de tools/data/.

A relancer si les donnees du jeu changent :
    python3 tools/generer-seed.py
"""

import datetime
import json
import os
import re

RACINE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA = os.path.join(RACINE, "tools", "data")
SORTIE = os.path.join(RACINE, "docker", "init-sql", "05-seed-items.sql")


def charger(nom):
    with open(os.path.join(DATA, nom), encoding="utf-8") as f:
        return json.load(f)


def sql(valeur):
    """Litteral SQL : texte quote, NULL, nombre ou tableau d'entiers."""
    if valeur is None or valeur == "":
        return "NULL"
    if isinstance(valeur, bool):
        return "true" if valeur else "false"
    if isinstance(valeur, int):
        return str(valeur)
    if isinstance(valeur, list):
        return "'{" + ",".join(str(int(v)) for v in valeur) + "}'"
    return "'" + str(valeur).replace("'", "''") + "'"


def heure(jeton):
    """'9 AM' -> '09:00:00'."""
    m = re.match(r"^(\d{1,2})\s*(AM|PM)$", jeton.replace("\xa0", " ").strip(), re.I)
    if not m:
        raise ValueError("heure illisible : " + repr(jeton))
    h = int(m.group(1)) % 12
    if m.group(2).upper() == "PM":
        h += 12
    return "%02d:00:00" % h


def creneaux(plages):
    """['9 AM - 4 PM', '9 PM - 4 AM'] -> (debut1, fin1, debut2, fin2).

    'All day' et une liste vide donnent quatre NULL : pas de restriction horaire.
    """
    resultat = []
    for plage in (plages or [])[:2]:
        plage = plage.replace("\xa0", " ").strip()
        if plage.lower() == "all day":
            continue
        bornes = re.split(r"\s*[–-]\s*", plage)
        if len(bornes) != 2:
            raise ValueError("plage illisible : " + repr(plage))
        resultat += [heure(bornes[0]), heure(bornes[1])]
    return tuple(resultat + [None] * (4 - len(resultat)))


def date_naissance(valeur):
    """'8/11' (mois/jour) -> '2000-08-11'. Le jeu ne donne pas d'annee."""
    mois, jour = (int(x) for x in valeur.split("/"))
    return datetime.date(2000, mois, jour).isoformat()


def bloc(colonnes, lignes, commentaire):
    """Un INSERT multi-lignes."""
    corps = ",\n".join("    (" + ", ".join(sql(v) for v in ligne) + ")" for ligne in lignes)
    return "-- %s (%d)\nINSERT INTO items (%s) VALUES\n%s;\n" % (
        commentaire, len(lignes), ", ".join(colonnes), corps
    )


def main():
    morceaux = []

    # ---------- Habitants ----------
    colonnes = ["categorie", "nom", "nom_vo", "image_url", "image_icone", "race",
                "personnalite", "date_naissance", "amiibo_serie", "amiibo_exemples"]
    lignes = [
        ["habitant", v["nameFr"], v["name"], v["photo"], v["icon"], v["species"],
         v["personality"], date_naissance(v["birthday"]),
         v.get("amiiboSeries"), v.get("amiiboExamples")]
        for v in charger("villagers.json")
    ]
    morceaux.append(bloc(colonnes, lignes, "Habitants"))

    # ---------- Poissons, creatures marines, insectes ----------
    colonnes = ["categorie", "nom", "nom_vo", "image_url", "image_icone", "lieu",
                "eau", "mois_dispo", "heure_debut", "heure_fin",
                "heure_debut_2", "heure_fin_2"]
    for fichier, categorie, libelle in [
        ("fish.json", "poisson", "Poissons"),
        ("sea-creatures.json", "creature_marine", "Creatures marines"),
        ("insects.json", "insecte", "Insectes"),
    ]:
        lignes = []
        for c in charger(fichier):
            d1, f1, d2, f2 = creneaux(c.get("time"))
            lignes.append([categorie, c["nameFr"], c["name"], c["image"], c["icon"],
                           c.get("whereHow"), c.get("water"), c.get("months"),
                           d1, f1, d2, f2])
        morceaux.append(bloc(colonnes, lignes, libelle))

    # ---------- Art : oeuvres authentiques ----------
    oeuvres = charger("artwork.json")
    noms = [o["nameFr"] for o in oeuvres]
    if len(set(noms)) != len(noms):
        raise SystemExit("noms d'oeuvres non uniques : le rattachement des faux echouerait")

    colonnes = ["categorie", "nom", "nom_vo", "image_url", "type_art", "titre_reel", "artiste"]
    lignes = [["art", o["nameFr"], o["name"], o["realImage"], o["category"],
               o["realTitle"], o["artist"]] for o in oeuvres]
    morceaux.append(bloc(colonnes, lignes, "Art - oeuvres authentiques"))

    # ---------- Art : contrefacons ----------
    # Lignes a part, rattachees a l'oeuvre vraie par est_contrefacon_de. Le JOIN
    # sur le nom evite d'ecrire des id en dur dans le seed.
    faux = [o for o in oeuvres if o["hasFake"]]
    valeurs = ",\n".join(
        "    (%s, %s, %s, %s, %s, %s)" % (
            sql(o["nameFr"]), sql(o["nameFr"] + " (contrefacon)"),
            sql(o["name"] + " (fake)"), sql(o["fakeImage"]),
            sql(o["category"]), sql(o["fakeDetail"]))
        for o in faux
    )
    morceaux.append(
        "-- Art - contrefacons (%d)\n"
        "INSERT INTO items (categorie, nom, nom_vo, image_url, type_art,\n"
        "                   detail_contrefacon, est_contrefacon_de)\n"
        "SELECT 'art', f.nom, f.nom_vo, f.image_url, f.type_art, f.detail, vrai.id\n"
        "FROM (VALUES\n%s\n"
        ") AS f(nom_oeuvre, nom, nom_vo, image_url, type_art, detail)\n"
        "JOIN items vrai\n"
        "  ON vrai.categorie = 'art'\n"
        " AND vrai.est_contrefacon_de IS NULL\n"
        " AND vrai.nom = f.nom_oeuvre;\n" % (len(faux), valeurs)
    )

    entete = (
        "-- ============================================================\n"
        "-- Nook Manager - donnees du jeu\n"
        "-- GENERE par tools/generer-seed.py a partir de tools/data/*.json.\n"
        "-- Ne pas editer a la main : relancer le script.\n"
        "-- ============================================================\n\n"
        "BEGIN;\n\n"
    )
    pied = (
        "\nCOMMIT;\n\n"
        "-- La vue materialisee a ete creee sur une base vide : son total d'items\n"
        "-- est encore a zero tant qu'on ne la rafraichit pas.\n"
        "REFRESH MATERIALIZED VIEW mv_classement;\n"
    )

    with open(SORTIE, "w", encoding="utf-8") as f:
        f.write(entete + "\n".join(morceaux) + pied)

    total = sum(m.count("\n    (") for m in morceaux)
    print("%s ecrit (%d lignes d'items)" % (os.path.relpath(SORTIE, RACINE), total))


if __name__ == "__main__":
    main()
