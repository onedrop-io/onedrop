# Local sandbox image: one project per container.
# Build with: php artisan sandbox:build-image
FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git curl unzip ca-certificates sqlite3 libsqlite3-dev libpq-dev libzip-dev procps openssh-server bash-completion vim ncdu rsync inotify-tools \
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

# Agent CLIs. OpenCode is the default agent and Claude Code the alternative (both pinned: their
# JSON events are parsed by the platform). Credentials arrive as env vars at run time, never at build time.
RUN npm install -g opencode-ai@1.18.32 @anthropic-ai/claude-code@2.1.284 @openai/codex

# /data/storage holds App Storage buckets: on the sandbox's disk but outside the app's code and git.
RUN useradd --create-home --shell /bin/bash sandbox \
    && mkdir -p /workspace /data/storage && chown sandbox:sandbox /workspace /data/storage

COPY placeholder /opt/zap/placeholder
# fork copies the app for a task and merges its work back (TASK-003). file-watcher.mjs tells the Files panel when files come and go (FILE-004). db.php backs the Tools → Database panel (SQLite, Postgres and MySQL through PDO); auth.php backs Users & Auth; secrets.php backs Secrets; storage.php backs App Storage; git.php backs Git.
COPY start.sh restart stop-agent checkpoint fork forwarder.mjs file-watcher.mjs host-proxy.mjs db.php auth.php secrets.php storage.php git.php instructions.md opencode.json /opt/zap/
# Task guides the agent follows (e.g. guides/auth.md for adding sign-in).
COPY guides /opt/zap/guides
# Shell setup lives in /opt/zap (not the home folder, which updates carry over) so image changes reach every sandbox; ~/.bashrc only loads it.
COPY bashrc home-bashrc starship.toml /opt/zap/
COPY --chown=sandbox:sandbox home-bashrc /home/sandbox/.bashrc
# sshd for Tools → Developer → SSH (key-only, as the sandbox user; see sshd_config).
COPY sshd_config /opt/zap/sshd_config

USER sandbox
WORKDIR /workspace
ENV LANG=C.UTF-8 PORT=8000 SHELL_PORT=7681 PROXY_PORT=8081 SSH_PORT=2222 APP_STORAGE_DIR=/data/storage
EXPOSE 8000 7681 2222

CMD ["/opt/zap/start.sh"]
