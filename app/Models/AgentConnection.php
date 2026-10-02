<?php

namespace App\Models;

use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use App\Sandbox\Agents\OllamaServer;
use Database\Factories\AgentConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's own credentials for an AI provider, injected into their sandboxes.
 *
 * @property int $id
 * @property int $user_id
 * @property AgentProvider $provider
 * @property CredentialType $credential_type
 * @property string $credential
 * @property string $hint
 * @property bool $is_default
 * @property Carbon|null $verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['provider', 'credential_type', 'credential', 'hint', 'is_default', 'verified_at'])]
#[Hidden(['credential'])]
class AgentConnection extends Model
{
    /** @use HasFactory<AgentConnectionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => AgentProvider::class,
            'credential_type' => CredentialType::class,
            'credential' => 'encrypted',
            'is_default' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * The last characters of a credential, safe to show in the UI.
     */
    public static function hintFor(string $credential): string
    {
        return substr($credential, -4);
    }

    /**
     * Environment variables that give an agent CLI in a sandbox this credential
     * (the names OpenCode, Claude Code and Codex read; the Codex agent gets a ChatGPT sign-in from codexAuth()).
     * A Claude subscription has none: Claude Code uses its own sign-in in the sandbox. An Ollama server
     * also gets the OpenCode config that points the agent at it.
     *
     * @return array<string, string>
     */
    public function sandboxEnvironment(): array
    {
        if ($this->credential_type === CredentialType::ChatGpt) {
            return ['OPENCODE_AUTH_CONTENT' => json_encode($this->opencodeAuth(), JSON_THROW_ON_ERROR)];
        }

        if ($this->credential_type === CredentialType::ClaudeLogin) {
            return [];
        }

        if ($this->credential_type === CredentialType::OllamaServer) {
            return app(OllamaServer::class)->sandboxEnvironment($this);
        }

        $variable = match ($this->provider) {
            AgentProvider::Claude => 'ANTHROPIC_API_KEY',
            AgentProvider::Codex => 'OPENAI_API_KEY',
            AgentProvider::OpenRouter, AgentProvider::Credits => 'OPENROUTER_API_KEY',
            AgentProvider::Gemini => 'GOOGLE_GENERATIVE_AI_API_KEY',
            AgentProvider::Ollama => 'OLLAMA_API_KEY',
        };

        return [$variable => $this->credential];
    }

    /**
     * The user's Ollama server: its base URL and key (empty when it has none).
     *
     * @return array{url: string, key: string}
     */
    public function ollamaServer(): array
    {
        $server = json_decode($this->credential, true);

        return ['url' => $server['url'] ?? '', 'key' => $server['key'] ?? ''];
    }

    /**
     * The stored ChatGPT sign-in tokens.
     *
     * @return array{access: string, refresh: string, expires: int, account_id: string|null, email: string|null, id_token?: string|null}
     */
    public function chatGptTokens(): array
    {
        return json_decode($this->credential, true);
    }

    /**
     * The Codex CLI's auth.json for this OpenAI connection: the API key, or the ChatGPT sign-in without its
     * refresh token (as for OpenCode, the platform refreshes it). Marked as just refreshed so Codex doesn't
     * try to refresh it itself. Codex reads the account from the ID token (see ChatGptAuth::ensureFresh()).
     *
     * @return array{OPENAI_API_KEY: string|null, tokens?: array{id_token: string, access_token: string, refresh_token: string, account_id: string|null}, last_refresh?: string}
     */
    public function codexAuth(): array
    {
        if ($this->credential_type !== CredentialType::ChatGpt) {
            return ['OPENAI_API_KEY' => $this->credential];
        }

        $tokens = $this->chatGptTokens();

        return [
            'OPENAI_API_KEY' => null,
            'tokens' => [
                'id_token' => (string) ($tokens['id_token'] ?? ''),
                'access_token' => $tokens['access'],
                'refresh_token' => '',
                'account_id' => $tokens['account_id'],
            ],
            'last_refresh' => now()->toIso8601ZuluString(),
        ];
    }

    /**
     * OpenCode's auth.json entry for a ChatGPT sign-in, without the refresh token:
     * the platform refreshes it (ChatGptAuth), so a sandbox can't rotate it away.
     *
     * @return array{openai: array{type: string, refresh: string, access: string, expires: int, accountId?: string}}
     */
    protected function opencodeAuth(): array
    {
        $tokens = $this->chatGptTokens();

        return ['openai' => array_filter([
            'type' => 'oauth',
            'refresh' => '',
            'access' => $tokens['access'],
            'expires' => $tokens['expires'] * 1000,
            'accountId' => $tokens['account_id'],
        ], fn ($value) => $value !== null)];
    }

    /**
     * The user who owns the connection.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
