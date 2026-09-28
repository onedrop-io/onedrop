# Local sandbox image: one project per container.
# Build with: php artisan sandbox:build-image
FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git curl unzip ca-certificates sqlite3 libsqlite3-dev libpq-dev libzip-dev procps openssh-server bash-completion \
    && docker-php-ext-install pdo_sqlite pdo_pgsql pdo_mysql zip \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ttyd: web terminal for the workspace Shell tab (static release binary; not packaged in bookworm).
ARG TARGETARCH
RUN case "${TARGETARCH:-amd64}" in arm64) arch=aarch64 ;; *) arch=x86_64 ;; esac \
    && curl -fsSL -o /usr/local/bin/ttyd "https://github.com/tsl0922/ttyd/releases/download/1.7.7/ttyd.${arch}" \
    && chmod +x /usr/local/bin/ttyd

# Shell tab extras: starship prompt, eza listings (ll/la/tree), fzf history search (Ctrl-R). Pinned release binaries.
RUN case "${TARGETARCH:-amd64}" in arm64) arch=aarch64 go=arm64 ;; *) arch=x86_64 go=amd64 ;; esac \
    && curl -fsSL "https://github.com/starship/starship/releases/download/v1.26.0/starship-${arch}-unknown-linux-musl.tar.gz" | tar -xz -C /usr/local/bin starship \
    && curl -fsSL "https://github.com/eza-community/eza/releases/download/v0.23.5/eza_${arch}-unknown-linux-gnu.tar.gz" | tar -xz -C /usr/local/bin ./eza \
    && curl -fsSL "https://github.com/junegunn/fzf/releases/download/v0.74.4/fzf-0.74.4-linux_${go}.tar.gz" | tar -xz -C /usr/local/bin fzf

# Agent CLIs. OpenCode is the default agent (pinned: its JSON events are parsed by the platform).
# Credentials arrive as env vars at container start, never at build time.
RUN npm install -g opencode-ai@1.18.32 @anthropic-ai/claude-code @openai/codex

# /data/storage holds App Storage buckets: on the sandbox's disk but outside the app's code and git.
RUN useradd --create-home --shell /bin/bash sandbox \
    && mkdir -p /workspace /data/storage && chown sandbox:sandbox /workspace /data/storage

COPY placeholder /opt/zap/placeholder
# db.php backs the Tools → Database panel (SQLite, Postgres and MySQL through PDO); auth.php backs Users & Auth; secrets.php backs Secrets; storage.php backs App Storage.
COPY start.sh restart stop-agent forwarder.mjs host-proxy.mjs db.php auth.php secrets.php storage.php instructions.md opencode.json /opt/zap/
# Task guides the agent follows (e.g. guides/auth.md for adding sign-in).
COPY guides /opt/zap/guides
COPY --chown=sandbox:sandbox bashrc /home/sandbox/.bashrc
COPY --chown=sandbox:sandbox starship.toml /home/sandbox/.config/starship.toml
# sshd for Tools → Developer → SSH (key-only, as the sandbox user; see sshd_config).
COPY sshd_config /opt/zap/sshd_config

USER sandbox
WORKDIR /workspace
ENV LANG=C.UTF-8 PORT=8000 SHELL_PORT=7681 PROXY_PORT=8081 SSH_PORT=2222 APP_STORAGE_DIR=/data/storage
EXPOSE 8000 7681 2222

CMD ["/opt/zap/start.sh"]
