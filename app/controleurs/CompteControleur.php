<?php
declare(strict_types=1);

/**
 * DELETE api/compte.php : supprime MON compte.
 *
 * PostgreSQL fait le menage relationnel (ON DELETE CASCADE : collections,
 * amities, activites, tokens). Redis n'a pas de cle etrangere : l'application
 * efface elle-meme ce qui est a ce joueur (classements, cache, notifications).
 * C'est le prix de la repartition des donnees sur deux bases.
 */
final class CompteControleur extends Controleur
{
    public function supprimer(): never
    {
        $this->exigerMethode('DELETE');
        $moi = $this->exigerConnexion();

        try {
            $requete = Bdd::authentifiee()->prepare('DELETE FROM users WHERE id = :id');
            $requete->execute([':id' => (int) $moi['id']]);
        } catch (PDOException $e) {
            Json::erreurSql($e);
        }

        Classement::retirerJoueur((int) $moi['id']);
        Securite::journaliser('compte_supprime', ['user_id' => (int) $moi['id']]);
        Session::fermer();

        Json::repondre(['message' => 'Compte supprime']);
    }
}
