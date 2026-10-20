<?php
declare(strict_types=1);

final class Statistiques
{
    /**
     * Avancement par categorie. La vue est en security_invoker : si le profil
     * vise n'est pas visible par l'utilisateur connecte, elle ne renvoie
     * simplement aucune ligne.
     */
    public static function parCategorie(int $userId): array
    {
        $requete = Bdd::authentifiee()->prepare(
            'SELECT categorie, nb_possedes, nb_au_musee, nb_total_categorie, pct_possede
             FROM v_stats_user
             WHERE user_id = :id
             ORDER BY categorie'
        );
        $requete->execute([':id' => $userId]);

        return $requete->fetchAll();
    }

    /**
     * Classement des profils visibles. La vue materialisee ne peut pas porter
     * de RLS : c'est la vue v_classement_amis posee par-dessus qui reapplique
     * la regle de visibilite.
     */
    public static function classement(): array
    {
        if (RedisClient::disponible()) {
            try {
                return self::classementRedis(null);
            } catch (Throwable $e) {
                error_log('Classement Redis indisponible, repli sur PostgreSQL : ' . $e->getMessage());
            }
        }

        $pdo = Bdd::authentifiee();

        // Le classement balaie toute la table collections : on le recalcule a
        // la demande plutot qu'a chaque marquage d'item. Sur une vraie
        // production, ce serait un pg_cron toutes les quelques minutes.
        $pdo->query('SELECT rafraichir_classement()');

        return $pdo->query(
            'SELECT c.user_id, c.pseudo, av.avatar_url, c.nb_possedes, c.nb_total, c.pct_global
             FROM v_classement_amis c
             JOIN v_avatars av ON av.user_id = c.user_id
             ORDER BY c.pct_global DESC NULLS LAST, c.pseudo'
        )->fetchAll();
    }

    /** Classement d'une seule categorie (meme principe : vue par-dessus la materialisee). */
    public static function classementCategorie(string $categorie): array
    {
        if (RedisClient::disponible()) {
            try {
                return self::classementRedis($categorie);
            } catch (Throwable $e) {
                error_log('Classement Redis indisponible, repli sur PostgreSQL : ' . $e->getMessage());
            }
        }

        $requete = Bdd::authentifiee()->prepare(
            'SELECT c.user_id, c.pseudo, av.avatar_url, c.nb_possedes, c.nb_total, c.pct
             FROM v_classement_categorie c
             JOIN v_avatars av ON av.user_id = c.user_id
             WHERE c.categorie = :categorie
             ORDER BY c.pct DESC NULLS LAST, c.pseudo'
        );
        $requete->execute([':categorie' => $categorie]);

        return $requete->fetchAll();
    }

    /**
     * Records de la communaute. Tout est calcule sur ce que l'utilisateur a le
     * droit de voir : un profil prive ne compte dans aucun chiffre.
     */
    public static function communaute(): array
    {
        if (RedisClient::disponible()) {
            try {
                return self::communauteRedis();
            } catch (Throwable $e) {
                error_log('Records Redis indisponibles, repli sur PostgreSQL : ' . $e->getMessage());
            }
        }

        $pdo = Bdd::authentifiee();

        $rares = $pdo->query(
            'SELECT item_id, nom, categorie, image_icone, nb_possesseurs
             FROM v_popularite_items
             WHERE nb_possesseurs > 0
             ORDER BY nb_possesseurs ASC, nb_au_musee ASC, nom
             LIMIT 5'
        )->fetchAll();

        $courants = $pdo->query(
            'SELECT item_id, nom, categorie, image_icone, nb_possesseurs
             FROM v_popularite_items
             ORDER BY nb_possesseurs DESC, nom
             LIMIT 5'
        )->fetchAll();

        $museeur = $pdo->query(
            "SELECT u.pseudo, av.avatar_url, count(*) AS nb_au_musee
             FROM collections c
             JOIN users u ON u.id = c.user_id
             JOIN v_avatars av ON av.user_id = u.id
             WHERE c.au_musee
             GROUP BY u.pseudo, av.avatar_url
             ORDER BY nb_au_musee DESC, u.pseudo
             LIMIT 1"
        )->fetch() ?: null;

        return [
            'plus_rares' => $rares, 'plus_courants' => $courants, 'plus_gros_musee' => $museeur,
            'trouvailles_48h' => self::trouvaillesRecentes(),
        ];
    }

