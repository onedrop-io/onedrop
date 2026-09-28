#!/usr/bin/env bash
# Installs Zap on a fresh Ubuntu 24.04 server (x86_64 or arm64), then deploys a release.
#
#   sudo APP_DOMAIN=zap.example.com APP_RELEASE=/tmp/zap.tar.gz bash bootstrap.sh
#
# APP_RELEASE may be a local path, an https:// URL, or an s3:// URL (needs the AWS CLI + credentials).
# Safe to re-run: every step checks before changing anything.
set -euo pipefail
export PATH="$PATH:/snap/bin:/usr/local/bin"

: "${APP_DOMAIN:?Set APP_DOMAIN, e.g. zap.example.com or 1-2-3-4.sslip.io}"
: "${APP_RELEASE:?Set APP_RELEASE to a release tarball path or URL}"

export DEBIAN_FRONTEND=noninteractive
# cloud-init and SSM run without a home directory; Composer and npm need one.
export HOME="${HOME:-/root}" COMPOSER_ALLOW_SUPERUSER=1
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
log() { echo "==> $*"; }

log "System packages"
apt-get update -q
apt-get install -y -q ca-certificates curl gnupg git unzip sqlite3 software-properties-common

if ! command -v php8.4 >/dev/null; then
    log "PHP 8.4"
    add-apt-repository -y ppa:ondrej/php
    apt-get update -q
    apt-get install -y -q php8.4-cli php8.4-fpm php8.4-sqlite3 php8.4-mbstring php8.4-xml \
        php8.4-curl php8.4-zip php8.4-intl php8.4-bcmath
fi

if ! command -v composer >/dev/null; then
    log "Composer"
    curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

if ! command -v node >/dev/null || ! node --version | grep -q '^v22'; then
    log "Node.js 22"
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
    apt-get install -y -q nodejs
fi

if ! command -v docker >/dev/null; then
    log "Docker"
    curl -fsSL https://get.docker.com | sh
fi

if ! command -v caddy >/dev/null; then
    log "Caddy"
    curl -1sLf https://dl.cloudsmith.io/public/caddy/stable/gpg.key | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
    curl -1sLf https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt > /etc/apt/sources.list.d/caddy-stable.list
    apt-get update -q
    apt-get install -y -q caddy
fi

if ! command -v tailscale >/dev/null; then
    log "Tailscale (not logged in; run 'sudo tailscale up' to join your tailnet)"
    curl -fsSL https://tailscale.com/install.sh | sh
fi

log "zap user and directories"
id zap >/dev/null 2>&1 || useradd --system --create-home --home-dir /opt/zap --shell /bin/bash zap
usermod -aG docker zap
# Caddy must pass through /opt/zap to serve public/; 751 lets it traverse without listing. Secrets stay 600.
chmod 751 /opt/zap
install -d -o zap -g zap /opt/zap/releases /opt/zap/shared /opt/zap/shared/storage /opt/zap/bin
for dir in app/public framework/cache/data framework/sessions framework/views framework/testing logs; do
    install -d -o zap -g zap "/opt/zap/shared/storage/$dir"
done
# install -d only owns the last directory; the app must be able to write to the parents too.
chown -R zap:zap /opt/zap/shared/storage

log "PHP-FPM pool (runs as zap so the app can drive Docker)"
install -m 644 "$HERE/php-fpm-zap.conf" /etc/php/8.4/fpm/pool.d/zap.conf
rm -f /etc/php/8.4/fpm/pool.d/www.conf

log "Queue worker service"
install -m 644 "$HERE/zap-queue.service" /etc/systemd/system/zap-queue.service
systemctl daemon-reload
systemctl enable zap-queue

log "Caddy config"
install -m 644 "$HERE/Caddyfile" /etc/caddy/Caddyfile
mkdir -p /etc/systemd/system/caddy.service.d
printf '[Service]\nEnvironment=APP_DOMAIN=%s\n' "$APP_DOMAIN" > /etc/systemd/system/caddy.service.d/zap.conf
systemctl daemon-reload

install -m 755 "$HERE/deploy.sh" /opt/zap/bin/deploy
install -m 755 "$HERE/set-domain.sh" /opt/zap/bin/set-domain
APP_DOMAIN="$APP_DOMAIN" /opt/zap/bin/deploy "$APP_RELEASE"

log "Done. Open https://$APP_DOMAIN, sign up, then make yourself an admin:"
echo "    sudo -u zap php /opt/zap/current/artisan zap:admin you@example.com"
