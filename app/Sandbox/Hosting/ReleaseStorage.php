<?php

namespace App\Sandbox\Hosting;

use App\Models\Project;
use App\Models\SystemSetting;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Where a deploy's release waits for the provider to fetch it (HOST-001): the snapshot disk when it's S3-compatible,
 * otherwise an R2 bucket made once in the Cloudflare account the project would use, with a key for that bucket only.
 * Either way the sandbox and the builder only ever get signed links.
 */
class ReleaseStorage
{
    /** The R2 buckets made for releases, by Cloudflare account id. */
    public const SETTING = 'hosting_releases';

    public const BUCKET = 'onedrop-releases';

    /** How long to wait for a new bucket key to work: READY_ATTEMPTS tries, READY_SECONDS apart. */
    protected const READY_ATTEMPTS = 30;

    protected const READY_SECONDS = 3;

    public function __construct(protected HostingProviders $providers) {}

    /**
     * Whether a project's releases have somewhere to go.
     */
    public function available(Project $project): bool
    {
        return $this->snapshotDiskWorks() || $this->providers->account($project->organization, 'cloudflare') !== null;
    }

    /**
     * The disk for a project's releases, making its R2 bucket the first time.
     *
     * @throws HostingException
     */
    public function disk(Project $project): Filesystem
    {
        if ($this->snapshotDiskWorks()) {
            return Storage::disk($this->snapshotDisk());
        }

        $account = $this->providers->account($project->organization, 'cloudflare')
            ?? throw new HostingException('Hosting needs somewhere to keep releases: turn on Cloudflare in Settings → Hosting, or use an S3-compatible SANDBOX_SNAPSHOT_DISK.');
        $accountId = (string) $account->get('account_id');
        $buckets = SystemSetting::group(self::SETTING);
        $bucket = $buckets[$accountId] ?? null;

        $made = ! is_array($bucket);

        if ($made) {
            $bucket = (new CloudflareApi($account))->createBucket(self::BUCKET);
            SystemSetting::merge(self::SETTING, [$accountId => $bucket]);
        }

        $disk = Storage::build([
            'driver' => 's3',
            'key' => $bucket['access_key_id'],
            'secret' => $bucket['secret_access_key'],
            'region' => 'auto',
            'bucket' => $bucket['bucket'],
            'endpoint' => $bucket['endpoint'],
            'use_path_style_endpoint' => true,
            'throw' => true,
        ]);

        if ($made) {
            $this->waitUntilReady($disk);
        }

        return $disk;
    }

    /**
     * A new R2 key takes a little while to work (a deploy once failed with a 401 twenty seconds after making one), so
     * try it before anything is handed a link signed with it.
     *
     * @throws HostingException
     */
    protected function waitUntilReady(Filesystem $disk): void
    {
        for ($attempt = 1; $attempt <= self::READY_ATTEMPTS; $attempt++) {
            try {
                $disk->put('.onedrop-ready', 'ok');

                return;
            } catch (Throwable) {
                Sleep::for(self::READY_SECONDS)->seconds();
            }
        }

        throw new HostingException("The releases bucket's new key didn't start working. Publish again in a minute.");
    }

    protected function snapshotDiskWorks(): bool
    {
        return config("filesystems.disks.{$this->snapshotDisk()}.driver") === 's3';
    }

    protected function snapshotDisk(): string
    {
        return config('sandbox.snapshot_disk') ?: config('sandbox.backup_disk') ?: config('filesystems.default');
    }
}
