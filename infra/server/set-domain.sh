#!/usr/bin/env bash
# Switch a OneDrop server to a new domain (DNS for <domain> and *.<domain> must already point here).
#
#   sudo /opt/onedrop/bin/set-domain onedrop.io
set -euo pipefail
export PATH="$PATH:/snap/bin:/usr/local/bin"

DOMAIN="${1:?Usage: set-domain <domain>}"
ENV_FILE=/opt/onedrop/shared/.env

set_env() { # set_env KEY VALUE
    if grep -q "^$1=" "$ENV_FILE"; then
        sed -i "s|^$1=.*|$1=$2|" "$ENV_FILE"
    else
        echo "$1=$2" >> "$ENV_FILE"
    fi
}

set_env APP_URL "https://$DOMAIN"
set_env SANDBOX_CALLBACK_URL "https://$DOMAIN"
set_env SANDBOX_GATEWAY_DOMAIN "$DOMAIN"

printf '[Service]\nEnvironment=APP_DOMAIN=%s\n' "$DOMAIN" > /etc/systemd/system/caddy.service.d/onedrop.conf
systemctl daemon-reload

cd /opt/onedrop/current
sudo -u onedrop -H php artisan config:cache
systemctl restart php8.4-fpm onedrop-queue onedrop-reverb caddy

echo "Now serving https://$DOMAIN (previews at preview-<id>.$DOMAIN). Everyone must log in again."
