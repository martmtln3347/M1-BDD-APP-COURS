<?php
declare(strict_types=1);

/**
 * Outils communs aux controleurs : lecture de la requete et garde-fous.
 */
abstract class Controleur
{
    protected function exigerMethode(string $methode): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== $methode) {
            Json::erreur("Methode non autorisee, $methode attendu", 405);
        }
        if ($methode !== 'GET') {
            $this->exigerJeton();
        }
    }

    protected function methode(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    protected function corpsJson(): array
    {
        $brut = file_get_contents('php://input');
        if ($brut === '' || $brut === false) {
            return [];
        }
        $donnees = json_decode($brut, true);
        if (!is_array($donnees)) {
            Json::erreur('Corps de requete JSON invalide');
        }
        return $donnees;
    }

    /** Renvoie l'utilisateur connecte, ou coupe court en 401. */
    protected function exigerConnexion(): array
    {
        $utilisateur = Session::utilisateur();
        if ($utilisateur === null) {
            Json::erreur('Connexion requise', 401);
        }
        return $utilisateur;
    }

    /**
     * Protection CSRF de toute requete qui modifie quelque chose. Trois
     * barrieres independantes, la derniere suffirait a elle seule :
     *  1. Sec-Fetch-Site : le navigateur dit lui-meme d'ou vient la requete ;
     *  2. Origin : doit etre ce site, une page tierce ne peut pas le falsifier ;
     *  3. Content-Type JSON : un formulaire HTML ne sait pas en envoyer sans
     *     declencher une verification prealable (preflight) que le site refuse.
     * Le cookie de session est en plus SameSite=Strict (voir Session).
     */
    protected function exigerJeton(): void
    {
        $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
        if ($site !== null && !in_array($site, ['same-origin', 'none'], true)) {
            $this->refuserOrigine('sec-fetch-site');
        }

        $origine = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($origine !== null) {
            $hote = (string) parse_url($origine, PHP_URL_HOST);
            $port = parse_url($origine, PHP_URL_PORT);
            $recu = $hote . ($port ? ':' . $port : '');
            if (!hash_equals((string) ($_SERVER['HTTP_HOST'] ?? ''), $recu)) {
                $this->refuserOrigine('origin');
            }
        }

        $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (!str_starts_with($type, 'application/json')) {
            Json::erreur('Content-Type application/json requis', 415);
        }
    }

    private function refuserOrigine(string $raison): never
    {
        Securite::journaliser('csrf_refuse', ['raison' => $raison, 'uri' => $_SERVER['REQUEST_URI'] ?? '']);
        Json::erreur('Origine non autorisee', 403);
    }
}
