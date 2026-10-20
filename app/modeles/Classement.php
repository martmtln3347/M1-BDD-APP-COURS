<?php
declare(strict_types=1);

/**
 * Classements et popularite, ranges dans Redis : motif « classement » du cours
 * cle-valeur, porte par les ensembles tries (ZSET).
 *
 *   nook:classement:global           membre = id du joueur, score = nb d'elements possedes
 *   nook:classement:cat:<categorie>  idem, une categorie
 *   nook:popularite:items            membre = id de l'item, score = nb de joueurs qui le possedent
 *   nook:cache:totaux                JSON des tailles de catalogue (TTL 1 h)
 *
 * Pourquoi pas la vue materialisee de PostgreSQL ? Elle se recalcule en
 * balayant toute la table collections ; un ZSET se met a jour en O(log n) et se
 * lit en O(log n + k). Source de verite : PostgreSQL. Redis n'en est qu'un
 * index derive, que l'appli met a jour apres chaque ecriture et que le script
 * docker/init-nosql/peupler-redis.php reconstruit en entier.
 */
final class Classement
{
    public const GLOBAL = 'nook:classement:global';
    public const POPULARITE = 'nook:popularite:items';

    public static function cle(?string $categorie): string
    {
        return $categorie === null ? self::GLOBAL : 'nook:classement:cat:' . $categorie;
    }

    /**
     * Reconstruit tous les classements depuis PostgreSQL. Appele par le script
     * de peuplement, avec une connexion qui voit tous les comptes.
     *
     * @return array{joueurs:int, items:int}
     */
    public static function reconstruire(PDO $pdo): array
    {
        $redis = RedisClient::instance();

        foreach (array_merge([self::GLOBAL, self::POPULARITE], array_map(self::cle(...), Item::CATEGORIES)) as $cle) {
            $redis->del($cle);
        }

        // Un seul balayage de collections : possedes par joueur et par categorie.
        $lignes = $pdo->query(
            'SELECT c.user_id, i.categorie, count(*) AS nb
             FROM collections c
             JOIN items i ON i.id = c.item_id
             WHERE c.possede AND i.est_contrefacon_de IS NULL
             GROUP BY c.user_id, i.categorie'
        )->fetchAll();

        $parCategorie = [];
        $global = [];
        foreach ($lignes as $ligne) {
            $parCategorie[$ligne['categorie']][$ligne['user_id']] = (int) $ligne['nb'];
            $global[$ligne['user_id']] = ($global[$ligne['user_id']] ?? 0) + (int) $ligne['nb'];
        }
        // Les joueurs sans aucun element comptent aussi (score 0).
        foreach ($pdo->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $global[$id] ??= 0;
        }

        self::charger($redis, self::GLOBAL, $global);
        foreach ($parCategorie as $categorie => $scores) {
            self::charger($redis, self::cle($categorie), $scores);
        }

        $popularite = [];
        foreach ($pdo->query(
            'SELECT item_id, count(*) AS nb FROM collections WHERE possede GROUP BY item_id'
        )->fetchAll() as $ligne) {
            $popularite[$ligne['item_id']] = (int) $ligne['nb'];
        }
        self::charger($redis, self::POPULARITE, $popularite);
        $redis->del('nook:cache:totaux');

        return ['joueurs' => count($global), 'items' => count($popularite)];
    }

    /** ZADD par paquets : une commande pour 500 membres plutot qu'une par membre. */
    private static function charger(RedisClient $redis, string $cle, array $scores): void
    {
        foreach (array_chunk($scores, 500, true) as $paquet) {
            $arguments = ['ZADD', $cle];
            foreach ($paquet as $membre => $score) {
                $arguments[] = $score;
                $arguments[] = $membre;
            }
            $redis->commande(...$arguments);
        }
    }

