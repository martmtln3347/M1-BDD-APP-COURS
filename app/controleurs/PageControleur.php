<?php
declare(strict_types=1);

/**
 * Controleur des pages HTML : choisit la vue demandee et la rend dans le
 * gabarit commun. Les donnees, elles, arrivent ensuite par l'API JSON.
 */
final class PageControleur
{
    /**
     * Liste blanche : ?page= arrive de l'URL et sert a construire un chemin
     * de fichier. Sans elle, ?page=../../.env inclurait n'importe quoi.
     */
    private const PAGES = [
        'accueil', 'habitants', 'poissons', 'creatures', 'insectes', 'art',
        'connexion', 'inscription', 'verification', 'mot-de-passe-oublie',
        'reinitialiser', 'profil', 'amis', 'comparer',
    ];

    public function afficher(): void
    {
        $pageCourante = (string) ($_GET['page'] ?? 'accueil');
        if (!in_array($pageCourante, self::PAGES, true)) {
            http_response_code(404);
            $pageCourante = 'introuvable';
        }

        // Valeurs par defaut, que chaque vue remplace.
        $titre = 'Nook Manager';
        $description = '';
        $scripts = '';

        $dossierVues = dirname(__DIR__) . '/vues';

        ob_start();
        require $dossierVues . '/pages/' . $pageCourante . '.php';
        $contenu = ob_get_clean();

        ob_start();
        require $dossierVues . '/gabarit.php';
        $html = Securite::signerScripts((string) ob_get_clean());

        Securite::entetesPage();
        echo $html;
    }
}
