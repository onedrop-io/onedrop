<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;

/**
 * The commands routes/console.php schedules, loaded afresh with the given config.
 *
 * @return list<string>
 */
function scheduledCommands(bool $onLaravelCloud): array
{
    config(['app.laravel_cloud' => $onLaravelCloud]);
    app()->instance(Schedule::class, $schedule = new Schedule);
    ScheduleFacade::clearResolvedInstance();

    require base_path('routes/console.php');

    return collect($schedule->events())
        ->map(fn ($event) => $event->description ?? (string) preg_replace('/^.*artisan.? /', '', (string) $event->command))
        ->values()
        ->all();
}

test('a host of our own runs every scheduled task', function () {
    expect(scheduledCommands(onLaravelCloud: false))
        ->toContain('sandbox:suspend-idle', 'server:sample', 'sandbox:prune-images', 'database-backup');
})->group('SBX-007', 'ADMIN-003');

test('Laravel Cloud skips the tasks that would keep a sleeping app awake', function () {
    $commands = scheduledCommands(onLaravelCloud: true);

    expect($commands)->toContain('sandbox:prune-images', 'database-backup')
        ->not->toContain('sandbox:suspend-idle')
        ->not->toContain('server:sample');
})->group('SBX-007', 'ADMIN-003');
