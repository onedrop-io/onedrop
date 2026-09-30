#!/usr/bin/env bash
# Starts OneDrop in its container (see /Dockerfile): first-boot setup, then the queue worker and web server.
set -euo pipefail
cd /app

log() { echo "[drop] $*"; }

# Settings → Server (ADMIN-004) shows how to change this install's domain with `drop`.
export APP_INSTALL=container

# Everything worth keeping lives in /data: the database, settings (.env) and storage.
mkdir -p /data/storage/app/public /data/storage/app/private /data/storage/logs \
    /data/storage/framework/cache/data /data/storage/framework/sessions /data/storage/framework/views
if [ ! -f /data/.env ]; then
    log "First start: creating /data/.env"
    echo "APP_KEY=base64:$(head -c 32 /dev/urandom | base64)" > /data/.env
    chmod 600 /data/.env
fi
ln -sfn /data/.env /app/.env
touch /data/database.sqlite

# Server mode (INSTALL-002): HTTPS at APP_DOMAIN, previews and shells through the gateway, sandboxes reached by
# name on the drop network, and a setup link for the first account.
if [ -n "${APP_DOMAIN:-}" ]; then
    export APP_URL="https://$APP_DOMAIN" SANDBOX_GATEWAY_DOMAIN="$APP_DOMAIN" SANDBOX_DOCKER_REACH=network \
        SESSION_SECURE_COOKIE=true
    if ! grep -q '^SETUP_TOKEN=' /data/.env; then
        echo "SETUP_TOKEN=$(head -c 24 /dev/urandom | base64 | tr -d '/+=')" >> /data/.env
    fi
fi

if ! docker info >/dev/null 2>&1; then
    log "Warning: can't reach Docker. Start this container with -v /var/run/docker.sock:/var/run/docker.sock so projects can run."
elif ! docker image inspect "$SANDBOX_DOCKER_IMAGE" >/dev/null 2>&1; then
    log "Pulling the sandbox image $SANDBOX_DOCKER_IMAGE in the background"
    (docker pull --quiet "$SANDBOX_DOCKER_IMAGE" >/dev/null && log "Sandbox image ready") &
fi

php artisan migrate --force --no-interaction
php artisan optimize --no-interaction >/dev/null

# The queue worker creates sandboxes and runs agents; restart it whenever it exits.
(while true; do php artisan queue:work --tries=1 --timeout=0 --sleep=1 || true; sleep 1; done) &

# The scheduler: idle sandboxes (SBX-007), server monitoring samples (ADMIN-003) and database backups (ADMIN-005).
(while true; do php artisan schedule:work || true; sleep 1; done) &

if [ -n "${APP_DOMAIN:-}" ]; then
    log "OneDrop is running at https://$APP_DOMAIN"
    exec frankenphp run --config /etc/drop/Caddyfile --adapter caddyfile
fi

log "OneDrop is running on port 8000"
exec frankenphp php-server --root /app/public --listen :8000
