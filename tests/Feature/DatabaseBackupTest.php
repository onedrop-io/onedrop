<?php

use App\Jobs\BackUpDatabase;
use App\Models\SystemSetting;
use App\Models\User;
use App\Sandbox\DatabaseBackups;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->storage = sys_get_temp_dir().'/onedrop-backups-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists("{$this->storage}/app/private");
    $this->app->useStoragePath($this->storage);

    // A SQLite file of its own, outside the suite's transaction: VACUUM INTO can't run in one, and restores swap files.
    $this->database = "{$this->storage}/database.sqlite";
    touch($this->database);
    config([
        'database.connections.backups' => [...config('database.connections.sqlite'), 'database' => $this->database],
        'database.default' => 'backups',
    ]);
    Artisan::call('migrate', ['--database' => 'backups', '--force' => true]);
    SystemSetting::flush();
});

afterEach(function () {
    // RefreshDatabase rolls back the default connection's transaction when the test ends.
    config(['database.default' => 'sqlite']);
    DB::purge('backups');
    File::deleteDirectory($this->storage);
});

test('backing up now copies the database to this server\'s disk, and the page lists it', function () {
    User::factory()->create(['email' => 'kept@example.com']);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.backups.store'))->assertRedirect(route('admin.backups.index'));

    $files = File::files(DatabaseBackups::localRoot());
    expect($files)->toHaveCount(1)
        ->and($files[0]->getFilename())->toMatch(DatabaseBackups::NAME);

    // A real SQLite database, with the data in it.
    $copy = "{$this->storage}/copy.sqlite";
    File::put($copy, gzdecode(File::get($files[0]->getPathname())));
    expect((new PDO("sqlite:{$copy}"))->query("SELECT count(*) FROM users WHERE email = 'kept@example.com'")->fetchColumn())->toBe(1);

    $this->actingAs($admin)->get(route('admin.backups.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/backups')
            ->where('database', 'sqlite')
            ->where('last.ok', true)
            ->where('last.file', $files[0]->getFilename())
            ->where('busy', null)
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->where('backups.error', null)
                ->where('backups.items.0.name', $files[0]->getFilename())
                ->where('backups.items.0.size', $files[0]->getSize())));
})->group('ADMIN-005');

test('only the newest backups are kept', function () {
    app(DatabaseBackups::class)->update(['enabled' => true, 'schedule' => '0 0 * * *', 'keep' => 2, 'destination' => 'local', 'prefix' => 'nightly']);

    foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $day) {
        Carbon::setTestNow("{$day} 00:00:00");
        app(DatabaseBackups::class)->backUp();
    }

    expect(collect(app(DatabaseBackups::class)->list())->pluck('name')->all())
        ->toBe(['onedrop-20260903-000000.sqlite.gz', 'onedrop-20260902-000000.sqlite.gz'])
        ->and(File::files(DatabaseBackups::localRoot().'/nightly'))->toHaveCount(2);
})->group('ADMIN-005');

test('admins can download and delete a backup', function () {
    $admin = User::factory()->admin()->create();
    $name = app(DatabaseBackups::class)->backUp();

    $this->actingAs($admin)->get(route('admin.backups.download', $name))
        ->assertOk()
        ->assertDownload($name);

    $this->actingAs($admin)->delete(route('admin.backups.destroy', $name))->assertRedirect(route('admin.backups.index'));

    expect(app(DatabaseBackups::class)->list())->toBe([]);
    $this->actingAs($admin)->get(route('admin.backups.download', $name))->assertNotFound();
})->group('ADMIN-005');

test('backup names are checked, so no other file can be downloaded or deleted', function () {
    File::ensureDirectoryExists(DatabaseBackups::localRoot());
    File::put(DatabaseBackups::localRoot().'/notes.txt', 'secret');
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('admin.backups.download', 'notes.txt'))->assertNotFound();
    $this->actingAs($admin)->delete(route('admin.backups.destroy', 'notes.txt'))->assertNotFound();

    expect(File::exists(DatabaseBackups::localRoot().'/notes.txt'))->toBeTrue();
})->group('ADMIN-005');

