<?php

namespace App\Console\Commands;

use App\Sandbox\Providers\RuntimeSandboxProvider;
use App\Sandbox\SandboxException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sandbox:prune-images {--dry-run : List the image versions that would be deleted, without deleting them}')]
#[Description('Delete old versions of the sandbox image on Runtime Cloud that no sandbox still uses')]
class PruneSandboxImages extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $config = config('sandbox.providers.runtime');

        // Runtime sandboxes may outlive SANDBOX_PROVIDER=runtime (each keeps its provider), so this runs whenever there's a key.
        if (blank($config['api_key'])) {
            $this->components->info('RUNTIME_API_KEY is not set: no Runtime images to prune.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $images = (new RuntimeSandboxProvider($config))->pruneImages($dryRun);
        } catch (SandboxException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($images as $image) {
            $this->components->twoColumnDetail("{$image['id']} (version {$image['version']}, {$image['state']})", $dryRun ? 'would delete' : 'deleted');
        }

        $this->components->info(match (true) {
            $images === [] => 'No old images to prune.',
            $dryRun => count($images).' old image version(s) would be deleted.',
            default => 'Deleted '.count($images).' old image version(s).',
        });

        return self::SUCCESS;
    }
}
