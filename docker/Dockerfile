# Use the Node image only as the source of a current Node runtime. The asset
# build also runs `php artisan` through the Wayfinder Vite plugin.
FROM node:22-bookworm-slim AS node

FROM php:8.3-cli-bookworm

# Install only the system libraries needed by Laravel and the PHP extensions.
RUN apt-get update \
    && apt-get install --yes --no-install-recommends \
        git \
        libonig-dev \
        libpq-dev \
        libstdc++6 \
        libzip-dev \
        unzip \
        zip \
    && docker-php-ext-install -j"$(nproc)" bcmath mbstring pdo pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

WORKDIR /var/www/html

COPY . .

# These directories are ignored from the build context, but Laravel creates
# package manifests and runtime files inside them during Composer installation.
RUN mkdir -p \
        bootstrap/cache \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && chmod -R ug+rwx bootstrap/cache storage

# Production containers do not need test and development dependencies.
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && npm ci \
    && npm run build \
    && rm -rf node_modules

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
