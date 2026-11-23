<?php

namespace App\Http\Controllers;

use App\Models\Sandbox;
use App\Sandbox\GitException;
use App\Sandbox\GitHubApp;
use App\Sandbox\GitOrigin;
use App\Sandbox\GitRemote;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Called by git's credential helper in a sandbox (GIT-021), on the signed address GitOrigin gave it.
 */
class SandboxGitController extends Controller
{
    /** How long a token is handed out again: GitHub's last an hour, so each one has at least 15 minutes left. */
    protected const TOKEN_SECONDS = 2700;

    /**
     * A token that can only read the project's GitHub App repository (and its pull requests), for git and gh.
     */
    public function token(Sandbox $sandbox, GitHubApp $github): JsonResponse
    {
        $project = $sandbox->project;

        if (! GitOrigin::readsThroughGitHubApp($project)) {
            return response()->json(['message' => __('This project\'s remote isn\'t a GitHub repository connected through the GitHub App.')], 404);
        }

        $repository = GitRemote::gitHubRepository($project->git_remote_url);

        try {
            $token = Cache::remember("sandbox-git-token:{$project->github_installation_id}:".strtolower($repository), self::TOKEN_SECONDS,
                fn () => $github->repositoryReadToken($project->github_installation_id, $repository, pullRequests: true));
        } catch (GitException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['username' => 'x-access-token', 'password' => $token]);
    }
}
