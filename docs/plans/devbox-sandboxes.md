# Plan: Devbox sandboxes (any stack) and the E2B template

Status: proposed, not started. Replaces the hard-coded `php:8.4-cli` sandbox image with a Nix + Devbox image,
so a project can use any language Nix provides (README "Any stack"), and shapes that image so the same build
becomes the E2B template.

## Decisions

- **One image, three providers.** `docker/sandbox/Dockerfile` stays the only definition. Docker runs it; E2B
  builds its template `fromImage()` from the same image pushed to a registry; Daytona (later) snapshots it.
  Nothing provider-specific goes in the image.
- **Platform and project are separate.** The platform's tools live under `/opt/zap` with their own PHP and Node
  and are called by absolute path. The project's stack lives in `/workspace/devbox.json`. A project that drops
  PHP, or pins Node 18, can't break the Tools panels, the forwarder, or the agent.
- **Slim base, pre-warmed default stack.** The image ships the platform plus a pre-filled `/nix/store` for the
  default stack. Everything else is installed on demand with `devbox add` from cache.nixos.org (prebuilt, seconds).
  Grow the pre-warmed set from real usage (log `devbox add` calls), not guesses.
- **No Nix daemon.** Single-user Nix, owned by the `sandbox` user (what the official `jetpackio/devbox` image does).
  Side benefit: the agent can install system packages without root, which it can't do today.

## What goes where

| Where | What |
| --- | --- |
| Base image, platform | Debian slim, single-user Nix, Devbox, `/opt/zap/bin/{php,node}` (PHP with pdo_sqlite/pdo_pgsql/pdo_mysql, Node 22), ttyd, openssh, git, sqlite, OpenCode (pinned), starship, eza, fzf, bash-completion, `/opt/zap` scripts |
| Base image, pre-warmed store only | Default stack: php84 + composer, nodejs_22, postgresql, python3 (~683 MiB closure together, measured) |
| Project `devbox.json` | The project's language(s), libraries and services (Postgres/Redis via `devbox services`); Claude Code / Codex CLIs if wanted |

Pre-warmed is not activated: a package in the store is only on `PATH` if the project lists it.

**Store hits need identical store paths**, not just the same version. `devbox.lock` records the nixpkgs commit and
store path, so the platform pins one nixpkgs commit: the image is pre-warmed from `docker/sandbox/stack/devbox.lock`,
and new projects are seeded with that same `devbox.json` + `devbox.lock`. A `devbox add` that resolves a different
commit still works; it just downloads.

Estimated image: ~1.3 GB (vs 2.26 GB today), mostly because the PHP build toolchain, the `-dev` libraries, the npm
cache and the Claude Code/Codex CLIs leave the base, and platform PHP/Node share store paths with the default stack.

## Work

### Phase 1: Platform runtime by absolute path (current image, small)

Do this first; it's independent of Nix and de-risks the rest.

