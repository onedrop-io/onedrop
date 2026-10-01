<?php

namespace App\Sandbox\Templates;

/**
 * A registry of ready-made apps listed beside the built-in templates on the new-project page (PRJ-012), such as
 * Dokploy's. Each format (how a registry lays out its index and files) is one implementation.
 *
 * @phpstan-type Template array{value: string, label: string, description: string, prompt: string, registry: ?string, logo: ?string, tags: list<string>, compose: bool, version: ?string, links: array{website: ?string, github: ?string, docs: ?string}}
 */
interface TemplateRegistry
{
    /**
     * The registry's key in config, the first part of its templates' values ("dokploy/n8n").
     */
    public function key(): string;

    /**
     * Its name, shown on each of its templates.
     */
    public function name(): string;

    /**
     * Every template it lists, or none when it can't be reached.
     *
     * @return list<Template>
     */
    public function templates(): array;

    /**
     * Its popular templates, in order, shown under the prompt.
     *
     * @return list<Template>
     */
    public function popular(): array;

    /**
     * The files a new project starts with, by path in the workspace.
     *
     * @return array<string, string>
     *
     * @throws TemplateException
     */
    public function files(string $id): array;

    /**
     * What the agent's first run is told about setting the template up, beside the user's prompt.
     *
     * @param  Template  $template
     */
    public function agentContext(array $template): string;
}
