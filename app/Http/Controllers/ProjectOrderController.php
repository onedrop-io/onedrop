<?php

namespace App\Http\Controllers;

use App\Enums\ProjectSort;
use App\Http\Middleware\ResolveOrganization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProjectOrderController extends Controller
{
    /**
     * Choose how the sidebar sorts the user's projects (PRJ-010).
     */
    public function sort(Request $request): RedirectResponse
    {
        $sort = $request->validate(['sort' => ['required', Rule::enum(ProjectSort::class)]])['sort'];

        $request->user()->forceFill(['project_sort' => $sort])->save();

        return back();
    }

    /**
     * Save the order the user dragged a sidebar list into, and sort by it from now on (PRJ-010).
     *
     * The dragged projects go first; the rest keep the order they're shown in now, after them,
     * so switching to the manual order doesn't move anything the user didn't drag.
     */
    public function update(Request $request): RedirectResponse
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
        ])['ids'];

        $user = $request->user();
        $projects = $user->projects()
            ->inOrganization(ResolveOrganization::current($request))
            ->select(['id', 'sidebar_position'])
            ->sortedBy($user->project_sort)
            ->get();

        $positions = array_flip(array_map(intval(...), $ids));
        $ordered = $projects->whereIn('id', $ids)->sortBy(fn (Project $project): int => $positions[$project->id])
            ->concat($projects->whereNotIn('id', $ids))
            ->values();

        // Reordering isn't an update to the project, so it keeps its place under "Last updated".
        DB::transaction(fn () => Project::withoutTimestamps(function () use ($ordered) {
            foreach ($ordered as $index => $project) {
                if ($project->sidebar_position !== $index + 1) {
                    Project::query()->whereKey($project->id)->update(['sidebar_position' => $index + 1]);
                }
            }
        }));

        $user->forceFill(['project_sort' => ProjectSort::Manual])->save();

        return back();
    }
}
