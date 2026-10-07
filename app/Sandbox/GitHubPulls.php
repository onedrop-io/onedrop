<?php

namespace App\Sandbox;

use App\Models\Project;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A project's GitHub pull requests (GIT-013): the list, one's conversation, commits, files and checks, and the end
 * of its failed checks' logs (GIT-015). Read on the platform with the same credentials as pushing (GitRemote): the
 * GitHub App installation's token, or the token stored for the remote. Nothing here runs in the sandbox.
 */
class GitHubPulls
{
    protected const API = 'https://api.github.com';

    /** Seconds GitHub's answers are reused, so tabs and refreshes don't spend the rate limit. */
    public const CACHE_SECONDS = 15;

    /** The end of each failed check's log sent to the agent, and of all of them. */
    public const LOG_CHARACTERS = 4000;

    public const LOGS_CHARACTERS = 12000;

    /** A file's diff longer than this is cut short. */
    protected const PATCH_CHARACTERS = 200_000;

    /** States of a check that failed. */
    protected const FAILED = ['failure', 'timed_out', 'cancelled', 'action_required', 'startup_failure', 'stale'];

    public function __construct(protected GitHubApp $github) {}

    /**
     * The "owner/name" of the project's GitHub repository, or null when its remote isn't on GitHub.
     */
    public static function repository(Project $project): ?string
    {
        return $project->git_remote_url ? GitRemote::gitHubRepository($project->git_remote_url) : null;
    }

    /**
     * Whether the project's pull requests can be read: a GitHub remote with an installation or a token.
     */
    public static function available(Project $project): bool
    {
        return self::repository($project) !== null && ($project->github_installation_id !== null || filled($project->git_remote_token));
    }

    /**
     * The 50 most recently changed open (or closed and merged) pull requests, with their checks' and review's state.
     *
     * @return list<array<string, mixed>>
     *
     * @throws GitException
     */
    public function list(Project $project, bool $closed = false): array
    {
        [$owner, $name] = explode('/', $this->repositoryOf($project), 2);

        return $this->cached($project, 'list:'.($closed ? 'closed' : 'open'), function () use ($project, $owner, $name, $closed) {
            $response = $this->api($project)->post(self::API.'/graphql', [
                'query' => <<<'GRAPHQL'
                    query($owner: String!, $name: String!, $states: [PullRequestState!]) {
                      repository(owner: $owner, name: $name) {
                        pullRequests(states: $states, first: 50, orderBy: {field: UPDATED_AT, direction: DESC}) {
                          nodes {
                            number title state isDraft url updatedAt headRefName baseRefName reviewDecision
                            author { login avatarUrl }
                            headRepository { nameWithOwner }
                            comments { totalCount }
                            commits(last: 1) { nodes { commit { oid statusCheckRollup { state } } } }
                          }
                        }
                      }
                    }
                    GRAPHQL,
                'variables' => ['owner' => $owner, 'name' => $name, 'states' => $closed ? ['CLOSED', 'MERGED'] : ['OPEN']],
            ]);

            $this->check($response, 'Pull requests');

            if (($errors = $response->json('errors')) !== null) {
                // GraphQL answers a missing permission with 200 and this error, rather than a 403.
                if (($errors[0]['type'] ?? null) === 'FORBIDDEN' || Str::contains((string) ($errors[0]['message'] ?? ''), 'not accessible by integration')) {
                    throw new GitException(__('GitHub refused to show this: the app or token needs the :permission: Read permission. (:message)', ['permission' => 'Pull requests', 'message' => $errors[0]['message'] ?? 'Forbidden']));
                }

                throw new GitException(__('GitHub couldn\'t list the pull requests: :message', ['message' => $errors[0]['message'] ?? 'unknown error']));
            }

            return array_values(array_map(fn (array $pull): array => [
                'number' => (int) $pull['number'],
                'title' => (string) $pull['title'],
                'state' => $pull['state'] === 'OPEN' && $pull['isDraft'] ? 'draft' : strtolower((string) $pull['state']),
                'url' => (string) $pull['url'],
                'updated_at' => $pull['updatedAt'],
                'head' => (string) $pull['headRefName'],
                'base' => (string) $pull['baseRefName'],
                'author' => $this->person($pull['author'] ?? null, 'avatarUrl'),
                'fork' => ($pull['headRepository']['nameWithOwner'] ?? null) !== "{$owner}/{$name}",
                'comments' => (int) ($pull['comments']['totalCount'] ?? 0),
                'review' => match ($pull['reviewDecision'] ?? null) {
                    'APPROVED' => 'approved',
                    'CHANGES_REQUESTED' => 'changes_requested',
                    'REVIEW_REQUIRED' => 'review_required',
                    default => null,
                },
                'checks' => match ($pull['commits']['nodes'][0]['commit']['statusCheckRollup']['state'] ?? null) {
                    'SUCCESS' => 'success',
                    'FAILURE', 'ERROR' => 'failure',
                    'PENDING', 'EXPECTED' => 'pending',
                    default => null,
                },
            ], $response->json('data.repository.pullRequests.nodes') ?? []));
        });
    }

