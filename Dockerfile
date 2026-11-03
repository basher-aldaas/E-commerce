FROM php:8.3-cli

# System packages needed to build the PHP extensions this app uses.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath zip pcntl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Composer from the official image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Don't let Composer kill long-running installs on a slow connection.
ENV COMPOSER_PROCESS_TIMEOUT=0

WORKDIR /var/www/html

EXPOSE 8000

# The whole project (including vendor/) is bind-mounted at runtime
# (see docker-compose.yml), so dependencies are NOT installed into the image —
# that would just be shadowed by the mount. Startup steps (install deps if
# missing, migrate, seed roles/permissions, serve) live in docker/entrypoint.sh.
CMD ["sh", "docker/entrypoint.sh"]