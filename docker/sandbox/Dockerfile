# Local sandbox image: one project per container.
# Build with: php artisan sandbox:build-image
# Versions here and in onedrop-tool are kept current by Renovate (renovate.json), in one weekly pull request; each
# "# renovate:" comment says where the line below it gets its releases. The base images are pinned by digest, so a
# rebuilt php or composer image (Debian security fixes) is an update too.
FROM php:8.4-cli-bookworm@sha256:f1d32fb402fffba0b3dd8ba8c0aca474c9e9f04395fa846eedea77c503257dee

# Postgres's own apt repository, so psql and pg_dump are the newest major and work with any server version (Debian's
# are 15, and pg_dump refuses newer servers).
RUN apt-get update \
    && apt-get install -y --no-install-recommends git curl unzip ca-certificates sqlite3 libsqlite3-dev libpq-dev libzip-dev procps openssh-server bash-completion vim ncdu rsync inotify-tools tmux zstd \
    && docker-php-ext-install pdo_sqlite pdo_pgsql pdo_mysql zip pcntl \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && install -m 0755 -d /etc/apt/keyrings \
    && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /etc/apt/keyrings/postgresql.asc \
    && echo "deb [signed-by=/etc/apt/keyrings/postgresql.asc] https://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends nodejs postgresql-client mariadb-client redis-tools python3 python3-venv python3-pip shellcheck \
    && corepack enable \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac /usr/bin/composer /usr/bin/composer

# ttyd: web terminal for the workspace Shell tab (static release binary; not packaged in bookworm).
ARG TARGETARCH
# renovate: datasource=github-releases depName=tsl0922/ttyd
ARG TTYD_VERSION=1.7.7
RUN case "${TARGETARCH:-amd64}" in arm64) arch=aarch64 ;; *) arch=x86_64 ;; esac \
    && curl -fsSL -o /usr/local/bin/ttyd "https://github.com/tsl0922/ttyd/releases/download/${TTYD_VERSION}/ttyd.${arch}" \
    && chmod +x /usr/local/bin/ttyd

# Shell tab extras: starship prompt, eza listings (ll/la/tree), fzf history search (Ctrl-R), bat (cat with highlighting). Pinned release binaries.
# renovate: datasource=github-releases depName=starship/starship
ARG STARSHIP_VERSION=1.26.0
# renovate: datasource=github-releases depName=eza-community/eza
ARG EZA_VERSION=0.23.5
# renovate: datasource=github-releases depName=junegunn/fzf
ARG FZF_VERSION=0.74.4
# renovate: datasource=github-releases depName=sharkdp/bat
ARG BAT_VERSION=0.26.1
RUN case "${TARGETARCH:-amd64}" in arm64) arch=aarch64 go=arm64 ;; *) arch=x86_64 go=amd64 ;; esac \
    && curl -fsSL "https://github.com/starship/starship/releases/download/v${STARSHIP_VERSION}/starship-${arch}-unknown-linux-musl.tar.gz" | tar -xz -C /usr/local/bin starship \
    && curl -fsSL "https://github.com/eza-community/eza/releases/download/v${EZA_VERSION}/eza_${arch}-unknown-linux-gnu.tar.gz" | tar -xz -C /usr/local/bin ./eza \
    && curl -fsSL "https://github.com/junegunn/fzf/releases/download/v${FZF_VERSION}/fzf-${FZF_VERSION}-linux_${go}.tar.gz" | tar -xz -C /usr/local/bin fzf \
    && curl -fsSL "https://github.com/sharkdp/bat/releases/download/v${BAT_VERSION}/bat-v${BAT_VERSION}-${arch}-unknown-linux-gnu.tar.gz" | tar -xz -C /usr/local/bin --strip-components=1 "bat-v${BAT_VERSION}-${arch}-unknown-linux-gnu/bat"

