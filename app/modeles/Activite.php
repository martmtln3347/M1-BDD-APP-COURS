<?php
declare(strict_types=1);

final class Activite
{
    /**
     * Fil d'actu : les prises et les dons au musee des profils visibles.
     * Aucune ecriture ici, c'est le trigger sur collections qui alimente la
     * table ; la RLS filtre selon la visibilite de chaque profil.
     */
    public static function fil(int $limite = 40): array
    {
        $requete = Bdd::authentifiee()->prepare(
            'SELECT a.id, u.pseudo, av.avatar_url, a.type, a.cree_le,
                    i.nom AS item, i.categorie, i.image_url
             FROM activites a
             JOIN users u ON u.id = a.user_id
             JOIN v_avatars av ON av.user_id = u.id
             JOIN items i ON i.id = a.item_id
             ORDER BY a.cree_le DESC, a.id DESC
             LIMIT :limite'
        );
        $requete->bindValue(':limite', $limite, PDO::PARAM_INT);
        $requete->execute();

        return $requete->fetchAll();
    }

    /**
     * Les dernieres trouvailles d'un joueur, pour son carnet. La RLS de
     * collections decide si on a le droit de les voir : profil invisible,
     * aucune ligne.
     */
    public static function recentes(int $userId, int $limite = 12): array
    {
        $requete = Bdd::authentifiee()->prepare(
            'SELECT i.id AS item_id, i.nom, i.categorie, i.image_icone, c.au_musee, c.obtenu_le
             FROM collections c
             JOIN items i ON i.id = c.item_id
             WHERE c.user_id = :id AND c.possede AND i.est_contrefacon_de IS NULL
             ORDER BY c.obtenu_le DESC NULLS LAST, i.nom
             LIMIT :limite'
        );
        $requete->bindValue(':id', $userId, PDO::PARAM_INT);
        $requete->bindValue(':limite', $limite, PDO::PARAM_INT);
        $requete->execute();

        return $requete->fetchAll();
    }
}
