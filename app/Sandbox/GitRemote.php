<?php

namespace App\Sandbox;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\Sandbox;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Pushes to and pulls from a project's git remote (GitHub, GitLab, Forgejo, any HTTPS git host) on the platform,
 * so the remote's token never enters the sandbox: commits leave the sandbox as the project's backup bundle
 * (ProjectBackups) and come back in as a bundle that git.php fast-forwards to.
 */
class GitRemote
{
    /** Where fetched commits are copied into the sandbox. */
    protected const PULL_DIRECTORY = '/tmp/onedrop-pull';

    public function __construct(
        protected SandboxProvider $provider,
        protected ProjectBackups $backups,
        protected WorkspaceGit $git,
        protected GitHubApp $github,
    ) {}

    /**
     * The "owner/name" of a GitHub repository URL, or null when it isn't one.
     */
    public static function gitHubRepository(string $url): ?string
    {
        return preg_match('#^https://github\.com/([A-Za-z0-9-]+/[A-Za-z0-9._-]+?)(?:\.git)?/?$#i', $url, $match) ? $match[1] : null;
    }

    /**
     * Why a remote URL can't be used, or null when it can: HTTPS only, no credentials in it, and (unless
     * SANDBOX_GIT_ALLOW_PRIVATE_REMOTES) only hosts on the public internet, so the platform can't be pointed at its own network.
     */
    public static function problemWith(string $url): ?string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, config('sandbox.git.protocols'), true)) {
            return __('Use the repository\'s HTTPS URL, e.g. https://github.com/you/app.git.');
        }

        if ($scheme === 'file') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '' || parse_url($url, PHP_URL_USER) !== null) {
            return __('Use the repository\'s HTTPS URL without a username or token in it.');
        }

        if (config('sandbox.git.allow_private_remotes')) {
            return null;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ($addresses === []) {
            return __('Couldn\'t find :host.', ['host' => $host]);
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return __('That address is on a private network. Ask an admin to set SANDBOX_GIT_ALLOW_PRIVATE_REMOTES to push there.');
            }
        }

        return null;
    }

    /**
     * Push the sandbox's current branch to the remote.
     *
     * @throws SandboxException|GitException
     */
    public function push(Project $project): void
    {
        [$sandbox, $branch] = $this->prepare($project);

        $this->backups->backUp($project, force: true);
        $directory = $this->backups->download($project);

        try {
            $this->run($project, ['git', 'init', '--bare', '-q', 'repo.git'], $directory);
            $this->run($project, ['git', '-C', 'repo.git', 'fetch', '-q', '../repo.bundle', "+refs/heads/{$branch}:refs/heads/{$branch}"], $directory);
            $this->run($project, ['git', '-C', 'repo.git', 'push', '-q', $project->git_remote_url, "refs/heads/{$branch}:refs/heads/{$branch}"], $directory, remote: true);
            $sha = trim($this->run($project, ['git', '-C', 'repo.git', 'rev-parse', "refs/heads/{$branch}"], $directory));
        } finally {
            File::deleteDirectory($directory);
        }

        $this->git->pushed($sandbox, $branch, $sha);
    }

    /**
     * Bring the remote's new commits on the current branch into the sandbox (fast-forward only). A project with no
     * commits yet gets $branch (or its current one) from the remote: an import.
     *
     * @throws SandboxException|GitException
     */
    public function pull(Project $project, ?string $branch = null): void
    {
        [$sandbox, $branch] = $this->prepare($project, importing: true, importBranch: $branch);

        $directory = storage_path('framework/git-pull-'.uniqid());
        File::ensureDirectoryExists("{$directory}/out");

        try {
            $this->run($project, ['git', 'init', '--bare', '-q', 'repo.git'], $directory);
            $this->run($project, ['git', '-C', 'repo.git', 'fetch', '-q', $project->git_remote_url, "+refs/heads/{$branch}:refs/heads/{$branch}"], $directory, remote: true);
            $this->run($project, ['git', '-C', 'repo.git', 'bundle', 'create', '-q', '../out/pull.bundle', "refs/heads/{$branch}"], $directory);

            $this->provider->copyIn($sandbox->external_id, "{$directory}/out", self::PULL_DIRECTORY);
        } finally {
            File::deleteDirectory($directory);
        }

        $this->git->pulled($sandbox, $branch, self::PULL_DIRECTORY.'/pull.bundle');
    }

    /**
     * Fetch a GitHub pull request's newest commits (`refs/pull/N/head`, which a fork's pull request has too) into
     * $directory/pull.bundle, whose HEAD is the pull request's head, for its task's copy (GIT-014). Returns that commit.
     *
     * @throws GitException
     */
    public function fetchPullRequest(Project $project, int $number, string $directory): string
    {
        $this->checkRemote($project);
        $work = storage_path('framework/git-pr-'.uniqid());
        File::ensureDirectoryExists($work);
        File::ensureDirectoryExists($directory);

        try {
            $this->run($project, ['git', 'init', '--bare', '-q', 'repo.git'], $work);
            $this->run($project, ['git', '-C', 'repo.git', 'fetch', '-q', $project->git_remote_url, "+refs/pull/{$number}/head:refs/heads/pull"], $work, remote: true);
            $this->run($project, ['git', '-C', 'repo.git', 'symbolic-ref', 'HEAD', 'refs/heads/pull'], $work);
            $this->run($project, ['git', '-C', 'repo.git', 'bundle', 'create', '-q', "{$directory}/pull.bundle", 'HEAD', 'refs/heads/pull'], $work);

            return trim($this->run($project, ['git', '-C', 'repo.git', 'rev-parse', 'HEAD'], $work));
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * Push the HEAD of a bundle (a pull request's task's branch, from its copy) to $branch on the remote, never
     * forcing: when the remote has moved on, it fails and says to pull first. Returns the pushed commit.
     *
     * @throws GitException
     */
    public function pushBundle(Project $project, string $bundle, string $branch): string
    {
        $this->checkRemote($project);
        $work = storage_path('framework/git-pr-'.uniqid());
        File::ensureDirectoryExists($work);

        try {
            $this->run($project, ['git', 'init', '--bare', '-q', 'repo.git'], $work);
            $this->run($project, ['git', '-C', 'repo.git', 'fetch', '-q', $bundle, "+HEAD:refs/heads/{$branch}"], $work);
            $this->run($project, ['git', '-C', 'repo.git', 'push', '-q', $project->git_remote_url, "refs/heads/{$branch}:refs/heads/{$branch}"], $work, remote: true);

            return trim($this->run($project, ['git', '-C', 'repo.git', 'rev-parse', "refs/heads/{$branch}"], $work));
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * The default branch of a repository anyone can read (no token), checked before importing it into a new project;
     * null when it has no commits yet.
     *
     * @throws GitException when it can't be reached
     */
    public function defaultBranch(string $url): ?string
    {
        // A project that isn't saved has no token or installation, so the remote is asked anonymously.
        $output = $this->run(new Project(['git_remote_url' => $url]), ['git', 'ls-remote', '--symref', $url, 'HEAD'], sys_get_temp_dir(), remote: true);

        return preg_match('#^ref: refs/heads/(\S+)\s+HEAD#m', $output, $match) ? $match[1] : null;
    }

    /**
     * Make a new private (or public) repository on the token owner's GitHub account and return its HTTPS URL.
     *
     * @throws GitException
     */
    public function createGitHubRepository(string $token, string $name, bool $private): string
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(20)
            ->post('https://api.github.com/user/repos', ['name' => $name, 'private' => $private, 'auto_init' => false]);

        if ($response->status() === 401) {
            throw new GitException(__('GitHub didn\'t accept the token.'));
        }

        if ($response->status() === 422) {
            throw new GitException(__('Your GitHub account already has a repository called :name.', ['name' => $name]));
        }

        if ($response->failed() || ! is_string($response->json('clone_url'))) {
            throw new GitException(__('GitHub couldn\'t create the repository: :message', ['message' => $response->json('message') ?? $response->status()]));
        }

        return $response->json('clone_url');
    }

    /**
     * The running sandbox and the branch to push or pull: the current one, or when $importing into a project
     * with no commits yet, $importBranch.
     *
     * @return array{Sandbox, string}
     *
     * @throws SandboxException|GitException
     */
    protected function prepare(Project $project, bool $importing = false, ?string $importBranch = null): array
    {
        $this->checkRemote($project);

        $sandbox = $project->sandbox()->first();

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            throw new SandboxException(__("The project's sandbox isn't running."));
        }

        $status = $this->git->status($sandbox);

        if ($status['head'] === null) {
            if (! $importing) {
                throw new GitException(__('Commit something first.'));
            }

            return [$sandbox, $importBranch ?? $status['branch'] ?? 'main'];
        }

        if ($status['branch'] === null) {
            throw new GitException(__('Switch to a branch first.'));
        }

        return [$sandbox, $status['branch']];
    }

    /**
     * @throws GitException when the project has no remote, or one that can't be used
     */
    protected function checkRemote(Project $project): void
    {
        if ($project->git_remote_url === null) {
            throw new GitException(__('Connect a remote repository first.'));
        }

        if (($problem = self::problemWith($project->git_remote_url)) !== null) {
            throw new GitException($problem);
        }
    }

    /**
     * Run git on the platform, ignoring this machine's git settings; $remote adds the remote's token as a header
     * (for a GitHub App repository, a fresh installation token).
     *
     * @param  list<string>  $command
     *
     * @throws GitException
     */
    protected function run(Project $project, array $command, string $directory, bool $remote = false): string
    {
        $config = ['http.followRedirects' => 'false', 'credential.helper' => ''];

        $token = match (true) {
            ! $remote => null,
            $project->github_installation_id !== null => $this->github->installationToken($project->github_installation_id),
            default => $project->git_remote_token,
        };

        if ($token) {
            $username = $project->github_installation_id !== null ? 'x-access-token' : ($project->git_remote_username ?: 'x-access-token');
            $config['http.extraHeader'] = 'Authorization: Basic '.base64_encode("{$username}:{$token}");
        }

        $env = [
            'GIT_TERMINAL_PROMPT' => '0',
            // Only the remote is limited to HTTPS: the local steps read the backup bundle, which git counts as "file".
            ...($remote ? ['GIT_ALLOW_PROTOCOL' => implode(':', config('sandbox.git.protocols'))] : []),
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_COUNT' => (string) count($config),
        ];

        foreach (array_keys($config) as $index => $key) {
            $env["GIT_CONFIG_KEY_{$index}"] = $key;
            $env["GIT_CONFIG_VALUE_{$index}"] = $config[$key];
        }

        $result = Process::path($directory)->env($env)->timeout(300)->run($command);

        if ($result->failed()) {
            throw new GitException($this->explain($result->errorOutput()));
        }

        return $result->output();
    }

    protected function explain(string $error): string
    {
        return match (true) {
            Str::contains($error, ['non-fast-forward', 'fetch first', '[rejected]']) => __('The remote has commits this project doesn\'t. Pull first.'),
            Str::contains($error, ["couldn't find remote ref", 'could not find remote ref']) => __('The remote doesn\'t have this branch yet. Push it first.'),
            Str::contains($error, ['Authentication failed', 'could not read Username', 'returned error: 401', 'returned error: 403', 'Permission to']) => __('The remote didn\'t accept the token. Check that it can read and write this repository.'),
            Str::contains($error, ['not found', 'returned error: 404']) => __('The remote repository wasn\'t found. Check the URL, and that the token can see it.'),
            default => __('Git couldn\'t reach the remote: :error', ['error' => Str::limit(trim(Str::before(trim($error), "\n")), 200)]),
        };
    }
}
