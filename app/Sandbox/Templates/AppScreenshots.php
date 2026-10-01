<?php

namespace App\Sandbox\Templates;

use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\UriInterface;
use RuntimeException;
use Throwable;

/**
 * Pictures of a free app for its details (PRJ-012): the preview image from its website, then screenshots from the app
 * stores in config/sandbox.php `app_screenshots.galleries`. Only https images; the browser loads them, not us.
 *
 * @phpstan-import-type Template from TemplateRegistry
 *
 * @phpstan-type Screenshot array{url: string, source: string}
 */
class AppScreenshots
{
    /** Most screenshots taken from one app store. */
    protected const PER_GALLERY = 4;

    /** How much of a website's page is read looking for its preview image. */
    protected const PAGE_BYTES = 512 * 1024;

    /**
     * The website's preview image first, then the app stores' screenshots.
     *
     * @param  Template  $template
     * @return list<Screenshot>
     */
    public function for(array $template): array
    {
        $preview = $this->websitePreview($template);

        return [...($preview ? [$preview] : []), ...$this->storeScreenshots($template)];
    }

    /**
     * The one picture for an app in the free apps' coverflow (PRJ-012): a real screenshot from an app store when
     * there is one, since it shows the app itself, else the website's preview image.
     *
     * @param  Template  $template
     */
    public function cover(array $template): ?string
    {
        return ($this->storeScreenshots($template)[0] ?? $this->websitePreview($template))['url'] ?? null;
    }

    /**
     * @param  Template  $template
     * @return Screenshot|null
     */
    protected function websitePreview(array $template): ?array
    {
        $website = $template['links']['website'] ?? null;

        if (! config('sandbox.app_screenshots.website_previews') || ! is_string($website) || ! ($preview = $this->preview($website))) {
            return null;
        }

        return ['url' => $preview, 'source' => (string) preg_replace('/^www\./', '', (string) parse_url($website, PHP_URL_HOST))];
    }

    /**
     * @param  Template  $template
     * @return list<Screenshot>
     */
    protected function storeScreenshots(array $template): array
    {
        $screenshots = [];
        $names = array_unique(array_filter([
            self::normalize(str_contains($template['value'], '/') ? substr($template['value'], strpos($template['value'], '/') + 1) : $template['value']),
            self::normalize($template['label']),
        ]));

        foreach (config('sandbox.app_screenshots.galleries', []) as $key => $gallery) {
            if (! ($gallery['enabled'] ?? true)) {
                continue;
            }

            $folders = $this->gallery((string) $key, $gallery);

            foreach ($names as $name) {
                if (isset($folders[$name])) {
                    foreach (array_slice($folders[$name], 0, self::PER_GALLERY) as $path) {
                        $screenshots[] = [
                            'url' => rtrim((string) config('sandbox.app_screenshots.cdn_url'), '/')."/{$gallery['repository']}@{$gallery['branch']}/".implode('/', array_map('rawurlencode', explode('/', $path))),
                            'source' => (string) $gallery['name'],
                        ];
                    }

                    break;
                }
            }
        }

        return $screenshots;
    }

    /**
     * A website's preview image (og:image, else twitter:image), cached a week; a day when it has none or can't be read.
     */
    public function preview(string $website): ?string
    {
        $key = 'app-preview:'.md5($website);
        $cached = Cache::get($key);

        if (is_string($cached)) {
            return $cached === '' ? null : $cached;
        }

        try {
            $image = $this->previewImage($website);
        } catch (Throwable) {
            $image = null;
        }

        Cache::put($key, $image ?? '', $image ? now()->addWeek() : now()->addDay());

        return $image;
    }

    /**
     * A store's screenshots by app folder (normalized), from its repository's file list, cached a day (an hour after a
     * failed read, since GitHub allows few unsigned requests).
     *
     * @param  array{repository: string, branch: string, pattern: string}  $gallery
     * @return array<string, list<string>>
     */
    protected function gallery(string $key, array $gallery): array
    {
        $cacheKey = "app-gallery:{$key}:".md5($gallery['repository'].$gallery['branch'].$gallery['pattern']);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $tree = Http::timeout(10)->acceptJson()
                ->get(rtrim((string) config('sandbox.app_screenshots.github_url'), '/')."/repos/{$gallery['repository']}/git/trees/{$gallery['branch']}", ['recursive' => 1])
                ->throw()->json('tree');
        } catch (Throwable) {
            $tree = null;
        }

