<?php

namespace App\Sandbox\Agents;

use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The models a user can pick for the agent, from the models.dev catalog OpenCode uses.
 * Falls back to the configured defaults and featured ids when the catalog is unreachable.
 */
class ModelCatalog
{
    public const CACHE_KEY = 'agent-model-catalog';

    /**
     * Tool-capable models for a provider: featured first (config order), then newest first.
     * Pass the user to leave out models their connection can't run (see includedWithChatGpt()).
     *
     * @return list<array{id: string, name: string, featured: bool, efforts: list<string>, context: int|null, cost: array{input: float, output: float}|null, released: string|null, vision: bool|null}>
     */
    public function models(AgentProvider $provider, ?User $user = null): array
    {
        $models = $this->allModels($provider);

        if (! $this->signedInWithChatGpt($provider, $user)) {
            return $models;
        }

        return array_values(array_filter($models, fn (array $model) => $this->includedWithChatGpt($model['id'])));
    }

    /**
     * @return list<array{id: string, name: string, featured: bool, efforts: list<string>, context: int|null, cost: array{input: float, output: float}|null, released: string|null, vision: bool|null}>
     */
    protected function allModels(AgentProvider $provider): array
    {
        $featured = config("sandbox.featured_models.{$provider->value}", []);
        $featuredKeys = array_map($this->matchKey(...), $featured);
        $raw = $this->catalog()[$provider->catalogId()]['models'] ?? null;

        if (! is_array($raw) || $raw === []) {
            $ids = array_values(array_unique([$this->defaultModel($provider), ...$featured]));

            return array_map(fn (string $id) => $this->entry($id, ['name' => $id], $featuredKeys), $ids);
        }

        $models = collect($raw)
            ->filter(fn ($model) => is_array($model) && ($model['tool_call'] ?? false) && ($model['status'] ?? null) !== 'deprecated')
            ->map(fn (array $model, string $id) => $this->entry((string) ($model['id'] ?? $id), $model, $featuredKeys))
            ->values();

        return $models
            ->sortBy(fn (array $model) => [
                $model['featured'] ? array_search($this->matchKey($model['id']), $featuredKeys, true) : PHP_INT_MAX,
                -strtotime($model['released'] ?? '1970-01-01'),
                $model['name'],
            ])
            ->values()
            ->all();
    }

    /**
     * A single model, or null if the catalog doesn't know it.
     *
     * @return array{id: string, name: string, featured: bool, efforts: list<string>, context: int|null, cost: array{input: float, output: float}|null, released: string|null, vision: bool|null}|null
     */
    public function find(AgentProvider $provider, string $id, ?User $user = null): ?array
    {
        return collect($this->models($provider, $user))->firstWhere('id', $id);
    }

    /**
     * Whether OpenCode lets a ChatGPT sign-in use this model: only the ones OpenAI includes with
     * Codex. Mirrors the filter in OpenCode's codex plugin (src/plugin/openai/codex.ts).
     */
    public function includedWithChatGpt(string $id): bool
    {
        if (in_array($id, ['gpt-5.5', 'gpt-5.3-codex-spark', 'gpt-5.4', 'gpt-5.4-mini'], true)) {
            return true;
        }

        if (in_array($id, ['gpt-5.5-pro', 'gpt-5.6'], true) || str_ends_with($id, '-pro')) {
            return false;
        }

        if (! preg_match('/^gpt-(\d+)(?:\.(\d+))?/', $id, $version)) {
            return false;
        }

        return (int) $version[1] > 5 || ((int) $version[1] === 5 && (int) ($version[2] ?? 0) > 4);
    }

    /**
     * Providers the user can actually run the agent with (OpenCode can't use Claude subscription tokens).
     *
     * @return list<AgentProvider>
     */
    public function usableProviders(User $user): array
    {
        return $user->agentConnections()
            ->where('credential_type', '!=', CredentialType::OAuthToken)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->map(fn ($connection) => $connection->provider)
            ->all();
    }

    /**
     * What the agent will run for a project: its saved choice, or the owner's default provider's default model.
     *
     * @return array{provider: AgentProvider, model: string, variant: string|null}|null
     */
    public function selectionFor(Project $project): ?array
    {
        $usable = $this->usableProviders($project->user);

        if ($project->agent_provider && in_array($project->agent_provider, $usable, true) && $project->agent_model
            && (! $this->signedInWithChatGpt($project->agent_provider, $project->user) || $this->includedWithChatGpt($project->agent_model))) {
            return ['provider' => $project->agent_provider, 'model' => $project->agent_model, 'variant' => $project->agent_variant];
        }

        return $this->defaultSelection($project->user);
    }

    /**
     * The user's default provider with its configured default model.
     *
     * @return array{provider: AgentProvider, model: string, variant: null}|null
     */
    public function defaultSelection(User $user): ?array
    {
        $provider = $this->usableProviders($user)[0] ?? null;

        return $provider ? ['provider' => $provider, 'model' => $this->defaultModel($provider, $user), 'variant' => null] : null;
    }

