<?php

namespace App\Sandbox\Agents;

use App\Models\AgentConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * "Sign in with ChatGPT" through OpenAI's device-code flow, the one `codex login --device-auth`
 * and OpenCode's headless ChatGPT login use. The platform keeps the refresh token and refreshes
 * it here; sandboxes only ever get the access token (see AgentConnection::sandboxEnvironment()).
 */
class ChatGptAuth
{
    public const ISSUER = 'https://auth.openai.com';

    /** OpenAI's public client id for Codex, shared by the Codex CLI and OpenCode. */
    public const CLIENT_ID = 'app_EMoamEEZ73f0CkXaXp7hrann';

    /** The Codex CLI version the sandbox image pins; ChatGPT lists the models that version can run. */
    public const CODEX_VERSION = '0.159.2';

    /** Refresh when the access token has less than this left, so it outlives a long agent run. */
    public const REFRESH_MARGIN_SECONDS = 6 * 3600;

    /**
     * Ask OpenAI for a one-time code the user enters on its sign-in page.
     *
     * @return array{device_auth_id: string, user_code: string, verification_url: string, interval: int}
     *
     * @throws ChatGptSignInFailed
     */
    public function requestDeviceCode(): array
    {
        $response = $this->send(fn () => Http::asJson()->post(self::ISSUER.'/api/accounts/deviceauth/usercode', [
            'client_id' => self::CLIENT_ID,
        ]));

        $userCode = $response->json('user_code') ?? $response->json('usercode');

        if ($response->failed() || ! $response->json('device_auth_id') || ! $userCode) {
            throw new ChatGptSignInFailed(__("Couldn't start ChatGPT sign-in. Try again, or paste an OpenAI API key instead."));
        }

        return [
            'device_auth_id' => (string) $response->json('device_auth_id'),
            'user_code' => (string) $userCode,
            'verification_url' => self::ISSUER.'/codex/device',
            'interval' => max((int) $response->json('interval', 5), 1),
        ];
    }

    /**
     * Check whether the user has approved the code. Null while they haven't yet.
     *
     * @return array{access: string, refresh: string, expires: int, account_id: string|null, email: string|null, id_token: string|null}|null
     *
     * @throws ChatGptSignInFailed when OpenAI denies or fails the sign-in
     */
    public function poll(string $deviceAuthId, string $userCode): ?array
    {
        $response = $this->send(fn () => Http::asJson()->post(self::ISSUER.'/api/accounts/deviceauth/token', [
            'device_auth_id' => $deviceAuthId,
            'user_code' => $userCode,
        ]));

        // OpenAI answers 403/404 until the user approves the code.
        if ($response->forbidden() || $response->notFound()) {
            return null;
        }

        if ($response->failed() || ! $response->json('authorization_code') || ! $response->json('code_verifier')) {
            throw new ChatGptSignInFailed(__('ChatGPT sign-in failed. Try again.'));
        }

        $tokens = $this->send(fn () => Http::asForm()->post(self::ISSUER.'/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $response->json('authorization_code'),
            'redirect_uri' => self::ISSUER.'/deviceauth/callback',
            'client_id' => self::CLIENT_ID,
            'code_verifier' => $response->json('code_verifier'),
        ]));

        if ($tokens->failed() || ! $tokens->json('access_token') || ! $tokens->json('refresh_token')) {
            throw new ChatGptSignInFailed(__('ChatGPT sign-in failed. Try again.'));
        }

