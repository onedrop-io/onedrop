<?php

namespace App\Sandbox;

use App\Enums\SocialProvider;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Public GitHub repositories matching what's typed in the new-project page's repository field (PRJ-009), so it
 * suggests repositories before (or without) connecting the GitHub App. "owner" lists that owner's repositories and
 * name matches; "owner/na" searches that owner's repositories by name. Only public, non-empty repositories.
 */
class GitHubRepositorySearch
{
    protected const API = 'https://api.github.com';

    protected const LIMIT = 8;

    /** Public data, the same for everyone, so shared between users to spare GitHub's rate limits. */
    protected const CACHE_SECONDS = 600;

    /**
     * @return list<array{full_name: string, private: false, html_url: string, pushed_at: ?string}>
     */
    public function search(User $user, string $query): array
    {
        $query = self::normalize($query);

        if ($query === null) {
            return [];
        }

        return Cache::remember('github-search:'.strtolower($query), self::CACHE_SECONDS, fn () => $this->fetch($user, $query));
    }

    /**
     * What's typed as "owner" or "owner/name-start", or null when it isn't a GitHub repository being typed.
     */
    public static function normalize(string $query): ?string
    {
        $query = trim($query);
        $query = (string) preg_replace('#^(https?://)?(www\.)?github\.com/#i', '', $query);
        $query = (string) preg_replace('#(\.git|/tree/.*)$#i', '', $query);
        $query = (string) preg_replace('#/+$#', '/', $query);

        return preg_match('#^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})(?:/[A-Za-z0-9._-]{0,100})?$#', $query) && strlen($query) >= 2 ? $query : null;
    }

    /**
     * @return list<array{full_name: string, private: false, html_url: string, pushed_at: ?string}>
     */
    protected function fetch(User $user, string $query): array
    {
        [$owner, $name] = array_pad(explode('/', $query, 2), 2, null);
        $token = $user->socialAccounts()->where('provider', SocialProvider::GitHub)->first()?->token;
        $token = is_string($token) && $token !== '' ? $token : null;
        $api = $this->authorize(Http::acceptJson(), $token);

        try {
            $responses = match (true) {
                $name === null => Http::pool(fn (Pool $pool) => [
                    $this->authorize($pool->as('owner'), $token)->get(self::API."/users/{$owner}/repos", ['sort' => 'pushed', 'per_page' => self::LIMIT]),
                    $this->authorize($pool->as('search'), $token)->get(self::API.'/search/repositories', ['q' => "{$owner} in:name", 'per_page' => self::LIMIT]),
                ]),
                $name === '' => ['owner' => $api->get(self::API."/users/{$owner}/repos", ['sort' => 'pushed', 'per_page' => self::LIMIT * 2])],
                default => ['search' => $api->get(self::API.'/search/repositories', ['q' => "{$name} in:name user:{$owner}", 'per_page' => self::LIMIT])],
            };
        } catch (Throwable) {
            return [];
        }

        $repositories = [
            ...$this->items($responses['owner'] ?? null, fn (Response $response) => $response->json()),
            ...$this->items($responses['search'] ?? null, fn (Response $response) => $response->json('items')),
        ];

        $seen = [];
        $results = [];

        foreach ($repositories as $repository) {
            $fullName = $repository['full_name'] ?? null;

            if (! is_string($fullName) || isset($seen[strtolower($fullName)]) || ($repository['private'] ?? true) || ($repository['size'] ?? 1) === 0) {
                continue;
            }

            $seen[strtolower($fullName)] = true;
            $results[] = [
                'full_name' => $fullName,
                'private' => false,
                'html_url' => (string) ($repository['html_url'] ?? "https://github.com/{$fullName}"),
                'pushed_at' => is_string($repository['pushed_at'] ?? null) ? $repository['pushed_at'] : null,
            ];
        }

        return array_slice($results, 0, self::LIMIT);
    }

    /**
     * A successful response's repositories; nothing for a missing owner, a rate limit or a failed request.
     *
     * @param  callable(Response): mixed  $items
     * @return list<array<string, mixed>>
     */
    protected function items(mixed $response, callable $items): array
    {
        if (! $response instanceof Response || ! $response->successful()) {
            return [];
        }

        $list = $items($response);

        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    /**
     * With the user's own GitHub sign-in token when they have one (GitHub allows far more searches with it);
     * public repositories need none.
     */
    protected function authorize(PendingRequest $request, ?string $token): PendingRequest
    {
        $request->acceptJson()->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])->timeout(10);

        return $token === null ? $request : $request->withToken($token);
    }
}