    /**
     * One pull request: its state, branches, author, description, whether it can be merged, and its size.
     *
     * @return array<string, mixed>
     *
     * @throws GitException
     */
    public function find(Project $project, int $number): array
    {
        $repository = $this->repositoryOf($project);

        return $this->cached($project, "pull:{$number}", function () use ($project, $repository, $number) {
            $pull = $this->check($this->api($project)->get(self::API."/repos/{$repository}/pulls/{$number}"), 'Pull requests')->json();

            return [
                'number' => (int) $pull['number'],
                'title' => (string) $pull['title'],
                'body' => (string) ($pull['body'] ?? ''),
                'state' => match (true) {
                    (bool) ($pull['merged'] ?? false) => 'merged',
                    $pull['state'] === 'open' && ($pull['draft'] ?? false) => 'draft',
                    default => (string) $pull['state'],
                },
                'url' => (string) $pull['html_url'],
                'author' => $this->person($pull['user'] ?? null),
                'created_at' => $pull['created_at'] ?? null,
                'updated_at' => $pull['updated_at'] ?? null,
                'head' => (string) $pull['head']['ref'],
                'head_sha' => (string) $pull['head']['sha'],
                'head_repository' => $pull['head']['repo']['full_name'] ?? null,
                'base' => (string) $pull['base']['ref'],
                // A fork's branch (or one whose fork was deleted) can't be pushed to with this repository's credentials.
                'fork' => ($pull['head']['repo']['full_name'] ?? null) !== $repository,
                // null while GitHub is still working it out.
                'mergeable' => $pull['mergeable'] ?? null,
                'mergeable_state' => $pull['mergeable_state'] ?? null,
                'commits' => (int) ($pull['commits'] ?? 0),
                'additions' => (int) ($pull['additions'] ?? 0),
                'deletions' => (int) ($pull['deletions'] ?? 0),
                'changed_files' => (int) ($pull['changed_files'] ?? 0),
                'comments' => (int) ($pull['comments'] ?? 0) + (int) ($pull['review_comments'] ?? 0),
            ];
        });
    }

    /**
     * The comments, reviews and comments on lines, oldest first.
     *
     * @return list<array{kind: string, author: ?array{login: string, avatar_url: ?string}, body: string, state: ?string, path: ?string, line: ?int, created_at: ?string, url: ?string}>
     *
     * @throws GitException
     */
    public function conversation(Project $project, int $number): array
    {
        $repository = $this->repositoryOf($project);

        return $this->cached($project, "conversation:{$number}", function () use ($project, $repository, $number) {
            $comments = $this->pages($project, "/repos/{$repository}/issues/{$number}/comments", 'Pull requests');
            $reviews = $this->pages($project, "/repos/{$repository}/pulls/{$number}/reviews", 'Pull requests');
            $lineComments = $this->pages($project, "/repos/{$repository}/pulls/{$number}/comments", 'Pull requests');

            $entries = [
                ...array_map(fn (array $comment) => $this->entry('comment', $comment, $comment['created_at'] ?? null), $comments),
                // A review that only left line comments has no text of its own; its state still says something.
                ...array_map(fn (array $review) => [
                    ...$this->entry('review', $review, $review['submitted_at'] ?? null),
                    'state' => strtolower((string) ($review['state'] ?? '')) ?: null,
                ], array_filter($reviews, fn (array $review) => ($review['state'] ?? null) !== 'PENDING')),
                ...array_map(fn (array $comment) => [
                    ...$this->entry('line', $comment, $comment['created_at'] ?? null),
                    'path' => $comment['path'] ?? null,
                    'line' => isset($comment['line']) ? (int) $comment['line'] : null,
                ], $lineComments),
            ];

            usort($entries, fn (array $a, array $b) => strcmp((string) $a['created_at'], (string) $b['created_at']));

            return $entries;
        });
    }

    /**
     * The pull request's commits (up to 250, GitHub's limit), oldest first.
     *
     * @return list<array{sha: string, message: string, author: string, date: ?string, url: string}>
     *
     * @throws GitException
     */
    public function commits(Project $project, int $number): array
    {
        $repository = $this->repositoryOf($project);

        return $this->cached($project, "commits:{$number}", fn () => array_map(fn (array $commit) => [
            'sha' => (string) $commit['sha'],
            'message' => (string) ($commit['commit']['message'] ?? ''),
            'author' => (string) ($commit['author']['login'] ?? $commit['commit']['author']['name'] ?? ''),
            'date' => $commit['commit']['author']['date'] ?? null,
            'url' => (string) ($commit['html_url'] ?? ''),
        ], $this->pages($project, "/repos/{$repository}/pulls/{$number}/commits", 'Pull requests')));
    }

