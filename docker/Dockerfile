# Application Nook Manager : PHP 8.3 + PDO PostgreSQL, serveur integre de PHP
# avec routeur a liste blanche (router.php). Aucune dependance Composer : le
# client Redis est ecrit dans l'application (app/modeles/RedisClient.php).
FROM php:8.3-cli-alpine

RUN apk add --no-cache postgresql-dev \
    && docker-php-ext-install pdo_pgsql

WORKDIR /var/www/html
COPY app/ /var/www/html/
COPY docker/init-nosql/ /opt/init-nosql/

# Utilisateur sans privileges : l'application n'a pas besoin d'etre root.
RUN adduser -D -H appuser
USER appuser

EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "router.php"]
