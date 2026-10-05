# syntax=docker/dockerfile:1

# ─────────────────────────────────────────────────────────────
#  Image de base : PHP 8.4 FPM + extensions nécessaires à Symfony
# ─────────────────────────────────────────────────────────────
FROM php:8.4-fpm AS app_base

WORKDIR /app

# acl : permissions partagées sur var/ entre root (CLI) et www-data (FPM)
RUN apt-get update \
    && apt-get install -y --no-install-recommends acl git unzip \
    && rm -rf /var/lib/apt/lists/*

# install-php-extensions gère les dépendances système de chaque extension
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql intl opcache zip

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY docker/php/conf.d/app.ini $PHP_INI_DIR/conf.d/
COPY docker/php/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
# sed : protège contre les fins de ligne Windows (CRLF) si le dépôt est cloné sous Windows
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint && chmod +x /usr/local/bin/docker-entrypoint

ENTRYPOINT ["docker-entrypoint"]
CMD ["php-fpm"]

# ─────────────────────────────────────────────────────────────
#  Développement : dépendances dev (fixtures, phpunit, profiler)
#  Le code source est monté en volume par compose.override.yaml
# ─────────────────────────────────────────────────────────────
FROM app_base AS app_dev

ENV APP_ENV=dev
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .
RUN cp .env.example .env \
    && composer dump-autoload \
    && composer run-script auto-scripts --no-interaction

# ─────────────────────────────────────────────────────────────
#  Production : sans dépendances dev, autoload optimisé, assets compilés
# ─────────────────────────────────────────────────────────────
FROM app_base AS app_prod

ENV APP_ENV=prod
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/conf.d/app.prod.ini $PHP_INI_DIR/conf.d/

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .
# .env n'est jamais copié (.dockerignore) : on part du modèle, les vraies
# valeurs arrivent par les variables d'environnement du conteneur
RUN cp .env.example .env \
    && composer dump-autoload --classmap-authoritative --no-dev \
    && composer run-script auto-scripts --no-interaction \
    && php bin/console asset-map:compile \
    && chown -R www-data:www-data var

# ─────────────────────────────────────────────────────────────
#  Caddy (prod) : embarque les fichiers publics compilés
# ─────────────────────────────────────────────────────────────
FROM caddy:2.10 AS caddy_prod

COPY docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY --from=app_prod /app/public /app/public
