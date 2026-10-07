# syntax=docker/dockerfile:1

# --- Dépendances PHP ---------------------------------------------------------
# Installation sans plugins ni scripts : les scripts (cache:clear…) ont besoin
# du code de l'application, copié plus loin, et le cache est préchauffé dans
# l'image d'exécution.
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
RUN composer dump-autoload --no-dev --classmap-authoritative --no-plugins --no-scripts

# --- Image d'exécution -------------------------------------------------------
FROM php:8.3-apache

# Extensions : intl (formats de dates et de nombres), pdo_mysql, opcache,
# gd (icônes PWA générées à partir du logo).
# Les paquets -dev ne servent qu'à la compilation : seules les bibliothèques
# utilisées par les extensions sont conservées (méthode des images officielles).
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends libicu-dev libpng-dev libjpeg62-turbo-dev; \
    docker-php-ext-configure gd --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" intl pdo_mysql opcache gd; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark; \
    find /usr/local/lib/php/extensions -name '*.so' -exec ldd '{}' ';' \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); printf "*%s\n", so }' \
        | sort -u \
        | xargs -r dpkg-query --search \
        | cut -d: -f1 \
        | sort -u \
        | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*; \
    php -m | grep -q '^intl$'; \
    php -m | grep -q '^gd$'; \
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
RUN mkdir -p var/cache var/log var/sessions public/uploads/logo public/uploads/newsletter \
    && chown -R www-data:www-data var public/uploads \
    && su -s /bin/sh www-data -c "APP_SECRET=build MAILER_DSN=null://null \
        DATABASE_URL='mysql://build:build@127.0.0.1:3306/build?serverVersion=8.4.0' \
        php bin/console cache:warmup"

VOLUME ["/var/www/html/public/uploads", "/var/www/html/var/sessions"]

ENTRYPOINT ["app-entrypoint"]
CMD ["apache2-foreground"]
