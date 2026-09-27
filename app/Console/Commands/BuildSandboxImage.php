<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

#[Signature('sandbox:build-image')]
#[Description('Build the local Docker image used for project sandboxes')]
class BuildSandboxImage extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $image = config('sandbox.providers.docker.image');

        $this->components->info("Building {$image} from docker/sandbox (first build takes a few minutes)...");

        $result = Process::forever()
            ->path(base_path('docker/sandbox'))
            ->run(['docker', 'build', '--tag', $image, '.'], fn (string $type, string $output) => $this->output->write($output));

        if ($result->failed()) {
            $this->components->error('Image build failed.');

            return self::FAILURE;
        }

        $this->components->info("Built {$image}.");

        return self::SUCCESS;
    }
}
