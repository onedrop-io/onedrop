#!/usr/bin/env bash
# Keeps the preview server running: the app's own dev server once the agent has
# created /workspace/.zap/dev, otherwise a placeholder page.
# `/opt/zap/restart` kills the current server; this loop starts the right one again.
# Also keeps a web terminal (ttyd) running on $SHELL_PORT for the Shell tab.
set -uo pipefail

touch /tmp/zap-server.log

# Host-rewriting proxy in front of the app, used by published URLs.
(
    while true; do
        node /opt/zap/host-proxy.mjs >>/tmp/zap-proxy.log 2>&1
        sleep 1
    done
) &

(
    while true; do
        ttyd --writable --port "${SHELL_PORT}" --cwd /workspace \
            -t fontSize=13 -t rendererType=dom -t 'theme={"background":"#0a0a0a"}' -t disableLeaveAlert=true \
            bash >/dev/null 2>&1
        sleep 1
    done
) &

while true; do
    if [ -x /workspace/.zap/dev ]; then
        setsid bash -c 'cd /workspace && exec /workspace/.zap/dev' >>/tmp/zap-server.log 2>&1 &
    else
        setsid php -S "0.0.0.0:${PORT}" /opt/zap/placeholder/index.php >>/tmp/zap-server.log 2>&1 &
    fi

    echo $! > /tmp/zap-server.pid
    wait $!
    sleep 1
done
