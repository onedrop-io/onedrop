<?php

namespace App\Http\Controllers;

use App\Enums\SandboxStatus;
use App\Http\Middleware\UseDesktopToken;
use App\Jobs\MoveSandbox;
use App\Models\Project;
use App\Sandbox\Providers\DeviceSandboxProvider;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxInspector;
use App\Sandbox\SandboxUpdater;
use App\Sandbox\WorkspaceSsh;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Tools → This computer (DESK-006): the project's ports to forward, the hosts on its people's network it may reach,
 * and the computer it runs on. What the desktop app does with them happens in the app.
 */
class ProjectComputerController extends Controller
{
    /** Ports apps' own services use, named for the panel's suggestions. */
    protected const KNOWN_PORTS = [
        5432 => 'Postgres',
        3306 => 'MySQL',
        6379 => 'Redis',
        27017 => 'MongoDB',
        1025 => 'Mail (SMTP)',
        8025 => 'Mailpit',
        9000 => 'MinIO',
    ];

    /** Most hosts a project may list. */
    protected const MAX_HOSTS = 50;

    public function show(Request $request, Project $project, SandboxInspector $inspector): JsonResponse
    {
        Gate::authorize('view', $project);

        $user = $request->user();
        $sandbox = $project->sandbox;
        $running = $sandbox?->status === SandboxStatus::Running && $sandbox->external_id !== null;
        $token = UseDesktopToken::from($request) ? $user->currentAccessToken() : null;
        $moving = SandboxUpdater::isUpdating($project) || $sandbox?->status === SandboxStatus::Creating;

        return response()->json([
            'can_update' => $user->can('update', $project),
            'is_owner' => $project->user_id === $user->id,
            'owner_name' => $project->user->name,
            'host_alias' => WorkspaceSsh::hostAlias($project),
            'running' => $running,
            // Not while it moves: the move's own commands come first, and the panel asks every few seconds then.
            'ports' => $running && ! $moving ? $this->ports($inspector, $project) : [],
            'network_hosts' => array_values($project->network_hosts ?? []),
            'device' => $project->device_id ? [
                'id' => $project->device_id,
                'name' => DeviceSandboxProvider::name($project->device_id),
                'this' => $token !== null && $token->getKey() === $project->device_id,
            ] : null,
            'devices_available' => DeviceSandboxProvider::available(),
            'moving' => $moving,
            'move_error' => MoveSandbox::error($project),
        ]);
    }

    /**
     * The hosts on its people's network the project may reach through their desktop app (DESK-009).
     */
    public function network(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'hosts' => ['present', 'array', 'max:'.self::MAX_HOSTS],
            // A name or address, without a scheme or path: "db.internal", "10.0.0.5", "[fd00::1]".
            'hosts.*.host' => ['required', 'string', 'max:253', 'regex:/^(\[[0-9a-fA-F:]+\]|[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?)$/'],
            'hosts.*.port' => ['required', 'integer', 'between:1,65535'],
        ], [
            'hosts.*.host.regex' => __('Use a name or address, like db.internal or 10.0.0.5, without http:// or a path.'),
        ]);

        $hosts = collect($validated['hosts'])
            ->map(fn (array $host): array => ['host' => strtolower($host['host']), 'port' => (int) $host['port']])
            ->unique(fn (array $host): string => "{$host['host']}:{$host['port']}")
            ->values()
            ->all();

        $project->update(['network_hosts' => $hosts ?: null]);

        return response()->json(['network_hosts' => $hosts]);
    }

    /**
     * Move the project to the computer this request comes from, or back to the cloud (DESK-010).
     */
    public function device(Request $request, Project $project): JsonResponse
    {
        abort_unless($project->user_id === $request->user()->id, 403, __('Only the project\'s owner can move it.'));

        $validated = $request->validate(['to' => ['required', Rule::in(['this', 'cloud'])]]);

        if ($validated['to'] === 'this') {
            abort_unless(UseDesktopToken::from($request), 422, __('Open the project in the desktop app on the computer to move it there.'));
            abort_unless(DeviceSandboxProvider::available(), 422, __('This OneDrop can\'t run projects on computers.'));
        }

        $to = $validated['to'] === 'this' ? $request->user()->currentAccessToken()->getKey() : null;
        $from = $project->device_id;

        if ($to !== $from) {
            $project->update(['device_id' => $to]);
            MoveSandbox::dispatch($project, $from);
        }

        return response()->json(['moving' => $to !== $from]);
    }

    /**
     * What the panel offers to forward: the app (through its proxy, as the preview), then the services it runs.
     *
     * @return list<array{port: int, label: string}>
     */
    protected function ports(SandboxInspector $inspector, Project $project): array
    {
        $ports = [['port' => (int) config('sandbox.proxy_port'), 'label' => __('The app')]];
        $sandbox = $project->sandbox;

        try {
            // Looked at once a minute at most: each look is a command in the sandbox.
            $listening = Cache::remember("computer-ports:{$sandbox->id}:{$sandbox->external_id}", 60, fn () => $inspector->ports($sandbox));
        } catch (SandboxException) {
            return $ports;
        }

        foreach ($listening as $port) {
            if ($port['role'] !== null || $port['port'] === (int) config('sandbox.tunnel_port') || collect($ports)->contains('port', $port['port'])) {
                continue;
            }

            $ports[] = ['port' => $port['port'], 'label' => self::KNOWN_PORTS[$port['port']] ?? ($port['process'] ?? __('Port :port', ['port' => $port['port']]))];
        }

        return $ports;
    }
}
