# Redis — conventions de clés et stratégie de durée de vie

Convention : `nook:<domaine>:<identifiant>[:<qualificatif>]`, en minuscules, séparateur `:`.
Chaque domaine a son préfixe, ce qui permet de lister (`SCAN 0 MATCH nook:notif:* COUNT 100`) ou de purger un domaine entier.

| Clé | Structure | Écrite par | Expiration | Invalidation |
| --- | --- | --- | --- | --- |
| `nook:session:<id>` | STRING | `SessionRedis::write` | 2 h glissantes (`EXPIRE` à chaque requête) | `DEL` à la déconnexion |
| `nook:limite:<cle>` | STRING (entier) | `Securite::noter` (`INCR`) | 15 min ou 1 h, posée à la 1re tentative | `DEL` après une connexion réussie |
| `nook:stats:jour:AAAAMMJJ` | HASH | `Metrique::compter` (`HINCRBY`) | 8 jours | expire seule |
| `nook:classement:global` | ZSET | `Classement::apresMarquage`, `peupler-redis.php` | aucune | reconstruit par `peupler-redis` ; `ZREM` à la suppression d'un compte |
| `nook:classement:cat:<categorie>` | ZSET | idem | aucune | idem |
| `nook:popularite:items` | ZSET | `ZINCRBY ±1` à chaque (dé)possession | aucune | reconstruit par `peupler-redis` |
| `nook:notif:<user_id>` | LIST | `Notification::creer` (`LPUSH` + `LTRIM` + `EXPIRE`) | 7 jours | `LREM` / `DEL` par l'utilisateur ; `DEL` à la suppression du compte |
| `nook:cache:stats:<user_id>` | STRING (JSON) | `Statistiques::parCategorieEnCache` | **120 s** | `DEL` à chaque écriture de collection |
| `nook:cache:totaux` | STRING (JSON) | `Classement::totaux` | **1 h** | `DEL` par `peupler-redis` |

## Stratégie d'invalidation

- **Index dérivés (ZSET)** : mis à jour en *write-through* après chaque écriture acceptée par PostgreSQL (le score est recalculé depuis PostgreSQL puis `ZADD`, donc idempotent). `peupler-redis` les reconstruit entièrement si on doute de leur état.
- **Caches** : TTL court + invalidation explicite. Le TTL borne l'erreur si l'invalidation échoue.
- **État éphémère** : aucune invalidation, l'expiration *est* la politique.

## Pourquoi tel type de structure

| Besoin | Structure | Raison |
| --- | --- | --- |
| Top-N, rang d'un joueur | ZSET | tri maintenu à chaque écriture ; `ZREVRANGE` et `ZREVRANK` en O(log n) |
| Plusieurs compteurs par jour | HASH | un champ par événement, `HINCRBY` atomique, une seule clé à faire expirer |
| Boîte de notifications ordonnée, plafonnée | LIST | `LPUSH` + `LTRIM` : les plus récentes en tête, taille bornée |
| Compteur de tentatives | STRING + `INCR` | atomique, et l'`EXPIRE` fait office de fenêtre |

## Commandes utiles

```text
KEYS nook:*                              # tout (à éviter en production : préférer SCAN)
ZREVRANGE nook:classement:global 0 9 WITHSCORES
ZREVRANK nook:classement:global 1
TTL nook:notif:20
LRANGE nook:notif:20 0 -1
HGETALL nook:stats:jour:20261020
```
