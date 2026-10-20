<?php
declare(strict_types=1);

final class AmiControleur extends Controleur
{
    /** GET : mes relations. POST : envoyer une demande ou y repondre. */
    public function traiter(): never
    {
        if ($this->methode() === 'GET') {
            $this->lister();
        }
        $this->agir();
    }

    public function lister(): never
    {
        $moi = $this->exigerConnexion();

        try {
            Json::repondre(Amitie::relations((int) $moi['id']));
        } catch (PDOException $e) {
            Json::erreurSql($e);
        }
    }

    private function agir(): never
    {
        $this->exigerMethode('POST');
        $moi = $this->exigerConnexion();

        $corps = $this->corpsJson();
        $action = (string) ($corps['action'] ?? '');
        $cible = filter_var($corps['user_id'] ?? null, FILTER_VALIDATE_INT);

        if ($cible === false || $cible === null) {
            Json::erreur('user_id manquant ou invalide');
        }

        try {
            if ($action === 'demander') {
                Amitie::envoyer((int) $moi['id'], $cible);
                Notification::creer($cible, 'demande', (int) $moi['id'], (string) $moi['pseudo']);
                Json::repondre(['message' => 'Demande envoyée'], 201);
            }

            if ($action === 'accepter' || $action === 'refuser') {
                Amitie::repondre($cible, (int) $moi['id'], $action === 'accepter');
                if ($action === 'accepter') {
                    Notification::creer($cible, 'acceptee', (int) $moi['id'], (string) $moi['pseudo']);
                }
                Json::repondre(['message' => $action === 'accepter' ? 'Demande acceptée' : 'Demande refusée']);
            }

            if ($action === 'retirer') {
                Amitie::retirer((int) $moi['id'], $cible);
                Json::repondre(['message' => 'Relation supprimée']);
            }
        } catch (PDOException $e) {
            Json::erreurSql($e);
        }

        Json::erreur('Action inconnue, attendu : demander, accepter, refuser ou retirer');
    }

    public function rechercher(): never
    {
        $moi = $this->exigerConnexion();

        $terme = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($terme) < 2) {
            Json::erreur('Saisis au moins 2 caractères');
        }

        try {
            Json::repondre(['utilisateurs' => Utilisateur::rechercher($terme, (int) $moi['id'])]);
        } catch (PDOException $e) {
            Json::erreurSql($e);
        }
    }
}