# More Shell tab tools: rg (search), fd (find), zoxide (z to jump to directories), jq (JSON), btop (processes),
# lazygit (git UI), micro (editor, also $EDITOR), yq (YAML), xh (HTTP requests), uv (Python packages, uvx runs
# Python CLIs). Pinned release binaries.
# renovate: datasource=github-releases depName=BurntSushi/ripgrep
ARG RIPGREP_VERSION=15.2.0
# renovate: datasource=github-releases depName=sharkdp/fd
ARG FD_VERSION=10.5.0
# renovate: datasource=github-releases depName=ajeetdsouza/zoxide
ARG ZOXIDE_VERSION=0.10.0
# renovate: datasource=github-releases depName=jqlang/jq extractVersion=^jq-(?<version>.+)$
ARG JQ_VERSION=1.8.2
# renovate: datasource=github-releases depName=aristocratos/btop
ARG BTOP_VERSION=1.4.7
# renovate: datasource=github-releases depName=jesseduffield/lazygit
ARG LAZYGIT_VERSION=0.65.1
# renovate: datasource=github-releases depName=micro-editor/micro
ARG MICRO_VERSION=2.0.15
# renovate: datasource=github-releases depName=mikefarah/yq
ARG YQ_VERSION=4.54.1
# renovate: datasource=github-releases depName=ducaale/xh
ARG XH_VERSION=0.26.2
# renovate: datasource=github-releases depName=astral-sh/uv
ARG UV_VERSION=0.12.22
RUN case "${TARGETARCH:-amd64}" in arm64) arch=aarch64 go=arm64 lazygit=arm64 micro=linux-arm64 ;; *) arch=x86_64 go=amd64 lazygit=x86_64 micro=linux64-static ;; esac \
    && curl -fsSL "https://github.com/BurntSushi/ripgrep/releases/download/${RIPGREP_VERSION}/ripgrep-${RIPGREP_VERSION}-${arch}-unknown-linux-musl.tar.gz" | tar -xz -C /usr/local/bin --strip-components=1 "ripgrep-${RIPGREP_VERSION}-${arch}-unknown-linux-musl/rg" \
    && curl -fsSL "https://github.com/sharkdp/fd/releases/download/v${FD_VERSION}/fd-v${FD_VERSION}-${arch}-unknown-linux-musl.tar.gz" | tar -xz -C /usr/local/bin --strip-components=1 "fd-v${FD_VERSION}-${arch}-unknown-linux-musl/fd" \
    && curl -fsSL "https://github.com/ajeetdsouza/zoxide/releases/download/v${ZOXIDE_VERSION}/zoxide-${ZOXIDE_VERSION}-${arch}-unknown-linux-musl.tar.gz" | tar -xz -C /usr/local/bin zoxide \
    && curl -fsSL -o /usr/local/bin/jq "https://github.com/jqlang/jq/releases/download/jq-${JQ_VERSION}/jq-linux-${go}" && chmod +x /usr/local/bin/jq \
    && curl -fsSL "https://github.com/aristocratos/btop/releases/download/v${BTOP_VERSION}/btop-${arch}-unknown-linux-musl.tar.gz" | tar -xz -C /usr/local/bin --strip-components=3 ./btop/bin/btop \
    && curl -fsSL "https://github.com/jesseduffield/lazygit/releases/download/v${LAZYGIT_VERSION}/lazygit_${LAZYGIT_VERSION}_linux_${lazygit}.tar.gz" | tar -xz -C /usr/local/bin lazygit \
    && curl -fsSL "https://github.com/micro-editor/micro/releases/download/v${MICRO_VERSION}/micro-${MICRO_VERSION}-${micro}.tar.gz" | tar -xz -C /usr/local/bin --strip-components=1 "micro-${MICRO_VERSION}/micro" \
    && curl -fsSL -o /usr/local/bin/yq "https://github.com/mikefarah/yq/releases/download/v${YQ_VERSION}/yq_linux_${go}" && chmod +x /usr/local/bin/yq \
    && curl -fsSL "https://github.com/ducaale/xh/releases/download/v${XH_VERSION}/xh-v${XH_VERSION}-${arch}-unknown-linux-musl.tar.gz" | tar -xz -C /usr/local/bin --strip-components=1 "xh-v${XH_VERSION}-${arch}-unknown-linux-musl/xh" \
    && curl -fsSL "https://github.com/astral-sh/uv/releases/download/${UV_VERSION}/uv-${arch}-unknown-linux-gnu.tar.gz" | tar -xz -C /usr/local/bin --strip-components=1 "uv-${arch}-unknown-linux-gnu/uv" "uv-${arch}-unknown-linux-gnu/uvx"

