# Plan de tournage — 10 à 11 minutes

Les consignes demandent de **montrer en direct** (pas de diapositives) : démarrage à vide, peuplement avec preuve, application, écriture puis relecture dans les bases, lecture croisée, requêtes avancées commentées, choix techniques. Ce plan suit la grille d'évaluation (3 points : démarrage et peuplement, application et interactions, requêtes et choix).

**Préparation** : fenêtre de terminal à police agrandie, navigateur à côté, `docker compose down -v` fait juste avant d'enregistrer (état vierge), deux terminaux ouverts dans `docker/` (un pour `psql`, un pour `redis-cli`).

| Durée | Étape | À montrer et à dire |
| --- | --- | --- |
| 0:00 – 0:45 | **Le projet** | Le but de l'application, les deux bases et ce que chacune porte (tableau du §5 de la présentation, 20 secondes). |
| 0:45 – 2:15 | **Démarrage à vide** | `docker compose down -v` puis `docker compose up -d --build`. Montrer `docker compose ps` : `db` et `redis` passent `healthy`, `peupler-redis` finit `Exited (0)`. Dire ce que chaque service fait. |
| 2:15 – 3:45 | **Preuve du peuplement** | **PostgreSQL** : `select count(*) from users / items / collections / amities`, puis un aperçu (`select * from items limit 3`, `\d collections`). **Redis** : `DBSIZE`, `ZCARD nook:classement:global`, `ZREVRANGE nook:classement:global 0 2 WITHSCORES`, `TYPE` de quelques clés. Montrer en 15 s le journal de `peupler-redis` (`docker compose logs peupler-redis`). |
| 3:45 – 5:45 | **L'application et une écriture qui traverse les deux bases** | Connexion en `alice@example.fr`. Ouvrir « Poissons », cocher « Je l'ai » sur un poisson. **Aussitôt** : dans `psql`, `select * from collections where user_id = 1 and item_id = …` (la ligne est là) ; dans `redis-cli`, `ZSCORE nook:classement:global 1` (le score a monté de 1). Décocher puis `DELETE` : le score redescend. |
| 5:45 – 7:00 | **Lecture croisée** | Page Profil : le classement. Expliquer le chemin : `ZREVRANGE` dans Redis, puis `peut_voir_profil()` dans PostgreSQL. Montrer que **carol** (profil privé) est absente alors qu'elle est classée dans Redis (`ZSCORE nook:classement:global <id de carol>`). Se connecter en `bob` : il voit des profils différents. |
| 7:00 – 8:00 | **Redis comme base à part entière** | Envoyer une demande d'ami (page Amis). `redis-cli` : `LRANGE nook:notif:<id>`, `TTL` (≈ 7 jours), `TYPE`. Se connecter avec le destinataire : la notification apparaît, la supprimer (`LREM`). Montrer `HGETALL nook:stats:jour:…` et le TTL d'une session (`KEYS nook:session:*`, `TTL`). |
| 8:00 – 9:30 | **Requêtes avancées SQL** | Dans `psql` : (1) `v_classement_musee` — `GROUP BY` / `HAVING` ; (2) `progression_recente(1, 7)` — CTE, `LEFT JOIN`, fonction fenêtre ; (3) `items_chez_les_amis` — CTE, `NOT EXISTS`. Pour chacune : l'intention, puis le résultat. |
| 9:30 – 10:15 | **Index et sécurité côté base** | `\i docs/perf/index-explain.sql` (ou le coller) : montrer `Seq Scan` 8,7 ms → `Index Only Scan` 0,4 ms. Puis, en 30 s : `\i tests/securite.sql` (« Tous les tests passent ») ou le scénario RLS (rôle applicatif sans droit de lecture sur l'email). |
| 10:15 – 11:00 | **Les choix** | Pourquoi PostgreSQL pour le cœur (intégrité, RLS) ; pourquoi Redis (classement en O(log n), TTL natifs, compteurs atomiques) ; qui est la source de vérité ; que se passe-t-il si Redis tombe (repli) ; la sauvegarde (`sauvegarde.sh`). Conclure sur les limites (cohérence à terme). |

**Conseils**

- Avant d'enregistrer, répéter une fois : les commandes `redis-cli` avec mot de passe sont longues, préparer des alias (`alias rd='docker compose exec redis redis-cli -a demo-redis-a-changer --no-auth-warning'`).
- Agrandir la police du terminal et le zoom du navigateur : les consignes exigent un écran lisible.
- Une vidéo qui ne montre que des diapositives ne vaut pas les points de démonstration : chaque affirmation doit être suivie d'une commande exécutée.
- Exporter en MP4, vérifier la voix, déposer dans `docs/video/` (ou héberger et lier si > 100 Mo).
