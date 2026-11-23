<?php

namespace App\Sandbox;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\Sandbox;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;

/**
 * Points a sandbox's repository at the project's remote as `origin` (GIT-021), so the agent, the Shell and git itself
 * can fetch, list and check out any branch or pull request like in a clone. A GitHub App repository gets git's
 * credential helper (docker/sandbox/lazy/git-credential-onedrop), which asks the platform for a token that can only read
 * that repository, for an hour, each time git needs one. Pushing stays with the platform (GIT-004), and the remote's
 * own token (a token remote's) never enters the sandbox: a private repository on another host can't be fetched from
 * the sandbox, only pulled from Source Control.
 */
class GitOrigin
{
    /** The tool files this needs, copied into a sandbox that doesn't have them yet. */
    public const TOOLS = ['git.php', 'lazy/git-credential-onedrop', 'onedrop-tool'];

    public function __construct(protected SandboxProvider $provider, protected SandboxTools $tools, protected WorkspaceGit $git) {}

    /**
     * What the sandbox should have: the remote's URL, and for a GitHub App repository its "owner/name" and the signed
     * address its credential helper asks for a token.
     *
     * @return array{url: ?string, repository: ?string, token_url: ?string}
     */
    public function settings(Sandbox $sandbox): array
    {
        $project = $sandbox->project;
        $url = $project->git_remote_url;

        if ($url === null || ! str_starts_with(strtolower($url), 'https://') || $project->isComputer()) {
            return ['url' => null, 'repository' => null, 'token_url' => null];
        }

        $repository = self::readsThroughGitHubApp($project) ? GitRemote::gitHubRepository($url) : null;

        return [
            'url' => $url,
            'repository' => $repository,
            'token_url' => $repository === null ? null : rtrim(config('sandbox.callback_url'), '/')
                .URL::signedRoute('sandbox-git.token', $sandbox, absolute: false),
        ];
    }

    /**
     * Whether the sandbox can get a token for the project's remote: a GitHub repository reached through the GitHub App.
     */
    public static function readsThroughGitHubApp(Project $project): bool
    {
        return $project->github_installation_id !== null && GitRemote::gitHubRepository((string) $project->git_remote_url) !== null;
    }

    /**
     * Bring a sandbox's `origin` up to date. What each sandbox was last given is remembered, so one that's up to date
     * isn't asked again; one with a remote but no repository yet (an import under way) is asked again next time. A
     * project without a remote only takes `origin` away from sandboxes that were given one, unless $evenWithoutRemote
     * (a new sandbox, whose files may come from a project that had one, like a remix).
     *
     * @throws SandboxException|GitException
     */
    public function sync(Sandbox $sandbox, bool $evenWithoutRemote = false): void
    {
        if ($sandbox->external_id === null || $sandbox->project->isComputer()) {
            return;
        }

        $settings = $this->settings($sandbox);
        $key = "git-origin:{$sandbox->external_id}";
        $hash = sha1(json_encode($settings, JSON_THROW_ON_ERROR));

        // Up to date, or a project without a remote whose sandbox was never given one: nothing to add or take away.
        if (Cache::get($key) === $hash || ($settings['url'] === null && ! Cache::has($key) && ! $evenWithoutRemote)) {
            return;
        }

        $this->tools->ensure($this->provider, $sandbox->external_id, self::TOOLS);

        $status = $this->git->origin($sandbox, $settings['url'], $settings['repository'], $settings['token_url']);

        // Without a remote there's nothing to add once the repository starts, so that's remembered too.
        if (($status['initialized'] ?? false) || $settings['url'] === null) {
            Cache::put($key, $hash, now()->addDays(30));
        }
    }

    /**
     * After the project's remote changed or its repository came in, update its running sandboxes (task copies too).
     * Sleeping ones are brought up to date when they wake, and every agent run updates its own first.
     */
    public function syncProject(Project $project): void
    {
        Sandbox::query()
            ->where('project_id', $project->id)
            ->where('status', SandboxStatus::Running)
            ->whereNull('suspended_at')
            ->whereNotNull('external_id')
            ->each(function (Sandbox $sandbox) {
                try {
                    $this->sync($sandbox);
                } catch (SandboxException|GitException $e) {
                    report($e);
                }
            });
    }

    /**
     * The same as sync(), reporting instead of failing: for steps where `origin` is a nicety, not the point.
     */
    public function syncQuietly(Sandbox $sandbox, bool $evenWithoutRemote = false): void
    {
        try {
            $this->sync($sandbox, $evenWithoutRemote);
        } catch (SandboxException|GitException $e) {
            report($e);
        }
    }
}
