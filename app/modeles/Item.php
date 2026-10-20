<?php
declare(strict_types=1);

final class Item
{
    public const CATEGORIES = ['habitant', 'poisson', 'creature_marine', 'insecte', 'art'];

    public static function categorieValide(string $categorie): bool
    {
        return in_array($categorie, self::CATEGORIES, true);
    }

    public static function parCategorie(string $categorie): array
    {
        return $categorie === 'art' ? self::art() : self::catalogue($categorie);
    }

    private static function catalogue(string $categorie): array
    {
        $requete = Bdd::connexion()->prepare(
            'SELECT id, nom, nom_vo, image_url, image_icone, race, personnalite,
                    date_naissance, amiibo_serie, amiibo_exemples,
                    mois_dispo, heure_debut, heure_fin, heure_debut_2, heure_fin_2,
                    lieu, eau, prix_boutique
             FROM items
             WHERE categorie = :categorie
             ORDER BY nom'
        );
        $requete->execute([':categorie' => $categorie]);

        return array_map(self::normaliser(...), $requete->fetchAll());
    }

    /**
     * L'oeuvre authentique et sa contrefacon sont deux lignes : l'auto-jointure
     * les ramene sur une seule, telle que la page les affiche cote a cote.
     */
    private static function art(): array
    {
        $requete = Bdd::connexion()->query(
            "SELECT vrai.id, vrai.nom, vrai.nom_vo, vrai.image_url, vrai.type_art,
                    vrai.titre_reel, vrai.artiste,
                    faux.id IS NOT NULL AS a_contrefacon,
                    faux.image_url      AS image_contrefacon,
                    faux.detail_contrefacon
             FROM items vrai
             LEFT JOIN items faux ON faux.est_contrefacon_de = vrai.id
             WHERE vrai.categorie = 'art' AND vrai.est_contrefacon_de IS NULL
             ORDER BY vrai.nom"
        );

        return $requete->fetchAll();
    }

    /**
     * mois_dispo revient de PostgreSQL au format texte '{1,2,3}' : on le rend
     * au front sous forme de vrai tableau JSON.
     */
    private static function normaliser(array $item): array
    {
        $item['mois_dispo'] = $item['mois_dispo'] === null
            ? []
            : array_map('intval', array_filter(explode(',', trim($item['mois_dispo'], '{}')), 'strlen'));

        return $item;
    }
}
