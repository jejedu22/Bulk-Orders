# syntax=docker/dockerfile:1

# --- Dépendances PHP ---------------------------------------------------------
# composer.lock a été généré avec Composer 1 et symfony/flex 1.6 (incompatible
# avec Composer 2) : on installe donc sans plugins ni scripts.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-plugins --no-scripts --no-autoloader \
        --no-interaction --no-progress --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-plugins --no-scripts

# --- Image d'exécution -------------------------------------------------------
FROM php:7.4-apache

# PHP 7.4 n'existe que sur Debian bullseye : si ses dépôts ont été déplacés
# vers archive.debian.org, on bascule dessus.
RUN set -eux; \
    if ! apt-get update; then \
        sed -i -e 's|deb.debian.org|archive.debian.org|g' -e '/bullseye-updates/d' /etc/apt/sources.list; \
        apt-get update; \
    fi; \
    apt-get install -y --no-install-recommends libicu-dev; \
    docker-php-ext-install -j"$(nproc)" intl pdo_mysql opcache; \
    apt-get purge -y --auto-remove libicu-dev; \
    apt-get install -y --no-install-recommends libicu67; \
    rm -rf /var/lib/apt/lists/*; \
    a2enmod rewrite headers remoteip

COPY docker/php/app.ini "$PHP_INI_DIR/conf.d/app.ini"
COPY docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint

WORKDIR /var/www/html
ENV APP_ENV=prod \
    APP_DEBUG=0

COPY --from=vendor --chown=www-data:www-data /app /var/www/html

# Préchauffage du cache prod. Les %env()% sont résolus à l'exécution :
# des valeurs factices suffisent ici.
RUN mkdir -p var/cache var/log var/sessions public/uploads/logo \
    && chown -R www-data:www-data var public/uploads \
    && su -s /bin/sh www-data -c "APP_SECRET=build MAILER_DSN=null://null \
        DATABASE_URL='mysql://build:build@127.0.0.1:3306/build?serverVersion=8.0' \
        php bin/console cache:warmup"

VOLUME ["/var/www/html/public/uploads", "/var/www/html/var/sessions"]

ENTRYPOINT ["app-entrypoint"]
CMD ["apache2-foreground"]
