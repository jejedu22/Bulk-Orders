#!/bin/sh
set -e

# TRUSTED_HOSTS calculé depuis APP_HOST (points échappés) s'il n'est pas fourni
if [ -z "${TRUSTED_HOSTS:-}" ] && [ -n "${APP_HOST:-}" ]; then
    TRUSTED_HOSTS="^$(printf '%s' "$APP_HOST" | sed 's/\./\\./g')\$"
    export TRUSTED_HOSTS
fi

case "${MAILER_DSN:-null://null}" in
    null://*) echo "ATTENTION : MAILER_DSN non configuré, aucun e-mail ne sera envoyé." >&2 ;;
esac

console() {
    su -s /bin/sh www-data -c "php bin/console $*"
}

if [ "$1" = "apache2-foreground" ]; then
    # Les volumes montés peuvent appartenir à root
    mkdir -p var/cache var/log var/sessions public/uploads/logo public/uploads/newsletter
    chown -R www-data:www-data var public/uploads

    if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
        echo "Attente de la base de données..."
        i=0
        until console 'doctrine:query:sql "SELECT 1"' >/dev/null 2>&1; do
            i=$((i + 1))
            if [ "$i" -ge 60 ]; then
                echo "Base de données injoignable" >&2
                exit 1
            fi
            sleep 2
        done

        # Dernier nombre de la sortie : le format dépend des versions de
        # PHP/DBAL (var_dump string(1) "5" ou int(5), ou tableau)
        tables=$(console 'doctrine:query:sql "SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE()"' \
            | grep -oE '[0-9]+' | tail -n 1)

        if [ "$tables" = "0" ]; then
            # Base vide : les migrations historiques ne rejouent pas depuis zéro,
            # on crée le schéma depuis les entités et on marque les migrations comme passées.
            echo "Base vide : création du schéma"
            console "doctrine:schema:create --no-interaction"
            # Crée la table des migrations (doctrine/migrations 3) avant d'y inscrire les versions
            console "doctrine:migrations:sync-metadata-storage --no-interaction"
            console "doctrine:migrations:version --add --all --no-interaction"
        else
            # Une table de migrations au format 2.x est convertie (versions
            # préfixées par l'espace de noms) avant l'exécution des migrations
            console "doctrine:migrations:migrate --no-interaction --allow-no-migration"
        fi
    fi
fi

exec docker-php-entrypoint "$@"
