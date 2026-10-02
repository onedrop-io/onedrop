# Local sandbox image: one project per container.
# Build with: php artisan sandbox:build-image
FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git curl unzip ca-certificates sqlite3 libsqlite3-dev libpq-dev libzip-dev procps openssh-server bash-completion vim ncdu rsync inotify-tools tmux zstd \
    && docker-php-ext-install pdo_sqlite pdo_pgsql pdo_mysql zip pcntl \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ttyd: web terminal for the workspace Shell tab (static release binary; not packaged in bookworm).
ARG TARGETARCH
RUN case "${TARGETARCH:-amd64}" in arm64) arch=aarch64 ;; *) arch=x86_64 ;; esac \
    && curl -fsSL -o /usr/local/bin/ttyd "https://github.com/tsl0922/ttyd/releases/download/1.7.7/ttyd.${arch}" \
    && chmod +x /usr/local/bin/ttyd

# Shell tab extras: starship prompt, eza listings (ll/la/tree), fzf history search (Ctrl-R), bat (cat with highlighting). Pinned release binaries.
RUN case "${TARGETARCH:-amd64}" in arm64) arch=aarch64 go=arm64 ;; *) arch=x86_64 go=amd64 ;; esac \
    && curl -fsSL "https://github.com/starship/starship/releases/download/v1.26.0/starship-${arch}-unknown-linux-musl.tar.gz" | tar -xz -C /usr/local/bin starship \
    && curl -fsSL "https://github.com/eza-community/eza/releases/download/v0.23.5/eza_${arch}-unknown-linux-gnu.tar.gz" | tar -xz -C /usr/local/bin ./eza \
    && curl -fsSL "https://github.com/junegunn/fzf/releases/download/v0.74.4/fzf-0.74.4-linux_${go}.tar.gz" | tar -xz -C /usr/local/bin fzf \
    && curl -fsSL "https://github.com/sharkdp/bat/releases/download/v0.26.1/bat-v0.26.1-${arch}-unknown-linux-gnu.tar.gz" | tar -xz -C /usr/local/bin --strip-components=1 "bat-v0.26.1-${arch}-unknown-linux-gnu/bat"

# More Shell tab tools: rg (search), fd (find), zoxide (z to jump to directories), jq (JSON), btop (processes),
# lazygit (git UI), micro (editor, also $EDITOR). Pinned release binaries.
RUN case "${TARGETARCH:-amd64}" in arm64) arch=aarch64 go=arm64 lazygit=arm64 micro=linux-arm64 ;; *) arch=x86_64 go=amd64 lazygit=x86_64 micro=linux64-static ;; esac \
    && curl -fsSL "https://github.com/BurntSushi/ripgrep/releases/download/15.2.0/ripgrep-15.2.0-${arch}-unknown-linux-musl.tar.gz" | tar -xz -C /usr/local/bin --strip-components=1 "ripgrep-15.2.0-${arch}-unknown-linux-musl/rg" \
    && curl -fsSL "https://github.com/sharkdp/fd/releases/download/v10.5.0/fd-v10.5.0-${arch}-unknown-linux-musl.tar.gz" | tar -xz -C /usr/local/bin --strip-components=1 "fd-v10.5.0-${arch}-unknown-linux-musl/fd" \
    && curl -fsSL "https://github.com/ajeetdsouza/zoxide/releases/download/v0.10.0/zoxide-0.10.0-${arch}-unknown-linux-musl.tar.gz" | tar -xz -C /usr/local/bin zoxide \
    && curl -fsSL -o /usr/local/bin/jq "https://github.com/jqlang/jq/releases/download/jq-1.8.2/jq-linux-${go}" && chmod +x /usr/local/bin/jq \
    && curl -fsSL "https://github.com/aristocratos/btop/releases/download/v1.4.7/btop-${arch}-unknown-linux-musl.tar.gz" | tar -xz -C /usr/local/bin --strip-components=3 ./btop/bin/btop \
    && curl -fsSL "https://github.com/jesseduffield/lazygit/releases/download/v0.65.1/lazygit_0.65.1_linux_${lazygit}.tar.gz" | tar -xz -C /usr/local/bin lazygit \
    && curl -fsSL "https://github.com/micro-editor/micro/releases/download/v2.0.15/micro-2.0.15-${micro}.tar.gz" | tar -xz -C /usr/local/bin --strip-components=1 micro-2.0.15/micro

