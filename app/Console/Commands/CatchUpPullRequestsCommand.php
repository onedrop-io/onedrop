<?php

namespace App\Console\Commands;

use App\Jobs\CatchUpPullRequests;
use App\Models\Project;
use App\Sandbox\PullRequestPreviews;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Catch up on pull requests no webhook told OneDrop about (GIT-026): hourly for every project that previews them, and
 * every 5 minutes (--unhooked) for those that can't get the GitHub App's webhook: token remotes, or no webhook set up.
 */
#[Signature('pull-requests:catch-up {--unhooked : Only projects the GitHub App\'s webhook can\'t reach}')]
#[Description('Check out new pull requests, bring in their commits and settle closed ones, for projects that preview them')]
class CatchUpPullRequestsCommand extends Command
{
    public function handle(): int
    {
        $hooked = filled(config('services.github_app.webhook_secret'));

        Project::query()
            ->where('preview_pull_requests', true)
            ->whereNotNull('git_remote_url')
            ->when($this->option('unhooked') && $hooked, fn ($query) => $query->whereNull('github_installation_id'))
            ->each(function (Project $project) {
                if (PullRequestPreviews::enabled($project)) {
                    CatchUpPullRequests::dispatch($project);
                }
            });

        return self::SUCCESS;
    }
}
