<?php

use App\Models\Sandbox;
use App\Models\ServerMetric;
use App\Models\User;
use App\Sandbox\ServerMetrics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia;

/**
 * A /proc with the given CPU time and counters.
 */
function fakeProc(int $busy = 300, int $idle = 700, int $readSectors = 2048, int $writtenSectors = 4096, int $in = 5000, int $out = 9000): string
{
    $proc = sys_get_temp_dir().'/onedrop-proc-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists("{$proc}/net");

    // user nice system idle iowait irq softirq steal
    File::put("{$proc}/stat", 'cpu  '.($busy - 50).' 0 50 '.($idle - 100)." 100 0 0 0 0 0\ncpu0 1 2 3 4 5 6 7 8 0 0\n");
    File::put("{$proc}/meminfo", "MemTotal:       16000000 kB\nMemFree:         2000000 kB\nMemAvailable:    6000000 kB\n");
    File::put("{$proc}/diskstats", implode("\n", [
        "   8       0 sda 100 0 {$readSectors} 0 50 0 {$writtenSectors} 0 0 0 0",
        '   8       1 sda1 100 0 999999 0 50 0 999999 0 0 0 0',
        '   7       0 loop0 1 0 999999 0 1 0 999999 0 0 0 0',
    ])."\n");
    File::put("{$proc}/net/dev", implode("\n", [
        'Inter-|   Receive                                                |  Transmit',
        ' face |bytes    packets errs drop fifo frame compressed multicast|bytes    packets errs drop fifo colls carrier compressed',
        '    lo: 99999 1 0 0 0 0 0 0 99999 1 0 0 0 0 0 0',
        "  eth0: {$in} 10 0 0 0 0 0 0 {$out} 10 0 0 0 0 0 0",
        '  veth1a2b: 77777 1 0 0 0 0 0 0 77777 1 0 0 0 0 0 0',
    ])."\n");

    return $proc;
}

test('a sample reads CPU time, memory, disk space, whole-disk I/O and real interfaces\' traffic', function () {
    $sample = (new ServerMetrics(fakeProc()))->read();

    expect($sample)->toMatchArray([
        'cpu_busy' => 300,
        'cpu_total' => 1000,
        'memory_used' => 10_000_000 * 1024,
        'memory_total' => 16_000_000 * 1024,
        'disk_read' => 2048 * 512,
        'disk_written' => 4096 * 512,
        'network_in' => 5000,
        'network_out' => 9000,
    ])->and($sample['disk_total'])->toBeGreaterThan(0);
})->group('ADMIN-003');

test('metrics the server cannot report are null, not zero', function () {
    $sample = (new ServerMetrics('/nonexistent-proc'))->read();

    expect($sample['cpu_busy'])->toBeNull()
        ->and($sample['memory_used'])->toBeNull()
        ->and($sample['disk_read'])->toBeNull()
        ->and($sample['network_in'])->toBeNull()
        ->and($sample['disk_total'])->toBeGreaterThan(0);
})->group('ADMIN-003');

test('the series shows CPU use and rates from the difference between samples, and old samples are deleted', function () {
    $now = Carbon::parse('2026-09-30 12:00:00');
    Carbon::setTestNow($now);
    ServerMetric::query()->create(['recorded_at' => $now->copy()->subDays(8)]);

    (new ServerMetrics(fakeProc(busy: 300, idle: 700, readSectors: 0, in: 0)))->record($now->copy()->subMinutes(2));
    (new ServerMetrics(fakeProc(busy: 350, idle: 750, readSectors: 1200, in: 6000)))->record($now->copy()->subMinute());

    expect(ServerMetric::query()->count())->toBe(2);

    $summary = app(ServerMetrics::class)->summary('1h', $now->getTimestamp());
    $last = collect($summary['series'])->last(fn (array $point) => $point['cpu'] !== null);

    expect($summary['bucket_seconds'])->toBe(60)
        ->and($summary['series'])->toHaveCount(60)
        ->and($summary['current']['cpu'])->toEqual(50.0)
        ->and($summary['current']['memory_total'])->toBe(16_000_000 * 1024)
        ->and($last['cpu'])->toEqual(50.0)
        ->and($last['disk_read'])->toEqual(1200 * 512 / 60)
        ->and($last['network_in'])->toEqual(100)
        ->and($summary['series'][0]['cpu'])->toBeNull();
})->group('ADMIN-003');

test('counters that go backwards (a reboot) have no rate', function () {
    $now = Carbon::parse('2026-09-30 12:00:00');
    (new ServerMetrics(fakeProc(in: 90_000)))->record($now->copy()->subMinutes(2));
    (new ServerMetrics(fakeProc(in: 10)))->record($now->copy()->subMinute());

    $summary = app(ServerMetrics::class)->summary('1h', $now->getTimestamp());

    expect(collect($summary['series'])->pluck('network_in')->filter()->all())->toBe([]);
})->group('ADMIN-003');

test('admins see the monitoring page with install counts, and Docker disk usage loads on its own', function () {
    Process::preventStrayProcesses();
    Process::fake([
        '*docker*system*df*' => Process::result(
            '{"Active":"2","Reclaimable":"1.2GB (50%)","Size":"2.4GB","TotalCount":"5","Type":"Images"}'."\n"
            .'{"Active":"1","Reclaimable":"0B (0%)","Size":"10MB","TotalCount":"1","Type":"Containers"}',
        ),
    ]);
    Sandbox::factory()->create(['provider' => 'docker', 'status' => 'running']);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('admin.monitoring.show', ['range' => '24h']))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/monitoring')
            ->where('range', '24h')
            ->where('metrics.bucket_seconds', 900)
            ->where('counts.users', User::query()->count())
            ->where('counts.projects', 1)
            ->where('counts.running', 1)
            ->where('counts.providers.docker', 1)
            ->missing('docker')
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->where('docker.0', ['type' => 'Images', 'total' => 5, 'active' => 2, 'size' => '2.4GB', 'reclaimable' => '1.2GB (50%)'])
                ->has('docker', 2)));

    // Without the scheduler, opening the page records a sample.
    expect(ServerMetric::query()->count())->toBe(1);
})->group('ADMIN-003');

test('docker disk usage is null without Docker', function () {
    Process::preventStrayProcesses();
    Process::fake(['*docker*system*df*' => Process::result('', 'command not found', 127)]);

    expect(app(ServerMetrics::class)->dockerDiskUsage())->toBeNull();
})->group('ADMIN-003');

test('the scheduler samples the server every minute', function () {
    $this->artisan('server:sample')->assertSuccessful();

    expect(ServerMetric::query()->count())->toBe(1);
})->group('ADMIN-003');

test('non-admins cannot see server monitoring', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.monitoring.show'))->assertForbidden();
})->group('ADMIN-003');
