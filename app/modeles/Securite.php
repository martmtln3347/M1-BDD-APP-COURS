<?php
declare(strict_types=1);

/**
 * Garde-fous communs a toutes les reponses : en-tetes de securite, politique
 * de contenu (CSP) a nonce, limitation des tentatives et journal.
 */
final class Securite
{
    private static ?string $nonce = null;

    /** Nonce propre a la requete : seuls les scripts qui le portent s'executent. */
    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(16));
    }

    /** En-tetes envoyes avec chaque reponse, page ou API. */
    public static function entetesCommuns(): void
    {
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        // Les liens de verification et de reinitialisation portent un token dans
        // l'URL : aucune requete sortante (polices, images) ne doit le divulguer.
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');

        if (self::estHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /** Page HTML : CSP stricte, sans 'unsafe-inline' pour les scripts. */
    public static function entetesPage(): void
    {
        self::entetesCommuns();
        header('Cache-Control: no-cache');
        header("Content-Security-Policy: "
            . "default-src 'none'; "
            . "script-src 'self' 'nonce-" . self::nonce() . "'; "
            . "style-src 'self' https://fonts.googleapis.com; "
            . "font-src https://fonts.gstatic.com; "
            . "img-src 'self' data: https://acnhcdn.com; "
            . "connect-src 'self'; "
            . "worker-src 'self'; "
            . "manifest-src 'self'; "
            . "object-src 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self'; "
            . "frame-ancestors 'none'");
    }

    /** Reponse d'API : jamais mise en cache, c'est du contenu par utilisateur. */
    public static function entetesApi(): void
    {
        self::entetesCommuns();
        header('Cache-Control: no-store');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    }

    /** Ajoute le nonce a chaque balise <script> du HTML rendu. */
    public static function signerScripts(string $html): string
    {
        return preg_replace('/<script(?![^>]*\bnonce=)/i', '<script nonce="' . self::nonce() . '"', $html) ?? $html;
    }

    public static function estHttps(): bool
    {
        return (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off');
    }

    /**
     * Adresse du client. On ne fait confiance qu'a REMOTE_ADDR : X-Forwarded-For
     * est librement ecrit par le client, un attaquant s'en servirait pour
     * changer d'adresse a chaque tentative et contourner la limitation.
     */
    public static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'inconnue');
    }

    /** Duree de fenetre (en secondes) retenue pour chaque cle, pour que noter() pose le bon TTL. */
    private static array $fenetres = [];

    /**
     * Refuse (429) si la cle a depasse son quota sur la fenetre.
     *
     * Motif « compteur » du cours cle-valeur : un compteur par cle
     * (`nook:limite:<cle>`), incremente par INCR, qui expire tout seul avec sa
     * fenetre (fenetre fixe). Si Redis est indisponible, PostgreSQL prend le
     * relais avec sa table tentatives_auth : la protection ne tombe jamais.
     */
    public static function limiter(string $cle, int $max, string $fenetre): void
    {
        self::$fenetres[$cle] = self::secondes($fenetre);

        $depasse = RedisClient::disponible()
            ? (int) (RedisClient::instance()->get('nook:limite:' . $cle) ?? 0) >= $max
            : self::limiterPg($cle, $max, $fenetre);

        if ($depasse) {
            self::journaliser('limite_atteinte', ['cle' => self::masquer($cle)]);
            header('Retry-After: 900');
            Json::erreur('Trop de tentatives. Patiente quelques minutes avant de réessayer.', 429);
        }
    }

    public static function noter(string $cle): void
    {
        if (RedisClient::disponible()) {
            $redis = RedisClient::instance();
            $compteur = 'nook:limite:' . $cle;
            // Le TTL est pose a la premiere tentative : la fenetre court depuis elle.
            if ($redis->incr($compteur) === 1) {
                $redis->expire($compteur, self::$fenetres[$cle] ?? 900);
            }
            return;
        }
        Bdd::connexion()->prepare('SELECT noter_tentative(:cle)')->execute([':cle' => $cle]);
    }

    public static function oublier(string $cle): void
    {
        if (RedisClient::disponible()) {
            RedisClient::instance()->del('nook:limite:' . $cle);
            return;
        }
        Bdd::connexion()->prepare('SELECT oublier_tentatives(:cle)')->execute([':cle' => $cle]);
    }

    private static function limiterPg(string $cle, int $max, string $fenetre): bool
    {
        $requete = Bdd::connexion()->prepare('SELECT trop_de_tentatives(:cle, :max, CAST(:fenetre AS interval))');
        $requete->execute([':cle' => $cle, ':max' => $max, ':fenetre' => $fenetre]);
        return $requete->fetchColumn() === true;
    }

    private static function secondes(string $fenetre): int
    {
        return match (true) {
            str_contains($fenetre, 'hour') => 3600 * max(1, (int) $fenetre),
            default => 60 * max(1, (int) $fenetre),
        };
    }

    /** Empreinte pour les cles de limitation : l'email n'est jamais stocke en clair. */
    public static function empreinte(string $valeur): string
    {
        return substr(hash('sha256', mb_strtolower($valeur)), 0, 32);
    }

    /** Evenement de securite dans le journal du serveur (error_log). */
    public static function journaliser(string $evenement, array $details = []): void
    {
        error_log('[securite] ' . $evenement . ' ' . json_encode(
            ['ip' => self::ip()] + $details,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    private static function masquer(string $cle): string
    {
        return explode(':', $cle)[0];
    }
}