        return $this->bundle($tokens->json());
    }

    /**
     * Make sure the connection's access token will last the next agent run, refreshing it if not. The Codex
     * agent also needs the sign-in's ID token, which connections made before it was kept don't have yet.
     *
     * @throws ChatGptSignInFailed when the sign-in can't be refreshed
     */
    public function ensureFresh(AgentConnection $connection, bool $needsIdToken = false): AgentConnection
    {
        if (! $this->needsRefresh($connection, $needsIdToken)) {
            return $connection;
        }

        // Refresh tokens are single-use: two runs refreshing at once would sign the user out.
        return Cache::lock("chatgpt-refresh:{$connection->id}", 30)->block(20, function () use ($connection, $needsIdToken) {
            $connection->refresh();

            if (! $this->needsRefresh($connection, $needsIdToken)) {
                return $connection;
            }

            $current = $connection->chatGptTokens();
            $response = $this->send(fn () => Http::asForm()->post(self::ISSUER.'/oauth/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $current['refresh'],
                'client_id' => self::CLIENT_ID,
                'scope' => 'openid profile email',
            ]), __("Couldn't reach OpenAI to refresh your ChatGPT sign-in. Try again in a moment."));

            if ($response->clientError()) {
                $connection->update(['verified_at' => null]);

                throw new ChatGptSignInFailed(__('Your ChatGPT sign-in has expired. Sign in with ChatGPT again in Settings → AI.'));
            }

            if ($response->failed() || ! $response->json('access_token')) {
                throw new ChatGptSignInFailed(__('OpenAI returned an error while refreshing your ChatGPT sign-in. Try again in a moment.'));
            }

            $tokens = $this->bundle([
                'refresh_token' => $current['refresh'],
                ...array_filter($response->json()),
            ]);

            $connection->update([
                'credential' => json_encode([
                    ...$tokens,
                    'account_id' => $tokens['account_id'] ?? $current['account_id'],
                    'email' => $tokens['email'] ?? $current['email'],
                    'id_token' => $tokens['id_token'] ?? $current['id_token'] ?? null,
                ]),
                'verified_at' => now(),
            ]);

            return $connection;
        });
    }

    /**
     * The models the signed-in ChatGPT account can use with Codex (they differ by plan), as ChatGPT lists
     * them to the Codex CLI. Null when ChatGPT can't be asked, e.g. the access token has expired.
     *
     * @return list<string>|null
     */
    public function accountModels(AgentConnection $connection): ?array
    {
        $tokens = $connection->chatGptTokens();

        try {
            $response = Http::timeout(10)
                ->withToken($tokens['access'])
                ->withHeaders(array_filter(['ChatGPT-Account-ID' => $tokens['account_id'], 'originator' => 'codex_cli_rs']))
                ->get(config('sandbox.chatgpt_models_url'), ['client_version' => self::CODEX_VERSION]);
        } catch (ConnectionException) {
            return null;
        }

        $listed = $response->successful() ? $response->json('models') : null;
        $models = [];

        foreach (is_array($listed) ? $listed : [] as $model) {
            if (is_array($model) && is_string($model['slug'] ?? null) && ($model['visibility'] ?? 'list') === 'list') {
                $models[] = $model['slug'];
            }
        }

        return $models !== [] ? $models : null;
    }

    /**
     * The stored form of a token response.
     *
     * @param  array<string, mixed>  $tokens
     * @return array{access: string, refresh: string, expires: int, account_id: string|null, email: string|null, id_token: string|null}
     */
    protected function bundle(array $tokens): array
    {
        $claims = $this->claims((string) ($tokens['id_token'] ?? '')) ?: $this->claims((string) $tokens['access_token']);

        return [
            'access' => (string) $tokens['access_token'],
            'refresh' => (string) $tokens['refresh_token'],
            'expires' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600))->getTimestamp(),
            'account_id' => $claims['chatgpt_account_id']
                ?? $claims['https://api.openai.com/auth']['chatgpt_account_id']
                ?? $claims['organizations'][0]['id']
                ?? null,
            'email' => $claims['email'] ?? $claims['https://api.openai.com/profile']['email'] ?? null,
            'id_token' => isset($tokens['id_token']) && $tokens['id_token'] !== '' ? (string) $tokens['id_token'] : null,
        ];
    }

    /**
     * The (unverified) claims of a JWT; we only read our own account's id and email from it.
     *
     * @return array<string, mixed>
     */
    protected function claims(string $jwt): array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            return [];
        }

        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);

        return is_array($claims) ? $claims : [];
    }

    protected function needsRefresh(AgentConnection $connection, bool $needsIdToken): bool
    {
        return $this->expiresSoon($connection) || ($needsIdToken && empty($connection->chatGptTokens()['id_token']));
    }

    protected function expiresSoon(AgentConnection $connection): bool
    {
        return $connection->chatGptTokens()['expires'] < now()->addSeconds(self::REFRESH_MARGIN_SECONDS)->getTimestamp();
    }

    /**
     * @param  callable(): Response  $request
     *
     * @throws ChatGptSignInFailed when OpenAI can't be reached
     */
    protected function send(callable $request, ?string $unreachable = null): Response
    {
        try {
            return $request();
        } catch (ConnectionException) {
            throw new ChatGptSignInFailed($unreachable ?? __("Couldn't reach OpenAI. Try again."));
        }
    }
}
