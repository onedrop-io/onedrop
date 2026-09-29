<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectSearchController extends Controller
{
    /**
     * The user's projects whose name matches the query, most recently updated first.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $query = trim($request->validate(['q' => ['nullable', 'string', 'max:255']])['q'] ?? '');

        $projects = $request->user()->projects()
            ->select(['id', 'name', 'archived_at'])
            ->when($query !== '', fn ($projects) => $projects->whereLike('name', "%{$query}%"))
            ->latest('updated_at')
            ->limit(20)
            ->get()
            ->map(fn ($project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'archived' => $project->archived_at !== null,
            ]);

        return response()->json(['projects' => $projects]);
    }
}
