<?php

namespace App\Console\Commands;

use App\Enums\PublishStatus;
use App\Enums\PublishTarget;
use App\Enums\SandboxStatus;
use App\Models\Sandbox;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxUpdater;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('sandbox:suspend-idle')]
#[Description('Suspend Docker sandboxes nobody has used for a while (memory kept, woken by their next use), and stop ones suspended for longer (memory freed).')]
class SuspendIdleSandboxes extends Command
{
    /** The container's network counters: a preview opened straight on its port only shows up here. */
    public const TRAFFIC = ['cat', '/sys/class/net/eth0/statistics/rx_bytes', '/sys/class/net/eth0/statistics/tx_bytes'];

    /**
     * Managed providers (Runtime, Blaxel) pause idle sandboxes by themselves; Docker needs the platform to (SBX-007).
     */
    public function handle(SandboxProvider $provider): int
    {
        $seconds = (int) config('sandbox.providers.docker.idle_seconds');
        $minutes = (int) config('sandbox.providers.docker.stop_after_minutes');

        if ($seconds > 0) {
            $this->suspendIdle($provider, $seconds);
        }

        if ($seconds > 0 && $minutes > 0) {
            $this->stopSuspended($provider, $minutes);
        }

        return self::SUCCESS;
    }

    /**
     * Freeze sandboxes unused for $seconds: no CPU, memory kept, back in a moment.
     */
    protected function suspendIdle(SandboxProvider $provider, int $seconds): void
    {
        Sandbox::query()
            ->with('project')
            ->where('provider', 'docker')
            ->where('status', SandboxStatus::Running)
            ->whereNotNull('external_id')
            ->whereNull('suspended_at')
            ->each(function (Sandbox $sandbox) use ($provider, $seconds) {
                if ($this->hadTraffic($provider, $sandbox)) {
                    $sandbox->forceFill(['last_active_at' => now()])->saveQuietly();

                    return;
                }

                // Never used since this was added: count from its last change instead.
                $lastUsed = $sandbox->last_active_at ?? $sandbox->updated_at;
                $project = $sandbox->project;

                // A published app has visitors the platform doesn't see; an agent or an update is using it now.
                if ($lastUsed?->gt(now()->subSeconds($seconds)) || $this->inUse($sandbox)) {
                    return;
                }

                try {
                    $provider->suspend($sandbox->external_id);
                    $sandbox->forceFill(['suspended_at' => now()])->saveQuietly();
                    $this->components->info("Suspended project {$project->id}'s sandbox".($sandbox->task_id ? " (task {$sandbox->task_id})" : '').'.');
                } catch (SandboxException $e) {
                    // Removed outside the app: say so in the workspace instead of trying again every few seconds.
                    if (str_contains($e->getMessage(), 'No such container')) {
                        $sandbox->update(['status' => SandboxStatus::Failed, 'error' => __("The sandbox's container no longer exists.")]);
                    }

                    $this->components->warn("Project {$project->id}: couldn't suspend the sandbox: {$e->getMessage()}");
                }
            });
    }

    /**
     * Stop sandboxes suspended for $minutes, freeing their memory. The next use starts them again in a second or two,
     * with their files; only their processes start afresh.
     */
    protected function stopSuspended(SandboxProvider $provider, int $minutes): void
    {
        $since = now()->subMinutes($minutes);

        Sandbox::query()
            ->with('project')
            ->where('provider', 'docker')
            ->where('status', SandboxStatus::Running)
            ->whereNotNull('external_id')
            ->where('suspended_at', '<', $since)
            ->whereNull('stopped_at')
            ->each(function (Sandbox $sandbox) use ($provider, $since) {
                // A command the platform ran in it (an agent run) wakes it without it counting as woken.
                if ($sandbox->last_active_at?->gt($since) || $this->inUse($sandbox)) {
                    return;
                }

                try {
                    $provider->pause($sandbox->external_id);
                    $sandbox->forceFill(['stopped_at' => now()])->saveQuietly();
                    $this->components->info("Stopped project {$sandbox->project_id}'s sandbox".($sandbox->task_id ? " (task {$sandbox->task_id})" : '').'.');
                } catch (SandboxException $e) {
                    $this->components->warn("Project {$sandbox->project_id}: couldn't stop the sandbox: {$e->getMessage()}");
                }
            });
    }

    /**
     * An app published from the sandbox has visitors the platform doesn't see; an agent or an update is using it now.
     */
    protected function inUse(Sandbox $sandbox): bool
    {
        $project = $sandbox->project;

        // A hosted app runs off the sandbox (HOST-001), so its sandbox can sleep.
        $servesApp = $project->publish_status === PublishStatus::Live && $project->publish_target !== PublishTarget::Hosting;

        return $servesApp || $project->busyIn($sandbox) || SandboxUpdater::isUpdating($project);
    }

    /**
     * Whether the container sent or received anything since the last check (someone using its preview or shell, the
     * app talking to the outside). The first check, or one that can't read the counters, says no.
     */
    protected function hadTraffic(SandboxProvider $provider, Sandbox $sandbox): bool
    {
        try {
            $result = $provider->exec($sandbox->external_id, self::TRAFFIC);
        } catch (SandboxException) {
            return false;
        }

        if (! $result->successful()) {
            return false;
        }

        $counters = preg_replace('/\s+/', ' ', trim($result->output));
        $previous = Cache::get("sandbox-traffic:{$sandbox->id}");
        Cache::put("sandbox-traffic:{$sandbox->id}", $counters, now()->addHour());

        return $previous !== null && $previous !== $counters;
    }
}