    /**
     * The files it changes (up to 300), each with its diff; GitHub leaves out the diff of very large files.
     *
     * @return list<array{path: string, previous_path: ?string, status: string, additions: int, deletions: int, binary: bool, patch: string, truncated: bool}>
     *
     * @throws GitException
     */
    public function files(Project $project, int $number): array
    {
        $repository = $this->repositoryOf($project);

        return $this->cached($project, "files:{$number}", fn () => array_map(function (array $file) {
            $patch = (string) ($file['patch'] ?? '');
            $changes = (int) ($file['changes'] ?? 0);

            return [
                'path' => (string) $file['filename'],
                'previous_path' => $file['previous_filename'] ?? null,
                'status' => (string) $file['status'],
                'additions' => (int) ($file['additions'] ?? 0),
                'deletions' => (int) ($file['deletions'] ?? 0),
                // GitHub gives a binary file no patch, but counts no lines either.
                'binary' => $patch === '' && $changes === 0 && $file['status'] !== 'renamed',
                'patch' => Str::limit($patch, self::PATCH_CHARACTERS, ''),
                'truncated' => ($patch === '' && $changes > 0) || strlen($patch) > self::PATCH_CHARACTERS,
            ];
        }, $this->pages($project, "/repos/{$repository}/pulls/{$number}/files", 'Pull requests', maxPages: 3)));
    }

    /**
     * The checks and commit statuses on a commit, failures first.
     *
     * @return list<array{id: string, name: string, state: string, actions: bool, started_at: ?string, completed_at: ?string, url: ?string, summary: ?string}>
     *
     * @throws GitException
     */
    public function checks(Project $project, string $sha, bool $fresh = false): array
    {
        $repository = $this->repositoryOf($project);

        if ($fresh) {
            Cache::forget($this->cacheKey($project, "checks:{$sha}"));
        }

        return $this->cached($project, "checks:{$sha}", function () use ($project, $repository, $sha) {
            $runs = $this->check($this->api($project)->get(self::API."/repos/{$repository}/commits/{$sha}/check-runs", ['per_page' => 100]), 'Checks')->json('check_runs') ?? [];
            $statuses = $this->check($this->api($project)->get(self::API."/repos/{$repository}/commits/{$sha}/status"), 'Commit statuses')->json('statuses') ?? [];

            $checks = [
                ...array_map(fn (array $run) => [
                    'id' => 'run-'.$run['id'],
                    'name' => (string) $run['name'],
                    'state' => $run['status'] === 'completed' ? (string) ($run['conclusion'] ?? 'neutral') : (string) $run['status'],
                    // A GitHub Actions job: its check run's id is the job's, whose log can be read.
                    'actions' => ($run['app']['slug'] ?? null) === 'github-actions',
                    'started_at' => $run['started_at'] ?? null,
                    'completed_at' => $run['completed_at'] ?? null,
                    'url' => $run['html_url'] ?? $run['details_url'] ?? null,
                    'summary' => filled($run['output']['summary'] ?? null) ? Str::limit((string) $run['output']['summary'], 2000) : null,
                ], $runs),
                ...array_map(fn (array $status) => [
                    'id' => 'status-'.$status['id'],
                    'name' => (string) $status['context'],
                    'state' => match ($status['state']) {
                        'error' => 'failure',
                        default => (string) $status['state'],
                    },
                    'actions' => false,
                    'started_at' => $status['created_at'] ?? null,
                    'completed_at' => $status['state'] === 'pending' ? null : ($status['updated_at'] ?? null),
                    'url' => $status['target_url'] ?? null,
                    'summary' => $status['description'] ?? null,
                ], $statuses),
            ];

            usort($checks, fn (array $a, array $b) => [self::rank($a['state']), $a['name']] <=> [self::rank($b['state']), $b['name']]);

            return $checks;
        });
    }

    /**
     * The checks' state as a whole: failure when any failed, else pending while any runs, else success; null with none.
     *
     * @param  list<array{state: string}>  $checks
     */
    public static function summarize(array $checks): ?string
    {
        $states = array_column($checks, 'state');

        return match (true) {
            $states === [] => null,
            array_intersect($states, self::FAILED) !== [] => 'failure',
            array_diff($states, ['success', 'neutral', 'skipped']) !== [] => 'pending',
            default => 'success',
        };
    }

    /**
     * The failed checks among $checks.
     *
     * @param  list<array<string, mixed>>  $checks
     * @return list<array<string, mixed>>
     */
    public static function failed(array $checks): array
    {
        return array_values(array_filter($checks, fn (array $check) => in_array($check['state'], self::FAILED, true)));
    }

