<?php

namespace App\Sandbox;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\Sandbox;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Pushes to and pulls from a project's git remote (GitHub, GitLab, Forgejo, any HTTPS git host). Pushes run on the
 * platform, so a token that can write never enters the sandbox: commits leave the sandbox as the project's backup
 * bundle (ProjectBackups). Pulls come back in as a bundle that git.php fast-forwards to. A GitHub App repository's
 * pull is fetched by the sandbox itself, in the background, with a token that can only read that repository
 * (pullStep): it holds the code anyway, and a large history takes minutes, longer than a queue job should run.
 * Other remotes' tokens can't be narrowed like that, so they're fetched on the platform.
 */
class GitRemote
{
    /** Seconds git may take for one command on the platform. */
    public const TIMEOUT = 300;

    /** Seconds a pull's fetch may take: an import brings a repository's whole history, hundreds of MB for some. */
    public const FETCH_TIMEOUT = 1200;

    /** Where fetched commits are copied into the sandbox. */
    protected const PULL_DIRECTORY = '/tmp/onedrop-pull';

    /** Where the sandbox keeps a fetch it runs itself: its branch, state, exit code, error and the bundle it makes. */
    protected const FETCH_DIRECTORY = '/tmp/onedrop-fetch';

    /** Marks the sandbox's fetch as started, before it runs, so the next check finds it. */
    protected const FETCH_START = <<<'SH'
        rm -rf "$1" && mkdir -p "$1" && printf '%s' "$2" >"$1/branch" && echo running >"$1/state"
        SH;

    /**
     * The sandbox's fetch, in the background: the token reaches git only as a header in its environment (never its
     * arguments or the URL), and the result is a bundle for git.php, like a pull fetched on the platform.
     */
    protected const FETCH = <<<'SH'
        dir=$1
        echo $$ >"$dir/pid"
        export GIT_TERMINAL_PROMPT=0 GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_COUNT=3 \
            GIT_CONFIG_KEY_0=http.followRedirects GIT_CONFIG_VALUE_0=false \
            GIT_CONFIG_KEY_1=credential.helper GIT_CONFIG_VALUE_1= \
            GIT_CONFIG_KEY_2=http.extraHeader GIT_CONFIG_VALUE_2="$ONEDROP_FETCH_AUTH"
        unset ONEDROP_FETCH_AUTH
        ref="refs/heads/$ONEDROP_FETCH_BRANCH"
        git init --bare -q "$dir/repo.git" \
            && timeout "$2" git -C "$dir/repo.git" fetch -q "$ONEDROP_FETCH_URL" "+$ref:$ref" 2>"$dir/error"
        code=$?
        if [ "$code" = 0 ]; then
            GIT_CONFIG_COUNT=0 git -C "$dir/repo.git" bundle create -q "$dir/pull.bundle" "$ref" 2>"$dir/error"
            code=$?
        fi
        rm -rf "$dir/repo.git"
        echo "$code" >"$dir/exit"
        [ "$code" = 0 ] && state=done || state=failed
        echo "$state" >"$dir/state.new" && mv -f "$dir/state.new" "$dir/state"
        SH;

    /**
     * How the sandbox's fetch is doing: its state (none, running, done, failed, or lost when it stopped without
     * saying, e.g. the sandbox restarted), branch and exit code, one per line, then its error.
     */
    protected const FETCH_STATUS = <<<'SH'
        dir=$1
        [ -f "$dir/state" ] || { echo none; exit 0; }
        state=$(cat "$dir/state")
        if [ "$state" = running ]; then
            if [ -f "$dir/pid" ]; then
                kill -0 "$(cat "$dir/pid")" 2>/dev/null || state=$(cat "$dir/state")
                [ "$state" = running ] && ! kill -0 "$(cat "$dir/pid")" 2>/dev/null && state=lost
            elif [ -n "$(find "$dir/state" -mmin +2)" ]; then
                state=lost
            fi
        fi
        printf '%s\n%s\n%s\n' "$state" "$(cat "$dir/branch" 2>/dev/null)" "$(cat "$dir/exit" 2>/dev/null)"
        head -c 2000 "$dir/error" 2>/dev/null
        true
        SH;

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
     * Pull a step at a time, for a queue job that checks back until it's done: the first call starts the sandbox's
     * fetch, later ones check on it, and the one that finds it finished brings the commits in and returns true.
     * Remotes the sandbox doesn't fetch itself are pulled in one go.
     *
     * @throws SandboxException|GitException
     */
    public function pullStep(Project $project, ?string $branch = null): bool
    {
        if (! $this->fetchesInSandbox($project)) {
            $this->pull($project, $branch);

            return true;
        }

        $this->checkRemote($project);
        $sandbox = $this->runningSandbox($project);
        $fetch = $this->fetchStatus($sandbox);

        if ($fetch['state'] === 'none') {
            [$sandbox, $branch] = $this->prepare($project, importing: true, importBranch: $branch);
            $this->startFetch($project, $sandbox, $branch);

            return false;
        }

        if ($fetch['state'] === 'running') {
            return false;
        }

        try {
            match ($fetch['state']) {
                'done' => $this->git->pulled($sandbox, $fetch['branch'], self::FETCH_DIRECTORY.'/pull.bundle'),
                'lost' => throw new GitException(__('The download stopped before it finished. Try again.')),
                default => throw new GitException($fetch['exit'] === '124'
                    ? __('The remote took too long to answer (over :minutes minutes). Try again; a very large repository may need a faster connection.', ['minutes' => intdiv(self::FETCH_TIMEOUT, 60)])
                    : $this->explain($fetch['error'])),
            };
        } finally {
            $this->provider->exec($sandbox->external_id, ['rm', '-rf', self::FETCH_DIRECTORY]);
        }

        return true;
    }

