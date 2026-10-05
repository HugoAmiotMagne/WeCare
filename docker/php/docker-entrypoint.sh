#!/bin/sh
set -e

if [ "$1" = 'php-fpm' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
    # En dev, vendor/ est un volume : on s'assure qu'il est à jour avec composer.lock
    if [ "$APP_ENV" != 'prod' ]; then
        composer install --prefer-dist --no-progress --no-interaction
    fi

    # Attente de la base de données (MariaDB peut mettre quelques secondes à démarrer)
    ATTEMPTS_LEFT=60
    until php bin/console dbal:run-sql -q "SELECT 1" > /dev/null 2>&1; do
        ATTEMPTS_LEFT=$((ATTEMPTS_LEFT - 1))
        if [ "$ATTEMPTS_LEFT" -le 0 ]; then
            echo 'Base de données injoignable.' >&2
            php bin/console dbal:run-sql "SELECT 1"
            exit 1
        fi
        echo "En attente de la base de données... ($ATTEMPTS_LEFT)"
        sleep 1
    done

    # Migrations versionnées : idempotent, ne rejoue que les migrations manquantes
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

    # var/ doit être accessible en écriture à la fois par root (CLI) et www-data (FPM)
    mkdir -p var/cache var/log
    setfacl -R -m u:www-data:rwX -m u:"$(whoami)":rwX var
    setfacl -dR -m u:www-data:rwX -m u:"$(whoami)":rwX var
fi

exec "$@"