    /**
     * The end of a GitHub Actions check's log (its job's), without timestamps or colors; for another CI's check, its summary.
     *
     * @param  array<string, mixed>  $check
     */
    public function log(Project $project, array $check, int $characters = self::LOG_CHARACTERS): ?string
    {
        if (! $check['actions']) {
            return $check['summary'];
        }

        $repository = $this->repositoryOf($project);
        $job = Str::after((string) $check['id'], 'run-');

        return $this->cached($project, "log:{$job}:{$characters}", function () use ($project, $repository, $job, $characters) {
            // GitHub answers with a redirect to the log file, which the client follows without the token.
            $response = $this->api($project)->timeout(30)->get(self::API."/repos/{$repository}/actions/jobs/{$job}/logs");

            if (! $response->successful()) {
                return null;
            }

            $log = preg_replace(['/^\d{4}-\d\d-\d\dT[\d:.]+Z ?/m', '/\e\[[\d;]*[A-Za-z]/'], '', $response->body()) ?? '';

            return Str::substr(trim($log), -$characters);
        });
    }

    /**
     * Failures first, then running, then the rest.
     */
    protected static function rank(string $state): int
    {
        return match (true) {
            in_array($state, self::FAILED, true) => 0,
            in_array($state, ['queued', 'in_progress', 'pending', 'waiting', 'requested'], true) => 1,
            default => 2,
        };
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{kind: string, author: ?array{login: string, avatar_url: ?string}, body: string, state: ?string, path: ?string, line: ?int, created_at: ?string, url: ?string}
     */
    protected function entry(string $kind, array $item, ?string $at): array
    {
        return [
            'kind' => $kind,
            'author' => $this->person($item['user'] ?? null),
            'body' => (string) ($item['body'] ?? ''),
            'state' => null,
            'path' => null,
            'line' => null,
            'created_at' => $at,
            'url' => $item['html_url'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $user
     * @return array{login: string, avatar_url: ?string}|null
     */
    protected function person(?array $user, string $avatarKey = 'avatar_url'): ?array
    {
        return $user ? ['login' => (string) ($user['login'] ?? ''), 'avatar_url' => $user[$avatarKey] ?? null] : null;
    }

    /**
     * Every page of a list GitHub pages 100 at a time, up to $maxPages.
     *
     * @return list<array<string, mixed>>
     *
     * @throws GitException
     */
    protected function pages(Project $project, string $path, string $permission, int $maxPages = 3): array
    {
        $items = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $batch = $this->check($this->api($project)->get(self::API.$path, ['per_page' => 100, 'page' => $page]), $permission)->json() ?? [];
            array_push($items, ...$batch);

            if (count($batch) < 100) {
                break;
            }
        }

        return $items;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function cached(Project $project, string $key, Closure $callback): mixed
    {
        return Cache::remember($this->cacheKey($project, $key), self::CACHE_SECONDS, $callback);
    }

    protected function cacheKey(Project $project, string $key): string
    {
        // The repository is part of the key, so connecting another one never shows the last one's.
        return 'github-pulls:'.$project->id.':'.md5((string) self::repository($project)).':'.$key;
    }

    /**
     * @throws GitException
     */
    protected function repositoryOf(Project $project): string
    {
        return self::repository($project) ?? throw new GitException(__('Connect a GitHub repository in Tools → Git to see its pull requests.'));
    }

    /**
     * GitHub's API with the project's credentials: a fresh installation token for a GitHub App remote, else its stored token.
     *
     * @throws GitException
     */
    protected function api(Project $project): PendingRequest
    {
        $token = $project->github_installation_id !== null
            ? $this->github->installationToken($project->github_installation_id)
            : $project->git_remote_token;

        if (blank($token)) {
            throw new GitException(__('Connect a GitHub repository in Tools → Git to see its pull requests.'));
        }

        return Http::acceptJson()->withToken($token)->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])->timeout(20);
    }

    /**
     * @throws GitException
     */
    protected function check(Response $response, string $permission): Response
    {
        $message = (string) ($response->json('message') ?? $response->status());

        return match (true) {
            $response->successful() => $response,
            $response->status() === 401 => throw new GitException(__('GitHub didn\'t accept the repository\'s token. Connect it again in Tools → Git.')),
            in_array($response->status(), [403, 429], true) && Str::contains(Str::lower($message), 'rate limit') => throw new GitException(__('GitHub\'s rate limit was reached. Try again in a few minutes.')),
            $response->status() === 403 => throw new GitException(__('GitHub refused to show this: the app or token needs the :permission: Read permission. (:message)', ['permission' => $permission, 'message' => $message])),
            $response->status() === 404 => throw new GitException(__('GitHub couldn\'t find that pull request, or the app or token can\'t see it.')),
            default => throw new GitException(__('GitHub didn\'t answer: :message', ['message' => $message])),
        };
    }
}
