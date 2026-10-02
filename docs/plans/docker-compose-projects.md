# Plan: Projects that bring their own way to run (Docker Compose, devcontainer, Devbox)

Status: Phase 0 done locally (works); Phase 1 started for local Docker (SBX-008: Docker inside sandboxes, `/opt/onedrop/compose init`). Lets a project whose repo already says how to run it (a `docker-compose.yml` with
Mongo, Redis, Elasticsearch, its own app image and workers; a `.devcontainer`; a `devbox.json`) run that way in its
sandbox, the same on local Docker, on a server, and on Blaxel or Runtime Cloud. Built for any repo; shaped around a
real one.

## The example: `~/www/pixwel/platform2`

It has most of what real repos do, often several ways at once:

- **Six compose files.** `docker-compose.yml` + `docker-compose.override.yml` (loaded automatically by compose), and
  overlays you opt into: `.devcontainer.yml` (an external network only the devcontainer creates, which breaks
  `up` everywhere else), `.arm64.yml` (ES 7.1.1 and elasticmq only exist for amd64), `.debug.yml`, and an unrelated
  `.minio.yml`. `.devcontainer/devcontainer.json` picks the set with `COMPOSE_FILE`.
- **The app is a container.** nginx + php-fpm built from `docker/app/Dockerfile`, code bind-mounted from `.`;
  nine workers reuse its image with a different `entrypoint`; `graphql` is a second built image.
- **Two HTTP ports the browser uses:** the app on 8080 and GraphQL on 4000, with links built from
  `REST_PUBLIC_HOST` (default `127.0.0.1:8080`).
- **Backing services:** `mongo:8.0.16` as a single-node replica set that advertises `mongo:27017`, so clients
  must resolve `mongo`; data bind-mounted at `./tmp/mongo`; pinned because newer builds refuse to start on Linux
  6.19+ kernels, so **the kernel the sandbox runs on matters**. `redis:5`, `elasticsearch:7.1.1`, `elasticmq`
  (`privileged: true`).
- **Profiles:** `deploy` turns on seeding (a 4.5 GB Mongo snapshot from S3) and migrations; off by default.
- **Secrets everywhere:** a build secret from `AUTH_GITHUB_TOKEN`, `env_file: .env` (not in git), `install.sh`
  (the devcontainer's `postCreateCommand`) needs `NPM_TOKEN` and AWS credentials to fetch secrets and data.
- **Sizing:** `hostRequirements` says 8 CPUs, 16 GB.
- **A second way to run:** `devbox.json` + `devbox.d/` (php82, nginx, redis plugins) + `process-compose.yaml`
  running mongod, ES, elasticmq, the workers and the UI dev server natively.

## Decisions

- **Detect how the repo runs; don't assume.** A project can have several "ways to run": Compose (with a chosen
  file set and profiles), Devbox services (`devbox.json` / `process-compose.yaml`), or the platform's own
  `.onedrop/dev`. The platform lists what it found, recommends one, and the user can switch; the choice is saved
  on the project. A devcontainer isn't run as-is (the sandbox _is_ the dev box); its settings are read as hints:
  `COMPOSE_FILE`, `hostRequirements`, `postCreateCommand`, `forwardPorts`, `remoteEnv`.
- **Run the real compose file, not a translation.** Converting services to Nix packages was ruled out: Mongo
  (SSPL) and Elasticsearch (Elastic License) are unfree in nixpkgs, so they aren't in the binary cache and would
  build from source, and an app's own Dockerfile can't be converted. When the repo ships its own Devbox setup
  (as platform2 does), that's a separate way to run, not something we generate.
- **Compose's own rules pick the files.** By default: what bare `docker compose` loads (base + override). Add
  overlays from the devcontainer's `COMPOSE_FILE`, minus files that declare an `external` network the platform
  didn't create; add an `*arm64*` overlay only on arm64 hosts. The user can change the list and the profiles.
- **Docker runs inside the sandbox, on every provider.** Each sandbox gets its own `dockerd`, so relative bind
  mounts, `build:`, build secrets, `env_file` and `ports:` all work because compose runs from `/workspace` as on a
  laptop. Rejected: sibling containers on the host's Docker (platform2's devcontainer does this with the host
  socket; it only works with our Docker provider and gives the project the host); a separate services host per
  provider (another thing to secure, back up and wake).
- **Isolation stays at the sandbox boundary.** Local Docker Desktop may use `--privileged` (dev only). Servers
  must use a runtime that makes nested Docker safe (Sysbox, or gVisor with nested Docker); with neither, compose
  projects are refused there with a message saying why. Never `--privileged` outside `APP_ENV=local`, never the
  host's Docker socket. Blaxel/Runtime are microVMs: fine if their kernels allow dockerd (Phase 0).
