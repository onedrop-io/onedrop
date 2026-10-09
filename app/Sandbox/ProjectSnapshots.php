<?php

namespace App\Sandbox;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\ProjectSnapshot;
use App\Models\Sandbox;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File as LocalFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Keeps each project's whole state outside its sandbox (SBX-009): its workspace, dependencies, the sandbox user's home
 * folder (a database the agent set up, the agent's history) and App Storage, in layers on the snapshot disk. A layer
 * that didn't change isn't packed or stored again. The sandbox packs and unpacks them itself (docker/sandbox/snapshot):
 * on an S3-compatible disk it uploads and downloads straight to it through signed links, never through the platform,
 * and never with storage credentials; on a local disk (Docker sandboxes only) the platform copies them on the same machine.
 */
class ProjectSnapshots
{
    /** In the order they're restored: dependencies go into the workspace. */
    public const LAYERS = ['workspace', 'deps', 'home', 'storage'];

    /** How long a signed upload or download link lasts. */
    protected const LINK_MINUTES = 30;

    /** Longest a snapshot may hold the project's snapshot lock, in seconds. */
    protected const LOCK_SECONDS = 900;

    protected const SCRIPT = SandboxTools::PATH.'/snapshot';

    public function __construct(protected SandboxProvider $provider, protected SandboxTools $tools) {}

    /**
     * How a sandbox's snapshots move: 'links' (straight between the sandbox and an S3-compatible disk), 'copy' (a local
     * disk and a Docker sandbox on the same machine), or null when they can't (a local disk and a remote sandbox).
     */
    public function transport(string $provider): ?string
    {
        return match (true) {
            config("filesystems.disks.{$this->diskName()}.driver") === 's3' => 'links',
            $provider === 'docker' => 'copy',
            default => null,
        };
    }

    /**
     * The project's latest snapshot that a new sandbox may get: ready, the project's own (not a task copy's), and
     * not files recovered from a sandbox that had stopped answering (SBX-013), which only an admin restores.
     */
    public function latest(Project $project): ?ProjectSnapshot
    {
        return $project->snapshots()->whereNull('task_id')->where('status', ProjectSnapshot::READY)
            ->where('reason', '!=', ProjectSnapshot::RECOVERED)->latest('id')->first();
    }

    /**
     * Take a snapshot of the project's running main sandbox and wait for it. Layers that didn't change since the
     * latest snapshot are kept as they are; with none changed, the latest is returned. Null when there's nothing to
     * snapshot (no running sandbox, a disk it can't reach, or a sandbox without the snapshot tool that can't be given
     * it). For commands; a queue job starts one with begin() and checks on it with check() (TakeSnapshot).
     *
     * @throws SandboxException
     */
    public function take(Project $project, string $reason): ?ProjectSnapshot
    {
        $snapshot = ($sandbox = $project->sandbox()->first()) ? $this->begin($sandbox, $reason) : null;

        while ($snapshot?->isPending() && ! $this->check($snapshot)) {
            Sleep::for(2)->seconds();
        }

        return $snapshot;
    }

    /**
     * Start a snapshot of a running sandbox (the project's main one, or a task's copy), packed and uploaded by the
     * sandbox in the background. Returns it pending (see check()), or ready: on a local disk it's taken on the spot,
     * and when no layer changed since $basis (the latest by default) that is returned. A snapshot already under way
     * in the sandbox is returned instead of starting a second. Null when it can't be taken (see take()).
     *
     * @throws SandboxException
     */
    public function begin(Sandbox $sandbox, string $reason, ?ProjectSnapshot $basis = null): ?ProjectSnapshot
    {
        $transport = $this->transport($sandbox->provider);

        if (! $this->isRunning($sandbox) || $transport === null || ! $this->hasScript($sandbox)) {
            return null;
        }

        return Cache::lock("project-snapshot:{$sandbox->project_id}", self::LOCK_SECONDS)->block(60, function () use ($sandbox, $transport, $reason, $basis) {
            $pending = ProjectSnapshot::query()->where('external_id', $sandbox->external_id)->where('status', ProjectSnapshot::PENDING)->latest('id')->first();

            if ($pending) {
                return $pending;
            }

            $project = $sandbox->project;
            $basis ??= $sandbox->task_id === null ? $this->latest($project) : null;

            if ($transport === 'copy') {
                return $this->snapshot($project, $sandbox, $reason, $basis);
            }

            $fingerprints = $this->fingerprints($sandbox);
            $changed = array_values(array_filter(self::LAYERS, fn (string $layer) => ($basis?->layers[$layer]['fingerprint'] ?? null) !== $fingerprints[$layer]));

            if ($changed === [] && $basis !== null) {
                return $basis;
            }

            $compression = trim($this->run($sandbox, [self::SCRIPT, 'compression'], [], "Couldn't pack the project's snapshot"));
            $layers = [];

            foreach (self::LAYERS as $layer) {
                $layers[$layer] = in_array($layer, $changed, true)
                    ? ['path' => $this->layerPath($project->id, $sandbox->task_id, $layer, $fingerprints[$layer], $compression), 'fingerprint' => $fingerprints[$layer], 'compression' => $compression, 'size' => 0]
                    : $basis->layers[$layer];
            }

            $snapshot = $project->snapshots()->create([
                'task_id' => $sandbox->task_id,
                'reason' => $reason,
                'status' => ProjectSnapshot::PENDING,
                'external_id' => $sandbox->external_id,
                'layers' => $layers,
            ]);

            try {
                $env = [];

                foreach ($changed as $layer) {
                    ['url' => $url, 'headers' => $headers] = $this->disk()->temporaryUploadUrl($layers[$layer]['path'], now()->addMinutes(self::LINK_MINUTES));
                    $env['ONEDROP_SNAPSHOT_URL_'.strtoupper($layer)] = $url;
                    $env['ONEDROP_SNAPSHOT_HEADERS_'.strtoupper($layer)] = $this->headerLines($headers);
                }

                $directory = $this->workDirectory('snapshot', $snapshot);
                $this->run($sandbox, [self::SCRIPT, 'begin', $directory], [], "Couldn't start the project's snapshot");
                $this->provider->exec($sandbox->external_id, [self::SCRIPT, 'take', $directory, ...$changed], $env, detach: true);
            } catch (SandboxException $e) {
                $snapshot->update(['status' => ProjectSnapshot::FAILED]);

                throw $e;
            }

            return $snapshot;
        });
    }

    /**
     * Whether a pending snapshot is ready: its sandbox has packed and uploaded every changed layer. False while it's
     * still at it.
     *
     * @throws SandboxException when it failed, stopped without saying, or took too long (it's marked failed)
     */
    public function check(ProjectSnapshot $snapshot): bool
    {
        if (! $snapshot->isPending()) {
            return $snapshot->status === ProjectSnapshot::READY;
        }

        try {
            $work = $this->background((string) $snapshot->external_id, $this->workDirectory('snapshot', $snapshot));

            if ($work['state'] === 'running' || $work['state'] === 'none') {
                if ($snapshot->created_at->lt(now()->subMinutes(self::LINK_MINUTES))) {
                    throw new SandboxException("The project's snapshot took longer than ".self::LINK_MINUTES.' minutes.');
                }

                return false;
            }

            if ($work['state'] !== 'done') {
                throw new SandboxException($work['state'] === 'lost'
                    ? "The project's snapshot stopped before it finished."
                    : "Couldn't take the project's snapshot: ".(strtok(trim($work['error']), "\n") ?: 'unknown error'));
            }
        } catch (SandboxException $e) {
            $snapshot->update(['status' => ProjectSnapshot::FAILED]);

            throw $e;
        }

        $layers = $snapshot->layers;

        foreach (preg_split('/\R/', trim($work['output'])) ?: [] as $line) {
            [$layer, , $size] = explode(' ', $line) + [null, null, null];

            if (isset($layers[$layer])) {
                $layers[$layer]['size'] = (int) $size;
            }
        }

        $snapshot->update(['status' => ProjectSnapshot::READY, 'layers' => $layers, 'size' => array_sum(array_column($layers, 'size'))]);
        $this->provider->exec((string) $snapshot->external_id, ['rm', '-rf', $this->workDirectory('snapshot', $snapshot)]);

        return true;
    }

    /**
     * Unpack a snapshot (the latest by default) into a sandbox and wait for it. Returns false when there's no snapshot
     * or the sandbox can't reach it. The caller restarts the app. For commands; a queue job uses startRestore().
     *
     * @throws SandboxException
     */
    public function restore(Project $project, Sandbox $sandbox, ?ProjectSnapshot $snapshot = null): bool
    {
        $snapshot ??= $this->latest($project);

        if ($snapshot === null || ! $this->canRestore($sandbox)) {
            return false;
        }

        if (! $this->startRestore($sandbox, $snapshot)) {
            while (! $this->restored($sandbox, $snapshot)) {
                Sleep::for(2)->seconds();
            }
        }

        return true;
    }

    /**
     * Whether a snapshot can be unpacked into this sandbox.
     *
     * @throws SandboxException
     */
    public function canRestore(Sandbox $sandbox): bool
    {
        return $this->isRunning($sandbox) && $this->transport($sandbox->provider) !== null && $this->hasScript($sandbox);
    }

    /**
     * Start unpacking a snapshot into a sandbox: the project's files, dependencies, home folder and App Storage as
     * they were. With signed links the sandbox downloads and unpacks them in the background (see restored()), and this
     * returns false; on a local disk they're unpacked on the spot, and it returns true.
     *
     * @throws SandboxException
     */
    public function startRestore(Sandbox $sandbox, ProjectSnapshot $snapshot): bool
    {
        $layers = collect(self::LAYERS)->filter(fn (string $layer) => isset($snapshot->layers[$layer]));

        if ($this->transport($sandbox->provider) === 'links') {
            $env = [];

            foreach ($layers as $layer) {
                $env['ONEDROP_SNAPSHOT_URL_'.strtoupper($layer)] = $this->disk()->temporaryUrl($snapshot->layers[$layer]['path'], now()->addMinutes(self::LINK_MINUTES));
                $env['ONEDROP_SNAPSHOT_COMPRESSION_'.strtoupper($layer)] = $snapshot->layers[$layer]['compression'];
            }

            $directory = $this->workDirectory('restore', $snapshot);
            $this->run($sandbox, [self::SCRIPT, 'begin', $directory], [], "Couldn't start restoring the project's snapshot");
            $this->provider->exec($sandbox->external_id, [self::SCRIPT, 'unpack', $directory, ...$layers->values()->all()], $env, detach: true);

            return false;
        }

        $local = $this->localDirectory();
        $remote = '/tmp/onedrop-restore-'.Str::lower(Str::random(8));

        try {
            foreach ($layers as $layer) {
                $this->download($snapshot->layers[$layer]['path'], "{$local}/{$layer}.tar.{$snapshot->layers[$layer]['compression']}");
            }

            $this->provider->copyIn($sandbox->external_id, $local, $remote);
        } finally {
            File::deleteDirectory($local);
        }

        foreach ($layers as $layer) {
            $this->run($sandbox, [self::SCRIPT, 'restore', $layer, "{$remote}/{$layer}.tar.{$snapshot->layers[$layer]['compression']}"], [], "Couldn't restore the project's {$layer}");
        }

        $this->provider->exec($sandbox->external_id, ['rm', '-rf', $remote]);

        return true;
    }

    /**
     * Whether the sandbox has finished unpacking a snapshot started with startRestore(). False while it's at it.
     *
     * @throws SandboxException when it failed or stopped without saying
     */
    public function restored(Sandbox $sandbox, ProjectSnapshot $snapshot): bool
    {
        $directory = $this->workDirectory('restore', $snapshot);
        $work = $this->background((string) $sandbox->external_id, $directory);

        if ($work['state'] === 'running') {
            return false;
        }

        if ($work['state'] !== 'done') {
            throw new SandboxException(match ($work['state']) {
                'lost' => "Restoring the project's snapshot stopped before it finished.",
                'none' => "Restoring the project's snapshot never started.",
                default => "Couldn't restore the project's snapshot: ".(strtok(trim($work['error']), "\n") ?: 'unknown error'),
            });
        }

        $this->provider->exec((string) $sandbox->external_id, ['rm', '-rf', $directory]);

        return true;
    }

    /**
     * Delete a snapshot that only carried a task's copy to a new sandbox, and its files.
     */
    public function forget(ProjectSnapshot $snapshot): void
    {
        $used = ProjectSnapshot::query()->whereKeyNot($snapshot->id)->where('project_id', $snapshot->project_id)->get()
            ->flatMap(fn (ProjectSnapshot $other) => collect($other->layers)->pluck('path'));

        $this->disk()->delete(collect($snapshot->layers)->pluck('path')->diff($used)->values()->all());
        $snapshot->delete();
    }

    /**
     * Keep the latest 10 snapshots and the newest of each of the last 7 days; delete the rest, and stored layers no
     * kept snapshot points at.
     */
    public function prune(Project $project): int
    {
        $all = $project->snapshots()->latest('id')->get();
        $snapshots = $all->whereNull('task_id')->where('status', ProjectSnapshot::READY)->where('reason', '!=', ProjectSnapshot::RECOVERED);
        // Newest first, so the first of each day is that day's newest.
        $kept = $snapshots->take(10)
            ->merge($snapshots->filter(fn (ProjectSnapshot $snapshot) => $snapshot->created_at->gt(now()->subDays(7)))
                ->unique(fn (ProjectSnapshot $snapshot) => $snapshot->created_at->toDateString()))
            // Under way, a task copy's on its way to a new sandbox, and recovered files an admin may still restore.
            ->merge($all->filter(fn (ProjectSnapshot $snapshot) => $snapshot->isPending() && $snapshot->created_at->gt(now()->subDay())))
            ->merge($all->whereNotNull('task_id')->where('status', '!=', ProjectSnapshot::FAILED))
            ->merge($all->where('reason', ProjectSnapshot::RECOVERED)->filter(fn (ProjectSnapshot $snapshot) => $snapshot->created_at->gt(now()->subDays(30))))
            ->unique('id');
        $dropped = $all->whereNotIn('id', $kept->pluck('id'));

        if ($dropped->isEmpty()) {
            return 0;
        }

        $used = $kept->flatMap(fn (ProjectSnapshot $snapshot) => collect($snapshot->layers)->pluck('path'))->unique();
        $unused = $dropped->flatMap(fn (ProjectSnapshot $snapshot) => collect($snapshot->layers)->pluck('path'))->unique()->diff($used);

        $this->disk()->delete($unused->values()->all());
        ProjectSnapshot::query()->whereKey($dropped->pluck('id'))->delete();

        return $dropped->count();
    }

    /**
     * Remove every snapshot of the project (it's being deleted).
     */
    public function delete(Project $project): void
    {
        $this->disk()->deleteDirectory($this->path($project->id));
    }

    /**
     * @throws SandboxException
     */
    protected function snapshot(Project $project, Sandbox $sandbox, string $reason, ?ProjectSnapshot $previous): ProjectSnapshot
    {
        $fingerprints = $this->fingerprints($sandbox);
        $layers = [];
        $changed = [];

        foreach (self::LAYERS as $layer) {
            $before = $previous?->layers[$layer] ?? null;

            if ($before !== null && $before['fingerprint'] === $fingerprints[$layer]) {
                $layers[$layer] = $before;
            } else {
                $changed[] = $layer;
            }
        }

        if ($changed === [] && $previous !== null) {
            return $previous;
        }

        $remote = '/tmp/onedrop-snapshot-'.Str::lower(Str::random(8));

        try {
            $packed = $this->run($sandbox, [self::SCRIPT, 'pack', ...$changed, $remote], [], "Couldn't pack the project's snapshot");

            foreach (preg_split('/\R/', trim($packed)) ?: [] as $line) {
                [$layer, $compression, $size] = explode(' ', $line) + [null, null, null];

                if (in_array($layer, $changed, true)) {
                    $layers[$layer] = [
                        'path' => $this->layerPath($project->id, $sandbox->task_id, $layer, $fingerprints[$layer], $compression),
                        'fingerprint' => $fingerprints[$layer],
                        'compression' => $compression,
                        'size' => (int) $size,
                    ];
                }
            }

            $this->store($sandbox, $remote, array_intersect_key($layers, array_flip($changed)));
        } finally {
            $this->provider->exec($sandbox->external_id, ['rm', '-rf', $remote]);
        }

        return $project->snapshots()->create([
            'task_id' => $sandbox->task_id,
            'reason' => $reason,
            'status' => ProjectSnapshot::READY,
            'layers' => $layers,
            'size' => array_sum(array_column($layers, 'size')),
        ]);
    }

    /**
     * Copy packed layers out of a sandbox on the same machine to the (local) disk.
     *
     * @param  array<string, array{path: string, fingerprint: string, compression: string, size: int}>  $layers
     *
     * @throws SandboxException
     */
    protected function store(Sandbox $sandbox, string $remote, array $layers): void
    {
        $local = $this->localDirectory();

        try {
            $this->provider->copyOut($sandbox->external_id, $remote, $local);

            foreach ($layers as $layer => $meta) {
                $file = "{$local}/{$layer}.tar.{$meta['compression']}";

                if (! is_file($file)) {
                    throw new SandboxException("The project's {$layer} didn't copy out of its sandbox.");
                }

                $this->disk()->putFileAs(dirname($meta['path']), new LocalFile($file), basename($meta['path']));
            }
        } finally {
            File::deleteDirectory($local);
        }
    }

    /**
     * @return array<string, string> layer => fingerprint
     *
     * @throws SandboxException
     */
    protected function fingerprints(Sandbox $sandbox): array
    {
        $output = $this->run($sandbox, [self::SCRIPT, 'fingerprint'], [], "Couldn't read the project's files");
        $fingerprints = [];

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            [$layer, $fingerprint] = explode(' ', $line) + [null, null];
            $fingerprints[$layer] = (string) $fingerprint;
        }

        foreach (self::LAYERS as $layer) {
            if (($fingerprints[$layer] ?? '') === '') {
                throw new SandboxException("Couldn't read the project's {$layer}.");
            }
        }

        return $fingerprints;
    }

    /**
     * The snapshot tool, copied in when it's missing or older (it runs on any base).
     *
     * @throws SandboxException
     */
    protected function hasScript(Sandbox $sandbox): bool
    {
        if ($this->tools->available()) {
            return $this->tools->ensure($this->provider, $sandbox->external_id, ['snapshot']);
        }

        return $this->provider->exec($sandbox->external_id, ['test', '-x', self::SCRIPT])->successful();
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     *
     * @throws SandboxException
     */
    protected function run(Sandbox $sandbox, array $command, array $env, string $failure): string
    {
        $result = $this->provider->exec($sandbox->external_id, $command, $env);

        if (! $result->successful()) {
            throw new SandboxException("{$failure}: ".(strtok(trim($result->errorOutput ?: $result->output), "\n") ?: 'unknown error'));
        }

        return $result->output;
    }

    /**
     * @throws SandboxException
     */
    protected function download(string $path, string $file): void
    {
        $stream = $this->disk()->readStream($path);

        if ($stream === null) {
            throw new SandboxException("Couldn't read the snapshot's {$path}.");
        }

        $target = fopen($file, 'wb');

        if ($target === false) {
            fclose($stream);

            throw new SandboxException("Couldn't write the snapshot's {$path} to {$file}.");
        }

        stream_copy_to_stream($stream, $target);
        fclose($target);
        fclose($stream);
    }

    /**
     * An upload link's headers as "Name: value" lines, for the sandbox's curl.
     *
     * @param  array<string, string|list<string>>  $headers
     */
    public function headerLines(array $headers): string
    {
        return collect($headers)
            ->map(fn (string|array $value, string $name) => $name.': '.(is_array($value) ? implode(', ', $value) : $value))
            ->implode("\n");
    }

    protected function isRunning(?Sandbox $sandbox): bool
    {
        return $sandbox?->status === SandboxStatus::Running && $sandbox->external_id !== null;
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    protected function diskName(): string
    {
        return config('sandbox.snapshot_disk') ?: config('sandbox.backup_disk') ?: config('filesystems.default');
    }

    /**
     * The project's folder on the snapshot disk; deleting the project deletes it.
     */
    public function path(int $projectId): string
    {
        return "project-snapshots/{$projectId}";
    }

    /**
     * Where a layer is stored: the project's layers are shared by its snapshots; a task copy's are its own.
     */
    protected function layerPath(int $projectId, ?int $taskId, string $layer, string $fingerprint, string $compression): string
    {
        return $this->path($projectId).($taskId === null ? '/layers' : "/tasks/{$taskId}")."/{$layer}-{$fingerprint}.tar.{$compression}";
    }

    /**
     * Where the sandbox keeps track of background work on a snapshot.
     */
    protected function workDirectory(string $kind, ProjectSnapshot $snapshot): string
    {
        return "/tmp/onedrop-{$kind}-{$snapshot->id}";
    }

    /**
     * How background work in a sandbox is doing (see `snapshot status`).
     *
     * @return array{state: string, output: string, error: string}
     *
     * @throws SandboxException
     */
    protected function background(string $externalId, string $directory): array
    {
        $result = $this->provider->exec($externalId, [self::SCRIPT, 'status', $directory]);

        if (! $result->successful()) {
            throw new SandboxException("Couldn't check on the project's snapshot: ".(strtok(trim($result->errorOutput), "\n") ?: 'unknown error'));
        }

        [$head, $error] = explode("::error::\n", $result->output, 2) + [1 => ''];
        [$state, $output] = explode("\n", $head, 2) + [1 => ''];

        return ['state' => trim($state), 'output' => $output, 'error' => $error];
    }

    protected function localDirectory(): string
    {
        $directory = storage_path('framework/project-snapshot-'.uniqid());
        File::ensureDirectoryExists($directory);

        return $directory;
    }
}
