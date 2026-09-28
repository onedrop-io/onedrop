#!/usr/bin/env bash
# Deploys a Zap release on a server prepared by bootstrap.sh.
#
#   sudo /opt/zap/bin/deploy <release.tar.gz | https://... | s3://...>
#
# Keeps .env, storage and the SQLite database in /opt/zap/shared across releases.
set -euo pipefail
export PATH="$PATH:/snap/bin:/usr/local/bin"

RELEASE_SOURCE="${1:?Usage: deploy <release tarball path or URL>}"
APP_DOMAIN="${APP_DOMAIN:-$(grep -oP '^SANDBOX_GATEWAY_DOMAIN=\K.*' /opt/zap/shared/.env 2>/dev/null || true)}"
: "${APP_DOMAIN:?Set APP_DOMAIN on first deploy}"

log() { echo "==> $*"; }
set_env() { # set_env KEY VALUE
    if grep -q "^$1=" /opt/zap/shared/.env; then
        sed -i "s|^$1=.*|$1=$2|" /opt/zap/shared/.env
    else
        echo "$1=$2" >> /opt/zap/shared/.env
    fi
}
as_zap() { sudo -u zap -H "$@"; }

RELEASE="/opt/zap/releases/$(date +%Y%m%d%H%M%S)"
TARBALL="$(mktemp --suffix=.tar.gz)"

log "Fetching $RELEASE_SOURCE"
case "$RELEASE_SOURCE" in
    s3://*) aws s3 cp --only-show-errors "$RELEASE_SOURCE" "$TARBALL" ;;
    http://* | https://*) curl -fsSL "$RELEASE_SOURCE" -o "$TARBALL" ;;
    *) cp "$RELEASE_SOURCE" "$TARBALL" ;;
esac

install -d -o zap -g zap "$RELEASE"
tar -xzf "$TARBALL" -C "$RELEASE"
chown -R zap:zap "$RELEASE"
rm -f "$TARBALL"

if [ ! -f /opt/zap/shared/.env ]; then
    log "Creating .env for https://$APP_DOMAIN"
    cp "$RELEASE/.env.example" /opt/zap/shared/.env
    set_env APP_ENV production
    set_env APP_DEBUG false
    set_env APP_URL "https://$APP_DOMAIN"
    set_env DB_CONNECTION sqlite
    set_env DB_DATABASE /opt/zap/shared/database.sqlite
    set_env QUEUE_CONNECTION database
    set_env SESSION_DRIVER database
    set_env SESSION_SECURE_COOKIE true
    set_env MAIL_MAILER log
    set_env AUTH_VERIFY_EMAIL false
    set_env SANDBOX_PROVIDER docker
    set_env SANDBOX_DOCKER_MEMORY 1536m
    set_env SANDBOX_CALLBACK_URL "https://$APP_DOMAIN"
    set_env SANDBOX_GATEWAY_DOMAIN "$APP_DOMAIN"
    chown zap:zap /opt/zap/shared/.env
    chmod 600 /opt/zap/shared/.env
fi

# Older releases shared the login cookie with preview addresses (SESSION_DOMAIN=.<domain>), where
# sandboxed code could read it. Keep it on the app's own host, under a new name so stale copies are ignored.
if grep -q '^SESSION_DOMAIN=\.' /opt/zap/shared/.env 2>/dev/null; then
    log "Keeping the login cookie on $APP_DOMAIN only (everyone logs in again once)"
    sed -i '/^SESSION_DOMAIN=/d' /opt/zap/shared/.env
    set_env SESSION_COOKIE zap_session
fi

touch /opt/zap/shared/database.sqlite
chown zap:zap /opt/zap/shared/database.sqlite

log "Linking shared files"
rm -rf "$RELEASE/storage"
ln -sfn /opt/zap/shared/storage "$RELEASE/storage"
ln -sfn /opt/zap/shared/.env "$RELEASE/.env"

cd "$RELEASE"
log "Installing PHP dependencies"
as_zap composer install --no-dev --optimize-autoloader --no-interaction --quiet

grep -q '^APP_KEY=base64:' /opt/zap/shared/.env || as_zap php artisan key:generate --force

log "Building the front end"
as_zap npm ci --no-audit --no-fund --loglevel=error
as_zap npm run build

log "Migrating the database"
as_zap php artisan migrate --force
as_zap php artisan config:cache
as_zap php artisan view:cache

log "Switching to the new release"
ln -sfn "$RELEASE" /opt/zap/current.new
mv -T /opt/zap/current.new /opt/zap/current

# Rebuild the sandbox image only when its sources changed.
IMAGE_HASH="$(find docker/sandbox -type f -print0 | sort -z | xargs -0 cat | sha256sum | cut -c1-16)"
if [ "$(cat /opt/zap/shared/sandbox-image.hash 2>/dev/null || true)" != "$IMAGE_HASH" ]; then
    log "Building the sandbox image (first build takes several minutes)"
    as_zap php artisan sandbox:build-image
    echo "$IMAGE_HASH" > /opt/zap/shared/sandbox-image.hash
fi

log "Restarting services"
systemctl restart php8.4-fpm
as_zap php artisan queue:restart || true
systemctl restart zap-queue
# Caddy reads the domain from a systemd drop-in. Servers installed before the rename set ZAP_DOMAIN there;
# rewrite it and restart Caddy (a reload keeps the old environment) only when it changes.
CADDY_ENV="$(printf '[Service]\nEnvironment=APP_DOMAIN=%s\n' "$APP_DOMAIN")"
if [ "$(cat /etc/systemd/system/caddy.service.d/zap.conf 2>/dev/null)" != "$CADDY_ENV" ]; then
    mkdir -p /etc/systemd/system/caddy.service.d
    echo "$CADDY_ENV" > /etc/systemd/system/caddy.service.d/zap.conf
    systemctl daemon-reload
    CADDY_RESTART=1
fi
# The gateway's routing and auth rules ship with the app.
if [ -f "$RELEASE/infra/server/Caddyfile" ]; then
    APP_DOMAIN="$APP_DOMAIN" caddy validate --config "$RELEASE/infra/server/Caddyfile" --adapter caddyfile >/dev/null 2>&1 \
        || { echo "The release's Caddyfile is invalid; keeping the current one."; exit 1; }
    install -m 644 "$RELEASE/infra/server/Caddyfile" /etc/caddy/Caddyfile
fi
systemctl enable --now caddy
if [ -n "${CADDY_RESTART:-}" ]; then systemctl restart caddy; else systemctl reload-or-restart caddy; fi

# Keep the five newest releases.
ls -1dt /opt/zap/releases/* | tail -n +6 | xargs -r rm -rf

log "Deployed $(basename "$RELEASE")"
