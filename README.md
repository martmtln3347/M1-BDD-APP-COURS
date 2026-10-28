# Nook Manager — Animal Crossing: New Horizons

Application web de suivi de collection pour *Animal Crossing: New Horizons* : chaque joueur a un compte, une collection (habitants, poissons, créatures marines, insectes, œuvres d'art), des amis, un classement et un fil d'actualité.

Projet final **Bases de données relationnelles et non relationnelles** — Mastère 1 Développement, EFREI.

| | |
| --- | --- |
| **Base relationnelle** | PostgreSQL 16 : comptes, catalogue, collections, amitiés — intégrité, Row Level Security, vues, procédures, déclencheurs |
| **Base non relationnelle** | Redis 7 (clé-valeur) : sessions, classements (ensembles triés), compteurs, notifications temporaires, cache |
| **Application** | API REST en PHP 8.3 (sans framework, sans Composer) et frontend HTML / CSS / JavaScript |
| **Infrastructure** | un seul `docker-compose.yml`, scripts de création et de peuplement, jeu de données de plus de **270 000 lignes** |

> **Pourquoi deux bases ?** PostgreSQL garde tout ce qui doit survivre et rester cohérent. Redis prend ce que PostgreSQL fait mal ou trop cher : classements en temps réel, état éphémère avec expiration, compteurs atomiques. Justification complète dans [`docs/presentation.md`](docs/presentation.md#5-justification-des-choix-techniques).

## Démarrage rapide

**Prérequis** : Docker (Docker Desktop, ou Docker Engine avec le plugin Compose). Rien d'autre : ni PHP, ni PostgreSQL, ni Redis à installer.

```bash
git clone https://github.com/martmtln3347/M1-BDD-APP-COURS.git nook-manager
cd nook-manager/docker
docker compose up -d --build
```

La première exécution construit l'image de l'application, crée le schéma, charge les données dans PostgreSQL (une dizaine de secondes), puis construit les classements dans Redis. Suivre l'avancement avec `docker compose ps` : quand `db` et `redis` sont `healthy` et que `peupler-redis` est `Exited (0)`, tout est prêt.

Aucun fichier `.env` n'est nécessaire pour une démonstration : `docker-compose.yml` fournit des valeurs par défaut (mots de passe factices, ports liés à `127.0.0.1` uniquement). Pour un autre usage, copier `docker/.env.example` en `docker/.env` et choisir de vrais mots de passe.

| | URL / accès |
| --- | --- |
| **Application (frontend + API)** | <http://localhost:8080> |
| API (exemple) | <http://localhost:8080/api/items.php?categorie=poisson> |
| PostgreSQL | `localhost:5432`, base `nook_manager` (rôle `postgres` / mot de passe de `docker-compose.yml`) |
| Redis | `localhost:6379` (mot de passe de `docker-compose.yml`) |
| **Adminer** (interface PostgreSQL) | <http://localhost:8081/?pgsql=db&username=postgres&db=nook_manager> |
| **RedisInsight** (interface Redis) | <http://localhost:5540> |

**Parcourir les deux bases à l'écran** (démonstration uniquement, liées à `127.0.0.1`) :

- **Adminer** (PostgreSQL) : serveur, utilisateur et base sont préremplis ; saisir le mot de passe `demo-superuser-a-changer`. On voit les tables, les clés, et on peut lancer du SQL.
- **RedisInsight** (Redis) : à la première ouverture, accepter ses conditions d'utilisation, puis *Add Redis database* avec hôte `redis`, port `6379`, mot de passe `demo-redis-a-changer`. On voit les clés `nook:*`, leur type (ZSET, LIST, STRING) et leur durée de vie (TTL).

Le rôle `postgres` utilisé par Adminer est le super-utilisateur : il contourne la Row Level Security, ce qui convient pour observer les données. L'application, elle, se connecte avec le rôle limité `acnh_app`.

**Un port est déjà pris ?** Les ports se changent par variable d'environnement : `APP_PORT=8082 POSTGRES_PORT=5433 REDIS_PORT=6380 docker compose up -d --build`.

**Comptes de démonstration** (mot de passe `demo1234` pour tous)

| Compte | Particularité |
| --- | --- |
| `alice@example.fr` | profil public, amie de bob |
| `bob@example.fr` | profil « amis seulement » |
| `carol@example.fr` | profil privé |
| `joueur0001@demo.example` … `joueur1500@demo.example` | 1 500 joueurs générés (70 % publics, 20 % amis, 10 % privés) |

**Arrêter / repartir de zéro** :

```bash
docker compose down        # arrête, garde les données
docker compose down -v     # arrête et SUPPRIME les données (le prochain démarrage recrée tout)
```

## Données chargées

| Base | Contenu | Volume |
| --- | --- | --- |
| PostgreSQL | `items` (catalogue du jeu) | 687 lignes |
| PostgreSQL | `users` | 1 503 |
| PostgreSQL | `collections` | ≈ 272 000 |
| PostgreSQL | `amities` | ≈ 4 500 |
| PostgreSQL | `activites` (fil d'actu) | ≈ 3 900 |
| Redis | classements (7 ZSET) + popularité | ≈ 10 000 membres |

Preuve du chargement :

```bash
docker compose exec db psql -U postgres -d nook_manager -c "select count(*) from collections"
docker compose exec redis redis-cli -a demo-redis-a-changer --no-auth-warning ZCARD nook:classement:global
```

## Pile technique

PostgreSQL 16 · Redis 7 · PHP 8.3 (PDO, serveur intégré avec routeur à liste blanche) · HTML / CSS / JavaScript sans framework · Docker Compose.

Le client Redis (protocole RESP) est écrit dans l'application (`app/modeles/RedisClient.php`) : aucune extension ni dépendance à installer.

## Endpoints principaux

Toutes les routes sont sous `/api/`. Les écritures exigent `Content-Type: application/json`.

| Route | Méthode | Base | Rôle |
| --- | --- | --- | --- |
| `inscription.php` · `connexion.php` · `deconnexion.php` | POST | PostgreSQL + Redis | compte, session (Redis), limitation des tentatives (Redis) |
| `verifier-email.php` · `mot-de-passe-oublie.php` · `reinitialiser-mot-de-passe.php` | GET / POST | PostgreSQL | cycle de vie du compte |
| `moi.php` | GET | PostgreSQL | état de la session |
| `items.php?categorie=` | GET | PostgreSQL | catalogue d'une catégorie |
| `collection.php` | GET / POST / **DELETE** | PostgreSQL (+ Redis) | lire, marquer, retirer un élément ; l'écriture met à jour le classement Redis |
| `visibilite.php` · `avatar.php` | POST (GET) | PostgreSQL | confidentialité, photo de profil |
| `compte.php` | **DELETE** | PostgreSQL + Redis | supprime le compte et nettoie Redis |
| `amis.php` · `utilisateurs.php?q=` | GET / POST | PostgreSQL (+ Redis) | amitiés ; une demande crée une notification Redis |
| `profil.php?user_id=` | GET | **PostgreSQL + Redis** | avancement (cache Redis), classement Redis filtré par la visibilité PostgreSQL |
| `classement-categorie.php?categorie=` | GET | **PostgreSQL + Redis** | classement d'une catégorie |
| `communaute.php` | GET | **PostgreSQL + Redis** | items les plus rares / courants (Redis), plus gros musée, trouvailles récentes (SQL) |
| `progression.php?jours=` | GET | PostgreSQL | requête à CTE et fonction fenêtre |
| `suggestions.php` | GET | PostgreSQL | ce que mes amis ont et que je n'ai pas |
| `comparer.php?user_id=` | GET | PostgreSQL | deux collections face à face |
| `notifications.php` | GET / POST | **Redis** | lire, supprimer une, tout effacer (CRUD sur une liste Redis) |

Erreurs gérées : ressource absente (404), données invalides (400), non connecté (401), non autorisé (403), trop de tentatives (429), service Redis indisponible pour les notifications (503).

## Tests

```bash
# Base relationnelle : RLS, privilèges, procédures, triggers (transaction annulée, ne laisse aucune trace)
docker compose exec -T db psql -U postgres -d nook_manager < ../tests/securite.sql

# API + Redis : écriture PostgreSQL puis relecture dans Redis, endpoint croisé, erreurs
../tests/smoke-api.sh                      # BASE_URL=http://127.0.0.1:8082 si le port a changé

# Mesure de l'index : EXPLAIN (ANALYZE) avant / après
docker compose exec -T db psql -U postgres -d nook_manager < ../docs/perf/index-explain.sql
```

Sauvegarde et restauration : `docker/scripts/sauvegarde.sh` et `restauration.sh` (voir le §8 de la présentation).

## Organisation du dépôt

```text
README.md                    ce fichier
docs/
  presentation.md            document de présentation (MCD/MLD/MPD, modèle Redis, justifications, requêtes, index, sauvegarde)
  schemas/                   sources Mermaid des schémas
  perf/                      mesure EXPLAIN avant / après index
  video/                     la vidéo de présentation (voir docs/video/README.md)
docker/
  docker-compose.yml         la pile complète : PostgreSQL, Redis, application, peuplement de Redis, Adminer, RedisInsight
  Dockerfile                 image de l'application
  .env.example               variables (facultatives pour une démo)
  init-sql/                  scripts PostgreSQL : schéma, vues, sécurité, données, requêtes avancées
  init-nosql/                peuplement de Redis (peupler-redis.php) et convention de clés (cles.md)
  scripts/                   sauvegarde et restauration
app/                         API PHP et frontend
  api/                       points d'entrée HTTP (un fichier par route)
  controleurs/ modeles/ vues/  MVC : requêtes, accès aux bases, mise en forme JSON et pages
  *.js, styles.css           frontend
tests/                       tests SQL et test de fumée de l'API
tools/                       générateur du catalogue à partir des données du jeu
```

## Vidéo de présentation

Voir [`docs/video/README.md`](docs/video/README.md) (la vidéo, ou le lien vers elle, et le plan de tournage).

## Document de présentation

[`docs/presentation.md`](docs/presentation.md) : projet, architecture, MCD / MLD / MPD, modèle Redis, justification des choix, exemples de requêtes, index et performance, sauvegarde, limites.

## Outils d'IA utilisés

Le développement a été assisté par **Claude (Claude Code, Anthropic)** : génération et relecture de code, scripts SQL, documentation. Chaque ligne livrée a été relue et testée, et peut être expliquée ; les choix d'architecture (répartition des données entre PostgreSQL et Redis, règles de visibilité, sécurité) sont ceux de l'auteur.

*Fan-site non officiel — Animal Crossing est une marque de Nintendo. Les images sont chargées depuis le CDN du jeu.*
