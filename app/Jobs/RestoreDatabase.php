<?php

namespace App\Jobs;

use App\Sandbox\DatabaseBackups;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Replace the app's database with a backup (ADMIN-005).
 */
class RestoreDatabase implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $name) {}

    /**
     * Execute the job. How it went is saved in the restored database, so the Backups page shows it afterwards.
     */
    public function handle(DatabaseBackups $backups): void
    {
        Cache::put(BackUpDatabase::BUSY, 'restore', now()->addHour());

        try {
            $backups->restore($this->name);
            $backups->remember('last_restore', true, null, $this->name);
        } catch (Throwable $e) {
            report($e);
            $backups->remember('last_restore', false, $e->getMessage(), $this->name);
        } finally {
            Cache::forget(BackUpDatabase::BUSY);
        }
    }
}