# Headless Chromium for share cards: a screenshot of the app and the card made from it (SHARE-001, see share-card).
RUN apt-get update \
    && apt-get install -y --no-install-recommends chromium fonts-liberation2 fonts-noto-color-emoji \
    && rm -rf /var/lib/apt/lists/*

# Docker inside the sandbox, for projects that run their own Docker Compose (SBX-008). Only started (see dockerd) when
# the provider gives the sandbox its own volume for Docker's data and sets ONEDROP_DOCKER=1.
RUN install -m 0755 -d /etc/apt/keyrings \
    && curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian bookworm stable" > /etc/apt/sources.list.d/docker.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends docker-ce docker-ce-cli containerd.io docker-compose-plugin docker-buildx-plugin iptables sudo \
    && rm -rf /var/lib/apt/lists/*

# Agent CLIs. OpenCode is the default agent, Claude Code and Codex the alternatives (all pinned: their
# JSON events are parsed by the platform). Credentials arrive as env vars at run time, never at build time.
RUN npm install -g opencode-ai@1.18.32 @anthropic-ai/claude-code@2.1.284 @openai/codex@0.159.2

# modern-screenshot takes the picture of a preview page the user draws on (AGT-013); host-proxy.mjs serves it.
RUN npm install --prefix /opt/onedrop/capture --no-save --no-audit --no-fund modern-screenshot@4.7.0 \
    && rm -rf /root/.npm

# /data/storage holds App Storage buckets: on the sandbox's disk but outside the app's code and git.
RUN useradd --create-home --shell /bin/bash sandbox \
    && mkdir -p /workspace /data/storage && chown sandbox:sandbox /workspace /data/storage

COPY placeholder /opt/onedrop/placeholder
# shell-entry runs each Shell tab in its own tmux session (tmux.conf, shell-reap), so a reloaded page gets it back (LAYOUT-005). fork copies the app for a task and merges its work back (TASK-003). file-watcher.mjs tells the Files panel when files come and go (FILE-004). inspector.js is the preview's Inspect mode, served by host-proxy.mjs (AGT-014). share-card makes share cards (SHARE-001). db.php backs the Tools → Database panel (SQLite, Postgres and MySQL through PDO); auth.php backs Users & Auth; secrets.php backs Secrets; storage.php backs App Storage; git.php backs Git. snapshot packs and restores project snapshots (SBX-009).
COPY start.sh restart stop-agent shell-entry shell-reap tmux.conf checkpoint fork share-card forwarder.mjs file-watcher.mjs host-proxy.mjs inspector.js db.php auth.php secrets.php storage.php git.php skills.php snapshot instructions.md opencode.json /opt/onedrop/
# Task guides the agent follows (e.g. guides/auth.md for adding sign-in).
COPY guides /opt/onedrop/guides
# Kits the agent copies into an app with `kit <name>`, as the app's own code (kits/tables: Airtable-style tables, TABLE-001).
COPY kit /opt/onedrop/
COPY kits /opt/onedrop/kits
# Shell setup lives in /opt/onedrop (not the home folder, which updates carry over) so image changes reach every sandbox; ~/.bashrc only loads it.
COPY bashrc home-bashrc starship.toml /opt/onedrop/
COPY --chown=sandbox:sandbox home-bashrc /home/sandbox/.bashrc
# sshd for Tools → Developer → SSH (key-only, as the sandbox user; see sshd_config).
COPY sshd_config /opt/onedrop/sshd_config
# compose runs a project's own Docker Compose stack; dockerd starts Docker for it, as root through the sandbox
# user's only sudo rule; the docker group lets the sandbox user (and the agent) use it (SBX-008).
COPY compose dockerd forward.mjs /opt/onedrop/
# tests.mjs runs the app's Playwright tests with playwright.config.mjs, recording each, for the Tests tab; run-tests
# is the agent's way in (TEST-001..003). test-steps.mjs, loaded into the test workers, records each test's steps and
# stops one at a step for browser.mjs, the browser the user takes over (with browser-viewer.html, and `browser` for
# the agent; TEST-005).
COPY tests.mjs playwright.config.mjs run-tests test-steps.mjs browser.mjs browser-viewer.html browser /opt/onedrop/
RUN mkdir -p /opt/onedrop/playwright && chown sandbox:sandbox /opt/onedrop/playwright
# The Shell tab's page: ttyd's own, with shell-keys.js added so workspace shortcuts typed in the terminal
# (Cmd/Ctrl+P, FILE-006) reach the workspace. start.sh serves it with ttyd --index.
COPY shell-keys.js /opt/onedrop/
RUN (ttyd --port 7699 --interface lo true >/dev/null 2>&1 &) \
    && curl -fsS --retry 10 --retry-connrefused --retry-delay 1 -o /tmp/ttyd.html http://127.0.0.1:7699/ \
    && node -e 'const fs = require("fs"); const page = fs.readFileSync("/tmp/ttyd.html", "utf8"); if (!page.includes("</head>")) { process.exit(1); } fs.writeFileSync("/opt/onedrop/shell.html", page.replace("</head>", () => "<script>" + fs.readFileSync("/opt/onedrop/shell-keys.js", "utf8") + "</script></head>"));' \
    && pkill ttyd && rm /tmp/ttyd.html
RUN usermod -aG docker sandbox \
    && echo 'sandbox ALL=(root) NOPASSWD: /opt/onedrop/dockerd' > /etc/sudoers.d/onedrop-dockerd \
    && chmod 0440 /etc/sudoers.d/onedrop-dockerd

USER sandbox
WORKDIR /workspace
ENV LANG=C.UTF-8 PORT=8000 SHELL_PORT=7681 PROXY_PORT=8081 SSH_PORT=2222 APP_STORAGE_DIR=/data/storage
# Playwright's ffmpeg records the tests' videos; it's fetched for the version the tests guide pins, so runs don't
# download it. Tests use the Chromium installed above, not one of Playwright's. HOME is set for this step only:
# Runtime's builder keeps HOME=/root after USER, so npm's cache would be unwritable there.
ENV PLAYWRIGHT_BROWSERS_PATH=/opt/onedrop/playwright
RUN HOME=/home/sandbox npx --yes playwright@1.63.0 install ffmpeg && rm -rf /home/sandbox/.npm
EXPOSE 8000 7681 2222

CMD ["/opt/onedrop/start.sh"]

# The base files, so the platform can tell a sandbox's base apart from the current one (SBX-002): other tool files are
# copied into running sandboxes when they change, but a change to these needs a new sandbox from a new image.
COPY Dockerfile start.sh dockerd shell-keys.js /opt/onedrop-base/
