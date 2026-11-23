<?php

namespace App\Console\Commands;

use App\Jobs\AnnounceSandboxCallback;
use App\Sandbox\SandboxCallback;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

#[Signature('sandbox:tunnel')]
#[Description('Open a public cloudflared tunnel so sandboxes in the cloud (E2B, Runtime, Blaxel) can report back to this machine')]
class SandboxTunnel extends Command
{
    /** This tunnel's address, once cloudflared says it. */
    protected ?string $url = null;

    /**
     * Run a cloudflared quick tunnel to the app and keep its address for SandboxCallback while it runs (SBX-015).
     * Only sandbox callbacks are served through it (OnlySandboxCallsThroughTunnel).
     */
    public function handle(): int
    {
        if (SandboxCallback::isPublic((string) config('sandbox.callback_url'))) {
            $this->components->info('SANDBOX_CALLBACK_URL is already public: no tunnel needed.');

            return self::SUCCESS;
        }

        $process = new Process(['cloudflared', 'tunnel', '--no-autoupdate', '--url', rtrim((string) config('app.url'), '/')]);
        $process->setTimeout(null);

        $this->trap([SIGINT, SIGTERM], function () use ($process): void {
            $this->forget();
            $process->stop();
        });

        try {
            $process->start(function (string $type, string $output): void {
                if (preg_match('~https://[a-z0-9-]+\.trycloudflare\.com~', $output, $match)) {
                    $this->url = $match[0];
                    Cache::forever(SandboxCallback::TUNNEL_CACHE_KEY, $match[0]);
                    $this->components->info("Cloud sandboxes reach this app at {$match[0]}");
                    // Running agents still have the last tunnel's address.
                    AnnounceSandboxCallback::dispatch();
                }
            });

            $process->wait();
        } finally {
            $this->forget();
        }

        $this->components->error('cloudflared stopped: '.collect(explode("\n", trim($process->getErrorOutput())))->last());

        return self::FAILURE;
    }

    /**
     * Stop handing out this tunnel's address, unless a newer tunnel has already put its own in place.
     */
    protected function forget(): void
    {
        if ($this->url !== null && Cache::get(SandboxCallback::TUNNEL_CACHE_KEY) === $this->url) {
            Cache::forget(SandboxCallback::TUNNEL_CACHE_KEY);
        }
    }
}
