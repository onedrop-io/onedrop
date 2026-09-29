<?php

namespace App\Models;

use App\Enums\AgentProvider;
use App\Enums\CredentialType;
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
     * (the names OpenCode, Claude Code and Codex read; a ChatGPT sign-in is OpenCode-only).
     *
     * @return array<string, string>
     */
    public function sandboxEnvironment(): array
    {
        if ($this->credential_type === CredentialType::ChatGpt) {
            return ['OPENCODE_AUTH_CONTENT' => json_encode($this->opencodeAuth(), JSON_THROW_ON_ERROR)];
        }

        $variable = match ($this->provider) {
            AgentProvider::Claude => $this->credential_type === CredentialType::OAuthToken
                ? 'CLAUDE_CODE_OAUTH_TOKEN'
                : 'ANTHROPIC_API_KEY',
            AgentProvider::Codex => 'OPENAI_API_KEY',
            AgentProvider::OpenRouter => 'OPENROUTER_API_KEY',
            AgentProvider::Gemini => 'GOOGLE_GENERATIVE_AI_API_KEY',
        };

        return [$variable => $this->credential];
    }

    /**
     * The stored ChatGPT sign-in tokens.
     *
     * @return array{access: string, refresh: string, expires: int, account_id: string|null, email: string|null}
     */
    public function chatGptTokens(): array
    {
        return json_decode($this->credential, true);
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