    /**
     * Badges calcules a partir des stats : rien n'est stocke, un badge est une
     * consequence de l'avancement, jamais une donnee a tenir a jour.
     */
    public static function badges(array $stats): array
    {
        $libelles = [
            'habitant' => ['habitants', 'tous'], 'poisson' => ['poissons', 'tous'],
            'creature_marine' => ['créatures marines', 'toutes'],
            'insecte' => ['insectes', 'tous'], 'art' => ['œuvres', 'toutes'],
        ];

        $total = 0;
        $possedes = 0;
        $badges = [];
        foreach ($stats as $ligne) {
            $total += (int) $ligne['nb_total_categorie'];
            $possedes += (int) $ligne['nb_possedes'];
            [$nom, $tous] = $libelles[$ligne['categorie']] ?? [$ligne['categorie'], 'tous'];
            $complet = (int) $ligne['nb_total_categorie'] > 0
                && (int) $ligne['nb_possedes'] === (int) $ligne['nb_total_categorie'];
            $museeComplet = $complet && (int) $ligne['nb_au_musee'] === (int) $ligne['nb_total_categorie'];

            $badges[] = [
                'id' => 'complet-' . $ligne['categorie'],
                'titre' => ucfirst($tous) . ' les ' . $nom,
                'detail' => 'Posséder 100 % des ' . $nom,
                'obtenu' => $complet,
            ];
            if ($ligne['categorie'] !== 'habitant') {
                $badges[] = [
                    'id' => 'musee-' . $ligne['categorie'],
                    'titre' => 'Musée : ' . $nom,
                    'detail' => 'Donner ' . $tous . ' les ' . $nom . ' au musée',
                    'obtenu' => $museeComplet,
                ];
            }
        }

        $pct = $total > 0 ? 100 * $possedes / $total : 0;
        array_unshift(
            $badges,
            ['id' => 'premier-pas', 'titre' => 'Premier pas', 'detail' => 'Cocher un premier élément', 'obtenu' => $possedes >= 1],
            ['id' => 'moitie', 'titre' => 'À mi-chemin', 'detail' => 'Atteindre 50 % de la collection', 'obtenu' => $pct >= 50],
        );

        return $badges;
    }

    /**
     * Classement lu dans Redis (ZSET), puis croise avec PostgreSQL pour ce que
     * Redis ne sait pas faire : les pseudos et surtout la VISIBILITE. Redis
     * ignore la Row Level Security ; c'est donc PostgreSQL qui decide quels
     * joueurs de la liste le visiteur a le droit de voir.
     */
    private static function classementRedis(?string $categorie): array
    {
        $scores = Classement::top($categorie, 100);
        if ($scores === []) {
            return [];
        }

        $visibles = self::joueursVisibles(array_keys($scores));
        $totaux = Classement::totaux();
        $total = $categorie === null ? ($totaux['total'] ?? 0) : ($totaux[$categorie] ?? 0);

        $lignes = [];
        foreach ($scores as $id => $score) {
            if (!isset($visibles[$id])) {
                continue; // profil prive ou reserve aux amis : absent du classement
            }
            $pct = $total > 0 ? round(100 * $score / $total, 1) : null;
            $ligne = [
                'user_id'    => $id,
                'pseudo'     => $visibles[$id]['pseudo'],
                'avatar_url' => $visibles[$id]['avatar_url'],
                'nb_possedes' => $score,
                'nb_total'   => $total,
            ];
            $lignes[] = $ligne + ($categorie === null ? ['pct_global' => $pct] : ['pct' => $pct]);
        }
        return $lignes;
    }

    /**
     * Parmi ces identifiants, ceux que le visiteur peut voir, avec pseudo et
     * photo. peut_voir_profil() est la fonction de visibilite de la base.
     *
     * @param list<int> $ids
     * @return array<int,array{pseudo:string,avatar_url:?string}>
     */
    public static function joueursVisibles(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $requete = Bdd::authentifiee()->prepare(
            'SELECT u.id, u.pseudo, av.avatar_url
             FROM users u
             JOIN v_avatars av ON av.user_id = u.id
             WHERE u.id = ANY(CAST(:ids AS int[])) AND peut_voir_profil(u.id)'
        );
        $requete->execute([':ids' => '{' . implode(',', array_map('intval', $ids)) . '}']);

        $visibles = [];
        foreach ($requete->fetchAll() as $ligne) {
            $visibles[(int) $ligne['id']] = ['pseudo' => $ligne['pseudo'], 'avatar_url' => $ligne['avatar_url']];
        }
        return $visibles;
    }

