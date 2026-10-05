<?php

namespace App\Http\Controllers\Api;

use App\Enums\SandboxStatus;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\DesktopTunnel;
use App\Sandbox\Providers\DeviceSandboxProvider;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxUpdater;
use App\Sandbox\WorkspaceSsh;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DesktopTunnelController extends Controller
{
    /**
     * A ticket for the desktop app's tunnel into the project's sandbox (DESK-007..009): to forward one of its ports,
     * SSH (the owner only), or its network connection, with the hosts it may reach.
     */
    public function store(Request $request, Project $project, DesktopTunnel $tunnel, SandboxProvider $provider, SandboxUpdater $updater, WorkspaceSsh $ssh): JsonResponse
    {
        $validated = $request->validate([
            'purpose' => ['required', Rule::in(['forward', 'ssh', 'network'])],
            'port' => ['required_if:purpose,forward', 'nullable', 'integer', 'between:1,65535', Rule::notIn([config('sandbox.tunnel_port')])],
        ]);

        $user = $request->user();

        // SSH logs in as the sandbox's owner, with only their keys (as on Developer → SSH).
        if ($validated['purpose'] === 'ssh') {
            abort_unless($project->user_id === $user->id, 403, __('Only the project\'s owner can open it in an editor.'));
        } else {
            Gate::authorize('update', $project);
        }

        $sandbox = $project->sandbox;

        if (! $sandbox || $sandbox->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            return response()->json(['message' => __('The project\'s sandbox isn\'t running.')], 409);
        }

        // Only the computer a project runs on can reach it there (DESK-010).
        $device = $sandbox->provider === 'device' ? DeviceSandboxProvider::parse($sandbox->external_id) : null;

        if ($device && $device['device'] !== $user->currentAccessToken()->getKey()) {
            return response()->json(['message' => __('This project runs on :computer.', ['computer' => DeviceSandboxProvider::name($device['device'])])], 409);
        }

        try {
            $sandbox->wake($provider);
            $this->ensure($tunnel, $updater, $project, $sandbox->refresh());

            // The computer's own key (DESK-008) may have been added a moment ago; the keys go in before connecting.
            if ($validated['purpose'] === 'ssh') {
                $ssh->sync($sandbox);
            }
        } catch (SandboxException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        $purpose = $validated['purpose'] === 'network' ? 'network' : 'forward';
        $port = match ($validated['purpose']) {
            'ssh' => (int) config('sandbox.ssh_port'),
            'forward' => (int) $validated['port'],
            default => null,
        };
        $ticket = $tunnel->ticket($sandbox, $user, $purpose, $port);

        return response()->json([
            'url' => $device ? null : $tunnel->url($sandbox, $ticket),
            'ticket' => $ticket,
            'hosts' => $purpose === 'network' ? $project->network_hosts ?? [] : null,
            'device' => $device ? ['id' => $device['device'], 'container' => $device['container']] : null,
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Start the sandbox's tunnel; a sandbox from before the tunnel gets the platform's tools first (seconds).
     *
     * @throws SandboxException
     */
    protected function ensure(DesktopTunnel $tunnel, SandboxUpdater $updater, Project $project, Sandbox $sandbox): void
    {
        try {
            $tunnel->ensure($sandbox);
        } catch (SandboxException $e) {
            try {
                $updated = $updater->updateIfOutdated($project, rebuild: false);
            } catch (SandboxException) {
                $updated = false;
            }

            if (! $updated) {
                throw $e;
            }

            $tunnel->ensure($sandbox->refresh());
        }
    }
}
