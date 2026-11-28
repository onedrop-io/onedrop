<?php

namespace App\Sandbox;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The public skills directory at skills.sh (SKILL-005): searching it and fetching a skill's files.
 * Uses the same endpoints as the `skills` CLI (`npx skills find`), which aren't documented, so everything they send
 * back is checked here.
 */
class SkillsDirectory
{
    public const MIN_QUERY = 2;

    protected const MAX_RESULTS = 50;

    protected const SEGMENT = '[A-Za-z0-9._-]{1,100}';

    /**
     * Skills matching the query, most relevant first. Only ones from GitHub repositories (`owner/repo`).
     *
     * @return list<array{id: string, name: string, source: string, installs: int, url: string}>
     *
     * @throws SkillException
     */
    public function search(string $query): array
    {
        $query = mb_substr(trim($query), 0, 100);

        if (mb_strlen($query) < self::MIN_QUERY) {
            return [];
        }

        return Cache::remember('skills-directory:search:'.sha1(mb_strtolower($query)), now()->addMinutes(10), function () use ($query) {
            $skills = $this->get('/api/search', ['q' => $query])['skills'] ?? [];
            $results = [];

            foreach (is_array($skills) ? $skills : [] as $skill) {
                $source = (string) ($skill['source'] ?? '');
                $slug = (string) ($skill['skillId'] ?? '');
                $name = (string) ($skill['name'] ?? $slug);

                if (! self::isGitHubSource($source) || ! preg_match('~^'.self::SEGMENT.'$~', $slug) || $slug === '..') {
                    continue;
                }

                $id = "{$source}/{$slug}";
                $results[$id] = [
                    'id' => $id,
                    'name' => mb_substr(preg_replace('/[^\pL\pN._ -]/u', '', $name) ?: $slug, 0, SkillDocument::MAX_NAME),
                    'source' => $source,
                    'installs' => max(0, (int) ($skill['installs'] ?? 0)),
                    'url' => $this->pageUrl($id),
                ];
            }

            return array_slice(array_values($results), 0, self::MAX_RESULTS);
        });
    }

    /**
     * One skill's files, checked like an upload, with its skills.sh page.
     *
     * @return array{name: string, description: string, content: string, files: list<array{path: string, data: string}>, url: string}
     *
     * @throws SkillException
     */
    public function fetch(string $id): array
    {
        [$owner, $repo, $slug] = self::parseId($id);

        $files = Cache::remember("skills-directory:download:{$owner}/{$repo}/{$slug}", now()->addHour(), function () use ($owner, $repo, $slug) {
            $files = [];

            foreach ($this->get('/api/download/'.rawurlencode($owner).'/'.rawurlencode($repo).'/'.rawurlencode($slug))['files'] ?? [] as $file) {
                if (is_array($file) && is_string($file['path'] ?? null) && is_string($file['contents'] ?? null)) {
                    $files[$file['path']] = $file['contents'];
                }
            }

            return $files;
        });

        if ($files === []) {
            throw new SkillException(__('skills.sh didn\'t send any files for that skill.'));
        }

        return [...SkillPackage::fromFiles($files), 'url' => $this->pageUrl("{$owner}/{$repo}/{$slug}")];
    }

    /**
     * The owner, repository and skill in a result's id (`owner/repo/skill`).
     *
     * @return array{string, string, string}
     *
     * @throws SkillException
     */
    public static function parseId(string $id): array
    {
        if (! preg_match('~^('.self::SEGMENT.')/('.self::SEGMENT.')/('.self::SEGMENT.')$~', $id, $match) || in_array('..', [$match[1], $match[2], $match[3]], true)) {
            throw new SkillException(__('That isn\'t a skill on skills.sh.'));
        }

        return [$match[1], $match[2], $match[3]];
    }

    protected static function isGitHubSource(string $source): bool
    {
        return (bool) preg_match('~^[A-Za-z0-9-]{1,39}/[A-Za-z0-9._-]{1,100}$~', $source) && ! str_contains($source, '..');
    }

    protected function pageUrl(string $id): string
    {
        return $this->base().'/'.$id;
    }

    protected function base(): string
    {
        return rtrim((string) config('services.skills_sh.url', 'https://skills.sh'), '/');
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     *
     * @throws SkillException
     */
    protected function get(string $path, array $query = []): array
    {
        try {
            $response = Http::acceptJson()->timeout(15)->get($this->base().$path, $query);
        } catch (ConnectionException) {
            throw new SkillException(__('Couldn\'t reach skills.sh. Try again in a moment.'));
        }

        if ($response->status() === 404) {
            throw new SkillException(__('skills.sh doesn\'t have that skill anymore.'));
        }

        if (! $response->successful() || ! is_array($response->json())) {
            throw new SkillException(__('skills.sh couldn\'t answer (:status). Try again in a moment.', ['status' => $response->status()]));
        }

        return $response->json();
    }
}