- **Service names work from the sandbox too.** Every service with a published port is reachable as
  `localhost:<port>` _and_ by its service name (`mongo`, `redis`) from the Shell tab, the agent and the Tools
  panels, via `/etc/hosts` entries to 127.0.0.1. Needed for Mongo replica sets that advertise `mongo:27017`.
- **Every HTTP port the app publishes gets an address.** The preview is one service's port (the user picks; `app`
  → 8080 here). Other HTTP ports (GraphQL on 4000) get their own gateway address behind the same auth check, and
  the host proxy rewrites `localhost:<port>` / `127.0.0.1:<port>` links to them, extending the existing
  localhost-link rewrite. Nothing is published on a provider's own URL.
- **`.onedrop/dev` stays the start contract.** For a compose project it runs `/opt/onedrop/compose up`, so
  `start.sh`, `/opt/onedrop/restart` and the error overlay keep working.
- **Secrets come from the project's Secrets, never the image or repo.** The platform lists what the stack needs:
  compose `secrets:` with an `environment:` source, `${VAR}` without a default, missing `env_file`s, and
  `remoteEnv` names. Values are passed per exec (build secrets, `docker login` for private images, the setup
  command). A missing `env_file` is created from `.env.example` if there is one, otherwise the user is asked.
- **Which provider a compose project runs on follows the admin's order** (Settings → Sandboxes, ADMIN-002): the
  first provider that's on, set up, _and_ can run Docker inside its sandboxes. When none can, the import prompt
  says so instead of starting a broken sandbox.
- **Local first.** Get platform2 running end to end on local Docker (OrbStack/Docker Desktop) before servers and
  managed providers.
