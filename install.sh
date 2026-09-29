#!/bin/sh
# Installs OneDrop on this computer (macOS or Linux; Docker is the only requirement):
#
#   curl -fsSL https://raw.githubusercontent.com/onedrop-io/onedrop/main/install.sh | sh
#
# On a server, with HTTPS at a domain (or at <ip>.sslip.io with "auto"):
#
#   curl -fsSL https://raw.githubusercontent.com/onedrop-io/onedrop/main/install.sh | sh -s -- --domain auto
#
# Puts the `drop` command in ~/.local/bin and runs `drop install` with any options given. Run it again to update.
set -eu

# Everything is inside main, run on the last line, so a half-downloaded script does nothing.
main() {
    source="${DROP_SOURCE:-https://raw.githubusercontent.com/onedrop-io/onedrop/main}"
    bin="${DROP_BIN_DIR:-$HOME/.local/bin}"

    command -v curl >/dev/null 2>&1 || { echo "Install curl first." >&2; exit 1; }

    mkdir -p "$bin"
    curl -fsSL "$source/docker/app/drop" -o "$bin/drop.download"
    chmod +x "$bin/drop.download"
    mv "$bin/drop.download" "$bin/drop"

    "$bin/drop" install "$@"

    case ":$PATH:" in
        *":$bin:"*) echo "Manage it with: drop help" ;;
        *) echo "Manage it with: $bin/drop help  (add $bin to your PATH to type just \"drop\")" ;;
    esac
}

main "$@"
