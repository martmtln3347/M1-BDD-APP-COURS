<?php
declare(strict_types=1);

/**
 * Boite de notifications : donnees ephemeres, donc stockees dans Redis et non
 * dans PostgreSQL.
 *
 * Une LISTE par joueur (`nook:notif:<user_id>`), la plus recente en tete.
 *  - creer    : LPUSH + LTRIM (50 au plus) + EXPIRE (7 jours)
 *  - lire     : LRANGE
 *  - supprimer: LREM d'une notification, ou DEL de toute la boite
 * Une notification non lue au bout de 7 jours disparait : c'est voulu, personne
 * n'a besoin de les retrouver dans un historique.
 */
final class Notification
{
    private const MAX = 50;
    private const TTL = 7 * 86400;

    private static function cle(int $userId): string
    {
        return 'nook:notif:' . $userId;
    }

    /** Ajoute une notification ; echoue en silence si Redis est absent. */
    public static function creer(int $destinataire, string $type, int $deId, string $dePseudo): void
    {
        try {
            if (!RedisClient::disponible()) {
                return;
            }
            $notification = json_encode([
                'id'     => bin2hex(random_bytes(6)),
                'type'   => $type,
                'de_id'  => $deId,
                'de'     => $dePseudo,
                'cree_le' => date('c'),
            ], JSON_UNESCAPED_UNICODE);

            $redis = RedisClient::instance();
            $cle = self::cle($destinataire);
            $redis->lpush($cle, $notification);
            $redis->ltrim($cle, 0, self::MAX - 1);
            $redis->expire($cle, self::TTL);
        } catch (Throwable) {
            // une notification perdue ne doit pas faire echouer l'action
        }
    }

    /** @return list<array<string,mixed>> */
    public static function lister(int $userId): array
    {
        $brutes = RedisClient::instance()->lrange(self::cle($userId), 0, self::MAX - 1);
        return array_values(array_filter(array_map(
            static fn (string $b): ?array => json_decode($b, true) ?: null,
            $brutes
        )));
    }

    public static function supprimer(int $userId, string $id): bool
    {
        $redis = RedisClient::instance();
        foreach ($redis->lrange(self::cle($userId), 0, self::MAX - 1) as $brute) {
            $notification = json_decode($brute, true);
            if (is_array($notification) && ($notification['id'] ?? null) === $id) {
                return $redis->lrem(self::cle($userId), $brute) > 0;
            }
        }
        return false;
    }

    public static function toutSupprimer(int $userId): void
    {
        RedisClient::instance()->del(self::cle($userId));
    }
}
