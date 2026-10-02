<?php

namespace App\Sandbox\Templates;

use App\Enums\AppTemplate;
use App\Sandbox\SandboxProviders;

/**
 * Every template the new-project page offers (PRJ-004, PRJ-012) as one list: the built-in ones, then each registry's
 * in config/sandbox.php `template_registries`. A registry's templates are valued "<registry>/<id>".
 *
 * @phpstan-import-type Template from TemplateRegistry
 *
 * @phpstan-type FeaturedTemplate array{value: string, label: string, description: string, prompt: string, registry: ?string, logo: ?string, tags: list<string>, compose: bool, version: ?string, links: array{website: ?string, github: ?string, docs: ?string}, cover: string}
 */
class TemplateCatalog
{
    /**
     * The registries that are on (they have a url) in a format we read.
     *
     * @return list<TemplateRegistry>
     */
    public function registries(): array
    {
        $registries = [];

        foreach (config('sandbox.template_registries', []) as $key => $registry) {
            $url = rtrim((string) ($registry['url'] ?? ''), '/');

            if ($url === '') {
                continue;
            }

            $registries[] = match ($registry['format'] ?? null) {
                'dokploy' => new DokployRegistry((string) $key, (string) ($registry['name'] ?? $key), $url, array_values(array_filter($registry['popular'] ?? [], 'is_string'))),
                default => null,
            };
        }

        return array_values(array_filter($registries));
    }

    /**
     * The built-in templates, then every registry's.
     *
     * @return list<Template>
     */
    public function all(): array
    {
        $templates = array_map(fn (array $template) => [
            ...$template,
            'registry' => null,
            'logo' => null,
            'tags' => ['business'],
            'compose' => false,
            'version' => null,
            'links' => ['website' => null, 'github' => null, 'docs' => null],
        ], AppTemplate::options());

        foreach ($this->registries() as $registry) {
            array_push($templates, ...$registry->templates());
        }

        return $templates;
    }

    /**
     * Every registry's popular templates, shown under the prompt (PRJ-012).
     *
     * @return list<Template>
     */
    public function popular(): array
    {
        $templates = [];

        foreach ($this->registries() as $registry) {
            array_push($templates, ...$registry->popular());
        }

        return $templates;
    }

    /**
     * Every registry's templates, the free apps under the prompt (PRJ-012): the popular ones first, then the rest.
     *
     * @return list<Template>
     */
    public function apps(): array
    {
        $popular = $this->popular();
        $first = array_column($popular, 'value');
        $rest = [];

        foreach ($this->registries() as $registry) {
            foreach ($registry->templates() as $template) {
                if (! in_array($template['value'], $first, true)) {
                    $rest[] = $template;
                }
            }
        }

        return [...$popular, ...$rest];
    }

    /**
     * The popular free apps that have a picture, each with it as `cover`, for the coverflow (PRJ-012).
     *
     * @return list<FeaturedTemplate>
     */
    public function featured(): array
    {
        $screenshots = app(AppScreenshots::class);
        $featured = [];

        foreach ($this->popular() as $template) {
            if ($cover = $screenshots->cover($template)) {
                $featured[] = [...$template, 'cover' => $cover];
            }
        }

        return $featured;
    }

    /**
     * A template by its value, built-in or from a registry.
     *
     * @return Template|null
     */
    public function find(string $value): ?array
    {
        if (AppTemplate::tryFrom($value)) {
            return collect($this->all())->firstWhere('value', $value);
        }

        $registry = $this->registryFor($value);

        return $registry ? collect($registry->templates())->firstWhere('value', $value) : null;
    }

    /**
     * The registry a template's value points at, if it's from one.
     */
    public function registryFor(string $value): ?TemplateRegistry
    {
        $key = str_contains($value, '/') ? strstr($value, '/', true) : null;

        return collect($this->registries())->first(fn (TemplateRegistry $registry) => $registry->key() === $key);
    }

    /**
     * Whether new projects' sandboxes can run a registry template's Docker Compose stack (SBX-008).
     */
    public function canRunCompose(): bool
    {
        return app(SandboxProviders::class)->runsDocker();
    }
}