test('a failed backup is shown with its error', function () {
    // Nothing listens there.
    app(DatabaseBackups::class)->update([
        'enabled' => true, 'schedule' => '0 0 * * *', 'keep' => 10, 'destination' => 's3',
        's3' => ['bucket' => 'b', 'key' => 'k', 'secret' => 's', 'endpoint' => 'http://127.0.0.1:9', 'path_style' => true],
    ]);

    expect(app(DatabaseBackups::class)->run())->toBeNull();

    $this->actingAs(User::factory()->admin()->create())->get(route('admin.backups.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('last.ok', false)
            ->where('last.message', fn (string $message) => $message !== '')
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload->where('backups.error', fn (?string $error) => filled($error))));
})->group('ADMIN-005');

test('restoring replaces the database with the backup, after backing up the current one', function () {
    $database = $this->database;
    User::factory()->create(['email' => 'before@example.com']);
    $admin = User::factory()->admin()->create(['email' => 'admin@example.com']);
    $name = app(DatabaseBackups::class)->backUp();

    Carbon::setTestNow(now()->addMinute());
    User::factory()->create(['email' => 'after@example.com']);

    $this->actingAs($admin)
        ->post(route('admin.backups.restore', $name), ['confirm' => $name])
        ->assertRedirect(route('admin.backups.index'));

    expect(User::query()->pluck('email')->sort()->values()->all())->toBe(['admin@example.com', 'before@example.com'])
        ->and(File::exists("{$database}-wal"))->toBeFalse()
        ->and(SystemSetting::group('backups')['last_restore'])->toMatchArray(['ok' => true, 'file' => $name])
        ->and(collect(app(DatabaseBackups::class)->list())->pluck('name')->filter(fn ($backup) => str_contains($backup, 'before-restore')))->toHaveCount(1);
})->group('ADMIN-005');

test('restoring needs the backup\'s name typed to confirm', function () {
    Queue::fake();
    $name = 'onedrop-20260901-000000.sqlite.gz';

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.backups.restore', $name), ['confirm' => 'yes'])
        ->assertSessionHasErrors('confirm');

    Queue::assertNothingPushed();
})->group('ADMIN-005');

test('a file that is not a OneDrop database is never restored', function () {
    $database = $this->database;
    User::factory()->create(['email' => 'still-here@example.com']);
    $name = 'onedrop-20260901-000000.sqlite.gz';
    File::ensureDirectoryExists(DatabaseBackups::localRoot());
    File::put(DatabaseBackups::localRoot()."/{$name}", gzencode('not a database'));

    expect(fn () => app(DatabaseBackups::class)->restore($name))->toThrow(RuntimeException::class, "isn't a valid OneDrop database");
    expect(User::query()->where('email', 'still-here@example.com')->exists())->toBeTrue()
        ->and(File::exists("{$database}.restoring"))->toBeFalse();
})->group('ADMIN-005');

