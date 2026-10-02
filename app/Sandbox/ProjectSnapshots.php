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
     * The project's latest snapshot.
     */
    public function latest(Project $project): ?ProjectSnapshot
    {
        return $project->snapshots()->latest('id')->first();
    }

    /**
     * Take a snapshot of the project's running main sandbox. Layers that didn't change since the latest snapshot are
     * kept as they are; with none changed, the latest is returned. Null when there's nothing to snapshot (no running
     * sandbox, a disk it can't reach, or a sandbox without the snapshot tool that can't be given it).
     *
     * @throws SandboxException
     */
    public function take(Project $project, string $reason): ?ProjectSnapshot
    {
        $sandbox = $project->sandbox()->first();
        $transport = $sandbox ? $this->transport($sandbox->provider) : null;

        if (! $this->isRunning($sandbox) || $transport === null || ! $this->hasScript($sandbox)) {
            return null;
        }

        return Cache::lock("project-snapshot:{$project->id}", self::LOCK_SECONDS)
            ->block(self::LOCK_SECONDS, fn () => $this->snapshot($project, $sandbox, $transport, $reason));
    }

    /**
     * Unpack a snapshot (the latest by default) into a sandbox: the project's files, dependencies, home folder and
     * App Storage as they were. Returns false when there's no snapshot or the sandbox can't reach it. The caller
     * restarts the app.
     *
     * @throws SandboxException
     */
    public function restore(Project $project, Sandbox $sandbox, ?ProjectSnapshot $snapshot = null): bool
    {
        $snapshot ??= $this->latest($project);
        $transport = $this->transport($sandbox->provider);

        if ($snapshot === null || ! $this->isRunning($sandbox) || $transport === null || ! $this->hasScript($sandbox)) {
            return false;
        }

        $layers = collect(self::LAYERS)->filter(fn (string $layer) => isset($snapshot->layers[$layer]));

        if ($transport === 'links') {
            foreach ($layers as $layer) {
                $this->run($sandbox, [self::SCRIPT, 'restore', $layer, 'url'], [
                    'ONEDROP_SNAPSHOT_URL' => $this->disk()->temporaryUrl($snapshot->layers[$layer]['path'], now()->addMinutes(self::LINK_MINUTES)),
                    'ONEDROP_SNAPSHOT_COMPRESSION' => $snapshot->layers[$layer]['compression'],
                ], "Couldn't restore the project's {$layer}");
            }

            return true;
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
     * Keep the latest 10 snapshots and the newest of each of the last 7 days; delete the rest, and stored layers no
     * kept snapshot points at.
     */
    public function prune(Project $project): int
    {
        $snapshots = $project->snapshots()->latest('id')->get();
        // Newest first, so the first of each day is that day's newest.
        $kept = $snapshots->take(10)
            ->merge($snapshots->filter(fn (ProjectSnapshot $snapshot) => $snapshot->created_at->gt(now()->subDays(7)))
                ->unique(fn (ProjectSnapshot $snapshot) => $snapshot->created_at->toDateString()))
            ->unique('id');
        $dropped = $snapshots->whereNotIn('id', $kept->pluck('id'));

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
    protected function snapshot(Project $project, Sandbox $sandbox, string $transport, string $reason): ProjectSnapshot
    {
        $fingerprints = $this->fingerprints($sandbox);
        $previous = $this->latest($project);
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
                        'path' => $this->path($project->id)."/layers/{$layer}-{$fingerprints[$layer]}.tar.{$compression}",
                        'fingerprint' => $fingerprints[$layer],
                        'compression' => $compression,
                        'size' => (int) $size,
                    ];
                }
            }

            $this->store($sandbox, $transport, $remote, array_intersect_key($layers, array_flip($changed)));
        } finally {
            $this->provider->exec($sandbox->external_id, ['rm', '-rf', $remote]);
        }

        return $project->snapshots()->create([
            'reason' => $reason,
            'layers' => $layers,
            'size' => array_sum(array_column($layers, 'size')),
        ]);
    }

    /**
     * Move packed layers from the sandbox to the disk.
     *
     * @param  array<string, array{path: string, fingerprint: string, compression: string, size: int}>  $layers
     *
     * @throws SandboxException
     */
    protected function store(Sandbox $sandbox, string $transport, string $remote, array $layers): void
    {
        if ($transport === 'links') {
            foreach ($layers as $layer => $meta) {
                ['url' => $url, 'headers' => $headers] = $this->disk()->temporaryUploadUrl($meta['path'], now()->addMinutes(self::LINK_MINUTES));

                $this->run($sandbox, [self::SCRIPT, 'put', "{$remote}/{$layer}.tar.{$meta['compression']}"], [
                    'ONEDROP_SNAPSHOT_URL' => $url,
                    'ONEDROP_SNAPSHOT_HEADERS' => $this->headerLines($headers),
                ], "Couldn't upload the project's {$layer}");
            }

            return;
        }

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
    protected function headerLines(array $headers): string
    {
        return collect($headers)
            ->map(fn (string|array $value, string $name) => $name.': '.(is_array($value) ? implode(', ', $value) : $value))
            ->implode("\n");
    }

    protected function isRunning(?Sandbox $sandbox): bool
    {
        return $sandbox?->status === SandboxStatus::Running && $sandbox->external_id !== null;
    }

    protected function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    protected function diskName(): string
    {
        return config('sandbox.snapshot_disk') ?: config('sandbox.backup_disk') ?: config('filesystems.default');
    }

    protected function path(int $projectId): string
    {
        return "project-snapshots/{$projectId}";
    }

    protected function localDirectory(): string
    {
        $directory = storage_path('framework/project-snapshot-'.uniqid());
        File::ensureDirectoryExists($directory);

        return $directory;
    }
}
