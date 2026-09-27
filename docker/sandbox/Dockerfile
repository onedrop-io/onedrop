# Local sandbox image: one project per container.
# Build with: php artisan sandbox:build-image
FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git curl unzip ca-certificates sqlite3 libsqlite3-dev libzip-dev \
    && docker-php-ext-install pdo_sqlite zip \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ttyd: web terminal for the workspace Shell tab (static release binary; not packaged in bookworm).
ARG TARGETARCH
RUN case "${TARGETARCH:-amd64}" in arm64) arch=aarch64 ;; *) arch=x86_64 ;; esac \
    && curl -fsSL -o /usr/local/bin/ttyd "https://github.com/tsl0922/ttyd/releases/download/1.7.7/ttyd.${arch}" \
    && chmod +x /usr/local/bin/ttyd

# Agent CLIs. OpenCode is the default agent (pinned: its JSON events are parsed by the platform).
# Credentials arrive as env vars at container start, never at build time.
RUN npm install -g opencode-ai@1.18.32 @anthropic-ai/claude-code @openai/codex

RUN useradd --create-home --shell /bin/bash sandbox \
    && mkdir -p /workspace && chown sandbox:sandbox /workspace

COPY placeholder /opt/zap/placeholder
COPY start.sh restart forwarder.mjs host-proxy.mjs instructions.md opencode.json /opt/zap/
COPY --chown=sandbox:sandbox bashrc /home/sandbox/.bashrc

USER sandbox
WORKDIR /workspace
ENV PORT=8000 SHELL_PORT=7681 PROXY_PORT=8081
EXPOSE 8000 7681

CMD ["/opt/zap/start.sh"]
