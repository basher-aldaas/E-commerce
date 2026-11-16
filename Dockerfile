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

# The gRPC extension the Warehouse client needs (grpc/grpc requires ext-grpc).
# It is compiled from source, so the first build takes several minutes;
# later builds reuse the cached layer.
COPY --from=mlocati/php-extension-installer:2.7 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions grpc

# GD, used by UploadedFile::fake()->image() in the tests. A separate layer so
# adding it doesn't recompile grpc.
RUN install-php-extensions gd

# phpredis, for the Redis cache store (CACHE_STORE=redis in docker-compose.yml).
RUN install-php-extensions redis

# OPcache for the CLI SAPI, which is what `php artisan serve` runs under. Off by
# default, so every request recompiled every PHP file from the bind mount.
#
# PHP_OPCACHE_VALIDATE_TIMESTAMPS (read when PHP starts):
#   1 = check every file's timestamp on each request, so code edits apply at once.
#       On a Windows bind mount each check costs ~1 ms per file (~1-2 s/request).
#   0 = never check: stable ~0.25 s requests, but restart the container
#       (`docker compose restart app`) after changing PHP code.
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=1
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=1'; \
        echo 'opcache.validate_timestamps=${PHP_OPCACHE_VALIDATE_TIMESTAMPS}'; \
        echo 'opcache.revalidate_freq=0'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.max_accelerated_files=20000'; \
    } > /usr/local/etc/php/conf.d/zz-opcache.ini

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