    /**
     * Whether the sandbox fetches this project's pulls itself: a GitHub App repository, whose token can be narrowed
     * to reading it.
     */
    public function fetchesInSandbox(Project $project): bool
    {
        return $project->github_installation_id !== null && self::gitHubRepository((string) $project->git_remote_url) !== null;
    }

    /**
     * Start the sandbox's fetch of $branch, in the background.
     *
     * @throws SandboxException|GitException
     */
    protected function startFetch(Project $project, Sandbox $sandbox, string $branch): void
    {
        $token = $this->github->repositoryReadToken($project->github_installation_id, self::gitHubRepository($project->git_remote_url));

        $started = $this->provider->exec($sandbox->external_id, ['bash', '-c', self::FETCH_START, 'fetch', self::FETCH_DIRECTORY, $branch]);

        if (! $started->successful()) {
            throw new SandboxException(__('Couldn\'t start the download in the sandbox: :error', ['error' => Str::limit(trim($started->errorOutput), 200)]));
        }

        $this->provider->exec($sandbox->external_id, ['bash', '-c', self::FETCH, 'fetch', self::FETCH_DIRECTORY, (string) self::FETCH_TIMEOUT], [
            'ONEDROP_FETCH_URL' => $project->git_remote_url,
            'ONEDROP_FETCH_BRANCH' => $branch,
            'ONEDROP_FETCH_AUTH' => 'Authorization: Basic '.base64_encode("x-access-token:{$token}"),
        ], detach: true);
    }

    /**
     * @return array{state: string, branch: string, exit: string, error: string}
     *
     * @throws SandboxException
     */
    protected function fetchStatus(Sandbox $sandbox): array
    {
        $result = $this->provider->exec($sandbox->external_id, ['bash', '-c', self::FETCH_STATUS, 'fetch', self::FETCH_DIRECTORY]);

        if (! $result->successful()) {
            throw new SandboxException(__('Couldn\'t check on the download in the sandbox.'));
        }

        $lines = explode("\n", $result->output, 4);

        return ['state' => trim($lines[0]), 'branch' => trim($lines[1] ?? ''), 'exit' => trim($lines[2] ?? ''), 'error' => $lines[3] ?? ''];
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
            $this->run($project, ['git', '-C', 'repo.git', 'fetch', '-q', $project->git_remote_url, "+refs/heads/{$branch}:refs/heads/{$branch}"], $directory, remote: true, timeout: self::FETCH_TIMEOUT);
            $this->run($project, ['git', '-C', 'repo.git', 'bundle', 'create', '-q', '../out/pull.bundle', "refs/heads/{$branch}"], $directory, timeout: self::FETCH_TIMEOUT);

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

        $sandbox = $this->runningSandbox($project);
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
     * @throws SandboxException
     */
    protected function runningSandbox(Project $project): Sandbox
    {
        $sandbox = $project->sandbox()->first();

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            throw new SandboxException(__("The project's sandbox isn't running."));
        }

        return $sandbox;
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
    protected function run(Project $project, array $command, string $directory, bool $remote = false, int $timeout = self::TIMEOUT): string
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

        try {
            $result = Process::path($directory)->env($env)->timeout($timeout)->run($command);
        } catch (ProcessTimedOutException) {
            throw new GitException($remote
                ? __('The remote took too long to answer (over :minutes minutes). Try again; a very large repository may need a faster connection.', ['minutes' => intdiv($timeout, 60)])
                : __('Git took too long (over :minutes minutes). Try again.', ['minutes' => intdiv($timeout, 60)]));
        }

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
