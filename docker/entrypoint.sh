#!/bin/sh
# Container startup for the app service. The whole project is bind-mounted at
# /var/www/html (see docker-compose.yml), so this script and vendor/ live on the
# host. Keeping the steps here (instead of inline in the Dockerfile CMD) avoids
# fragile backslash escaping for the namespaced seeder class.

# 1. Install PHP dependencies only if they are missing.
[ -f vendor/autoload.php ] || composer install --no-interaction --prefer-dist --no-scripts --optimize-autoloader

# 2. Run migrations (MySQL is already healthy thanks to depends_on).
php artisan migrate --force

# 3. Seed the database (roles, permissions and sample users via the default
#    DatabaseSeeder chain). Idempotent (firstOrCreate/findOrCreate), safe every boot.
php artisan db:seed --force

# 4. Start the dev server.
php artisan serve --host=0.0.0.0 --port=8000