<?php

namespace App\Sandbox;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Works out where a new project imported from a repository (PRJ-009) comes from: a GitHub repository the user and
 * the GitHub App can both reach (private ones too, no token kept), or any repository anyone can read over HTTPS.
 */
class RepositoryImport
{
    public function __construct(protected GitHubApp $github, protected GitRemote $remote) {}

    /**
     * The clone URL, the GitHub App installation to reach it through (if any), a project name, and the branch to import,
     * from "owner/name", a GitHub link (a `/tree/<branch>` link picks that branch), or any HTTPS git URL.
     *
     * @return array{url: string, installation_id: ?int, name: string, branch: string}
     *
     * @throws GitException
     */
    public function resolve(User $user, string $input): array
    {
        $input = trim($input);
        $branch = null;

        if (preg_match('#^(?:https?://)?(?:www\.)?github\.com/([A-Za-z0-9-]+)/([A-Za-z0-9._-]+?)(?:\.git)?(?:/tree/([^?\#]+))?/?(?:[?\#].*)?$#i', $input, $match)
            || preg_match('#^([A-Za-z0-9-]+)/([A-Za-z0-9._-]+?)(?:\.git)?$#', $input, $match)) {
            $fullName = "{$match[1]}/{$match[2]}";
            // The branch is the last group, so it's only set when the link names one.
            $branch = isset($match[3]) ? rawurldecode($match[3]) : null;
            $url = "https://github.com/{$fullName}.git";

            if (($reached = $this->throughGitHubApp($user, $fullName)) !== null) {
                return [...$reached, 'branch' => $branch ?? $reached['branch']];
            }
        } else {
            $url = $input;
        }

        if (($problem = GitRemote::problemWith($url)) !== null) {
            throw new GitException($problem);
        }

        try {
            $default = $this->remote->defaultBranch($url);
        } catch (GitException) {
            throw new GitException($this->github->configured()
                ? __('Couldn\'t reach that repository. If it\'s private, connect GitHub and pick it from your repositories.')
                : __('Couldn\'t reach that repository. Only public repositories can be imported without GitHub connected.'));
        }

        if ($default === null) {
            throw new GitException(__('That repository has no commits yet.'));
        }

        return [
            'url' => $url,
            'installation_id' => null,
            'name' => self::nameFrom($url),
            'branch' => $branch ?? $default,
        ];
    }

    /**
     * The repository as the user sees it through one of their GitHub App installations, or null when they can't
     * reach it that way (not signed in, not installed on its owner, or not one of the repositories it was given).
     *
     * @return array{url: string, installation_id: int, name: string, branch: string}|null
     *
     * @throws GitException when it's reachable but empty
     */
    protected function throughGitHubApp(User $user, string $fullName): ?array
    {
        if (! $this->github->configured()) {
            return null;
        }

        $owner = Str::before($fullName, '/');
        $installation = $user->githubInstallations()->get()->first(fn ($installation) => strcasecmp($installation->account_login, $owner) === 0);

        if ($installation === null) {
            return null;
        }

        try {
            $repository = $this->github->find($user, $fullName);
        } catch (GitException) {
            return null;
        }

        if ($repository['empty']) {
            throw new GitException(__('That repository has no commits yet.'));
        }

        return [
            'url' => $repository['clone_url'],
            'installation_id' => $installation->installation_id,
            'name' => self::nameFrom($repository['name']),
            'branch' => $repository['default_branch'],
        ];
    }

    /**
     * "team-timer" → "Team Timer", from a repository name or URL.
     */
    public static function nameFrom(string $repository): string
    {
        $name = (string) preg_replace('/\.git$/i', '', basename(rtrim(parse_url($repository, PHP_URL_PATH) ?: $repository, '/')));

        return Str::limit(Str::headline($name), 60, '') ?: 'Imported App';
    }
}
