#!/bin/sh
# Container startup for the app service. The whole project is bind-mounted at
# /var/www/html (see docker-compose.yml), so this script and vendor/ live on the
# host. Keeping the steps here (instead of inline in the Dockerfile CMD) avoids
# fragile escaping in the CMD.

# Stop at the first failed step, so the server never starts on a broken setup.
set -eu

# 1. Make sure Laravel's writable directories exist (some are gitignored or empty
#    on a fresh clone).
mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# 2. Install PHP dependencies only if they are missing.
[ -f vendor/autoload.php ] || composer install --no-interaction --prefer-dist --no-scripts --optimize-autoloader

# 3. Create the public/storage link only if it is missing or broken. -e follows
#    the link, so a working link is left alone and storage:link replaces a broken one.
[ -e public/storage ] || php artisan storage:link

# 4. Run migrations (MySQL is already healthy thanks to depends_on).
php artisan migrate --force

# 5. Run all database seeders via the default DatabaseSeeder chain (roles,
#    permissions, users, categories and products). Idempotent (firstOrCreate/
#    findOrCreate), so it is safe on every boot.
php artisan db:seed --force

# 6. Start the dev server. --no-reload makes it pass the container's environment
#    (docker-compose.yml) to the PHP server process. Without it, artisan serve
#    drops variables such as WAREHOUSE_GRPC_TARGET and the server falls back to
#    .env, where the Warehouse is 127.0.0.1:50051 (nothing listens there inside
#    the container). Side effect: after editing .env, restart the container.
php artisan serve --host=0.0.0.0 --port=8000 --no-reload
