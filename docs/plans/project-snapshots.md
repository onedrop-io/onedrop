# Plan: Project snapshots in object storage

Status: phases 1–3 built (snapshots, restore, the `deps` layer, retention; admin page and multipart uploads not yet). Spec SBX-009; decisions in `REQ.md` → Sandboxes, which win where this plan differs (layer names under `project-snapshots/<id>/layers/`, local disks for Docker sandboxes).

Sandboxes should be disposable. A project's state lives in S3-compatible storage, and any sandbox on any provider
can be made from it. Switching providers then means changing `SANDBOX_PROVIDER`, and a provider losing a sandbox
(Blaxel's free-tier expiry, an outage, Runtime going away) costs a minute, not files.

## Why (what's wrong today)

- **Moves go through the platform.** `SandboxUpdater::replace()` stops the old sandbox, `copyOut`s `/home/sandbox`
  and the workspace to the platform's temp disk, then `copyIn`s them to the new one. One project at a time under a
  lock, the app frozen throughout, minutes for a few GB of `node_modules`.
- **Moves need the old sandbox.** Without it, `restoreCode()` only has the git bundle (SBX-006): no uncommitted
  work, home folder, database or dependencies.
- **App Storage lives only on the sandbox's disk** (STORE-001) outside Docker; the platform never keeps a copy.

## Design

### Storage

- One bucket, a filesystem disk named by `SANDBOX_SNAPSHOT_DISK` (falls back to `SANDBOX_BACKUP_DISK`, then the
  default disk). Any S3-compatible service works through the existing `league/flysystem-aws-s3-v3`; no new PHP
  dependency.
- Recommended: **Cloudflare R2** ($0.015/GB-month, no egress fees, so restores and provider moves cost nothing to
  download; we already run a Cloudflare Worker). Backblaze B2 or plain S3 also work.
- Layout: `projects/<id>/snapshots/<snapshot-id>/<layer>.tar.zst` plus a `manifest.json` per snapshot (layers,
  sizes, hashes, image version, provider, time).

### Layers

Split so the frequent snapshot stays small:

| Layer       | Contents                                                                        | Changes               |
| ----------- | ------------------------------------------------------------------------------- | --------------------- |
| `workspace` | `/workspace` without dependency folders, including uncommitted files and `.git` | every turn            |
| `home`      | `/home/sandbox` minus caches (`.cache`, `.npm`, agent temp)                     | often                 |
| `storage`   | `$APP_STORAGE_DIR` (App Storage)                                                | when the app writes   |
| `deps`      | `node_modules`, `vendor`, keyed by a hash of the lockfiles                      | when lockfiles change |

Each layer's hash is computed in the sandbox (file list, sizes and mtimes; content hash for `deps` keys). A layer
whose hash matches the previous snapshot is referenced, not uploaded again.

### Taking a snapshot

1. The platform asks the sandbox for each layer's hash (one `exec`).
2. For changed layers it creates **presigned PUT URLs** (multipart for anything over 100 MB), valid ~30 minutes,
   and runs `/opt/onedrop/snapshot` with them in `env`, never on the command line.
3. The script freezes the app's processes (the freeze/watchdog updates already use) only while copying `home` and
   `storage`, so a database is consistent, then streams `tar | zstd | curl` to each URL.
4. The platform writes the manifest once every upload succeeds; a partial snapshot is never the "latest".

When: after every agent turn (in the queued job that already makes the git bundle, which stays as the code-only backup), before any
update or provider move, and daily for sandboxes that ran that day.

### Restoring

`CreateSandbox` and `SandboxUpdater` start the new sandbox, presign GETs for the latest manifest's layers, and run
`/opt/onedrop/restore`, which downloads and extracts in parallel, then `/opt/onedrop/restart`. The old sandbox,
if it still exists, is deleted only after the restore succeeds (same rule as today). `copyOut`/`copyIn` stay only
for the Docker provider's local fast path.

`deps` is restored only when the new workspace's lockfile hash matches; otherwise the app's own install runs.

### Retention and admin

- `sandbox:prune-snapshots` daily: keep the last 10 and one a day for 7 days; layers still referenced are kept.
- Admin project page: last snapshot time, total size, restore from an earlier snapshot.
- Deleting a project deletes `projects/<id>/`.

## Phases

1. **Snapshots and restore** for `workspace`, `home`, `storage`: `/opt/onedrop/snapshot` and `restore` in the image
   (needs `zstd` and `curl` in the Dockerfile), presigning, manifest, take after each turn, restore on create and
   update. Provider moves stop going through the platform.
2. **`deps` layer** keyed by lockfiles, so restores skip reinstalls.
3. **Retention, admin view, restore from an earlier snapshot.**
4. **Provider contract tests**: one Pest suite (create, exec, snapshot, restore, preview link) every provider must
   pass with `RUN_PROVIDER_TESTS=1`, so adding a provider is a class plus green tests.
5. **Later, separate decision:** App Storage straight on object storage (apps get presigned links instead of files
   on the sandbox's disk), so it's no longer snapshotted at all.

## Open questions

- Snapshot sizes in practice (`/home/sandbox` with a Postgres, `node_modules`): measure on production projects
  before choosing the multipart threshold and retention.
- Incremental uploads inside a layer (restic or similar) would cut bandwidth further but adds a dependency to the
  image; start with whole layers and only revisit if layers are routinely large.
- Encryption: bucket-side encryption only, or a per-project key the platform holds?

## Risks

- A turn that ends while a snapshot uploads: snapshots for one project run one at a time (cache lock), and the
  next one waits.
- Freezing the app for `home` makes the preview pause briefly after each turn; keep it to the copy, not the upload,
  by copying to the sandbox's own temp disk first if it's noticeable.
- Signed URLs leak only one object for ~30 minutes, write-only or read-only.
