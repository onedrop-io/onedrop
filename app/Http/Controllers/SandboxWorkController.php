<?php

namespace App\Http\Controllers;

use App\Enums\GitSyncStatus;
use App\Jobs\FinishSnapshot;
use App\Jobs\ImportRepository;
use App\Jobs\MoveProjectSandbox;
use App\Jobs\SyncGitRemote;
use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectSnapshot;
use App\Models\SandboxMove;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Background work in a sandbox saying it's over (a snapshot packed and uploaded, a move's files unpacked, a git
 * fetch), so the app carries on now instead of checking back. Each gets its signed address when the work starts; the
 * job that carries on reads the outcome from the sandbox itself.
 */
class SandboxWorkController extends Controller
{
    public function snapshot(ProjectSnapshot $snapshot): Response
    {
        FinishSnapshot::dispatch($snapshot);

        return response()->noContent();
    }

    public function move(SandboxMove $move): Response
    {
        MoveProjectSandbox::dispatch($move);

        return response()->noContent();
    }

    public function fetched(Request $request, Project $project): Response
    {
        $branch = $request->query('branch');
        $branch = is_string($branch) && $branch !== '' ? $branch : null;

        match ($request->query('job')) {
            'import' => ImportRepository::dispatch($project, Message::query()->where('project_id', $project->id)->findOrFail((int) $request->query('message')), (string) $branch, ImportRepository::CALLED_BACK),
            default => SyncGitRemote::dispatch($project, GitSyncStatus::Pulling, $branch, ImportRepository::CALLED_BACK),
        };

        return response()->noContent();
    }
}
