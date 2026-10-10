<?php

namespace App\Sandbox;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Fetches a skill folder from a GitHub link (SKILL-002): a folder (`/tree/<ref>/<path>`), its SKILL.md
 * (`/blob/<ref>/<path>/SKILL.md`), or a repository with one skill. Uses the user's GitHub sign-in when they have one,
 * so private repositories work.
 */
class GitHubSkillImporter
{
    protected const API = 'https://api.github.com';

    public function __construct(protected GitHubApp $github) {}

    /**
     * @return array{name: string, description: string, content: string, files: list<array{path: string, data: string}>, url: string}
     *
     * @throws SkillException
     */
    public function import(User $user, string $url): array
    {
        [$owner, $repo, $ref, $path] = $this->parse($url);
        $http = $this->client($user);

        try {
            $ref ??= (string) $this->get($http, "/repos/{$owner}/{$repo}")->json('default_branch');
            $tree = $this->get($http, "/repos/{$owner}/{$repo}/git/trees/".rawurlencode($ref), ['recursive' => 1])->json('tree') ?? [];

            $blobs = [];

            foreach ($tree as $entry) {
                $entryPath = (string) ($entry['path'] ?? '');

                if (($entry['type'] ?? null) === 'blob' && ($path === '' || str_starts_with($entryPath, $path.'/'))) {
                    $blobs[$path === '' ? $entryPath : substr($entryPath, strlen($path) + 1)] = (int) ($entry['size'] ?? 0);
                }
            }

            if ($blobs === []) {
                throw new SkillException(__('There\'s nothing at that link. Check it, and that you can open the repository.'));
            }

            $root = SkillPackage::root(array_keys($blobs));
            $inside = array_filter($blobs, fn (string $file) => $root === '' || str_starts_with($file, $root.'/'), ARRAY_FILTER_USE_KEY);

            if (count($inside) > SkillPackage::MAX_FILES || array_sum($inside) > SkillPackage::MAX_BYTES) {
                throw new SkillException(__('The skill is too big: skills can have up to :files files and 1 MB.', ['files' => SkillPackage::MAX_FILES]));
            }

            $files = [];
            $folder = trim($path.'/'.$root, '/');

            foreach (array_keys($inside) as $file) {
                $relative = $root === '' ? $file : substr($file, strlen($root) + 1);
                $files[$relative] = $this->get($http, "/repos/{$owner}/{$repo}/contents/".implode('/', array_map('rawurlencode', explode('/', trim("{$folder}/{$relative}", '/')))), ['ref' => $ref], raw: true)->body();
            }
        } catch (ConnectionException) {
            throw new SkillException(__('Couldn\'t reach GitHub. Try again in a moment.'));
        }

        return [...SkillPackage::fromFiles($files), 'url' => "https://github.com/{$owner}/{$repo}/tree/{$ref}".($folder === '' ? '' : "/{$folder}")];
    }

    /**
     * The owner, repository, ref (null for the default branch) and folder in a GitHub link.
     *
     * @return array{string, string, string|null, string}
     *
     * @throws SkillException
     */
    protected function parse(string $url): array
    {
        $pattern = '~^(?:https?://)?(?:www\.)?github\.com/([A-Za-z0-9-]+)/([A-Za-z0-9._-]+?)(?:\.git)?(?:/(tree|blob)/([^/]+)(?:/(.*?))?)?/?(?:[?#].*)?$~';

        if (! preg_match($pattern, trim($url), $match)) {
            throw new SkillException(__('That isn\'t a GitHub link. Paste a link like https://github.com/owner/repo/tree/main/skills/my-skill.'));
        }

        $path = trim(rawurldecode($match[5] ?? ''), '/');

        if (($match[3] ?? '') === 'blob' || basename($path) === 'SKILL.md') {
            $path = dirname($path) === '.' ? '' : dirname($path);
        }

        if ($path !== '' && ! WorkspaceFiles::isSafePath($path)) {
            throw new SkillException(__('That isn\'t a GitHub link. Paste a link like https://github.com/owner/repo/tree/main/skills/my-skill.'));
        }

        return [$match[1], $match[2], ($match[4] ?? '') !== '' ? rawurldecode($match[4]) : null, $path];
    }

    protected function client(User $user): PendingRequest
    {
        $token = $this->github->configured() ? $this->github->userToken($user) : null;

        return Http::withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->when($token, fn (PendingRequest $http) => $http->withToken($token))
            ->timeout(20);
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws SkillException|ConnectionException
     */
    protected function get(PendingRequest $http, string $path, array $query = [], bool $raw = false): Response
    {
        $response = $http->accept($raw ? 'application/vnd.github.raw' : 'application/vnd.github+json')->get(self::API.$path, $query);

        return match (true) {
            $response->successful() => $response,
            $response->status() === 404 => throw new SkillException(__('There\'s nothing at that link. Check it, and that you can open the repository (connect GitHub in Source Control for private ones).')),
            $response->status() === 403 || $response->status() === 429 => throw new SkillException(__('GitHub is limiting requests right now. Try again in a few minutes, or connect GitHub in Source Control.')),
            default => throw new SkillException(__('GitHub couldn\'t send the skill (:status).', ['status' => $response->status()])),
        };
    }
}
