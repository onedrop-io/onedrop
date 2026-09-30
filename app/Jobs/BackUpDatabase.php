<?php

namespace App\Jobs;

use App\Sandbox\DatabaseBackups;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Back up the app's database (ADMIN-005), on its schedule or when an admin asks.
 */
class BackUpDatabase implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Set while a backup or restore runs, so the Backups page shows it. */
    public const BUSY = 'database-backups:busy';

    public int $tries = 1;

    public int $timeout = 3600;

    /**
     * Execute the job.
     */
    public function handle(DatabaseBackups $backups): void
    {
        Cache::put(self::BUSY, 'backup', now()->addHour());

        try {
            $backups->run();
        } finally {
            Cache::forget(self::BUSY);
        }
    }
}
