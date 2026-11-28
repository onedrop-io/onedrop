<?php

namespace App\Http\Controllers;

use App\Sandbox\GitHubRepositorySearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public GitHub repositories matching what's typed in the new-project page's repository field (PRJ-009).
 */
class GitHubRepositorySearchController extends Controller
{
    public function __invoke(Request $request, GitHubRepositorySearch $search): JsonResponse
    {
        $query = $request->validate(['q' => ['required', 'string', 'max:200']])['q'];

        return response()->json(['repositories' => $search->search($request->user(), $query)]);
    }
}