- Add `/opt/zap/bin/php` and `/opt/zap/bin/node` (symlinks to today's binaries for now).
- Call them by path instead of bare `php`/`node`: `WorkspaceDatabase`, `WorkspaceSecrets`, `WorkspaceStorage`,
  `WorkspaceAuth`, `WorkspaceFlags`, `SandboxInspector`, `OpenCodeRunner` (forwarder), `start.sh` (host-proxy,
  placeholder server). One constant (e.g. `SandboxSpec::PLATFORM_PHP`), not seven strings.
- Tests: update the fake-provider assertions on exec'd commands.

### Phase 2: Nix + Devbox image

- `FROM debian:bookworm-slim`; create `sandbox` user; install single-user Nix and Devbox (`DEVBOX_USE_VERSION` pinned).
- Platform packages as a Devbox project at `/opt/zap/platform/devbox.json` (+ lock), root-owned so `devbox add` in
  the workspace can't change it. `/opt/zap/bin/*` point into its profile. Shell tab extras move here and the three
  `curl` downloads are deleted (`bashrc` already detects each tool).
- OpenCode stays an `npm install -g` pin run with the platform Node (nixpkgs lags its releases; `OpenCodeEvents`
  depends on the exact version). Add `npm cache clean --force`.
- Pre-warm: `devbox install` in `docker/sandbox/stack/`, then drop that project but keep the store.
- Verify: `php -m` in the platform PHP has the PDO drivers the Tools panels need.

### Phase 3: Project environment

- On create, seed `/workspace/devbox.json` + `devbox.lock` from `docker/sandbox/stack/` if the workspace has none.
- `start.sh`: `devbox install` (fast when pre-warmed), `devbox services up -b` if the project declares services,
  then run `.zap/dev` inside `devbox run` so the dev server sees the project's stack. `/opt/zap/restart` unchanged.
- `bashrc`: activate the project env (`eval "$(devbox shellenv)"` in `/workspace`), platform tools after it on `PATH`.
- Agent: `instructions.md` "Stack" section says to add packages with `devbox add <pkg>@<version>` (never apt), and
  lists the defaults. Keep `.zap/dev` as the start contract; skip README's `app.yaml` until publish needs it.
- Updates (`SandboxUpdater`) already recreate and copy `KEPT_PATHS`. Packages outside the pre-warmed set are
  re-downloaded from the lock on the new sandbox: acceptable; a shared host-level cache can come later if it hurts.
- Spec: new `SBX-003: Any stack` (below). Tests: seeding, start order, restart inside the env. Integration test
  (`RUN_DOCKER_TESTS`): a Python project (`devbox add python@3.12`, Flask dev server) serves its preview; a Laravel
  project starts with no downloads. Docs: `self-hosting/sandboxes.mdx` and a user-facing "Choosing your stack" page.

### Phase 4: E2B provider (after the M0 spike)

M0 (README) first: start this template on E2B with Laravel + Postgres, pause, resume, and measure resume-to-first-response.

- **Template:** CI builds the image and pushes it to GHCR/ECR; the template is `fromImage()` (the Dockerfile-parsing
  path doesn't support multi-stage builds), `setUser('sandbox')`, `setStartCmd('/opt/zap/start.sh', waitForPort(7681))`.
  The start command runs once at build time and its processes are snapshotted, so sandboxes boot with ttyd/sshd/proxy
  already running.
- **Env caveat:** create-time `envVars` reach commands we run, not the snapshotted start processes. Agent runs
  already pass credentials per `exec`; anything `start.sh` needs must be baked into the image (it already is:
  `PORT`, `SHELL_PORT`, ...). Shells spawned by ttyd won't see create-time env.
- **Provider:** `E2BSandboxProvider` over REST from PHP (`POST api.e2b.app/v2/sandboxes`, `X-API-Key`; pause,
  resume/connect, delete). Commands and files go through the sandbox's envd API with its access token; there's no
  PHP SDK, so this is the biggest chunk of provider work. `copyOut`/`copyIn` can tar through `exec`.
  Create with `autoPause: true` (and `autoResume` for the wake proxy); running time is capped at 24 h (Pro) per resume.
- **Previews stay behind our auth:** create with `network.allowPublicTraffic: false`; `SandboxGatewayController`
  proxies to `https://{port}-{id}.e2b.app` adding `e2b-traffic-access-token`. Never hand out the E2B URL.
- **Updates:** a sandbox keeps the template build it was created from. `isOutdated` compares the sandbox's build
  (stored in metadata at create) with the current tag's build; updating is the existing recreate + copy path.
- **Shell tab:** pause/resume drops websockets; the Shell tab must reconnect on its own.
- **Limits:** disk is per tier (10 GiB Hobby, 20+ GiB Pro), not per sandbox; ~1.3 GB image leaves room.
  Paused sandboxes are kept until deleted; billing stops while paused (storage pricing unconfirmed).

## Proposed spec entry

```md
## SBX-003: Any stack
- User should be able to build an app in any language; the agent adds what it needs to the project's devbox.json.
- A new Laravel or Node app should start without downloading its stack.
- Adding a package should keep working after the sandbox updates.
- Changing or removing the project's languages should never break the Tools panels, the Shell tab, or the agent.
```

## Open questions

- nixpkgs pin policy: how often to bump the pinned commit (each bump re-downloads the pre-warmed set in the image,
  and existing projects' locks drift from it).
- Keep Claude Code and Codex CLIs in the base, or per-project only?
- Does single-user Nix behave on E2B's Firecracker VMs and under gVisor (`SANDBOX_DOCKER_RUNTIME=runsc`)? Test both.
- Paused-sandbox storage pricing on E2B.

## Sources

- E2B: docs.e2b.dev template/base-image, template/start-ready-command, template/private-registries, template/tags,
  sandbox/persistence, network/restrict-public-access, api-reference/sandboxes/create-sandbox-v2.
- Devbox: jetify.com/docs/devbox installing-devbox, devbox-global, guides/services, guides/pinning-packages.
- Closure sizes measured against cache.nixos.org, nixpkgs `c27cdad` (Aug 2026), x86_64-linux.
