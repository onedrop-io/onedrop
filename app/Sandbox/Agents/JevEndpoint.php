<?php

namespace App\Sandbox\Agents;

use App\Models\AgentConnection;

/**
 * Where Jev's questions go: OpenRouter with a key, or Nimble (Bespoke Labs' decision model, same API) on the
 * user's own Ollama server (AI-007).
 */
final readonly class JevEndpoint
{
    public function __construct(
        public string $model,
        public ?string $openRouterKey = null,
        public ?AgentConnection $ollamaServer = null,
    ) {}

    public static function openRouter(string $key): self
    {
        return new self((string) config('services.openrouter.jev_model'), openRouterKey: $key);
    }

    public static function nimble(AgentConnection $ollamaServer, string $model): self
    {
        return new self($model, ollamaServer: $ollamaServer);
    }
}
