<?php
declare(strict_types=1);

final class ProfilControleur extends Controleur
{
    /**
     * Tout ce qu'affiche la page profil en un appel : avancement, classement
     * et fil d'actu. Avec ?user_id=, on regarde le profil de quelqu'un
     * d'autre — et c'est la RLS qui decide de ce qui revient.
     */
    public function resume(): never
    {
        $moi = $this->exigerConnexion();

        $cible = filter_var($_GET['user_id'] ?? $moi['id'], FILTER_VALIDATE_INT);
        if ($cible === false || $cible === null) {
            Json::erreur('user_id invalide');
        }

        try {
            $stats = Statistiques::parCategorieEnCache($cible, (int) $moi['id']);
            $identite = Utilisateur::identite($cible);
            $visible = $stats !== [];

            Json::repondre([
                'user_id'    => $cible,
                'pseudo'     => $identite['pseudo'] ?? null,
                'avatar_url' => $identite['avatar_url'] ?? null,
                'c_est_moi'  => $cible === (int) $moi['id'],
                // Aucune ligne = profil invisible pour l'utilisateur connecte.
                'visible'    => $visible,
                'stats'      => $stats,
                'badges'     => $visible ? Statistiques::badges($stats) : [],
                'recentes'   => $visible ? Activite::recentes($cible) : [],
                'classement' => Statistiques::classement(),
                'activites'  => Activite::fil(),
            ]);
        } catch (PDOException $e) {
            Json::erreurSql($e);
        }
    }

    public function visibilite(): never
    {
        $this->exigerMethode('POST');
        $moi = $this->exigerConnexion();

        $valeur = (string) ($this->corpsJson()['visibilite'] ?? '');
        if (!in_array($valeur, ['public', 'amis', 'prive'], true)) {
            Json::erreur('Visibilite inconnue, attendu : public, amis ou prive');
        }

        try {
            Utilisateur::changerVisibilite((int) $moi['id'], $valeur);
        } catch (PDOException $e) {
            Json::erreurSql($e);
        }

        // La session porte aussi la visibilite : on la remet a jour.
        $moi['visibilite'] = $valeur;
        Session::mettreAJour($moi);

        Json::repondre(['visibilite' => $valeur]);
    }

    /** Classement restreint a une categorie. */
    public function classementCategorie(): never
    {
        $this->exigerConnexion();

        $categorie = (string) ($_GET['categorie'] ?? '');
        if (!Item::categorieValide($categorie)) {
            Json::erreur('Categorie inconnue');
        }

        try {
            Statistiques::classement(); // rafraichit les deux vues materialisees
            Json::repondre(['categorie' => $categorie, 'classement' => Statistiques::classementCategorie($categorie)]);
        } catch (PDOException $e) {
            Json::erreurSql($e);
        }
    }

    /** Records de la communaute (profils visibles uniquement). */
    public function communaute(): never
    {
        $this->exigerConnexion();

        try {
            Json::repondre(Statistiques::communaute());
        } catch (PDOException $e) {
            Json::erreurSql($e);
        }
    }

    /**
     * GET : habitants proposables comme photo. POST {item_id|null} : choisir
     * ou retirer la sienne.
     */
    public function avatar(): never
    {
        $moi = $this->exigerConnexion();

        if ($this->methode() === 'GET') {
            try {
                Json::repondre(['habitants' => Utilisateur::habitantsPourAvatar()]);
            } catch (PDOException $e) {
                Json::erreurSql($e);
            }
        }

        $this->exigerMethode('POST');

        $brut = $this->corpsJson()['item_id'] ?? null;
        $itemId = $brut === null ? null : filter_var($brut, FILTER_VALIDATE_INT);
        if ($itemId === false) {
            Json::erreur('item_id invalide');
        }

        try {
            Utilisateur::changerAvatar((int) $moi['id'], $itemId);
        } catch (PDOException $e) {
            // 23503 : la cle etrangere composee refuse tout item qui n'est pas un habitant.
            if (($e->errorInfo[0] ?? '') === '23503') {
                Json::erreur('Choisis un habitant existant');
            }
            Json::erreurSql($e);
        }

        Json::repondre(['avatar_url' => Utilisateur::avatarDe((int) $moi['id'])]);
    }

    /** Ma collection face a celle d'un autre joueur. */
    public function comparer(): never
    {
        $moi = $this->exigerConnexion();

        $autre = filter_var($_GET['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($autre === false || $autre === null || $autre === (int) $moi['id']) {
            Json::erreur('Choisis un autre joueur a comparer');
        }

        try {
            $identite = Utilisateur::identite($autre);
            if ($identite === null) {
                Json::erreur('Joueur introuvable', 404);
            }
            $visible = Statistiques::parCategorie($autre) !== [];

            Json::repondre([
                'autre'    => $identite,
                'visible'  => $visible,
                'elements' => $visible ? Collection::comparer($autre) : [],
            ]);
        } catch (PDOException $e) {
            Json::erreurSql($e);
        }
    }

    /** Courbe de progression d'un joueur (CTE + fonction fenetre cote SQL). */
    public function progression(): never
    {
        $moi = $this->exigerConnexion();

        $cible = filter_var($_GET['user_id'] ?? $moi['id'], FILTER_VALIDATE_INT);
        $jours = filter_var($_GET['jours'] ?? 30, FILTER_VALIDATE_INT, ['options' => ['min_range' => 7, 'max_range' => 90]]);
        if ($cible === false || $jours === false) {
            Json::erreur('Parametres invalides (user_id entier, jours entre 7 et 90)');
        }

        try {
            Json::repondre(['jours' => $jours, 'points' => Statistiques::progression($cible, $jours)]);
        } catch (PDOException $e) {
            Json::erreurSql($e);
        }
    }

    /** Ce que mes amis ont et que je n'ai pas. */
    public function suggestions(): never
    {
        $this->exigerConnexion();

        try {
            Json::repondre(['items' => Statistiques::itemsChezLesAmis(20)]);
        } catch (PDOException $e) {
            Json::erreurSql($e);
        }
    }
}
