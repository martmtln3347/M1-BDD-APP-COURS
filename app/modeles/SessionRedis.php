<?php
declare(strict_types=1);

/**
 * Sessions PHP stockees dans Redis : motif « session » du cours cle-valeur.
 *
 * Une session = une cle `nook:session:<id>` qui porte son propre TTL. Plus de
 * fichiers a nettoyer : Redis expire les sessions inactives tout seul, et le
 * TTL est repousse a chaque requete (expiration glissante).
 */
final class SessionRedis implements SessionHandlerInterface, SessionIdInterface, SessionUpdateTimestampHandlerInterface
{
    public const DUREE = 7200; // 2 h d'inactivite

    private function cle(string $id): string
    {
        return 'nook:session:' . $id;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        return RedisClient::instance()->get($this->cle($id)) ?? '';
    }

    public function write(string $id, string $data): bool
    {
        RedisClient::instance()->set($this->cle($id), $data, self::DUREE);
        return true;
    }

    public function destroy(string $id): bool
    {
        RedisClient::instance()->del($this->cle($id));
        return true;
    }

    /** Redis expire les cles tout seul : rien a purger. */
    public function gc(int $max_lifetime): int|false
    {
        return 0;
    }

    public function create_sid(): string
    {
        return bin2hex(random_bytes(24));
    }

    /** Mode strict : un identifiant inconnu de Redis est refuse (fixation de session). */
    public function validateId(string $id): bool
    {
        return RedisClient::instance()->exists($this->cle($id));
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        RedisClient::instance()->expire($this->cle($id), self::DUREE);
        return true;
    }
}
