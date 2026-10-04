<?php

namespace App\Http\Controllers;

use App\Enums\PublishStatus;
use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\SandboxException;
use App\Sandbox\WorkspaceDemo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Tools → Demo (DEMO-001..003): the app's demo storyboard and video, in the project's sandbox.
 */
class ProjectDemoController extends Controller
{
    /**
     * The storyboard, the latest video and the render going, if any.
     */
    public function show(Project $project, WorkspaceDemo $demo): JsonResponse
    {
        Gate::authorize('view', $project);

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => response()->json($demo->status($sandbox)));
    }

    /**
     * Save the storyboard.
     */
    public function update(Request $request, Project $project, WorkspaceDemo $demo): JsonResponse
    {
        Gate::authorize('update', $project);

        $storyboard = $this->validateStoryboard($request);

        return $this->fromSandbox($project, function (Sandbox $sandbox) use ($demo, $storyboard) {
            $demo->save($sandbox, $storyboard);

            return response()->json(['saved' => true]);
        });
    }

    /**
     * Save the storyboard, if one is sent, and start rendering the video, with the published address for its end card.
     */
    public function render(Request $request, Project $project, WorkspaceDemo $demo): JsonResponse
    {
        Gate::authorize('update', $project);

        $storyboard = $request->has('storyboard') ? $this->validateStoryboard($request, 'storyboard.') : null;
        $published = $project->publish_status === PublishStatus::Live ? $project->published_url : null;

        return $this->fromSandbox($project, function (Sandbox $sandbox) use ($demo, $storyboard, $published) {
            if ($storyboard !== null) {
                $demo->save($sandbox, $storyboard);
            }

            return $demo->render($sandbox, $published)
                ? response()->json(['running' => true], 202)
                : response()->json(['message' => __('A demo is already rendering.')], 409);
        });
    }

    /**
     * Ask the agent in the chat (queued if it's working) to write the storyboard and render it.
     */
    public function write(Project $project, AgentQueue $queue): JsonResponse
    {
        Gate::authorize('update', $project);

        return response()->json(['queued' => (bool) $queue->send($project, WorkspaceDemo::WRITE_REQUEST)->queued]);
    }

    /**
     * The latest video, to watch or (with `download`) to save.
     */
    public function video(Request $request, Project $project, WorkspaceDemo $demo): Response|JsonResponse
    {
        Gate::authorize('view', $project);

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => response($demo->video($sandbox), 200, [
            'Content-Type' => 'video/mp4',
            ...($request->boolean('download')
                ? ['Content-Disposition' => 'attachment; filename="'.(str($project->name)->slug()->value() ?: 'app').'-demo.mp4"']
                : []),
        ]));
    }

    /**
     * @return array{title: string, tagline: string, accent: string|null, url: string|null, scenes: list<array{file: string, title: string, caption: string}>}
     */
    protected function validateStoryboard(Request $request, string $prefix = ''): array
    {
        $data = $request->validate([
            "{$prefix}title" => ['nullable', 'string', 'max:80'],
            "{$prefix}tagline" => ['nullable', 'string', 'max:160'],
            "{$prefix}accent" => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            "{$prefix}url" => ['nullable', 'url:http,https', 'max:300'],
            "{$prefix}scenes" => ['present', 'array', 'max:30'],
            "{$prefix}scenes.*.file" => ['required', 'string', 'max:300', 'regex:'.WorkspaceDemo::TEST_FILE_PATTERN],
            "{$prefix}scenes.*.title" => ['required', 'string', 'max:500'],
            "{$prefix}scenes.*.caption" => ['nullable', 'string', 'max:140'],
        ]);
        $storyboard = $prefix === '' ? $data : $data['storyboard'];

        return [
            'title' => (string) ($storyboard['title'] ?? ''),
            'tagline' => (string) ($storyboard['tagline'] ?? ''),
            'accent' => $storyboard['accent'] ?? null,
            'url' => $storyboard['url'] ?? null,
            'scenes' => array_map(fn (array $scene) => [
                'file' => $scene['file'],
                'title' => $scene['title'],
                'caption' => (string) ($scene['caption'] ?? ''),
            ], array_values($storyboard['scenes'] ?? [])),
        ];
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
