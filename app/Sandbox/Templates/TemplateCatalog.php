<?php

namespace App\Sandbox\Templates;

use App\Enums\AppTemplate;

/**
 * Every template the new-project page offers (PRJ-004, PRJ-012) as one list: the built-in ones, then each registry's
 * in config/sandbox.php `template_registries`. A registry's templates are valued "<registry>/<id>".
 *
 * @phpstan-import-type Template from TemplateRegistry
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
            'link' => null,
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
        return config('sandbox.provider') === 'docker'
            && in_array(config('sandbox.providers.docker.nested_docker'), ['privileged', 'runtime'], true);
    }
}
