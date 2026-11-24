<?php

namespace App\Http\Controllers;

use App\Jobs\HandlePullRequestEvent;
use App\Models\Project;
use App\Sandbox\GitHubPulls;
use App\Sandbox\GitRemote;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The GitHub App's webhook (GIT-026): pull requests opened, given commits, reopened, merged or closed, for the projects
 * connected to their repository through the app. Signed with GITHUB_WEBHOOK_SECRET; the work is queued.
 */
class GitHubWebhookController extends Controller
{
    /** The pull_request actions that change what a preview should run. */
    protected const ACTIONS = ['opened', 'reopened', 'synchronize', 'ready_for_review', 'converted_to_draft', 'closed'];

    public function store(Request $request): Response
    {
        $secret = (string) config('services.github_app.webhook_secret');
        $signature = (string) $request->header('X-Hub-Signature-256');

        if ($secret === '' || ! hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            return response('Bad signature', 401);
        }

        $event = $request->header('X-GitHub-Event');

        if ($event !== 'pull_request' || ! in_array($request->input('action'), self::ACTIONS, true)) {
            return response('Ignored', 202);
        }

        $repository = (string) $request->input('repository.full_name');
        $installation = $request->integer('installation.id');
        $pull = GitHubPulls::fromWebhook((array) $request->input('pull_request'), $repository);

        Project::query()
            ->where('github_installation_id', $installation)
            ->whereNotNull('git_remote_url')
            ->get()
            ->filter(fn (Project $project) => strcasecmp((string) GitRemote::gitHubRepository((string) $project->git_remote_url), $repository) === 0)
            ->each(fn (Project $project) => HandlePullRequestEvent::dispatch($project, $pull));

        return response('Queued', 202);
    }
}
