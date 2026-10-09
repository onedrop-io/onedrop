<?php

namespace App\Console\Commands;

use App\Sandbox\Providers\RuntimeSandboxProvider;
use App\Sandbox\SandboxException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

#[Signature('sandbox:build-image')]
#[Description('Build the image used for project sandboxes (locally with Docker, or on Blaxel, Runtime Cloud or E2B)')]
class BuildSandboxImage extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        return match (config('sandbox.provider')) {
            'blaxel' => $this->buildOnBlaxel(),
            'runtime' => $this->buildOnRuntime(),
            'e2b' => $this->buildOnE2b(),
            default => $this->build(config('sandbox.providers.docker.image'), ['docker', 'build', '--tag', config('sandbox.providers.docker.image'), '.']),
        };
    }

    /**
     * Blaxel builds docker/sandbox with the Blaxel-only steps in docker/sandbox/blaxel appended, in its own build system.
     */
    protected function buildOnBlaxel(): int
    {
        $config = config('sandbox.providers.blaxel');

        if (blank($config['api_key']) || blank($config['workspace'])) {
            $this->components->error('Set BL_API_KEY and BL_WORKSPACE first (Blaxel console → API keys).');

            return self::FAILURE;
        }

        $directory = storage_path('framework/blaxel-image-'.uniqid());

        // cp keeps the scripts' executable bits (File::copyDirectory drops them).
        if (Process::run(['cp', '-R', base_path('docker/sandbox'), $directory])->failed()) {
            $this->components->error("Couldn't copy docker/sandbox to {$directory}.");

            return self::FAILURE;
        }

        File::append("{$directory}/Dockerfile", File::get(base_path('docker/sandbox/blaxel/Dockerfile.append')));
        File::put("{$directory}/blaxel.toml", "name = \"{$config['image']}\"\ntype = \"sandbox\"\n\n[runtime]\ngeneration = \"mk3\"\nmemory = {$config['memory_mib']}\n");

        try {
            // The key goes through the environment, never the command line.
            return $this->build($config['image'], [$config['cli'], 'push', '--yes', '--name', $config['image'], '--type', 'sandbox', '--timeout', '40m'], $directory, [
                'BL_API_KEY' => $config['api_key'],
                'BL_WORKSPACE' => $config['workspace'],
            ], ' on Blaxel');
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * E2B makes a template of the published sandbox image (docker/sandbox, pushed to GitHub's registry by the `images`
     * workflow once main's tests pass) with start.sh started, through its SDK (infra/e2b/build.mjs). So E2B gets
     * docker/sandbox as it is on main, not as it is in this checkout.
     */
    protected function buildOnE2b(): int
    {
        $config = config('sandbox.providers.e2b');

        if (blank($config['api_key'])) {
            $this->components->error('Set E2B_API_KEY first (https://e2b.dev/dashboard).');

            return self::FAILURE;
        }

        if (Process::path(base_path('infra/e2b'))->run(['npm', 'ci', '--silent'])->failed()) {
            $this->components->error("Couldn't install E2B's SDK in infra/e2b (npm ci).");

            return self::FAILURE;
        }

        // The key goes through the environment, never the command line.
        return $this->build($config['image'], ['node', 'build.mjs'], base_path('infra/e2b'), [
            'E2B_API_KEY' => $config['api_key'],
            'E2B_TEMPLATE' => $config['image'],
            'E2B_SOURCE_IMAGE' => $config['source_image'],
            'E2B_CPU_COUNT' => (string) $config['vcpu'],
            'E2B_MEMORY_MB' => (string) $config['memory_mib'],
            'E2B_DISK_MB' => (string) $config['disk_mib'],
        ], " on E2B (from {$config['source_image']})");
    }

    /**
     * Runtime builds the same Dockerfile in its own build VM.
     */
    protected function buildOnRuntime(): int
    {
        $config = config('sandbox.providers.runtime');

        if (blank($config['api_key'])) {
            $this->components->error('Set RUNTIME_API_KEY first (https://withruntime.com/account/keys).');

            return self::FAILURE;
        }

        // The account caps how many images it holds: make room first (SBX-003). A failure here isn't the build's.
        try {
            $pruned = (new RuntimeSandboxProvider($config))->pruneImages();

            if ($pruned !== []) {
                $this->components->info('Deleted '.count($pruned).' old image version(s) first.');
            }
        } catch (SandboxException $e) {
            $this->components->warn("Couldn't delete old image versions first: {$e->getMessage()}");
        }

        return $this->build($config['image'], ['npx', '--yes', 'withruntime@0.8', 'image', 'build', '.', '--tag', $config['image']], env: [
            'RUNTIME_API_KEY' => $config['api_key'],
        ], where: ' on Runtime Cloud');
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    protected function build(string $image, array $command, ?string $path = null, array $env = [], string $where = ''): int
    {
        $this->components->info("Building {$image} from docker/sandbox{$where} (first build takes a few minutes)...");

        $result = Process::forever()
            ->path($path ?? base_path('docker/sandbox'))
            ->env($env)
            ->run($command, fn (string $type, string $output) => $this->output->write($output));

        if ($result->failed()) {
            $this->components->error('Image build failed.');

            return self::FAILURE;
        }

        $this->components->info("Built {$image}.");

        return self::SUCCESS;
    }
}
