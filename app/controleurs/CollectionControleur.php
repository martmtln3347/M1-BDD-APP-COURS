<?php
declare(strict_types=1);

final class CollectionControleur extends Controleur
{
    /** GET : lecture, POST : marquage. */
    public function traiter(): never
    {
        match ($this->methode()) {
            'GET'    => $this->lire(),
            'DELETE' => $this->retirer(),
            default  => $this->marquer(),
        };
    }

    public function lire(): never
    {
        $utilisateur = $this->exigerConnexion();

        try {
            Json::repondre(['collection' => Collection::pour((int) $utilisateur['id'])]);
        } catch (PDOException $e) {
            Json::erreurSql($e);
        }
    }

    public function marquer(): never
    {
        $this->exigerMethode('POST');
        $utilisateur = $this->exigerConnexion();

        $corps = $this->corpsJson();
        $itemId = filter_var($corps['item_id'] ?? null, FILTER_VALIDATE_INT);
        if ($itemId === false || $itemId === null) {
            Json::erreur('item_id manquant ou invalide');
        }

        // Les deux drapeaux sont optionnels : absent = on ne touche pas a la
        // valeur existante, ce que la procedure interprete via son COALESCE.
        $possede = $this->drapeau($corps, 'possede');
        $auMusee = $this->drapeau($corps, 'au_musee');

        if ($possede === null && $auMusee === null) {
            Json::erreur('Rien a modifier : fournir possede et/ou au_musee');
        }

        try {
            $avant = Collection::etat((int) $utilisateur['id'], $itemId);
            $item = Collection::marquer((int) $utilisateur['id'], $itemId, $possede, $auMusee);
        } catch (PDOException $e) {
            Json::erreurSql($e);
        }

        // PostgreSQL a accepte l'ecriture (source de verite) : on repercute
        // dans Redis le classement, la popularite, et on invalide le cache.
        Classement::apresMarquage(
            (int) $utilisateur['id'],
            $itemId,
            (bool) $avant['possede'],
            (bool) ($item['possede'] ?? false)
        );
        Statistiques::invalider((int) $utilisateur['id']);
        Metrique::compter('marquages');

        Json::repondre(['item' => $item]);
    }

    private function drapeau(array $corps, string $cle): ?bool
    {
        if (!array_key_exists($cle, $corps)) {
            return null;
        }
        return filter_var($corps[$cle], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    /** DELETE {item_id} : l'element disparait de la collection (et du classement). */
    public function retirer(): never
    {
        $this->exigerMethode('DELETE');
        $utilisateur = $this->exigerConnexion();

        $itemId = filter_var($this->corpsJson()['item_id'] ?? null, FILTER_VALIDATE_INT);
        if ($itemId === false || $itemId === null) {
            Json::erreur('item_id manquant ou invalide');
        }

        try {
            $avant = Collection::etat((int) $utilisateur['id'], $itemId);
            if (!Collection::retirer((int) $utilisateur['id'], $itemId)) {
                Json::erreur('Cet element n\'est pas dans ta collection', 404);
            }
        } catch (PDOException $e) {
            Json::erreurSql($e);
        }

        Classement::apresMarquage((int) $utilisateur['id'], $itemId, (bool) $avant['possede'], false);
        Statistiques::invalider((int) $utilisateur['id']);

        Json::repondre(['message' => 'Element retire de la collection']);
    }
}
