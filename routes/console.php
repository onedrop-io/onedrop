<?php

use App\Jobs\BackUpDatabase;
use App\Sandbox\DatabaseBackups;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Laravel Cloud has no Docker or host of ours, and wakes a sleeping app for every task: one due every few seconds
// would keep it awake for good.
if (! config('app.laravel_cloud')) {
    // Docker sandboxes don't pause by themselves when idle (SBX-007).
    Schedule::command('sandbox:suspend-idle')->everyFifteenSeconds()->withoutOverlapping();

    // Server monitoring (ADMIN-003): a sample of the server's resources every minute.
    Schedule::command('server:sample')->everyMinute()->withoutOverlapping();
}

// Runtime charges for stored images and caps how many an account has; every sandbox image build leaves the
// previous version behind (SBX-003).
Schedule::command('sandbox:prune-images')->hourly()->withoutOverlapping();

// Database backups (ADMIN-005), on the schedule an admin chose in Settings → Backups.
Schedule::job(new BackUpDatabase)
    ->cron(app(DatabaseBackups::class)->settings()['schedule'])
    ->when(fn (DatabaseBackups $backups) => $backups->scheduled())
    ->name('database-backup');
