# syntax=docker/dockerfile:1

# --- Dépendances PHP ---------------------------------------------------------
# composer.lock a été généré avec Composer 1 et symfony/flex 1.6 (incompatible
# avec Composer 2) : on installe donc sans plugins ni scripts.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock symfony.lock ./
# Cache Composer conservé entre les builds (utile quand composer.lock change)
RUN --mount=type=cache,target=/tmp/cache \
    composer install --no-dev --no-plugins --no-scripts --no-autoloader \
        --no-interaction --no-progress --prefer-dist --ignore-platform-reqs
# Seul src/ est nécessaire pour générer la classmap : modifier un template
# ou un asset ne relance pas cette étape.
COPY src/ src/
# ocramius/package-versions 1.x (plugin non exécuté) ne sait pas lire le
# installed.json de Composer 2 et fait planter doctrine:migrations:migrate :
# sans ce fichier, il se rabat sur composer.lock.
RUN composer dump-autoload --no-dev --classmap-authoritative --no-plugins --no-scripts \
    && rm vendor/composer/installed.json

# --- Image d'exécution -------------------------------------------------------
FROM php:7.4-apache

# PHP 7.4 n'existe que sur Debian bullseye (fin de support). L'image fournit
# déjà libicu67 : seul libicu-dev est nécessaire, pris dans bullseye main
# (bullseye-security référence des paquets retirés du miroir). Si le miroir
# principal ne le sert plus, on bascule sur archive.debian.org.
RUN set -eux; \
    install_icu_dev() { \
        apt-get update && apt-get install -y --no-install-recommends libicu-dev; \
    }; \
    echo 'deb http://deb.debian.org/debian bullseye main' > /etc/apt/sources.list; \
    if ! install_icu_dev; then \
        echo 'deb http://archive.debian.org/debian bullseye main' > /etc/apt/sources.list; \
        install_icu_dev; \
    fi; \
    apt-mark manual libicu67; \
    docker-php-ext-install -j"$(nproc)" intl pdo_mysql opcache; \
    apt-get purge -y --auto-remove libicu-dev; \
    rm -rf /var/lib/apt/lists/*; \
    a2enmod rewrite headers remoteip

COPY docker/php/app.ini "$PHP_INI_DIR/conf.d/app.ini"
COPY docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/apache/mpm_prefork.conf /etc/apache2/mods-available/mpm_prefork.conf
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint

WORKDIR /var/www/html
ENV APP_ENV=prod \
    APP_DEBUG=0

# vendor/ d'abord (change rarement), puis le code de l'application
COPY --from=vendor /app/vendor vendor/
COPY . .

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