    /**
     * Avancement par categorie avec cache Redis, pour SON PROPRE profil
     * seulement : le resultat d'un autre profil depend de qui regarde (RLS), un
     * cache partage servirait a l'un ce que l'autre a le droit de voir.
     * TTL 120 s, et invalidation explicite a chaque ecriture (voir invalider()).
     */
    public static function parCategorieEnCache(int $cible, int $moi): array
    {
        if ($cible !== $moi || !RedisClient::disponible()) {
            return self::parCategorie($cible);
        }

        $cle = 'nook:cache:stats:' . $cible;
        $redis = RedisClient::instance();
        $enCache = $redis->get($cle);
        if ($enCache !== null) {
            return json_decode($enCache, true) ?: [];
        }

        $stats = self::parCategorie($cible);
        $redis->set($cle, json_encode($stats), 120);
        return $stats;
    }

    public static function invalider(int $userId): void
    {
        try {
            if (RedisClient::disponible()) {
                RedisClient::instance()->del('nook:cache:stats:' . $userId);
            }
        } catch (Throwable) {
            // le TTL de 120 s rattrape un oubli
        }
    }

    /**
     * Records : les scores viennent de Redis (ZSET de popularite), les noms et
     * les images de PostgreSQL. Ce sont des compteurs anonymes sur l'ensemble
     * des joueurs, sans lien avec un compte precis.
     */
    private static function communauteRedis(): array
    {
        $rares = Classement::popularite(true, 5);
        $courants = Classement::popularite(false, 5);
        $ids = array_unique(array_merge(array_keys($rares), array_keys($courants)));

        $items = [];
        if ($ids !== []) {
            $requete = Bdd::connexion()->prepare(
                'SELECT id, nom, categorie, image_icone FROM items WHERE id = ANY(CAST(:ids AS int[]))'
            );
            $requete->execute([':ids' => '{' . implode(',', $ids) . '}']);
            foreach ($requete->fetchAll() as $ligne) {
                $items[(int) $ligne['id']] = $ligne;
            }
        }

        $assembler = static function (array $scores) use ($items): array {
            $liste = [];
            foreach ($scores as $id => $nb) {
                if (isset($items[$id])) {
                    $liste[] = [
                        'item_id' => $id, 'nom' => $items[$id]['nom'], 'categorie' => $items[$id]['categorie'],
                        'image_icone' => $items[$id]['image_icone'], 'nb_possesseurs' => $nb,
                    ];
                }
            }
            return $liste;
        };

        $museeur = Bdd::authentifiee()->query(
            "SELECT u.pseudo, av.avatar_url, count(*) AS nb_au_musee
             FROM collections c
             JOIN users u ON u.id = c.user_id
             JOIN v_avatars av ON av.user_id = u.id
             WHERE c.au_musee
             GROUP BY u.pseudo, av.avatar_url
             ORDER BY nb_au_musee DESC, u.pseudo
             LIMIT 1"
        )->fetch() ?: null;

        return [
            'plus_rares'      => $assembler($rares),
            'plus_courants'   => $assembler($courants),
            'plus_gros_musee' => $museeur,
            'aujourdhui'      => Metrique::aujourdhui(),
            'trouvailles_48h' => self::trouvaillesRecentes(),
        ];
    }

    /**
     * Progression jour par jour : la requete (CTE + fonction fenetre + jointure
     * externe) vit dans PostgreSQL, voir progression_recente().
     */
    public static function progression(int $userId, int $jours): array
    {
        $requete = Bdd::authentifiee()->prepare('SELECT jour, nouveaux, cumul FROM progression_recente(:id, :jours)');
        $requete->bindValue(':id', $userId, PDO::PARAM_INT);
        $requete->bindValue(':jours', $jours, PDO::PARAM_INT);
        $requete->execute();

        return $requete->fetchAll();
    }

    /** Ce que mes amis ont et que je n'ai pas (CTE + NOT EXISTS + HAVING, cote SQL). */
    public static function itemsChezLesAmis(int $limite): array
    {
        $requete = Bdd::authentifiee()->prepare(
            'SELECT item_id, nom, categorie, image_icone, nb_amis FROM items_chez_les_amis(:limite)'
        );
        $requete->bindValue(':limite', $limite, PDO::PARAM_INT);
        $requete->execute();

        return $requete->fetchAll();
    }

    /** Trouvailles des 48 dernieres heures (fonction SQL servie par un index partiel). */
    public static function trouvaillesRecentes(int $heures = 48): int
    {
        $requete = Bdd::authentifiee()->prepare('SELECT nb_trouvailles_recentes(:h)');
        $requete->bindValue(':h', $heures, PDO::PARAM_INT);
        $requete->execute();

        return (int) $requete->fetchColumn();
    }
}