- **Setup is a separate, re-runnable step.** The devcontainer's `postCreateCommand` (or one the user sets) runs
  once after the stack is first detected, with the project's Secrets, and can be re-run from Services. Big data
  restores (platform2's 4.5 GB Mongo snapshot) happen here, not on every start.

## How it fits together

| Piece                            | What it does                                                                                                                                                                                                                                                                                                       |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Image                            | `docker-ce`, `containerd`, compose plugin (~200 MB). `/opt/onedrop/dockerd` starts `dockerd` (root via a narrow sudo rule) from `start.sh` only when the project runs on Compose. Docker's data root on the sandbox disk, outside `KEPT_PATHS`.                                                                    |
| `/opt/onedrop/compose`           | `detect` (for each candidate file set: `docker compose config --format json`), `up`, `down`, `ps`, `logs`, `restart <svc>`, `setup`, `volumes export/import`. Writes `/etc/hosts` entries for services.                                                                                                            |
| `app/Sandbox/RunRecipes.php`     | Finds the ways to run (compose files and overlays, devcontainer hints, `devbox.json`/`process-compose.yaml`, `.onedrop/dev`) and recommends one.                                                                                                                                                                   |
| `app/Sandbox/ComposeProject.php` | Turns `detect` output into: preview candidates and other HTTP ports, required secrets/settings, private images, profiles, data mounts, size hint (from `hostRequirements` or a per-image estimate), warnings (amd64-only images on arm64, `external` networks, `privileged`). Stored on the project (JSON column). |
| Platform override                | `~/.onedrop/compose.override.yaml` (not in git): services the user switched off, `mem_limit`s, port bindings for the gateway.                                                                                                                                                                                      |
| Tools → Services                 | Way to run, file set and profiles; each service's status, logs, start/stop/restart, on/off; preview service; setup (run/re-run); what's missing (secrets, memory).                                                                                                                                                 |
| Agent                            | `instructions.md` gets a generated "How this project runs" section: use `docker compose logs/exec`, run PHP/tests inside the app container (the sandbox's own PHP isn't the app's), edits in `/workspace` show up through the bind mounts, restart a worker after changing its code, never commit `.env`.          |

## Work

### Phase 0: Spike (gate for everything else)

Run platform2's stack (base + override, arm64 overlay where needed) in a sandbox on each target and record:
dockerd starts; **kernel version** (Mongo's 6.19+ guard); `vm.max_map_count` for ES (a host sysctl, can't be set
from inside a container); amd64 emulation for ES 7.1.1/elasticmq on arm64 hosts; memory and disk needed; cold
`up` time with and without an image cache; whether suspend/freeze stops the containers too.

- Docker Desktop and OrbStack (`--privileged`), Ubuntu 24.04 server with Sysbox, the same with gVisor, Blaxel,
  Runtime Cloud.
- Outcome is a per-provider support matrix. A provider that can't run it shows compose projects as "not
  supported on this provider" rather than half-working.

**Results, 2026-09-30, local (OrbStack, kernel 7.0.14, arm64): it works.** platform2's whole stack (15
containers, base + override + arm64 overlay) ran unmodified on Docker inside a privileged container: Mongo
became primary, ES 7.1.1 and elasticmq ran as amd64 (OrbStack's Rosetta), the app answered on 8080 and GraphQL on 4000. Pausing the outer container froze the nested ones and they carried on after. Docker also started inside
`onedrop-sandbox` itself (+278 MB image). Measured: ~2.4 GB RAM steady (ES ~1.45 GB of it), ~8.3 GB disk for the
nested Docker (containerd keeps images compressed and unpacked), 24 s cold start to all ready, 19 s restart.
Two workers crash-looped, the same as on the host: repo bugs (`worker_default` has no `./api` mount and the image
has no vendor tree; `.env` lacks `sqs_queue_webhooks`), not nesting. What this changes:

- **Docker's data root must be its own volume/disk**, never the sandbox's overlay root (overlay-on-overlay fails:
  `failed to convert whiteout file`, `invalid argument`). This is the per-project disk Phase 4 keeps across updates.
- **Service data is owned by other users** (Mongo's `./tmp/mongo` is uid 999), so checkpoints, updates and resets
  need root for those paths or skip them. The image needs `sudo` (for the narrow dockerd rule) too.
- **amd64 images on arm64 hosts need emulation on the host** (Rosetta on OrbStack; qemu-user in binfmt_misc on an
  arm64 server). A host setting: on the support matrix.
- **Size:** at least 4 GB RAM and 20 GB disk for a stack like this before real data.
- **Cold starts restart workers a few times** until Mongo/elasticmq are up; Services and the error overlay wait
  ~30 s before calling anything failed.
- **Import clones from git, never copies a folder:** the working tree had 10 GB of `.claude/worktrees` and
  1.5 GB of ignored coverage.
- **Detection can flag repo problems:** an `env_file` key the compose file needs but `.env` lacks, a service whose
  command needs a mount it doesn't have.
- Kernel 7.0.14 ran Mongo 8.0.16 fine; still record each provider's kernel.

**Results, 2026-10-02, Runtime Cloud and Blaxel: Docker runs inside both.** Throwaway sandboxes, Docker
installed and started by hand, then `docker run` and a two-service compose stack (nginx + redis):

|                                       | Runtime Cloud                                            | Blaxel                                                                                                               |
| ------------------------------------- | -------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| Kernel                                | 6.1.186 (under Mongo's 6.19 guard)                       | 6.1.166                                                                                                              |
| Root filesystem                       | ext4 on `/dev/vda`, 7.9 GB                               | overlay, 1.9 GB (stock image)                                                                                        |
| Root for dockerd                      | `sudo` works as the sandbox user                         | stock image runs as root; our image runs everything as the sandbox user, so it needs the dockerd sudo rule           |
| Docker storage driver                 | overlayfs, straight on the root disk (no extra volume)   | **vfs**: overlay on overlay isn't allowed, so every layer is a full copy (slow, disk-hungry)                         |
| `docker run`, compose, published port | works, nginx 200                                         | works, but **port 8080 is taken by Blaxel's sandbox API**: a stack publishing 8080 (platform2's `app`) can't bind it |
| After pause/resume                    | stack still up and answering                             | not tested (the stock image has no `/workspace`)                                                                     |
| cgroups                               | v2                                                       | v2                                                                                                                   |
| `vm.max_map_count`                    | 65530 (ES wants 262144; a VM can raise it with `sysctl`) | 65530                                                                                                                |
| Default size                          | 2 vCPU, 4 GB, 8 GB disk: too small for platform2         | 4 GB, small root disk                                                                                                |

What this changes:

- **Runtime is the easy one**, and it's production: no privileged mode or extra volume, just start dockerd (via the
  existing sudo) when Docker inside sandboxes is on, raise `vm.max_map_count`, and offer bigger sizes (disk first).
- **Blaxel needs a real disk for `/var/lib/docker`** (a Blaxel volume/drive, if one can be ext4) or it's stuck on vfs,
  and **the sandbox API's 8080 must move** or be reserved like the sandbox's other ports. Until then, compose
  projects on Blaxel are "not supported" and the admin order skips Blaxel for them.

### Phase 1: Docker in the sandbox

- Image: Docker engine + compose plugin; `/opt/onedrop/dockerd`; sandbox user in the `docker` group.
- `DockerSandboxProvider`: `SANDBOX_DOCKER_NESTED=privileged|runtime|off` (`privileged` only when local).
  `infra/server/bootstrap.sh` installs Sysbox and sets `vm.max_map_count=262144`.
- Blaxel/Runtime per the spike (image flag, memory and disk tier).
- Per-project sandbox size (CPUs, memory, disk), defaulting from the size hint; the 2 GB default won't hold this.
- Tests: fake-provider assertions on the create command per mode; `RUN_DOCKER_TESTS` integration test that a
  compose project (nginx + redis) serves its preview.

### Phase 2: Detect

- Run detection on repo import (GIT-005 "Existing repository"), on sandbox start, and when the Files watcher sees
  a compose, devcontainer or devbox file change.
- On import: "This project runs with Docker Compose (13 services)" with the file set, profiles, preview service,
  workers on/off, the size it wants, and what's missing (secrets, `.env`), before anything runs.
- Tests: unit tests on `RunRecipes` and `ComposeProject` from fixtures (platform2's files and their `docker compose
config` output, plus a plain Node + Postgres compose and a devbox-only repo); feature test for the import prompt.

### Phase 3: Run

- `.onedrop/dev` → `/opt/onedrop/compose up` (foreground; `--build --remove-orphans`, chosen files, profiles,
  platform override). Setup step before the first `up`.
- Gateway addresses for extra HTTP ports; host proxy rewrites their loopback links.
- Tools → Services panel; compose logs feed the preview error overlay (ERR-001) and the agent's error context.
- Agent instructions section; the agent can run `docker` directly.
- Browser test (dev user): import a compose fixture repo, add a secret, accept the prompt, see the app in the
  preview, restart a service from Services.

### Phase 4: Keep data through updates, checkpoints and backups

- Bind-mounted data in `/workspace` (like `./tmp/mongo`) is already kept by updates. Exclude detected data mounts
  (targets like `/data/db`, `/var/lib/postgresql/data`, `/usr/share/elasticsearch/data`) from checkpoints, next to
  `node_modules/` in `checkpoint`.
- Named volumes (`mongo_data`, `graphql_node_modules`): `compose volumes export` into
  `/home/sandbox/.onedrop/volumes` before an update copies files, `import` on the new sandbox. Images aren't kept;
  they're pulled/built again. Multi-GB data makes updates slow: measure, and consider skipping volumes the user
  marks as disposable (re-run setup instead).
- Freeze-for-copy (SBX-002/003/004) must cover the containers, so Mongo is copied consistently.
- Image cache: a pull-through registry mirror per server (`registry-mirrors` in the sandbox's `daemon.json`), so
  the second project doesn't download Mongo again.

### Phase 5: Devbox services as a way to run

- Where the repo ships `devbox.json` + `process-compose.yaml`, `.onedrop/dev` runs `devbox services up` instead.
  Depends on the Devbox image (`devbox-sandboxes.md`); unfree packages (Mongo, ES) mean slow first installs, so
  Compose stays the recommended choice when both exist.

### Phase 6: Database panel for Mongo-compatible databases

- Tools → Database learns MongoDB (and compatible servers, e.g. FerretDB, DocumentDB): list databases and
  collections, browse documents with a filter, edit a document as JSON, run a query. Connects to the stack's Mongo
  by its service name, through a small script in the sandbox like `db.php` (PDO can't speak Mongo).

### Phase 7: Task copies

- A task (TASK-003) gets its own stack: bind-mounted data comes with the workspace copy, named volumes are
  exported/imported, and `COMPOSE_PROJECT_NAME` differs. Until then, tasks on compose projects are disabled with a
  reason.

## Proposed spec entry

```md
## SBX-008: Run a project the way its repo says

- User should see how an imported project can run (Docker Compose, its devbox setup, or OneDrop's default), with the recommended one picked, before anything runs.
- User should see a compose project's services, the files and profiles in use, the size it needs, and what's missing (secrets, .env) first.
- User should be able to run the project's compose stack in its sandbox, unmodified, and see the chosen service in the preview.
- User should be able to reach every other web port the app publishes (e.g. its GraphQL API) through OneDrop, behind the same sign-in as the preview.
- User should be able to pick the preview service, change the compose files and profiles, and switch services (e.g. workers) on and off.
- User should be able to give the stack its secrets (build secrets, registry logins, setup credentials) from Secrets, never in the repo.
- User should be able to run and re-run the project's setup step (e.g. restoring a database).
- User should be able to see each service's status and logs, and restart it, from Tools → Services.
- The agent should work with the stack: read its logs, run commands inside its containers, restart services.
- Services should be reachable from the Shell tab and the agent by their compose names as well as localhost.
- A project's service data (e.g. its Mongo database) should survive sandbox updates, and never be checkpointed into git.
- User should see why a compose project can't run (missing secret, not enough memory, provider without Docker support) instead of a broken preview.
- Compose projects should work the same with local Docker, on a server (Sysbox or gVisor), and on Blaxel or Runtime where Docker in the sandbox is supported.
```

## Open questions

- Blaxel and Runtime: can dockerd run in their VMs, what kernel do they run (Mongo's 6.19+ guard), and can ES get
  `vm.max_map_count`? Is 16 GB / 8 CPUs even offered? (Phase 0.)
- Run the workers by default, or only the app and its backing services until asked? (platform2 has nine.)
- Updating sandboxes with multi-GB volumes: copy them, or keep them on a disk that outlives the sandbox where the
  provider has one?
