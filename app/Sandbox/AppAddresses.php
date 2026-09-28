<?php

namespace App\Sandbox;

use App\Models\Project;

/**
 * Where a project's app can be reached from a browser: its preview (directly, or through the
 * gateway on servers) and its published address.
 */
class AppAddresses
{
    public function __construct(protected Gateway $gateway) {}

    /**
     * The preview's origin, e.g. "http://127.0.0.1:49152" or "https://preview-4.apps.example.com".
     */
    public function previewOrigin(Project $project): ?string
    {
        $sandbox = $project->sandbox;

        return $sandbox ? ($this->gateway->url($sandbox, 'preview') ?? self::origin($sandbox->preview_url)) : null;
    }

    public function publishedOrigin(Project $project): ?string
    {
        return self::origin($project->published_url);
    }

    /**
     * A link that opens $path in the preview. On servers it goes through the gateway's sign-in hand-off.
     */
    public function previewLink(Project $project, string $path): ?string
    {
        if ($this->gateway->enabled() && $project->sandbox) {
            return route('projects.gateway.open', [$project, 'preview', 'path' => $path]);
        }

        $origin = $this->previewOrigin($project);

        return $origin ? $origin.$path : null;
    }

    /**
     * $path at the preview and published addresses, labelled.
     *
     * @return list<array{label: string, url: string}>
     */
    public function everywhere(Project $project, string $path): array
    {
        return array_values(array_filter([
            ($origin = $this->previewOrigin($project)) ? ['label' => __('Preview'), 'url' => $origin.$path] : null,
            ($origin = $this->publishedOrigin($project)) ? ['label' => __('Published'), 'url' => $origin.$path] : null,
        ]));
    }

    public static function origin(?string $url): ?string
    {
        $parts = $url ? parse_url($url) : null;

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return "{$parts['scheme']}://{$parts['host']}".(isset($parts['port']) ? ":{$parts['port']}" : '');
    }
}
