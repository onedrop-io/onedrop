<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

$sandbox = dirname(__DIR__, 2).'/docker/sandbox';

beforeEach(function () {
    $this->app = sys_get_temp_dir().'/onedrop-kit-'.bin2hex(random_bytes(4));
    $this->bin = "{$this->app}-bin";

    (new Filesystem)->ensureDirectoryExists("{$this->app}/bootstrap");
    (new Filesystem)->ensureDirectoryExists($this->bin);
    // A stand-in Laravel app: `php artisan migrate` succeeds, npm records what it was asked to install.
    (new Filesystem)->put("{$this->app}/artisan", "<?php file_put_contents(__DIR__.'/migrated', implode(' ', array_slice(\$argv, 1)));\n");
    (new Filesystem)->put("{$this->app}/package.json", '{"dependencies": {"react": "^19.0.0"}}');
    (new Filesystem)->put("{$this->app}/bootstrap/providers.php", "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n");
    (new Filesystem)->put("{$this->bin}/npm", "#!/usr/bin/env bash\necho \"\$@\" > \"\$KIT_APP_DIR/npm-args\"\n");
    chmod("{$this->bin}/npm", 0755);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->app);
    (new Filesystem)->deleteDirectory($this->bin);
});

function runKit(string $sandbox, string $app, string $bin): Process
{
    $process = new Process(["{$sandbox}/kit", 'tables'], null, [
        'KIT_DIR' => "{$sandbox}/kits",
        'KIT_APP_DIR' => $app,
        'PATH' => $bin.':'.getenv('PATH'),
    ]);
    $process->run();

    return $process;
}

test('the table kit copies its files into the app, registers its provider, installs its packages and migrates', function () use ($sandbox) {
    $process = runKit($sandbox, $this->app, $this->bin);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and("{$this->app}/app/Tables/Table.php")->toBeFile()
        ->and("{$this->app}/app/Tables/Formula/Formula.php")->toBeFile()
        ->and("{$this->app}/resources/js/components/table/data-table.tsx")->toBeFile()
        ->and("{$this->app}/config/tables.php")->toBeFile()
        ->and(file_get_contents("{$this->app}/bootstrap/providers.php"))
        ->toContain("App\\Providers\\AppServiceProvider::class,\n    App\\Providers\\TablesServiceProvider::class,\n];")
        ->and(file_get_contents("{$this->app}/npm-args"))
        ->toContain('@tanstack/react-virtual')
        ->toContain('@radix-ui/react-popover')
        ->toContain('@laravel/echo-react')
        ->and(file_get_contents("{$this->app}/migrated"))->toContain('migrate --force')
        ->and(trim(file_get_contents("{$this->app}/.onedrop/kit-tables")))->toBe('tables '.trim(file_get_contents("{$sandbox}/kits/tables/VERSION")));
})->group('TABLE-001');

test('running the table kit again keeps the app\'s own changes and registers the provider once', function () use ($sandbox) {
    runKit($sandbox, $this->app, $this->bin);
    (new Filesystem)->put("{$this->app}/config/tables.php", "<?php\n\nreturn ['tables' => ['deals' => 'App\\\\Tables\\\\DealsTable']];\n");

    $process = runKit($sandbox, $this->app, $this->bin);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('Kept the app\'s own version of:')->toContain('config/tables.php')
        ->and(file_get_contents("{$this->app}/config/tables.php"))->toContain('DealsTable')
        ->and(substr_count(file_get_contents("{$this->app}/bootstrap/providers.php"), 'TablesServiceProvider'))->toBe(1);
})->group('TABLE-001');

test('the table kit refuses an app that isn\'t Laravel', function () use ($sandbox) {
    (new Filesystem)->delete("{$this->app}/artisan");

    $process = runKit($sandbox, $this->app, $this->bin);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('for Laravel apps')
        ->and("{$this->app}/app")->not->toBeDirectory();
})->group('TABLE-001');

test('the agent is told to use the table kit for record lists, and the image ships it', function () use ($sandbox) {
    expect(file_get_contents("{$sandbox}/instructions.md"))
        ->toContain('/opt/onedrop/kit tables')
        ->toContain('/opt/onedrop/guides/tables.md')
        ->and("{$sandbox}/guides/tables.md")->toBeFile()
        ->and(file_get_contents("{$sandbox}/Dockerfile"))
        ->toContain('COPY kit /opt/onedrop/')
        ->toContain('COPY kits /opt/onedrop/kits');
})->group('TABLE-001');
