<?php
declare(strict_types=1);

final class Collection
{
    /** Etat actuel d'un element dans ma collection (rien = ni possede, ni au musee). */
    public static function etat(int $userId, int $itemId): array
    {
        $requete = Bdd::authentifiee()->prepare(
            'SELECT possede, au_musee FROM collections WHERE user_id = :user_id AND item_id = :item_id'
        );
        $requete->execute([':user_id' => $userId, ':item_id' => $itemId]);

        return $requete->fetch() ?: ['possede' => false, 'au_musee' => false];
    }

    public static function pour(int $userId): array
    {
        $requete = Bdd::authentifiee()->prepare(
            'SELECT item_id, possede, au_musee FROM collections WHERE user_id = :id'
        );
        $requete->execute([':id' => $userId]);

        return $requete->fetchAll();
    }

    /**
     * Delegue a la procedure marquer_item, qui gere l'upsert, les horodatages
     * et la regle "pas au musee sans posseder". L'identite posee par
     * Bdd::authentifiee() fait le reste : la RLS refuse toute ecriture sur la
     * collection de quelqu'un d'autre.
     *
     * possede et auMusee a null = on ne touche pas a la valeur existante.
     */
    public static function marquer(int $userId, int $itemId, ?bool $possede, ?bool $auMusee): ?array
    {
        $pdo = Bdd::authentifiee();

        $appel = $pdo->prepare('CALL marquer_item(:user_id, :item_id, :possede, :au_musee)');
        $appel->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $appel->bindValue(':item_id', $itemId, PDO::PARAM_INT);
        $appel->bindValue(':possede', $possede, $possede === null ? PDO::PARAM_NULL : PDO::PARAM_BOOL);
        $appel->bindValue(':au_musee', $auMusee, $auMusee === null ? PDO::PARAM_NULL : PDO::PARAM_BOOL);
        $appel->execute();

        $etat = $pdo->prepare(
            'SELECT item_id, possede, au_musee FROM collections
             WHERE user_id = :user_id AND item_id = :item_id'
        );
        $etat->execute([':user_id' => $userId, ':item_id' => $itemId]);

        return $etat->fetch() ?: null;
    }

    /**
     * Compare ma collection a celle d'un autre joueur. La fonction SQL est en
     * security invoker : si son profil m'est invisible, elle ne renvoie rien.
     */
    public static function comparer(int $autre): array
    {
        $requete = Bdd::authentifiee()->prepare(
            'SELECT item_id, nom, categorie, image_icone, statut FROM comparer_collections(:autre)'
        );
        $requete->execute([':autre' => $autre]);

        return $requete->fetchAll();
    }

    /** DELETE : retire completement l'element de MA collection (la RLS limite a mes lignes). */
    public static function retirer(int $userId, int $itemId): bool
    {
        $requete = Bdd::authentifiee()->prepare(
            'DELETE FROM collections WHERE user_id = :user_id AND item_id = :item_id'
        );
        $requete->execute([':user_id' => $userId, ':item_id' => $itemId]);

        return $requete->rowCount() > 0;
    }
}
