#!/usr/bin/env bash
# Keeps the preview server running: the app's own dev server once the agent has
# created /workspace/.zap/dev, otherwise a placeholder page.
# `/opt/zap/restart` kills the current server; this loop starts the right one again.
# Also keeps a web terminal (ttyd) running on $SHELL_PORT for the Shell tab, and sshd on $SSH_PORT.
set -uo pipefail

touch /tmp/zap-server.log

# Host-rewriting proxy in front of the app, used by published URLs.
(
    while true; do
        node /opt/zap/host-proxy.mjs >>/tmp/zap-proxy.log 2>&1
        sleep 1
    done
) &

# Shell tab colors: One Dark on the workspace's near-black background.
SHELL_THEME='{"background":"#0a0a0a","foreground":"#abb2bf","cursor":"#528bff","cursorAccent":"#0a0a0a","selectionBackground":"#3e4451",'
SHELL_THEME+='"black":"#3f4451","red":"#e06c75","green":"#98c379","yellow":"#e5c07b","blue":"#61afef","magenta":"#c678dd","cyan":"#56b6c2","white":"#d7dae0",'
SHELL_THEME+='"brightBlack":"#5c6370","brightRed":"#ff7b86","brightGreen":"#b1e18b","brightYellow":"#efd08d","brightBlue":"#67cdff","brightMagenta":"#e48bff","brightCyan":"#63d4e0","brightWhite":"#ffffff"}'

(
    while true; do
        ttyd --writable --port "${SHELL_PORT}" --cwd /workspace \
            -t fontSize=13 -t lineHeight=1.2 -t cursorBlink=true -t rendererType=dom -t disableLeaveAlert=true \
            -t 'fontFamily=ui-monospace,SFMono-Regular,Menlo,Consolas,Liberation Mono,monospace' \
            -t "theme=${SHELL_THEME}" \
            bash >/dev/null 2>&1
        sleep 1
    done
) &

# SSH server (Tools → Developer → SSH), key-only as this user. The host key is made once per sandbox.
if [ -x /usr/sbin/sshd ]; then
    (
        mkdir -p ~/.ssh && chmod 700 ~/.ssh
        [ -f ~/.ssh/host_ed25519 ] || ssh-keygen -q -t ed25519 -N '' -f ~/.ssh/host_ed25519
        while true; do
            /usr/sbin/sshd -D -e -f /opt/zap/sshd_config -p "${SSH_PORT:-2222}" >>/tmp/zap-sshd.log 2>&1
            sleep 1
        done
    ) &
fi

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
