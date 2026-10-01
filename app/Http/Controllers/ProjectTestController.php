<?php

namespace App\Http\Controllers;

use App\Enums\SandboxStatus;
use App\Jobs\TriageFailingTests;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\Gateway;
use App\Sandbox\SandboxException;
use App\Sandbox\WorkspaceTests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The app's browser tests and their recordings (TEST-001..003), in the project's sandbox.
 */
class ProjectTestController extends Controller
{
    /**
     * The tests and each one's latest result, with Jev's verdict on each failure once it's worked out (TEST-008).
     * `cached` skips looking for new tests (cheap, for polling a run).
     */
    public function index(Request $request, Project $project, WorkspaceTests $tests): JsonResponse
    {
        Gate::authorize('view', $project);

        $cached = $request->boolean('cached');

        return $this->fromSandbox($project, fn ($sandbox) => response()->json(TriageFailingTests::annotate($project, $tests->status($sandbox, $cached))));
    }

    /**
     * Run all the tests, or some: files (optionally at a line) or tags such as @REQ-001.
     */
    public function store(Request $request, Project $project, WorkspaceTests $tests): JsonResponse
    {
        Gate::authorize('update', $project);

        $targets = $request->validate([
            'targets' => ['sometimes', 'array', 'max:50'],
            'targets.*' => ['string', 'max:300', 'regex:'.WorkspaceTests::TARGET_PATTERN],
        ])['targets'] ?? [];

        return $this->fromSandbox($project, fn ($sandbox) => $tests->run($sandbox, array_values($targets))
            ? response()->json(['running' => true], 202)
            : response()->json(['message' => __('The tests are already running.')], 409));
    }

    /**
     * Ask the agent in the chat (queued if it's working) to write the tests the requirements don't have yet.
     */
    public function write(Project $project, AgentQueue $queue): JsonResponse
    {
        Gate::authorize('update', $project);

        return response()->json(['queued' => (bool) $queue->send($project, WorkspaceTests::WRITE_REQUEST)->queued]);
    }

    /**
     * Open the test runner (TEST-004): the address of Playwright's UI mode on the sandbox's preview, with its token.
     * On a server it goes through the gateway, like the preview.
     */
    public function openRunner(Project $project, WorkspaceTests $tests, Gateway $gateway): JsonResponse
    {
        Gate::authorize('update', $project);

        return $this->fromSandbox($project, function (Sandbox $sandbox) use ($project, $tests, $gateway) {
            abort_if($sandbox->preview_url === null, 409, __("The app's preview isn't ready yet."));

            $path = $tests->openRunner($sandbox);

            return response()->json(['url' => $gateway->enabled()
                ? route('projects.gateway.open', [$project, 'preview', 'path' => $path])
                : rtrim($sandbox->preview_url, '/').$path]);
        });
    }

    /**
     * Stop the test runner.
     */
    public function closeRunner(Project $project, WorkspaceTests $tests): JsonResponse
    {
        Gate::authorize('update', $project);

        return $this->fromSandbox($project, function (Sandbox $sandbox) use ($tests) {
            $tests->closeRunner($sandbox);

            return response()->json(['closed' => true]);
        });
    }

    /**
     * A test's video (to watch) or trace (to download).
     */
    public function recording(Request $request, Project $project, WorkspaceTests $tests): Response|JsonResponse
    {
        Gate::authorize('view', $project);

        $path = (string) $request->validate([
            'path' => ['required', 'string', 'max:300', 'regex:'.WorkspaceTests::RECORDING_PATTERN],
        ])['path'];

        return $this->fromSandbox($project, fn ($sandbox) => response($tests->recording($sandbox, $path), 200, str_ends_with($path, '.webm')
            ? ['Content-Type' => 'video/webm']
            : ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="trace.zip"']));
    }

    /**
     * Run $call against the project's sandbox, or say why it can't.
     *
     * @template TResponse of Response|JsonResponse
     *
     * @param  callable(Sandbox): TResponse  $call
     * @return TResponse|JsonResponse
     */
    protected function fromSandbox(Project $project, callable $call): Response|JsonResponse
    {
        $sandbox = $project->sandbox;

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            return response()->json(['message' => __("The project's sandbox isn't running.")], 409);
        }

        try {
            return $call($sandbox);
        } catch (SandboxException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
