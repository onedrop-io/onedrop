<?php

namespace App\Http\Controllers;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\Gateway;
use App\Sandbox\SandboxException;
use App\Sandbox\WorkspaceBrowser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The browser the user takes over from a test (TEST-005), in the project's sandbox.
 */
class ProjectBrowserController extends Controller
{
    /**
     * Whether it's open, still getting to its step, or failed to.
     */
    public function show(Project $project, WorkspaceBrowser $browser): JsonResponse
    {
        Gate::authorize('update', $project);

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => $browser->status($sandbox));
    }

    /**
     * Run a test up to a step and take over its page: returns the address of its viewer (through the gateway on a
     * server, like the preview).
     */
    public function store(Request $request, Project $project, WorkspaceBrowser $browser, Gateway $gateway): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'target' => ['required', 'string', 'max:300', 'regex:'.WorkspaceBrowser::TARGET_PATTERN],
            'step' => ['required', 'integer', 'min:0', 'max:1000'],
            'test' => ['required', 'string', 'max:300'],
            'step_title' => ['nullable', 'string', 'max:300'],
        ]);

        return $this->fromSandbox($project, function (Sandbox $sandbox) use ($project, $browser, $gateway, $validated) {
            abort_if($sandbox->preview_url === null, 409, __("The app's preview isn't ready yet."));

            $path = $browser->open($sandbox, $validated['target'], (int) $validated['step'], [
                'test' => $validated['test'],
                'step' => $validated['step_title'] ?? null,
            ]);

            return ['url' => $gateway->enabled()
                ? route('projects.gateway.open', [$project, 'preview', 'path' => $path])
                : rtrim($sandbox->preview_url, '/').$path];
        });
    }

    /**
     * Close it.
     */
    public function destroy(Project $project, WorkspaceBrowser $browser): JsonResponse
    {
        Gate::authorize('update', $project);

        return $this->fromSandbox($project, function (Sandbox $sandbox) use ($browser) {
            $browser->close($sandbox);

            return ['closed' => true];
        });
    }

    /**
     * @param  callable(Sandbox): array<string, mixed>  $call
     */
    protected function fromSandbox(Project $project, callable $call): JsonResponse
    {
        $sandbox = $project->sandbox;

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            return response()->json(['message' => __("The project's sandbox isn't running.")], 409);
        }

        try {
            return response()->json($call($sandbox));
        } catch (SandboxException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