# Headless Chromium for share cards: a screenshot of the app and the card made from it (SHARE-001, see share-card).
RUN apt-get update \
    && apt-get install -y --no-install-recommends chromium fonts-liberation2 fonts-noto-color-emoji \
    && rm -rf /var/lib/apt/lists/*

# Docker inside the sandbox, for projects that run their own Docker Compose (SBX-008). Only started (see dockerd) when
# the provider gives the sandbox its own volume for Docker's data and sets ONEDROP_DOCKER=1.
RUN curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian bookworm stable" > /etc/apt/sources.list.d/docker.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends docker-ce docker-ce-cli containerd.io docker-compose-plugin docker-buildx-plugin iptables sudo \
    && rm -rf /var/lib/apt/lists/*

# Agent CLIs. OpenCode is the default agent, Claude Code and Codex the alternatives (all pinned: their
# JSON events are parsed by the platform). Credentials arrive as env vars at run time, never at build time.
# Renovate bumps them in a pull request of their own, since each needs its events parser re-checked.
# renovate: datasource=npm depName=opencode-ai
ARG OPENCODE_VERSION=1.18.32
# renovate: datasource=npm depName=@anthropic-ai/claude-code
ARG CLAUDE_CODE_VERSION=2.1.284
# renovate: datasource=npm depName=@openai/codex
ARG CODEX_VERSION=0.159.2
RUN npm install -g "opencode-ai@${OPENCODE_VERSION}" "@anthropic-ai/claude-code@${CLAUDE_CODE_VERSION}" "@openai/codex@${CODEX_VERSION}"

# modern-screenshot takes the picture of a preview page the user draws on (AGT-013); host-proxy.mjs serves it.
# renovate: datasource=npm depName=modern-screenshot
ARG MODERN_SCREENSHOT_VERSION=4.7.0
RUN npm install --prefix /opt/onedrop/capture --no-save --no-audit --no-fund "modern-screenshot@${MODERN_SCREENSHOT_VERSION}" \
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
# Service CLIs (aws, gcloud, az, wrangler, supabase, …) install on first use through onedrop-tool (SBX-011): each
# command's stand-in in lazy/ is at the end of PATH, so a copy the user or the app installed comes first.
COPY onedrop-tool /opt/onedrop/
COPY lazy /opt/onedrop/lazy
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
ENV LANG=C.UTF-8 PORT=8000 SHELL_PORT=7681 PROXY_PORT=8081 SSH_PORT=2222 APP_STORAGE_DIR=/data/storage \
    PATH="${PATH}:/opt/onedrop/lazy" COREPACK_ENABLE_DOWNLOAD_PROMPT=0
# Playwright's ffmpeg records the tests' videos; it's fetched for the version the tests guide pins, so runs don't
# download it. Tests use the Chromium installed above, not one of Playwright's. HOME is set for this step only:
# Runtime's builder keeps HOME=/root after USER, so npm's cache would be unwritable there. Not bumped by Renovate:
# the version is also in guides/tests.md and tests.mjs, and moves with them.
ENV PLAYWRIGHT_BROWSERS_PATH=/opt/onedrop/playwright
RUN HOME=/home/sandbox npx --yes playwright@1.63.0 install ffmpeg && rm -rf /home/sandbox/.npm
EXPOSE 8000 7681 2222

CMD ["/opt/onedrop/start.sh"]

# The base files, so the platform can tell a sandbox's base apart from the current one (SBX-002): other tool files are
# copied into running sandboxes when they change, but a change to these needs a new sandbox from a new image.
COPY Dockerfile start.sh dockerd shell-keys.js /opt/onedrop-base/
