# syntax=docker/dockerfile:1
# OneDrop in one container: the web app, its queue worker and a SQLite database in the /data volume.
# Projects run in sibling sandbox containers started through the host's Docker socket.
# Published as ghcr.io/onedrop-io/onedrop by .github/workflows/images.yml; installed by install.sh.

FROM dunglas/frankenphp:1-php8.4 AS base
RUN install-php-extensions bcmath intl pcntl pdo_pgsql zip
COPY --from=docker:29-cli /usr/local/bin/docker /usr/local/bin/docker
WORKDIR /app

FROM base AS build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund --loglevel=error
COPY . .
# The front-end build runs `php artisan wayfinder:generate`, so PHP dependencies come first.
RUN composer dump-autoload --optimize --no-dev \
    && npm run build \
    && rm -rf node_modules storage \
    && ln -s /data/storage storage

FROM base
COPY --from=build /app /app
COPY docker/app/entrypoint.sh /usr/local/bin/drop-entrypoint
# Server mode only (APP_DOMAIN set): HTTPS, and previews/shells through the gateway.
COPY docker/app/Caddyfile /etc/drop/Caddyfile

ENV APP_ENV=production \
    APP_DEBUG=false \
    APP_URL=http://localhost:8000 \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/database.sqlite \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=database \
    MAIL_MAILER=log \
    AUTH_VERIFY_EMAIL=false \
    SANDBOX_PROVIDER=docker \
    SANDBOX_DOCKER_IMAGE=ghcr.io/onedrop-io/onedrop-sandbox:latest \
    SANDBOX_DOCKER_NETWORK=drop \
    SANDBOX_DOCKER_STORAGE_PATH=(empty) \
    SANDBOX_CALLBACK_URL=http://drop:8000

VOLUME /data
EXPOSE 8000 80 443 443/udp
HEALTHCHECK --interval=10s --timeout=5s --start-period=60s CMD curl -fsS http://127.0.0.1:8000/up >/dev/null || exit 1
ENTRYPOINT ["drop-entrypoint"]
