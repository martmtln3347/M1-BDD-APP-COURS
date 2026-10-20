<?php
declare(strict_types=1);

/**
 * Client Redis minimal : le protocole RESP2 en une centaine de lignes, sans
 * extension ni Composer. Une connexion par requete HTTP, ouverte a la demande.
 *
 * Conventions de cles (voir docker/init-nosql/cles.md) :
 *   nook:<domaine>:<identifiant>[:<qualificatif>]
 */
final class RedisErreur extends RuntimeException
{
}

final class RedisClient
{
    private static ?self $instance = null;
    private static ?bool $disponible = null;

    /** @var resource */
    private $flux;

    private function __construct()
    {
        $hote = Env::lire('REDIS_HOST', '127.0.0.1');
        $port = Env::lire('REDIS_PORT', '6379');

        $flux = @stream_socket_client("tcp://$hote:$port", $code, $message, 0.5);
        if ($flux === false) {
            throw new RedisErreur("Connexion Redis impossible ($message)");
        }
        stream_set_timeout($flux, 1);
        $this->flux = $flux;

        $mdp = Env::lire('REDIS_PASSWORD', '');
        if ($mdp !== '') {
            $this->commande('AUTH', $mdp);
        }
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Redis est-il joignable ? Les fonctions annexes (cache, classement,
     * limitation) se replient sur PostgreSQL quand la reponse est non.
     */
    public static function disponible(): bool
    {
        if (self::$disponible === null) {
            try {
                self::$disponible = self::instance()->commande('PING') === 'PONG';
            } catch (Throwable) {
                self::$disponible = false;
            }
        }
        return self::$disponible;
    }

    /** Envoie une commande (tableau RESP) et renvoie la reponse decodee. */
    public function commande(string|int|float ...$arguments): mixed
    {
        $message = '*' . count($arguments) . "\r\n";
        foreach ($arguments as $argument) {
            $argument = (string) $argument;
            $message .= '$' . strlen($argument) . "\r\n" . $argument . "\r\n";
        }
        if (@fwrite($this->flux, $message) === false) {
            throw new RedisErreur('Ecriture Redis impossible');
        }
        return $this->lire();
    }

    private function lire(): mixed
    {
        $ligne = fgets($this->flux);
        if ($ligne === false) {
            throw new RedisErreur('Reponse Redis absente');
        }
        $type = $ligne[0];
        $reste = substr($ligne, 1, -2);

        return match ($type) {
            '+' => $reste,
            '-' => throw new RedisErreur($reste),
            ':' => (int) $reste,
            '$' => $this->lireChaine((int) $reste),
            '*' => $this->lireTableau((int) $reste),
            default => throw new RedisErreur('Reponse Redis inattendue'),
        };
    }

    private function lireChaine(int $longueur): ?string
    {
        if ($longueur < 0) {
            return null;
        }
        $donnees = '';
        while (strlen($donnees) < $longueur + 2) {
            $morceau = fread($this->flux, $longueur + 2 - strlen($donnees));
            if ($morceau === false || $morceau === '') {
                throw new RedisErreur('Lecture Redis interrompue');
            }
            $donnees .= $morceau;
        }
        return substr($donnees, 0, -2);
    }

    private function lireTableau(int $nombre): ?array
    {
        if ($nombre < 0) {
            return null;
        }
        $elements = [];
        for ($i = 0; $i < $nombre; $i++) {
            $elements[] = $this->lire();
        }
        return $elements;
    }

    // ---------- Raccourcis (un par commande utilisee par l'appli) ----------

    public function get(string $cle): ?string
    {
        return $this->commande('GET', $cle);
    }

    public function set(string $cle, string $valeur, int $ttl = 0): void
    {
        $ttl > 0 ? $this->commande('SET', $cle, $valeur, 'EX', $ttl) : $this->commande('SET', $cle, $valeur);
    }

    public function del(string ...$cles): int
    {
        return $cles === [] ? 0 : (int) $this->commande('DEL', ...$cles);
    }

    public function incr(string $cle): int
    {
        return (int) $this->commande('INCR', $cle);
    }

    public function expire(string $cle, int $secondes): void
    {
        $this->commande('EXPIRE', $cle, $secondes);
    }

    public function exists(string $cle): bool
    {
        return (int) $this->commande('EXISTS', $cle) === 1;
    }

    public function ttl(string $cle): int
    {
        return (int) $this->commande('TTL', $cle);
    }

    public function hincrby(string $cle, string $champ, int $delta = 1): int
    {
        return (int) $this->commande('HINCRBY', $cle, $champ, $delta);
    }

    /** @return array<string,string> */
    public function hgetall(string $cle): array
    {
        $plat = $this->commande('HGETALL', $cle) ?? [];
        $resultat = [];
        for ($i = 0; $i + 1 < count($plat); $i += 2) {
            $resultat[$plat[$i]] = $plat[$i + 1];
        }
        return $resultat;
    }

    public function zadd(string $cle, float|int $score, string|int $membre): void
    {
        $this->commande('ZADD', $cle, $score, $membre);
    }

    public function zincrby(string $cle, float|int $delta, string|int $membre): void
    {
        $this->commande('ZINCRBY', $cle, $delta, $membre);
    }

    /**
     * Les `limite` premiers membres par score decroissant (ou croissant), sous
     * la forme [membre => score].
     *
     * @return array<string,float>
     */
    public function zclasse(string $cle, int $limite, bool $decroissant = true): array
    {
        $plat = $this->commande($decroissant ? 'ZREVRANGE' : 'ZRANGE', $cle, 0, $limite - 1, 'WITHSCORES') ?? [];
        $resultat = [];
        for ($i = 0; $i + 1 < count($plat); $i += 2) {
            $resultat[$plat[$i]] = (float) $plat[$i + 1];
        }
        return $resultat;
    }

    /** Rang (0 = premier) par score decroissant, ou null si absent. */
    public function zrang(string $cle, string|int $membre): ?int
    {
        $rang = $this->commande('ZREVRANK', $cle, $membre);
        return $rang === null ? null : (int) $rang;
    }

    public function zscore(string $cle, string|int $membre): ?float
    {
        $score = $this->commande('ZSCORE', $cle, $membre);
        return $score === null ? null : (float) $score;
    }

    public function zcard(string $cle): int
    {
        return (int) $this->commande('ZCARD', $cle);
    }

    public function lpush(string $cle, string $valeur): void
    {
        $this->commande('LPUSH', $cle, $valeur);
    }

    public function ltrim(string $cle, int $debut, int $fin): void
    {
        $this->commande('LTRIM', $cle, $debut, $fin);
    }

    /** @return list<string> */
    public function lrange(string $cle, int $debut, int $fin): array
    {
        return $this->commande('LRANGE', $cle, $debut, $fin) ?? [];
    }

    public function lrem(string $cle, string $valeur): int
    {
        return (int) $this->commande('LREM', $cle, 0, $valeur);
    }

    public function llen(string $cle): int
    {
        return (int) $this->commande('LLEN', $cle);
    }
}
