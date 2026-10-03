<?php

namespace App\Http\Controllers;

use App\Enums\AbuseReviewStatus;
use App\Enums\PublishStatus;
use App\Enums\PublishTarget;
use App\Enums\PublishVisibility;
use App\Enums\SandboxStatus;
use App\Jobs\PublishProject;
use App\Models\Project;
use App\Sandbox\AbuseCheck;
use App\Sandbox\Publishing\Publishers;
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
    public function store(Request $request, Project $project, Publishers $publishers): RedirectResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'visibility' => ['required', Rule::enum(PublishVisibility::class)],
            'target' => ['nullable', Rule::enum(PublishTarget::class)],
        ]);
        $visibility = PublishVisibility::from($validated['visibility']);
        $target = isset($validated['target']) ? PublishTarget::from($validated['target']) : $publishers->default($project);

        $problem = $publishers->unavailableReason($target, $project)
            // Hosted apps are public for now (HOST-001): there's no OneDrop sign-in in front of them.
            ?? ($target === PublishTarget::Hosting && $visibility === PublishVisibility::Private ? __('Hosted apps are public for now. Publish to your domain or Tailscale to keep it private.') : null)
            ?? ($project->sandbox?->status !== SandboxStatus::Running ? __("The project's sandbox isn't running.") : null)
            // Taken down after a review on the hosted install (ADMIN-006): private publishing still works.
            ?? ($visibility === PublishVisibility::Public && $project->abuseReview?->status === AbuseReviewStatus::TakenDown ? __(AbuseCheck::TAKEN_DOWN_MESSAGE) : null);

        if ($problem) {
            throw ValidationException::withMessages(['publish' => $problem]);
        }

        // Moving to another target: take it down from the old one, which would otherwise keep serving it.
        $previous = $project->publish_target ?? PublishTarget::Tailscale;

        if ($project->publish_status !== null && $previous !== $target) {
            try {
                $publishers->for($previous)->stop($project);
            } catch (PublishException $e) {
                throw ValidationException::withMessages(['publish' => $e->getMessage()]);
            }

            $project->update(['published_url' => null]);
        }

        $project->update([
            'publish_status' => PublishStatus::Publishing,
            'publish_target' => $target,
            'publish_visibility' => $visibility,
            'published_by' => $request->user()->id,
            'publish_error' => null,
            'publish_login_url' => null,
            'publish_waiting_for' => null,
        ]);

        PublishProject::dispatch($project);

        return to_route('projects.show', $project);
    }

    /**
     * Take the project offline.
     */
    public function destroy(Project $project, Publishers $publishers): RedirectResponse
    {
        Gate::authorize('update', $project);

        try {
            $publishers->forProject($project)->stop($project);
        } catch (PublishException $e) {
            throw ValidationException::withMessages(['publish' => $e->getMessage()]);
        }

        $project->update([
            'publish_status' => null,
            'published_url' => null,
            'publish_error' => null,
            'publish_login_url' => null,
            'publish_waiting_for' => null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Unpublished.')]);

        return to_route('projects.show', $project);
    }
}
