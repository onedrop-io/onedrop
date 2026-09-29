#!/usr/bin/env bash
# Starts OneDrop in its container (see /Dockerfile): first-boot setup, then the queue worker and web server.
set -euo pipefail
cd /app

log() { echo "[drop] $*"; }

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

log "OneDrop is running on port 8000"
exec frankenphp php-server --root /app/public --listen :8000