test('postgres databases are dumped with pg_dump and restored with pg_restore', function () {
    Process::preventStrayProcesses();
    Process::fake(function ($process) {
        if (str_contains(implode(' ', (array) $process->command), 'pg_dump')) {
            preg_match('/--file=(\S+)/', implode(' ', $process->command), $match);
            File::put($match[1], 'PGDMP');
        }

        return Process::result();
    });

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('getConfig')->with()->andReturn(['host' => 'db.internal', 'port' => 5432, 'username' => 'onedrop', 'password' => 'pw', 'database' => 'onedrop', 'sslmode' => 'require']);
    $backups = Mockery::mock(DatabaseBackups::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $backups->shouldReceive('connection')->andReturn($connection);
    $this->app->instance(DatabaseBackups::class, $backups);

    $name = app(DatabaseBackups::class)->backUp();
    expect($name)->toEndWith('.pgdump')
        ->and(File::get(DatabaseBackups::localRoot()."/{$name}"))->toBe('PGDMP');

    app(DatabaseBackups::class)->restore($name);

    Process::assertRan(fn ($process) => $process->command[0] === 'pg_dump' && $process->environment['PGPASSWORD'] === 'pw' && $process->environment['PGHOST'] === 'db.internal');
    Process::assertRan(fn ($process) => $process->command[0] === 'pg_restore' && in_array('--clean', $process->command, true));
})->group('ADMIN-005');

test('admins can schedule backups to S3-compatible storage; the secret is kept when left blank', function () {
    $admin = User::factory()->admin()->create();
    $settings = [
        'enabled' => true,
        'schedule' => '30 2 * * *',
        'keep' => 7,
        'destination' => 's3',
        'prefix' => 'onedrop/db',
        's3' => ['bucket' => 'backups', 'region' => 'eu-west-1', 'endpoint' => 'https://s3.example.com', 'key' => 'AKIA', 'secret' => 'shh', 'path_style' => true],
    ];

    $this->actingAs($admin)->put(route('admin.backups.update'), $settings)->assertRedirect(route('admin.backups.index'));
    $this->actingAs($admin)->put(route('admin.backups.update'), [...$settings, 's3' => [...$settings['s3'], 'secret' => '']])->assertSessionHasNoErrors();

    $saved = app(DatabaseBackups::class)->settings();
    expect($saved)->toMatchArray(['enabled' => true, 'schedule' => '30 2 * * *', 'keep' => 7, 'destination' => 's3', 'prefix' => 'onedrop/db'])
        ->and($saved['s3']['secret'])->toBe('shh')
        ->and(app(DatabaseBackups::class)->disk()->getConfig())->toMatchArray(['driver' => 's3', 'bucket' => 'backups', 'endpoint' => 'https://s3.example.com', 'use_path_style_endpoint' => true]);

    $this->actingAs($admin)->get(route('admin.backups.index'))
        ->assertDontSee('shh')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('settings.s3.secret_set', true)->missing('settings.s3.secret'));
})->group('ADMIN-005');

test('backup settings are checked', function (array $input, string $field) {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.backups.update'), [...['enabled' => true, 'schedule' => '0 0 * * *', 'keep' => 10, 'destination' => 'local'], ...$input])
        ->assertSessionHasErrors($field);
})->with([
    'a bad schedule' => [['schedule' => 'every day'], 'schedule'],
    'keeping none' => [['keep' => 0], 'keep'],
    'a folder outside the destination' => [['prefix' => '../etc'], 'prefix'],
    'S3 without a bucket' => [['destination' => 's3', 's3' => ['key' => 'k', 'secret' => 's']], 's3.bucket'],
])->group('ADMIN-005');

test('scheduled backups run on the chosen schedule only when turned on', function () {
    app(DatabaseBackups::class)->update(['enabled' => false, 'schedule' => '15 3 * * *', 'keep' => 10, 'destination' => 'local']);

    $event = fn () => collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'database-backup');
    expect($event()->filtersPass($this->app))->toBeFalse();

    app(DatabaseBackups::class)->update(['enabled' => true, 'schedule' => '15 3 * * *', 'keep' => 10, 'destination' => 'local']);
    expect($event()->filtersPass($this->app))->toBeTrue();

    Queue::fake();
    $this->artisan('schedule:run');
    Queue::assertNotPushed(BackUpDatabase::class);
})->group('ADMIN-005');

test('non-admins cannot see, make, download or restore backups', function () {
    $user = User::factory()->create();
    $name = 'onedrop-20260901-000000.sqlite.gz';

    $this->actingAs($user)->get(route('admin.backups.index'))->assertForbidden();
    $this->actingAs($user)->post(route('admin.backups.store'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.backups.download', $name))->assertForbidden();
    $this->actingAs($user)->post(route('admin.backups.restore', $name), ['confirm' => $name])->assertForbidden();
})->group('ADMIN-005');
