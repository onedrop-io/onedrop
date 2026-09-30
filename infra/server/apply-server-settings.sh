#!/usr/bin/env bash
# Applies the domain and certificate settings an admin saved in Settings → Server (ADMIN-004). Started as root by
# onedrop-server-settings.path whenever the app writes the request; writes back how it went for the app to show.
#
#   sudo /opt/onedrop/bin/apply-server-settings
set -euo pipefail
export PATH="$PATH:/snap/bin:/usr/local/bin"

REQUEST=/opt/onedrop/shared/storage/app/server-settings.json
STATUS=/opt/onedrop/shared/storage/app/server-settings.status.json
OPTIONS=/etc/caddy/onedrop-options.caddy
ENV_FILE=/opt/onedrop/shared/.env

[ -f "$REQUEST" ] || exit 0

# The request is written by the app (not root), so only these fields are read, and each must look right.
field() { php -r '$d = json_decode((string) file_get_contents($argv[1]), true); $v = is_array($d) ? ($d[$argv[2]] ?? "") : ""; echo is_string($v) ? $v : "";' "$REQUEST" "$1"; }
DOMAIN="$(field domain)"
EMAIL="$(field email)"
CERTIFICATES="$(field certificates)"
REQUESTED_AT="$(field requested_at)"

status() { # status OK MESSAGE
    php -r 'file_put_contents($argv[1], json_encode(["requested_at" => $argv[2], "applied_at" => date(DATE_ATOM), "ok" => $argv[3] === "1", "message" => $argv[4] === "" ? null : $argv[4]]));' \
        "$STATUS" "$REQUESTED_AT" "$1" "$2"
    chown onedrop:onedrop "$STATUS"
}
fail() { status 0 "$1"; echo "$1" >&2; exit 1; }

[[ "$DOMAIN" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]([a-z0-9-]{0,61}[a-z0-9])?$ ]] || fail "Not a domain name: $DOMAIN"
[ -z "$EMAIL" ] || [[ "$EMAIL" =~ ^[A-Za-z0-9._%+-]+@([A-Za-z0-9-]+\.)+[A-Za-z]{2,}$ ]] || fail "Not an email address: $EMAIL"
case "$CERTIFICATES" in letsencrypt | local) ;; *) fail "Unknown certificates: $CERTIFICATES" ;; esac

# Caddy's global options (imported by /etc/caddy/Caddyfile); kept aside so a bad one can be undone.
cp "$OPTIONS" "$OPTIONS.previous" 2>/dev/null || true
{
    [ -n "$EMAIL" ] && echo "email $EMAIL"
    [ "$CERTIFICATES" = local ] && echo "local_certs"
    true
} > "$OPTIONS"
if ! APP_DOMAIN="$DOMAIN" caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null 2>&1; then
    mv "$OPTIONS.previous" "$OPTIONS" 2>/dev/null || : > "$OPTIONS"
    fail "Caddy rejected the settings."
fi

CURRENT="$(grep -oP '^SANDBOX_GATEWAY_DOMAIN=\K.*' "$ENV_FILE" 2>/dev/null || true)"
if [ "$DOMAIN" != "$CURRENT" ]; then
    # Rewrites .env and Caddy's domain, then restarts the app's services.
    /opt/onedrop/bin/set-domain "$DOMAIN" || fail "Couldn't switch to $DOMAIN."
else
    systemctl reload-or-restart caddy || fail "Couldn't reload Caddy."
fi

status 1 ""
echo "Applied: $DOMAIN ($CERTIFICATES certificates)."
