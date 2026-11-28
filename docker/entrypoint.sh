#!/bin/sh

set -eu

MODE="${1:-all}"

mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

if [ "$MODE" != "serve" ]; then
    [ -f vendor/autoload.php ] || composer install --no-interaction --prefer-dist --no-scripts --optimize-autoloader
    [ -e public/storage ] || php artisan storage:link
    php artisan migrate --force
    php artisan app:seed-if-empty
fi

if [ "$MODE" = "init" ]; then
    exit 0
fi

php artisan serve --host=0.0.0.0 --port=8000 --no-reload
