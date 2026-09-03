# Laravel API service. Uses `php artisan serve` rather than php-fpm+nginx --
# a deliberate hackathon-scope simplification (documented here, not hidden):
# for a real production deployment, put php-fpm behind nginx or swap in
# Octane/FrankenPHP instead of the built-in dev server. This same image is
# reused for the queue worker and scheduler containers in compose.yml, each
# overriding CMD.
FROM php:8.3-cli-bookworm

# $PHPIZE_DEPS (gcc/make/autoconf/etc., provided by the official php image)
# is only needed to compile the `redis` PECL extension -- purged afterward
# so it doesn't bloat the final image. `.env`/config/database.php set
# REDIS_CLIENT=phpredis (the native extension), not predis, so this is
# required, not optional -- without it, cache/queue/session all fail at
# runtime the moment they touch Redis (compose.yml sets CACHE_STORE,
# QUEUE_CONNECTION, and SESSION_DRIVER all to `redis`).
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libzip-dev libonig-dev $PHPIZE_DEPS \
    && docker-php-ext-install pdo_pgsql pcntl bcmath zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist

COPY backend/ ./
RUN composer dump-autoload --optimize \
    && php artisan config:clear

COPY docker/laravel-entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 8000
ENTRYPOINT ["/entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