    /**
     * Remet a jour les scores d'un joueur apres une ecriture, a partir de
     * PostgreSQL (ZADD est idempotent : rejouer ne corrompt rien) et ajuste la
     * popularite de l'item touche.
     */
    public static function apresMarquage(int $userId, int $itemId, bool $possedait, bool $possede): void
    {
        try {
            if (!RedisClient::disponible()) {
                return;
            }
            $redis = RedisClient::instance();

            $requete = Bdd::authentifiee()->prepare(
                'SELECT i.categorie, count(*) FILTER (WHERE c.possede) AS nb
                 FROM items i
                 LEFT JOIN collections c ON c.item_id = i.id AND c.user_id = :id
                 WHERE i.est_contrefacon_de IS NULL
                 GROUP BY i.categorie'
            );
            $requete->execute([':id' => $userId]);

            $total = 0;
            foreach ($requete->fetchAll() as $ligne) {
                $total += (int) $ligne['nb'];
                $redis->zadd(self::cle($ligne['categorie']), (int) $ligne['nb'], $userId);
            }
            $redis->zadd(self::GLOBAL, $total, $userId);

            if ($possedait !== $possede) {
                $redis->zincrby(self::POPULARITE, $possede ? 1 : -1, $itemId);
            }
        } catch (Throwable $e) {
            error_log('Mise a jour du classement impossible : ' . $e->getMessage());
        }
    }

    /** Efface un joueur de tous les index Redis (suppression de compte). */
    public static function retirerJoueur(int $userId): void
    {
        try {
            if (!RedisClient::disponible()) {
                return;
            }
            $redis = RedisClient::instance();
            foreach (array_merge([self::GLOBAL], array_map(self::cle(...), Item::CATEGORIES)) as $cle) {
                $redis->commande('ZREM', $cle, $userId);
            }
            $redis->del('nook:cache:stats:' . $userId, 'nook:notif:' . $userId);
        } catch (Throwable $e) {
            error_log('Nettoyage Redis impossible : ' . $e->getMessage());
        }
    }

    /**
     * Les `n` premiers du classement : [id du joueur => score].
     *
     * @return array<int,int>
     */
    public static function top(?string $categorie, int $n): array
    {
        $resultat = [];
        foreach (RedisClient::instance()->zclasse(self::cle($categorie), $n) as $id => $score) {
            $resultat[(int) $id] = (int) $score;
        }
        return $resultat;
    }

    /** @return array{rang:int, score:int, sur:int}|null */
    public static function position(int $userId, ?string $categorie = null): ?array
    {
        $redis = RedisClient::instance();
        $cle = self::cle($categorie);
        $rang = $redis->zrang($cle, $userId);
        $score = $redis->zscore($cle, $userId);

        return $rang === null || $score === null
            ? null
            : ['rang' => $rang + 1, 'score' => (int) $score, 'sur' => $redis->zcard($cle)];
    }

    /**
     * Items les plus rares (croissant) ou les plus courants (decroissant).
     *
     * @return array<int,int> id de l'item => nb de joueurs qui le possedent
     */
    public static function popularite(bool $rares, int $n): array
    {
        $resultat = [];
        $redis = RedisClient::instance();
        // Les rares : on saute les scores <= 0 (un item que personne n'a n'est pas « rare », il est inconnu).
        $limite = $rares ? max($n * 3, 30) : $n;
        foreach ($redis->zclasse(self::POPULARITE, $limite, !$rares) as $id => $score) {
            if ($score > 0 && count($resultat) < $n) {
                $resultat[(int) $id] = (int) $score;
            }
        }
        return $resultat;
    }

    /**
     * Taille des catalogues, mise en cache une heure : elle ne change qu'au
     * rechargement des donnees du jeu. Motif « cache » avec TTL.
     *
     * @return array<string,int> categorie => nb d'items, plus la cle "total"
     */
    public static function totaux(): array
    {
        $cle = 'nook:cache:totaux';
        $redis = RedisClient::instance();

        $enCache = $redis->get($cle);
        if ($enCache !== null) {
            return json_decode($enCache, true) ?: [];
        }

        $totaux = [];
        foreach (Bdd::connexion()->query(
            'SELECT categorie, count(*) AS nb FROM items WHERE est_contrefacon_de IS NULL GROUP BY categorie'
        )->fetchAll() as $ligne) {
            $totaux[$ligne['categorie']] = (int) $ligne['nb'];
        }
        $totaux['total'] = array_sum($totaux);

        $redis->set($cle, json_encode($totaux), 3600);
        return $totaux;
    }
}
