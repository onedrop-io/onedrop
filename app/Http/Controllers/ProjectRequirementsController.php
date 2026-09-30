<?php

namespace App\Http\Controllers;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\WorkspaceFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The requirements the agent keeps for a project (REQ-001): what the user asked for and the decisions behind it.
 */
class ProjectRequirementsController extends Controller
{
    /** Where the agent keeps them: the platform's own folder, so the app's own REQ.md or SPEC.md is never touched. */
    public const PATH = '.onedrop/REQ.md';

    /**
     * The requirements file's contents, or null when the agent hasn't written it yet.
     */
    public function show(Project $project, SandboxProvider $provider, WorkspaceFiles $files): JsonResponse
    {
        Gate::authorize('view', $project);

        $sandbox = $project->sandbox;

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            return response()->json(['message' => __("The project's sandbox isn't running.")], 409);
        }

        try {
            $exists = $provider->exec($sandbox->external_id, ['test', '-f', WorkspaceFiles::ROOT.'/'.self::PATH])->successful();

            return response()->json($exists
                ? $files->read($sandbox, self::PATH)
                : ['path' => self::PATH, 'content' => null, 'notice' => null]);
        } catch (SandboxException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    /**
     * Turn requirements tracking on or off; the agent follows it from its next run.
     */
    public function update(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $project->update($request->validate(['track_requirements' => ['required', 'boolean']]));

        return back();
    }
}
