<?php
declare(strict_types=1);

/**
 * Peuplement de Redis a partir de PostgreSQL.
 *
 *   docker compose run --rm peupler-redis      (rejouable a tout moment)
 *
 * PostgreSQL est la source de verite : ce script (re)construit les index
 * Redis derives, c'est-a-dire les classements (ZSET) et la popularite des
 * items. Il se connecte avec le role d'administration (BYPASSRLS) car il doit
 * voir TOUS les joueurs, et n'est jamais utilise par l'application web.
 */
require_once '/var/www/html/autoload.php';

function attendre(callable $essai, string $nom, int $tentatives = 60): mixed
{
    for ($i = 1; $i <= $tentatives; $i++) {
        try {
            return $essai();
        } catch (Throwable $e) {
            fwrite(STDERR, "[$nom] en attente ($i/$tentatives) : " . $e->getMessage() . "\n");
            sleep(2);
        }
    }
    fwrite(STDERR, "[$nom] injoignable, abandon\n");
    exit(1);
}

$pdo = attendre(static fn () => new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', Env::lire('DB_HOST'), Env::lire('POSTGRES_PORT', '5432'), Env::lire('POSTGRES_DB', 'nook_manager')),
    'acnh_admin',
    Env::lire('ACNH_ADMIN_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
), 'PostgreSQL');

attendre(static fn () => RedisClient::instance()->commande('PING'), 'Redis');

$debut = microtime(true);
$resume = Classement::reconstruire($pdo);
$redis = RedisClient::instance();

printf("Redis peuple en %.1f s\n", microtime(true) - $debut);
printf("  joueurs classes (nook:classement:global)   : %d\n", $redis->zcard(Classement::GLOBAL));
foreach (Item::CATEGORIES as $categorie) {
    printf("  %-44s: %d\n", Classement::cle($categorie), $redis->zcard(Classement::cle($categorie)));
}
printf("  items comptes (nook:popularite:items)      : %d\n", $redis->zcard(Classement::POPULARITE));

$premier = array_key_first($redis->zclasse(Classement::GLOBAL, 1));
printf("  meilleur joueur : id %s, %d elements\n", (string) $premier, (int) ($redis->zscore(Classement::GLOBAL, (int) $premier) ?? 0));
