<?php
declare(strict_types=1);

final class Amitie
{
    /**
     * Toutes les relations de l'utilisateur, rangees en trois tas : amis
     * confirmes, demandes recues, demandes envoyees.
     *
     * La RLS d'amities ne laisse deja passer que les lignes ou l'utilisateur
     * est partie prenante ; le CASE sert juste a ramener le pseudo de l'autre.
     */
    public static function relations(int $moi): array
    {
        $requete = Bdd::authentifiee()->prepare(
            "SELECT autre.id AS user_id,
                    autre.pseudo,
                    av.avatar_url,
                    a.statut,
                    a.demandeur_id = :moi AS je_suis_demandeur,
                    a.demande_le
             FROM amities a
             JOIN users autre
               ON autre.id = CASE WHEN a.demandeur_id = :moi
                                  THEN a.destinataire_id ELSE a.demandeur_id END
             JOIN v_avatars av ON av.user_id = autre.id
             WHERE a.statut <> 'refusee'
             ORDER BY autre.pseudo"
        );
        $requete->execute([':moi' => $moi]);

        $relations = ['amis' => [], 'demandes_recues' => [], 'demandes_envoyees' => []];
        foreach ($requete->fetchAll() as $ligne) {
            if ($ligne['statut'] === 'acceptee') {
                $relations['amis'][] = $ligne;
            } elseif ($ligne['je_suis_demandeur']) {
                $relations['demandes_envoyees'][] = $ligne;
            } else {
                $relations['demandes_recues'][] = $ligne;
            }
        }

        return $relations;
    }

    public static function envoyer(int $moi, int $cible): void
    {
        $appel = Bdd::authentifiee()->prepare('CALL envoyer_demande_ami(:moi, :cible)');
        $appel->execute([':moi' => $moi, ':cible' => $cible]);
    }

    /** Retirer un ami ou annuler une demande envoyee. */
    public static function retirer(int $moi, int $autre): void
    {
        $appel = Bdd::authentifiee()->prepare('CALL retirer_ami(:moi, :autre)');
        $appel->execute([':moi' => $moi, ':autre' => $autre]);
    }

    /** On ne repond qu'aux demandes recues : la policy UPDATE l'impose. */
    public static function repondre(int $demandeur, int $moi, bool $accepter): void
    {
        $appel = Bdd::authentifiee()->prepare('CALL repondre_ami(:demandeur, :moi, :accepter)');
        $appel->bindValue(':demandeur', $demandeur, PDO::PARAM_INT);
        $appel->bindValue(':moi', $moi, PDO::PARAM_INT);
        $appel->bindValue(':accepter', $accepter, PDO::PARAM_BOOL);
        $appel->execute();
    }
}