    /**
     * The configured default model for a provider, as a catalog id (e.g. "claude-sonnet-5"). For a
     * ChatGPT sign-in that can't run it, the first model it can.
     */
    public function defaultModel(AgentProvider $provider, ?User $user = null): string
    {
        $configured = Str::after((string) config("sandbox.models.{$provider->value}"), $provider->catalogId().'/');

        if (! $this->signedInWithChatGpt($provider, $user) || $this->includedWithChatGpt($configured)) {
            return $configured;
        }

        return $this->models($provider, $user)[0]['id'] ?? $configured;
    }

    /**
     * Whether the user connected this provider by signing in with ChatGPT.
     */
    protected function signedInWithChatGpt(AgentProvider $provider, ?User $user): bool
    {
        return $provider === AgentProvider::Codex
            && $user?->agentConnections()->where('provider', $provider)->where('credential_type', CredentialType::ChatGpt)->exists();
    }

    /**
     * A model that can look at images for this selection: the chosen one if it can (or might: the catalog
     * doesn't say), otherwise the provider's first one that can, featured first. Null when none can.
     *
     * @param  array{provider: AgentProvider, model: string, variant: string|null}  $selection
     * @return array{provider: AgentProvider, model: string, variant: string|null}|null
     */
    public function visionSelection(array $selection, ?User $user = null): ?array
    {
        if (($this->find($selection['provider'], $selection['model'], $user)['vision'] ?? null) !== false) {
            return $selection;
        }

        $model = collect($this->models($selection['provider'], $user))->firstWhere('vision', true);

        return $model ? ['provider' => $selection['provider'], 'model' => $model['id'], 'variant' => null] : null;
    }

    /**
     * The OpenCode "provider/model" id, e.g. "openrouter/anthropic/claude-opus-5".
     */
    public function opencodeId(AgentProvider $provider, string $model): string
    {
        return "{$provider->catalogId()}/{$model}";
    }

    /**
     * The picker's label for a selection.
     *
     * @param  array{provider: AgentProvider, model: string, variant: string|null}  $selection
     * @return array{provider: string, model: string, variant: string|null, name: string, efforts: list<string>}
     */
    public function describe(array $selection): array
    {
        $model = $this->find($selection['provider'], $selection['model']);

        return [
            'provider' => $selection['provider']->value,
            'model' => $selection['model'],
            'variant' => $selection['variant'],
            'name' => $model['name'] ?? $selection['model'],
            'efforts' => $model['efforts'] ?? [],
        ];
    }

    /**
     * The raw catalog, cached for a day (five minutes after a failed fetch).
     *
     * @return array<string, mixed>
     */
    protected function catalog(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $catalog = Http::timeout(10)->acceptJson()->get(config('sandbox.catalog_url'))->throw()->json();
        } catch (Throwable) {
            $catalog = null;
        }

        if (! is_array($catalog)) {
            Cache::put(self::CACHE_KEY, [], now()->addMinutes(5));

            return [];
        }

        // Keep only the providers we support; the full catalog is several MB.
        $catalog = array_intersect_key($catalog, array_flip(array_map(fn (AgentProvider $p) => $p->catalogId(), AgentProvider::cases())));
        Cache::put(self::CACHE_KEY, $catalog, now()->addDay());

        return $catalog;
    }

    /**
     * Catalogs spell versions differently ("claude-opus-5-5" vs "anthropic/claude-opus-5.5"),
     * so featured ids match either way.
     */
    protected function matchKey(string $id): string
    {
        return str_replace('.', '-', strtolower($id));
    }

    /**
     * @param  array<string, mixed>  $model
     * @param  list<string>  $featuredKeys
     * @return array{id: string, name: string, featured: bool, efforts: list<string>, context: int|null, cost: array{input: float, output: float}|null, released: string|null, vision: bool|null}
     */
    protected function entry(string $id, array $model, array $featuredKeys): array
    {
        $efforts = collect($model['reasoning_options'] ?? [])
            ->firstWhere('type', 'effort')['values'] ?? [];

        return [
            'id' => $id,
            'name' => (string) ($model['name'] ?? $id),
            'featured' => in_array($this->matchKey($id), $featuredKeys, true),
            'efforts' => array_values(array_filter($efforts, 'is_string')),
            'context' => isset($model['limit']['context']) ? (int) $model['limit']['context'] : null,
            'cost' => isset($model['cost']['input'], $model['cost']['output'])
                ? ['input' => (float) $model['cost']['input'], 'output' => (float) $model['cost']['output']]
                : null,
            'released' => $model['release_date'] ?? null,
            // Unknown when the catalog is unreachable.
            'vision' => isset($model['modalities']['input']) ? in_array('image', (array) $model['modalities']['input'], true) : null,
        ];
    }
}
