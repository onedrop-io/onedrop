#!/usr/bin/env bash
# Deploys a OneDrop release on a server prepared by bootstrap.sh.
#
#   sudo /opt/onedrop/bin/deploy <release.tar.gz | https://... | s3://...>
#
# Keeps .env, storage and the SQLite database in /opt/onedrop/shared across releases.
set -euo pipefail
export PATH="$PATH:/snap/bin:/usr/local/bin"

RELEASE_SOURCE="${1:?Usage: deploy <release tarball path or URL>}"
APP_DOMAIN="${APP_DOMAIN:-$(grep -oP '^SANDBOX_GATEWAY_DOMAIN=\K.*' /opt/onedrop/shared/.env 2>/dev/null || true)}"
: "${APP_DOMAIN:?Set APP_DOMAIN on first deploy}"

log() { echo "==> $*"; }
set_env() { # set_env KEY VALUE
    if grep -q "^$1=" /opt/onedrop/shared/.env; then
        sed -i "s|^$1=.*|$1=$2|" /opt/onedrop/shared/.env
    else
        echo "$1=$2" >> /opt/onedrop/shared/.env
    fi
}
as_onedrop() { sudo -u onedrop -H "$@"; }

RELEASE="/opt/onedrop/releases/$(date +%Y%m%d%H%M%S)"
TARBALL="$(mktemp --suffix=.tar.gz)"

log "Fetching $RELEASE_SOURCE"
case "$RELEASE_SOURCE" in
    s3://*) aws s3 cp --only-show-errors "$RELEASE_SOURCE" "$TARBALL" ;;
    http://* | https://*) curl -fsSL "$RELEASE_SOURCE" -o "$TARBALL" ;;
    *) cp "$RELEASE_SOURCE" "$TARBALL" ;;
esac

install -d -o onedrop -g onedrop "$RELEASE"
tar -xzf "$TARBALL" -C "$RELEASE"
chown -R onedrop:onedrop "$RELEASE"
rm -f "$TARBALL"

if [ ! -f /opt/onedrop/shared/.env ]; then
    log "Creating .env for https://$APP_DOMAIN"
    cp "$RELEASE/.env.example" /opt/onedrop/shared/.env
    set_env APP_ENV production
    set_env APP_DEBUG false
    set_env APP_URL "https://$APP_DOMAIN"
    set_env DB_CONNECTION sqlite
    set_env DB_DATABASE /opt/onedrop/shared/database.sqlite
    set_env QUEUE_CONNECTION database
    set_env SESSION_DRIVER database
    set_env SESSION_SECURE_COOKIE true
    set_env MAIL_MAILER log
    set_env AUTH_VERIFY_EMAIL false
    set_env SANDBOX_PROVIDER docker
    set_env SANDBOX_DOCKER_MEMORY 1536m
    set_env SANDBOX_CALLBACK_URL "https://$APP_DOMAIN"
    set_env SANDBOX_GATEWAY_DOMAIN "$APP_DOMAIN"
    chown onedrop:onedrop /opt/onedrop/shared/.env
    chmod 600 /opt/onedrop/shared/.env
fi

# Older releases shared the login cookie with preview addresses (SESSION_DOMAIN=.<domain>), where
# sandboxed code could read it. Keep it on the app's own host, under a new name so stale copies are ignored.
if grep -q '^SESSION_DOMAIN=\.' /opt/onedrop/shared/.env 2>/dev/null; then
    log "Keeping the login cookie on $APP_DOMAIN only (everyone logs in again once)"
    sed -i '/^SESSION_DOMAIN=/d' /opt/onedrop/shared/.env
    set_env SESSION_COOKIE onedrop_session
fi

# Live updates (LIVE-001): Reverb on loopback; browsers reach it through Caddy on the app's own address.
if ! grep -q '^REVERB_APP_KEY=.' /opt/onedrop/shared/.env; then
    log "Turning on live updates (Reverb)"
    set_env BROADCAST_CONNECTION reverb
    set_env REVERB_APP_ID "$(od -An -N4 -tu4 /dev/urandom | tr -d ' ')"
    set_env REVERB_APP_KEY "$(head -c 32 /dev/urandom | sha256sum | cut -c1-20)"
    set_env REVERB_APP_SECRET "$(head -c 32 /dev/urandom | sha256sum | cut -c1-20)"
    set_env REVERB_SERVER_HOST 127.0.0.1
    set_env REVERB_SERVER_PORT 8081
    set_env REVERB_HOST 127.0.0.1
    set_env REVERB_PORT 8081
    set_env REVERB_SCHEME http
    set_env REVERB_BROWSER_HOST ""
    set_env REVERB_BROWSER_PORT 443
    set_env REVERB_BROWSER_SCHEME https
fi

# Settings → Server (ADMIN-004) can change the domain on this kind of install.
grep -q '^APP_INSTALL=' /opt/onedrop/shared/.env || set_env APP_INSTALL server

