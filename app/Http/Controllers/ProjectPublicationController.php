<?php

namespace App\Http\Controllers;

use App\Enums\PublishStatus;
use App\Enums\PublishVisibility;
use App\Enums\SandboxStatus;
use App\Jobs\PublishProject;
use App\Models\Project;
use App\Sandbox\Publishing\Publisher;
use App\Sandbox\Publishing\PublishException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ProjectPublicationController extends Controller
{
    /**
     * Publish (or republish) the project.
     */
    public function store(Request $request, Project $project, Publisher $publisher): RedirectResponse
    {
        Gate::authorize('update', $project);

        $visibility = PublishVisibility::from($request->validate([
            'visibility' => ['required', Rule::enum(PublishVisibility::class)],
        ])['visibility']);

        $problem = $publisher->unavailableReason()
            ?? ($project->sandbox?->status !== SandboxStatus::Running ? __("The project's sandbox isn't running.") : null);

        if ($problem) {
            throw ValidationException::withMessages(['publish' => $problem]);
        }

        $project->update([
            'publish_status' => PublishStatus::Publishing,
            'publish_visibility' => $visibility,
            'published_by' => $request->user()->id,
            'publish_error' => null,
            'publish_login_url' => null,
        ]);

        PublishProject::dispatch($project);

        return to_route('projects.show', $project);
    }

    /**
     * Take the project offline.
     */
    public function destroy(Project $project, Publisher $publisher): RedirectResponse
    {
        Gate::authorize('update', $project);

        try {
            $publisher->stop($project);
        } catch (PublishException $e) {
            throw ValidationException::withMessages(['publish' => $e->getMessage()]);
        }

        $project->update([
            'publish_status' => null,
            'published_url' => null,
            'publish_error' => null,
            'publish_login_url' => null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Unpublished.')]);

        return to_route('projects.show', $project);
    }
}
