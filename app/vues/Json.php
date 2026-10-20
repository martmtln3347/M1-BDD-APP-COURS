<?php
declare(strict_types=1);

/**
 * La vue de l'API : elle ne decide rien, elle met en forme et envoie.
 * Les pages HTML du site sont l'autre moitie de la couche vue, rendue cote
 * navigateur a partir de ce que produit cette classe.
 */
final class Json
{
    public static function repondre(array $donnees, int $code = 200): never
    {
        http_response_code($code);
        Securite::entetesApi();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function erreur(string $message, int $code = 400): never
    {
        self::repondre(['erreur' => $message], $code);
    }

    /**
     * Traduit une erreur PostgreSQL en reponse HTTP.
     *
     * Les RAISE EXCEPTION des procedures metier portent un message deja redige
     * pour l'utilisateur ; un refus de la RLS, lui, ne doit rien laisser
     * filtrer de la regle qui l'a declenche.
     */
    public static function erreurSql(PDOException $e): never
    {
        $etat = $e->errorInfo[0] ?? '';
        $message = $e->errorInfo[2] ?? '';

        if ($etat === '42501' || str_contains($message, 'row-level security')) {
            self::erreur('Action non autorisee', 403);
        }
        if ($etat === '23505') {
            self::erreur('Cette valeur est deja utilisee', 409);
        }
        if ($etat === 'P0001') { // RAISE EXCEPTION d'une procedure metier
            // Le prefixe depend de la locale du serveur : ERROR en anglais,
            // ERREUR en francais.
            $message = trim(preg_replace('/^(ERROR|ERREUR):\s*/i', '', explode("\n", $message)[0]));
            self::erreur($message !== '' ? $message : 'Operation refusee', 400);
        }

        error_log('Erreur SQL : ' . $e->getMessage());
        self::erreur('Erreur interne', 500);
    }
}