        if (! is_array($tree)) {
            Cache::put($cacheKey, [], now()->addHour());

            return [];
        }

        $folders = [];

        foreach ($tree as $entry) {
            $path = is_array($entry) && ($entry['type'] ?? null) === 'blob' && is_string($entry['path'] ?? null) ? $entry['path'] : null;

            if ($path !== null && preg_match($gallery['pattern'], $path, $match) === 1 && ($name = self::normalize($match[1])) !== '') {
                $folders[$name][] = $path;
            }
        }

        foreach ($folders as &$paths) {
            natsort($paths);
            $paths = array_values($paths);
        }

        Cache::put($cacheKey, $folders, now()->addDay());

        return $folders;
    }

    /**
     * @throws Throwable
     */
    protected function previewImage(string $website): ?string
    {
        $this->assertPublic($website);

        $response = Http::timeout(5)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; OneDrop app previews)', 'Accept' => 'text/html'])
            ->withOptions([
                'stream' => true,
                'allow_redirects' => [
                    'max' => 3,
                    'protocols' => ['https'],
                    'track_redirects' => true,
                    'on_redirect' => fn ($request, $response, UriInterface $uri) => $this->assertPublic((string) $uri),
                ],
            ])
            ->get($website)
            ->throw();

        // A stream hands back a chunk at a time, so read until the limit or the end.
        $body = $response->toPsrResponse()->getBody();
        $page = '';

        while (! $body->eof() && strlen($page) < self::PAGE_BYTES) {
            $page .= $body->read(self::PAGE_BYTES - strlen($page));
        }

        $body->close();
        $redirects = $response->header('X-Guzzle-Redirect-History');
        $base = $redirects !== '' ? (string) last(explode(', ', $redirects)) : $website;

        return $this->imageFrom($page, $base);
    }

    /**
     * The preview image a page names, as an absolute https URL.
     */
    protected function imageFrom(string $page, string $base): ?string
    {
        preg_match_all('/<meta\b[^>]*>/i', $page, $tags);
        $found = [];

        foreach ($tags[0] as $tag) {
            preg_match_all('/([a-z:_-]+)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', $tag, $attributes, PREG_SET_ORDER);
            $values = [];

            foreach ($attributes as [, $name, $value]) {
                $values[strtolower($name)] = html_entity_decode(trim($value, '"\''), ENT_QUOTES | ENT_HTML5);
            }

            $kind = strtolower($values['property'] ?? $values['name'] ?? '');

            if (in_array($kind, ['og:image', 'og:image:secure_url', 'og:image:url', 'twitter:image', 'twitter:image:src'], true) && ($values['content'] ?? '') !== '') {
                $found[$kind] ??= $values['content'];
            }
        }

        $image = $found['og:image:secure_url'] ?? $found['og:image'] ?? $found['og:image:url'] ?? $found['twitter:image'] ?? $found['twitter:image:src'] ?? null;

        if ($image === null) {
            return null;
        }

        $url = (string) UriResolver::resolve(Utils::uriFor($base), Utils::uriFor($image));

        return str_starts_with($url, 'https://') && strlen($url) <= 2000 ? $url : null;
    }

    /**
     * Websites come from a registry, so we only ask public https addresses for them.
     *
     * @throws RuntimeException
     */
    protected function assertPublic(string $url): void
    {
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');

        if (! str_starts_with($url, 'https://') || $host === '') {
            throw new RuntimeException('Not a public https URL.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);

        if ($addresses === []) {
            throw new RuntimeException("Couldn't find {$host}.");
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException("{$host} is on a private network.");
            }
        }
    }

    /**
     * @return list<string>
     */
    protected function resolve(string $host): array
    {
        return gethostbynamel($host) ?: [];
    }

    /**
     * An app's id, name or store folder as letters and digits, so "Cal.com", "calcom" and "Calcom" match.
     */
    protected static function normalize(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($name));
    }
}
