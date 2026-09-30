<?php

use App\Jobs\BackUpDatabase;
use App\Sandbox\DatabaseBackups;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Docker sandboxes don't pause by themselves when idle (SBX-007).
Schedule::command('sandbox:suspend-idle')->everyFifteenSeconds()->withoutOverlapping();

// Server monitoring (ADMIN-003): a sample of the server's resources every minute.
Schedule::command('server:sample')->everyMinute()->withoutOverlapping();

// Database backups (ADMIN-005), on the schedule an admin chose in Settings → Backups.
Schedule::job(new BackUpDatabase)
    ->cron(app(DatabaseBackups::class)->settings()['schedule'])
    ->when(fn (DatabaseBackups $backups) => $backups->scheduled())
    ->name('database-backup');
