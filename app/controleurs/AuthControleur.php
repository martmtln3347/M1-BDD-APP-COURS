<?php
declare(strict_types=1);

final class AuthControleur extends Controleur
{
    private const LONGUEUR_MIN_MOT_DE_PASSE = 8;
    // bcrypt ne lit que les 72 premiers octets : au-dela, deux mots de passe
    // differents donneraient le meme hash. On refuse plutot que de tronquer.
    private const OCTETS_MAX_MOT_DE_PASSE = 72;

    // Hash bcrypt valide d'un mot de passe jetable. Quand l'email est inconnu,
    // on verifie quand meme contre lui : la reponse met le meme temps que pour
    // un vrai compte, ce qui empeche de deviner quelles adresses sont inscrites.
    private const HASH_FACTICE = '$2y$12$8DxGWZbiCD1RtQx4eCPcley5W9UFcsCLV5caspvSuysgmZlFcuBB2';

    public function inscription(): never
    {
        $this->exigerMethode('POST');

        $corps = $this->corpsJson();
        $pseudo = trim((string) ($corps['pseudo'] ?? ''));
        $email = trim((string) ($corps['email'] ?? ''));
        $motDePasse = (string) ($corps['mot_de_passe'] ?? '');

        $cleIp = 'inscription:ip:' . Securite::ip();
        Securite::limiter($cleIp, 5, '1 hour');

        if ($pseudo === '' || mb_strlen($pseudo) > 30) {
            Json::erreur('Le pseudo doit faire entre 1 et 30 caracteres');
        }
        // Lettres, chiffres, espace, point, tiret et underscore : pas de
        // caracteres de controle ni d'inversion de sens d'ecriture, qui
        // permettraient d'usurper l'apparence d'un autre pseudo.
        if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._-]*$/u', $pseudo)) {
            Json::erreur('Le pseudo ne peut contenir que des lettres, chiffres, espaces, points, tirets et underscores');
        }
        if (mb_strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Json::erreur('Adresse email invalide');
        }
        $this->validerMotDePasse($motDePasse);
        if (mb_strtolower($motDePasse) === mb_strtolower($email) || mb_strtolower($motDePasse) === mb_strtolower($pseudo)) {
            Json::erreur('Le mot de passe ne doit pas être ton pseudo ou ton email');
        }

        Securite::noter($cleIp);

        try {
            $tokenVerification = Utilisateur::creer($pseudo, $email, $motDePasse);
        } catch (PDOException $e) {
            if (($e->errorInfo[0] ?? '') === '23505') {
                Json::erreur('Ce pseudo ou cette adresse email est deja pris', 409);
            }
            Json::erreurSql($e);
        }

        Metrique::compter('inscriptions');
        $reponse = ['message' => 'Compte cree. Verifie ton adresse email pour pouvoir te connecter.'];
        if (Env::estDev()) {
            $reponse['lien_de_verification'] = $this->lien('verification', $tokenVerification);
        }

        Json::repondre($reponse, 201);
    }

    public function verifierEmail(): never
    {
        $token = (string) ($_GET['token'] ?? '');
        if ($token === '' || strlen($token) > 128) {
            Json::erreur('Token manquant');
        }

        $cleIp = 'jeton:ip:' . Securite::ip();
        Securite::limiter($cleIp, 20, '1 hour');
        Securite::noter($cleIp);

        try {
            $valide = Utilisateur::verifierEmail($token);
        } catch (PDOException $e) {
            Json::erreurSql($e);
        }

        if (!$valide) {
            Json::erreur('Lien de verification invalide ou expire', 410);
        }

        Json::repondre(['message' => 'Adresse email verifiee. Tu peux maintenant te connecter.']);
    }

    public function connexion(): never
    {
        $this->exigerMethode('POST');

        $corps = $this->corpsJson();
        $email = trim((string) ($corps['email'] ?? ''));
        $motDePasse = (string) ($corps['mot_de_passe'] ?? '');

        if ($email === '' || $motDePasse === '') {
            Json::erreur('Email et mot de passe requis');
        }
        if (mb_strlen($email) > 254 || strlen($motDePasse) > 1024) {
            Json::erreur('Email ou mot de passe incorrect', 401);
        }

        // Deux compteurs : par adresse IP (un attaquant qui essaie beaucoup de
        // comptes) et par email (plusieurs IP sur un meme compte).
        $cleIp = 'connexion:ip:' . Securite::ip();
        $cleMail = 'connexion:mail:' . Securite::empreinte($email);
        Securite::limiter($cleIp, 20, '15 minutes');
        Securite::limiter($cleMail, 8, '15 minutes');

        try {
            $utilisateur = Utilisateur::parEmail($email);
        } catch (PDOException $e) {
            Json::erreurSql($e);
        }

        // Meme message pour un email inconnu et un mot de passe faux, et meme
        // temps de calcul : rien ne permet de deviner quelles adresses ont un compte.
        $hash = $utilisateur['mot_de_passe_hash'] ?? self::HASH_FACTICE;
        $valide = password_verify($motDePasse, $hash) && $utilisateur !== null;
        if (!$valide) {
            Securite::noter($cleIp);
            Securite::noter($cleMail);
            Securite::journaliser('connexion_echec', ['compte' => Securite::empreinte($email)]);
            Json::erreur('Email ou mot de passe incorrect', 401);
        }
        Securite::oublier($cleMail);

        // Email non verifie : aucune session n'est ouverte. La policy
        // RESTRICTIVE cote base reste le filet de securite, pas le mecanisme
        // principal.
        if ($utilisateur['email_verifie'] !== true) {
            Json::erreur('Adresse email non verifiee : consulte le lien de verification', 403);
        }

        $profil = [
            'id'         => (int) $utilisateur['id'],
            'pseudo'     => $utilisateur['pseudo'],
            'visibilite' => $utilisateur['visibilite'],
        ];
        Session::ouvrir($profil, $utilisateur['maintenant']);
        Metrique::compter('connexions');

        Json::repondre(['message' => 'Connecte', 'utilisateur' => $profil]);
    }

    public function deconnexion(): never
    {
        $this->exigerMethode('POST');
        Session::fermer();
        Json::repondre(['message' => 'Deconnecte']);
    }

    /** Etat de session, appele au chargement de chaque page. */
    public function moi(): never
    {
        $utilisateur = Session::utilisateur();
        if ($utilisateur !== null) {
            $utilisateur['avatar_url'] = Utilisateur::avatarDe((int) $utilisateur['id']);
        }

        Json::repondre(['utilisateur' => $utilisateur]);
    }

    public function demanderReinitialisation(): never
    {
        $this->exigerMethode('POST');

        $email = trim((string) ($this->corpsJson()['email'] ?? ''));
        if (mb_strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Json::erreur('Adresse email invalide');
        }

        // Compte meme quand l'adresse est inconnue : la limite ne doit pas non
        // plus trahir quelles adresses existent.
        $cleIp = 'reset:ip:' . Securite::ip();
        $cleMail = 'reset:mail:' . Securite::empreinte($email);
        Securite::limiter($cleIp, 5, '1 hour');
        Securite::limiter($cleMail, 3, '1 hour');
        Securite::noter($cleIp);
        Securite::noter($cleMail);

        try {
            $token = Utilisateur::creerTokenReinitialisation($email);
        } catch (PDOException $e) {
            Json::erreurSql($e);
        }

        // La reponse est la meme que le compte existe ou non : ce formulaire
        // ne doit pas servir a tester quelles adresses sont inscrites.
        $reponse = ['message' => 'Si un compte existe pour cette adresse, un lien de réinitialisation valable une heure vient de lui être envoyé.'];
        if ($token !== null && Env::estDev()) {
            $reponse['lien_de_reinitialisation'] = $this->lien('reinitialiser', $token);
        }

        Json::repondre($reponse);
    }

    public function reinitialiser(): never
    {
        $this->exigerMethode('POST');

        $corps = $this->corpsJson();
        $token = (string) ($corps['token'] ?? '');
        $motDePasse = (string) ($corps['mot_de_passe'] ?? '');

        if ($token === '' || strlen($token) > 128) {
            Json::erreur('Token manquant');
        }
        $this->validerMotDePasse($motDePasse);

        $cleIp = 'jeton:ip:' . Securite::ip();
        Securite::limiter($cleIp, 20, '1 hour');
        Securite::noter($cleIp);

        try {
            $fait = Utilisateur::reinitialiserMotDePasse($token, $motDePasse);
        } catch (PDOException $e) {
            Json::erreurSql($e);
        }

        if (!$fait) {
            Json::erreur('Lien de réinitialisation invalide, expiré ou déjà utilisé', 410);
        }

        Json::repondre(['message' => 'Mot de passe modifié. Tu peux te connecter avec le nouveau.']);
    }

    private function validerMotDePasse(string $motDePasse): void
    {
        if (mb_strlen($motDePasse) < self::LONGUEUR_MIN_MOT_DE_PASSE) {
            Json::erreur(sprintf(
                'Le mot de passe doit faire au moins %d caracteres',
                self::LONGUEUR_MIN_MOT_DE_PASSE
            ));
        }
        if (strlen($motDePasse) > self::OCTETS_MAX_MOT_DE_PASSE) {
            Json::erreur(sprintf(
                'Le mot de passe est limite a %d caracteres',
                self::OCTETS_MAX_MOT_DE_PASSE
            ));
        }
    }

    /**
     * Lien vers une page portant un token. Hors mode dev, il n'est jamais
     * renvoye dans une reponse HTTP : il partirait par email.
     */
    private function lien(string $page, string $token): string
    {
        return sprintf(
            '%s://%s%s/index.php?page=%s&token=%s',
            ($_SERVER['HTTPS'] ?? '') !== '' ? 'https' : 'http',
            $_SERVER['HTTP_HOST'] ?? 'localhost',
            rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/'),
            $page,
            $token
        );
    }
}
