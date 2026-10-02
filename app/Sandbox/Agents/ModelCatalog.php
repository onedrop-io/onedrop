<?php

namespace App\Sandbox\Agents;

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use App\Models\AgentConnection;
use App\Models\Message;
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
        $server = $provider === AgentProvider::Ollama ? $this->ollamaServer($user) : null;

        if ($server) {
            return array_map(fn (string $id) => $this->entry($id, ['name' => $id, 'cost' => ['input' => 0, 'output' => 0]], []), app(OllamaServer::class)->models($server));
        }

        $models = $this->allModels($provider);

        // AI credits (CREDIT-001) only run the cheap models picked for them.
        if ($provider === AgentProvider::Credits) {
            return array_values(array_filter($models, fn (array $model) => $model['featured']));
        }

        if (! $this->signedInWithChatGpt($provider, $user)) {
            return $models;
        }

        return array_values(array_filter($models, fn (array $model) => $this->includedWithChatGpt($model['id'], $user)));
    }

    /**
     * @return list<array{id: string, name: string, featured: bool, efforts: list<string>, context: int|null, cost: array{input: float, output: float}|null, released: string|null, vision: bool|null}>
     */
    protected function allModels(AgentProvider $provider): array
    {
        $configured = config("sandbox.featured_models.{$provider->value}", []);
        $featured = is_array($configured) ? array_values(array_filter($configured, 'is_string')) : [];
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

        return array_values($models
            ->sortBy(fn (array $model) => [
                $model['featured'] ? array_search($this->matchKey($model['id']), $featuredKeys, true) : PHP_INT_MAX,
                -strtotime($model['released'] ?? '1970-01-01'),
                $model['name'],
            ])
            ->all());
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
     * Whether a ChatGPT sign-in can use this model: only the ones OpenAI includes with Codex. That depends
     * on the plan, so the user's account is asked (cached for an hour); when it can't be, this falls back
     * to the filter in OpenCode's codex plugin (src/plugin/openai/codex.ts).
     */
    public function includedWithChatGpt(string $id, ?User $user = null): bool
    {
        $account = $user ? $this->chatGptAccountModels($user) : null;

        if ($account !== null) {
            return in_array($id, $account, true);
        }

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
     * Providers the user can run with an agent: OpenCode can't use a Claude subscription, Claude Code
     * only runs Claude (with an API key or the user's Claude subscription), and Codex only runs OpenAI
     * (with an API key or the user's ChatGPT sign-in). Where the install offers AI credits (CREDIT-001), OpenCode
     * can also run on those, after the user's own connections.
     *
     * @return list<AgentProvider>
     */
    public function usableProviders(User $user, AgentHarness $harness = AgentHarness::OpenCode): array
    {
        if ($harness !== AgentHarness::OpenCode) {
            $provider = $harness === AgentHarness::ClaudeCode ? AgentProvider::Claude : AgentProvider::Codex;

            return $user->agentConnections()->where('provider', $provider)->exists() ? [$provider] : [];
        }

        $providers = array_values($user->agentConnections()
            ->where('credential_type', '!=', CredentialType::ClaudeLogin)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->map(fn (AgentConnection $connection) => $connection->provider)
            ->all());

        return app(AiCredits::class)->enabled() ? [...$providers, AgentProvider::Credits] : $providers;
    }

    /**
     * The agents the user's connections can run, OpenCode first (then Claude Code, then Codex).
     *
     * @return list<AgentHarness>
     */
    public function harnesses(User $user): array
    {
        return array_values(array_filter(AgentHarness::cases(), fn (AgentHarness $harness) => $this->usableProviders($user, $harness) !== []));
    }

    /**
     * The agent new projects start on: OpenCode, unless Claude Code is the only one the user can run.
     */
    public function defaultHarness(User $user): AgentHarness
    {
        return $this->harnesses($user)[0] ?? AgentHarness::OpenCode;
    }

    /**
     * The agent and model new projects start on: the user's last choice while they can still run it,
     * otherwise their AI subscription (Claude or ChatGPT), otherwise their default provider on the default agent.
     *
     * @return array{agent_harness: AgentHarness, agent_provider: AgentProvider, agent_model: string, agent_variant: string|null}|null
     */
    public function newProjectAgent(User $user): ?array
    {
        $preference = $user->agent_preference ?? [];
        $harness = AgentHarness::tryFrom($preference['harness'] ?? '');
        $provider = AgentProvider::tryFrom($preference['provider'] ?? '');
        $model = $preference['model'] ?? null;

        if ($harness && $provider && $model && in_array($provider, $this->usableProviders($user, $harness), true)
            && (! $this->signedInWithChatGpt($provider, $user) || $this->includedWithChatGpt($model, $user))) {
            return ['agent_harness' => $harness, 'agent_provider' => $provider, 'agent_model' => $model, 'agent_variant' => $preference['variant'] ?? null];
        }

        $subscription = $user->agentConnections()
            ->whereIn('credential_type', [CredentialType::ClaudeLogin, CredentialType::ChatGpt])
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if ($subscription) {
            return [
                'agent_harness' => $subscription->credential_type === CredentialType::ClaudeLogin ? AgentHarness::ClaudeCode : AgentHarness::OpenCode,
                'agent_provider' => $subscription->provider,
                'agent_model' => $this->defaultModel($subscription->provider, $user),
                'agent_variant' => null,
            ];
        }

        $harness = $this->defaultHarness($user);
        $selection = $this->defaultSelection($user, $harness);

        return $selection ? ['agent_harness' => $harness, 'agent_provider' => $selection['provider'], 'agent_model' => $selection['model'], 'agent_variant' => null] : null;
    }

    /**
     * The agent a project runs: its saved choice while the owner can still run it, otherwise their default.
     */
    public function harnessFor(Project $project): AgentHarness
    {
        return $project->agent_harness && in_array($project->agent_harness, $this->harnesses($project->user), true)
            ? $project->agent_harness
            : $this->defaultHarness($project->user);
    }

    /**
     * What the agent will run for a project: its saved choice, or the owner's default provider's default model.
     * For a $message Auto picked a model for (AGT-011), that model, while the owner can still run its provider.
     *
     * @return array{provider: AgentProvider, model: string, variant: string|null}|null
     */
    public function selectionFor(Project $project, ?Message $message = null): ?array
    {
        $harness = $this->harnessFor($project);
        $usable = $this->usableProviders($project->user, $harness);
        $picked = $message?->meta['selection'] ?? null;

        if (is_array($picked) && ($provider = AgentProvider::tryFrom($picked['provider'] ?? '')) && in_array($provider, $usable, true) && is_string($picked['model'] ?? null)) {
            return ['provider' => $provider, 'model' => $picked['model'], 'variant' => $picked['variant'] ?? null];
        }

        if ($project->agent_provider && in_array($project->agent_provider, $usable, true) && $project->agent_model
            && (! $this->signedInWithChatGpt($project->agent_provider, $project->user) || $this->includedWithChatGpt($project->agent_model, $project->user))) {
            return ['provider' => $project->agent_provider, 'model' => $project->agent_model, 'variant' => $project->agent_variant];
        }

        return $this->defaultSelection($project->user, $harness);
    }

    /**
     * The user's default provider with its configured default model.
     *
     * @return array{provider: AgentProvider, model: string, variant: null}|null
     */
    public function defaultSelection(User $user, ?AgentHarness $harness = null): ?array
    {
        $provider = $this->usableProviders($user, $harness ?? $this->defaultHarness($user))[0] ?? null;

        return $provider ? ['provider' => $provider, 'model' => $this->defaultModel($provider, $user), 'variant' => null] : null;
    }

    /**
     * The configured default model for a provider, as a catalog id (e.g. "claude-sonnet-5"). For a
     * ChatGPT sign-in that can't run it, the first model it can.
     */
    public function defaultModel(AgentProvider $provider, ?User $user = null): string
    {
        $configured = Str::after((string) config("sandbox.models.{$provider->value}"), $provider->catalogId().'/');

        if ($provider === AgentProvider::Ollama && $this->ollamaServer($user)) {
            $models = array_column($this->models($provider, $user), 'id');

            return in_array($configured, $models, true) ? $configured : ($models[0] ?? $configured);
        }

        if (! $this->signedInWithChatGpt($provider, $user) || $this->includedWithChatGpt($configured, $user)) {
            return $configured;
        }

        return $this->models($provider, $user)[0]['id'] ?? $configured;
    }

    /**
     * The models the user's ChatGPT account can use, or null when they didn't sign in with ChatGPT or
     * ChatGPT can't say right now (asked again after five minutes).
     *
     * @return list<string>|null
     */
    protected function chatGptAccountModels(User $user): ?array
    {
        $connection = $user->agentConnections()->where('provider', AgentProvider::Codex)->where('credential_type', CredentialType::ChatGpt)->first();

        if ($connection === null) {
            return null;
        }

        $key = "chatgpt-models:{$connection->id}";
        $cached = Cache::get($key);

        if (is_array($cached) && array_is_list($cached)) {
            return $cached !== [] ? $cached : null;
        }

        $models = app(ChatGptAuth::class)->accountModels($connection);
        Cache::put($key, $models ?? [], $models === null ? now()->addMinutes(5) : now()->addHour());

        return $models;
    }

    /**
     * The user's own Ollama server connection, if that's how they connected Ollama (AI-006).
     */
    protected function ollamaServer(?User $user): ?AgentConnection
    {
        return $user?->agentConnections()->where('provider', AgentProvider::Ollama)->where('credential_type', CredentialType::OllamaServer)->first();
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
     * @return array{harness: string, provider: string, model: string, variant: string|null, name: string, efforts: list<string>}
     */
    public function describe(array $selection, AgentHarness $harness = AgentHarness::OpenCode): array
    {
        $model = $this->find($selection['provider'], $selection['model']);

        return [
            'harness' => $harness->value,
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
        $options = $model['reasoning_options'] ?? [];
        $efforts = is_array($options) ? (collect($options)->firstWhere('type', 'effort')['values'] ?? []) : [];

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
