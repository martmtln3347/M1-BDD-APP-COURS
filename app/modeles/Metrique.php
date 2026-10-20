<?php
declare(strict_types=1);

/**
 * Compteurs d'activite du site, par jour : motif « compteur » du cours
 * cle-valeur. Un HASH par jour (`nook:stats:jour:AAAAMMJJ`), un champ par
 * evenement, incremente de facon atomique par HINCRBY. Les cles expirent au
 * bout de 8 jours : seules les statistiques recentes sont utiles.
 */
final class Metrique
{
    /** Incremente en silence : une metrique perdue ne doit jamais faire echouer une requete. */
    public static function compter(string $evenement): void
    {
        try {
            if (!RedisClient::disponible()) {
                return;
            }
            $cle = 'nook:stats:jour:' . date('Ymd');
            $redis = RedisClient::instance();
            $redis->hincrby($cle, $evenement, 1);
            $redis->expire($cle, 8 * 86400);
        } catch (Throwable) {
            // volontairement ignore
        }
    }

    /** @return array<string,int> */
    public static function aujourdhui(): array
    {
        try {
            if (!RedisClient::disponible()) {
                return [];
            }
            return array_map('intval', RedisClient::instance()->hgetall('nook:stats:jour:' . date('Ymd')));
        } catch (Throwable) {
            return [];
        }
    }
}
