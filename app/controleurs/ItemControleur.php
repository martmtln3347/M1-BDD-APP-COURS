<?php
declare(strict_types=1);

final class ItemControleur extends Controleur
{
    public function lister(): never
    {
        $categorie = (string) ($_GET['categorie'] ?? '');

        // Liste blanche : la categorie arrive de la requete mais n'est jamais
        // concatenee au SQL, elle passe par un parametre lie.
        if (!Item::categorieValide($categorie)) {
            Json::erreur('Categorie inconnue, attendu : ' . implode(', ', Item::CATEGORIES));
        }

        try {
            $items = Item::parCategorie($categorie);
        } catch (PDOException $e) {
            Json::erreurSql($e);
        }

        // Le catalogue ne bouge pas entre deux versions du jeu : on laisse le
        // navigateur le garder une heure.
        header('Cache-Control: public, max-age=3600');
        Json::repondre(['categorie' => $categorie, 'items' => $items]);
    }
}