touch /opt/onedrop/shared/database.sqlite
chown onedrop:onedrop /opt/onedrop/shared/database.sqlite

log "Linking shared files"
rm -rf "$RELEASE/storage"
ln -sfn /opt/onedrop/shared/storage "$RELEASE/storage"
ln -sfn /opt/onedrop/shared/.env "$RELEASE/.env"

cd "$RELEASE"
log "Installing PHP dependencies"
as_onedrop composer install --no-dev --optimize-autoloader --no-interaction --quiet

grep -q '^APP_KEY=base64:' /opt/onedrop/shared/.env || as_onedrop php artisan key:generate --force

log "Building the front end"
as_onedrop npm ci --no-audit --no-fund --loglevel=error
as_onedrop npm run build

log "Migrating the database"
as_onedrop php artisan migrate --force
as_onedrop php artisan config:cache
as_onedrop php artisan view:cache

log "Switching to the new release"
ln -sfn "$RELEASE" /opt/onedrop/current.new
mv -T /opt/onedrop/current.new /opt/onedrop/current

# Rebuild the sandbox image only when its sources changed.
IMAGE_HASH="$(find docker/sandbox -type f -print0 | sort -z | xargs -0 cat | sha256sum | cut -c1-16)"
if [ "$(cat /opt/onedrop/shared/sandbox-image.hash 2>/dev/null || true)" != "$IMAGE_HASH" ]; then
    log "Building the sandbox image (first build takes several minutes)"
    as_onedrop php artisan sandbox:build-image
    echo "$IMAGE_HASH" > /opt/onedrop/shared/sandbox-image.hash
fi

log "Restarting services"
systemctl restart php8.4-fpm
as_onedrop php artisan queue:restart || true
systemctl restart onedrop-queue
if [ -f "$RELEASE/infra/server/onedrop-reverb.service" ]; then
    install -m 644 "$RELEASE/infra/server/onedrop-reverb.service" /etc/systemd/system/onedrop-reverb.service
    systemctl daemon-reload
    systemctl enable onedrop-reverb
    systemctl restart onedrop-reverb
fi
if [ -f "$RELEASE/infra/server/drop-scheduler.service" ]; then
    install -m 644 "$RELEASE/infra/server/drop-scheduler.service" /etc/systemd/system/drop-scheduler.service
    systemctl daemon-reload
    systemctl enable drop-scheduler
    systemctl restart drop-scheduler
fi
# Caddy reads the domain from a systemd drop-in;
# rewrite it and restart Caddy (a reload keeps the old environment) only when it changes.
CADDY_ENV="$(printf '[Service]\nEnvironment=APP_DOMAIN=%s\n' "$APP_DOMAIN")"
if [ "$(cat /etc/systemd/system/caddy.service.d/onedrop.conf 2>/dev/null)" != "$CADDY_ENV" ]; then
    mkdir -p /etc/systemd/system/caddy.service.d
    echo "$CADDY_ENV" > /etc/systemd/system/caddy.service.d/onedrop.conf
    systemctl daemon-reload
    CADDY_RESTART=1
fi
# Settings → Server (ADMIN-004): a root unit applies the domain and certificates admins save in the app.
if [ -f "$RELEASE/infra/server/apply-server-settings.sh" ]; then
    install -m 755 "$RELEASE/infra/server/apply-server-settings.sh" /opt/onedrop/bin/apply-server-settings
    install -m 755 "$RELEASE/infra/server/set-domain.sh" /opt/onedrop/bin/set-domain
    install -m 644 "$RELEASE/infra/server/onedrop-server-settings.service" /etc/systemd/system/onedrop-server-settings.service
    install -m 644 "$RELEASE/infra/server/onedrop-server-settings.path" /etc/systemd/system/onedrop-server-settings.path
    systemctl daemon-reload
    systemctl enable --now onedrop-server-settings.path
fi
# Caddy's global options from those settings (email, certificate authority); imported by the Caddyfile.
[ -f /etc/caddy/onedrop-options.caddy ] || install -m 644 /dev/null /etc/caddy/onedrop-options.caddy
# The gateway's routing and auth rules ship with the app.
if [ -f "$RELEASE/infra/server/Caddyfile" ]; then
    APP_DOMAIN="$APP_DOMAIN" caddy validate --config "$RELEASE/infra/server/Caddyfile" --adapter caddyfile >/dev/null 2>&1 \
        || { echo "The release's Caddyfile is invalid; keeping the current one."; exit 1; }
    install -m 644 "$RELEASE/infra/server/Caddyfile" /etc/caddy/Caddyfile
fi
systemctl enable --now caddy
if [ -n "${CADDY_RESTART:-}" ]; then systemctl restart caddy; else systemctl reload-or-restart caddy; fi

# Keep the five newest releases.
ls -1dt /opt/onedrop/releases/* | tail -n +6 | xargs -r rm -rf

log "Deployed $(basename "$RELEASE")"
