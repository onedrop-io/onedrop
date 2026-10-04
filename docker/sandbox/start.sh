#!/usr/bin/env bash
# Keeps the preview server running: the app's own dev server once the agent has
# created /workspace/.onedrop/dev, otherwise a placeholder page.
# `/opt/onedrop/restart` kills the current server; this loop starts the right one again.
# Also keeps a web terminal (ttyd) running on $SHELL_PORT for the Shell tab, and sshd on $SSH_PORT.
set -uo pipefail

# The sandbox user's real home, whatever the platform started us with (Runtime uses HOME=/workspace), so
# ~/.onedrop-env, ~/.ssh and the Shell tab's ~/.bashrc are found where the image put them, not in the project.
HOME="$(getent passwd "$(id -un)" | cut -d: -f6)"
export HOME

touch /tmp/onedrop-server.log

# Host-rewriting proxy in front of the app, used by published URLs.
(
    while true; do
        node /opt/onedrop/host-proxy.mjs >>/tmp/onedrop-proxy.log 2>&1
        sleep 1
    done
) &

# Tells the Files panel when files are added, removed or renamed. Its address may only arrive in ~/.onedrop-env after start.
(
    while true; do
        [ -f ~/.onedrop-env ] && set -a && . ~/.onedrop-env && set +a

        if [ -n "${APP_FILES_CHANGED_URL:-}" ]; then
            node /opt/onedrop/file-watcher.mjs >>/tmp/onedrop-watcher.log 2>&1
            sleep 5
        else
            sleep 30
        fi
    done
) &

# Shell tab colors: One Dark on the workspace's near-black background.
SHELL_THEME='{"background":"#0a0a0a","foreground":"#abb2bf","cursor":"#528bff","cursorAccent":"#0a0a0a","selectionBackground":"#3e4451",'
SHELL_THEME+='"black":"#3f4451","red":"#e06c75","green":"#98c379","yellow":"#e5c07b","blue":"#61afef","magenta":"#c678dd","cyan":"#56b6c2","white":"#d7dae0",'
SHELL_THEME+='"brightBlack":"#5c6370","brightRed":"#ff7b86","brightGreen":"#b1e18b","brightYellow":"#efd08d","brightBlue":"#67cdff","brightMagenta":"#e48bff","brightCyan":"#63d4e0","brightWhite":"#ffffff"}'

(
    while true; do
        ttyd --writable --url-arg --port "${SHELL_PORT}" --cwd /workspace --index /opt/onedrop/shell.html \
            -t fontSize=13 -t lineHeight=1.2 -t cursorBlink=true -t scrollback=10000 -t rendererType=dom -t disableLeaveAlert=true \
            -t 'fontFamily=ui-monospace,SFMono-Regular,Menlo,Consolas,Liberation Mono,monospace' \
            -t "theme=${SHELL_THEME}" \
            /opt/onedrop/shell-entry >/dev/null 2>&1
        sleep 1
    done
) &

# SSH server (Tools → Developer → SSH), key-only as this user. The host key is made once per sandbox.
if [ -x /usr/sbin/sshd ]; then
    (
        mkdir -p ~/.ssh && chmod 700 ~/.ssh
        [ -f ~/.ssh/host_ed25519 ] || ssh-keygen -q -t ed25519 -N '' -f ~/.ssh/host_ed25519
        while true; do
            /usr/sbin/sshd -D -e -f /opt/onedrop/sshd_config -p "${SSH_PORT:-2222}" >>/tmp/onedrop-sshd.log 2>&1
            sleep 1
        done
    ) &
fi

# Docker inside the sandbox, for projects that run their own Docker Compose (SBX-008), when the provider turned it on.
# The setting may only arrive in ~/.onedrop-env after start (Runtime Cloud).
if [ -x /opt/onedrop/dockerd ]; then
    (
        while true; do
            [ -f ~/.onedrop-env ] && set -a && . ~/.onedrop-env && set +a

            [ "${ONEDROP_DOCKER:-}" = "1" ] && sudo -n /opt/onedrop/dockerd >>/tmp/onedrop-dockerd.log 2>&1
            sleep 5
        done
    ) &
fi

while true; do
    # Settings written after start by providers that can't set env at create (RuntimeSandboxProvider).
    [ -f ~/.onedrop-env ] && set -a && . ~/.onedrop-env && set +a

    if [ -x /workspace/.onedrop/dev ]; then
        # The log isn't a terminal, so tools would leave out their colours; the Console tab shows them.
        FORCE_COLOR=1 CLICOLOR_FORCE=1 setsid bash -c 'cd /workspace && exec /workspace/.onedrop/dev' >>/tmp/onedrop-server.log 2>&1 &
    else
        setsid php -S "0.0.0.0:${PORT}" /opt/onedrop/placeholder/index.php >>/tmp/onedrop-server.log 2>&1 &
    fi

    echo $! > /tmp/onedrop-server.pid
    wait $!
    sleep 1
done
