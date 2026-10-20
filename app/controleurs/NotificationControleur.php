<?php
declare(strict_types=1);

/**
 * CRUD sur la boite de notifications, qui vit dans Redis (pas dans PostgreSQL).
 *   GET                       lire        (LRANGE)
 *   POST {action:"supprimer"} supprimer une notification (LREM)
 *   POST {action:"tout_lire"} vider la boite (DEL)
 * La creation se fait cote serveur, quand un evenement la declenche (demande d'ami).
 */
final class NotificationControleur extends Controleur
{
    public function traiter(): never
    {
        $moi = $this->exigerConnexion();

        if (!RedisClient::disponible()) {
            Json::erreur('Service de notifications indisponible', 503);
        }

        try {
            if ($this->methode() === 'GET') {
                $liste = Notification::lister((int) $moi['id']);
                Json::repondre(['notifications' => $liste, 'total' => count($liste)]);
            }

            $this->exigerMethode('POST');
            $corps = $this->corpsJson();
            $action = (string) ($corps['action'] ?? '');

            if ($action === 'tout_lire') {
                Notification::toutSupprimer((int) $moi['id']);
                Json::repondre(['message' => 'Notifications effacees']);
            }

            if ($action === 'supprimer') {
                $id = (string) ($corps['id'] ?? '');
                if (!preg_match('/^[0-9a-f]{12}$/', $id)) {
                    Json::erreur('Identifiant de notification invalide');
                }
                if (!Notification::supprimer((int) $moi['id'], $id)) {
                    Json::erreur('Notification introuvable', 404);
                }
                Json::repondre(['message' => 'Notification supprimee']);
            }
        } catch (RedisErreur $e) {
            error_log('Redis : ' . $e->getMessage());
            Json::erreur('Service de notifications indisponible', 503);
        }

        Json::erreur('Action inconnue, attendu : supprimer ou tout_lire');
    }
}
