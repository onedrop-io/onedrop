<?php

namespace App\Http\Controllers;

use App\Jobs\CaptureShareCard;
use App\Models\Project;
use App\Models\ProjectShare;
use App\Sandbox\ShareCards;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The owner's side of a project's share page (SHARE-001): turn it on, change it, refresh its card, turn it off.
 */
class ProjectShareController extends Controller
{
    /**
     * Share the project, or save a new prompt or page for its share page. Either makes a new card.
     */
    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:'.ProjectShare::MAX_PROMPT],
            'page_path' => ['nullable', 'string', 'max:255', 'regex:#^/(?!/)\S*$#'],
        ], [
            'page_path.regex' => __('The page should be a path in the app, like / or /dashboard.'),
        ]);

        $share = $project->share ?? $project->share()->make(['slug' => ProjectShare::slugFor($project)]);
        $share->fill(['prompt' => trim($validated['prompt']), 'page_path' => ($validated['page_path'] ?? null) ?: '/']);

        if (! $share->exists || $share->isDirty() || $share->card_file === null) {
            $share->save();
            $this->capture($share);
        }

        return to_route('projects.show', $project);
    }

    /**
     * Take a new screenshot and make a new card, e.g. after the app changed.
     */
    public function refresh(Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $share = $project->share ?? abort(404);
        $this->capture($share);

        return to_route('projects.show', $project);
    }

    /**
     * Stop sharing: the page and its images are gone right away.
     */
    public function destroy(Project $project, ShareCards $cards): RedirectResponse
    {
        Gate::authorize('update', $project);

        $project->share()->delete();
        $cards->delete($project->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stopped sharing.')]);

        return to_route('projects.show', $project);
    }

    protected function capture(ProjectShare $share): void
    {
        ShareCards::markCapturing($share);
        CaptureShareCard::dispatch($share);
    }
}
