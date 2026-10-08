<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveOrganization;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Sandbox\ProjectIcons;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organization's Apps page (APPS-001..003): every app its people published where everyone in it can open it, at
 * `/o/<slug>/apps`. Listing an app shows it, not its project.
 */
class AppController extends Controller
{
    /**
     * The apps, featured first and then by name; hidden ones only for the people who can list them again.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $organization = ResolveOrganization::current($request);
        $manages = $organization->isManagedBy($user);
        $pinned = $user->pinnedApps()->pluck('projects.id')->all();

        $apps = Project::query()
            ->inOrganization($organization)
            ->openToOrganization()
            ->with(['user:id,name,avatar', 'groups:id,name'])
            ->get()
            ->filter(fn (Project $project): bool => $project->apps_listed || $manages || $project->user_id === $user->id)
            ->sortBy([
                fn (Project $a, Project $b): int => ($b->apps_featured_at?->getTimestamp() ?? 0) <=> ($a->apps_featured_at?->getTimestamp() ?? 0),
                fn (Project $a, Project $b): int => strcasecmp($a->name, $b->name),
            ])
            ->values();

        return Inertia::render('apps/index', [
            'apps' => $apps->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'url' => $project->published_url,
                'icon_url' => ProjectIcons::url($project),
                'visibility' => $project->publish_visibility,
                'published_at' => $project->published_at?->toIso8601String(),
                'owner' => ['name' => $project->user->name, 'avatar' => $project->user->avatar],
                'groups' => $project->groups->sortBy('name', SORT_FLAG_CASE | SORT_STRING)->map(fn (Group $group): array => $group->only('id', 'name'))->values(),
                'pinned' => in_array($project->id, $pinned, true),
                'featured' => $project->apps_featured_at !== null,
                'listed' => $project->apps_listed,
                'can' => [
                    'open_project' => $manages || $project->user_id === $user->id,
                    'edit' => $manages || $project->user_id === $user->id,
                    'feature' => $manages,
                ],
            ]),
            // The groups an app of theirs (or, for admins, any app) can go in (APPS-002).
            'assignableGroups' => $this->assignableGroups($user, $organization)->map(fn (Group $group): array => $group->only('id', 'name'))->values(),
        ]);
    }

    /**
     * List or hide the app, feature it, or choose its groups (APPS-002, APPS-003).
     */
    public function update(Request $request, Project $project): RedirectResponse
    {
        $user = $request->user();
        $organization = ResolveOrganization::current($request);
        abort_if($project->isComputer(), 404);

        if ($request->hasAny(['listed', 'group_ids'])) {
            Gate::authorize('update', $project);
        }

        if ($request->has('featured')) {
            Gate::authorize('feature', $project);
        }

        $assignable = $this->assignableGroups($user, $organization)->pluck('id');

        $validated = $request->validate([
            'listed' => ['sometimes', 'boolean'],
            'featured' => ['sometimes', 'boolean'],
            'group_ids' => ['sometimes', 'array'],
            'group_ids.*' => ['integer', Rule::in($assignable->all())],
        ]);

        $changes = [];

        if (array_key_exists('listed', $validated)) {
            $changes['apps_listed'] = $validated['listed'];
        }

        if (array_key_exists('featured', $validated)) {
            $changes['apps_featured_at'] = $validated['featured'] ? ($project->apps_featured_at ?? now()) : null;
        }

        $project->update($changes);

        if (array_key_exists('group_ids', $validated)) {
            // Groups the user can't choose (an admin put it in) stay as they are.
            $kept = $project->groups()->whereNotIn('groups.id', $assignable)->pluck('groups.id');
            $project->groups()->sync($kept->merge($validated['group_ids'])->unique()->values()->all());
        }

        return back();
    }

    /**
     * Pin the app to the top of the user's Apps page (APPS-002).
     */
    public function pin(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('openApp', $project);

        $request->user()->pinnedApps()->syncWithoutDetaching([$project->id]);

        return back();
    }

    /**
     * Take the app out of the user's pinned apps.
     */
    public function unpin(Request $request, Project $project): RedirectResponse
    {
        $request->user()->pinnedApps()->detach($project->id);

        return back();
    }

    /**
     * The groups the user can put an app in: theirs in the organization, or every one of its groups for its admins.
     *
     * @return Collection<int, Group>
     */
    protected function assignableGroups(User $user, Organization $organization): Collection
    {
        return ($organization->isManagedBy($user) ? $organization->groups() : $user->groups()->inOrganization($organization))
            ->orderBy('name')
            ->get(['groups.id', 'groups.name']);
    }
}
