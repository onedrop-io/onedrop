<?php

use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use App\Models\AgentConnection;

test('each connection maps to the env var its agent CLI reads', function (AgentProvider $provider, CredentialType $type, string $variable) {
    $connection = AgentConnection::factory()->make([
        'provider' => $provider,
        'credential_type' => $type,
        'credential' => 'secret',
    ]);

    expect($connection->sandboxEnvironment())->toBe([$variable => 'secret']);
})->with([
    'claude api key' => [AgentProvider::Claude, CredentialType::ApiKey, 'ANTHROPIC_API_KEY'],
    'codex' => [AgentProvider::Codex, CredentialType::ApiKey, 'OPENAI_API_KEY'],
    'openrouter' => [AgentProvider::OpenRouter, CredentialType::ApiKey, 'OPENROUTER_API_KEY'],
    'gemini' => [AgentProvider::Gemini, CredentialType::ApiKey, 'GOOGLE_GENERATIVE_AI_API_KEY'],
    'ollama' => [AgentProvider::Ollama, CredentialType::ApiKey, 'OLLAMA_API_KEY'],
])->group('AI-001');

test('claude tokens from setup-token are recognised as subscription tokens', function () {
    expect(AgentProvider::Claude->isSubscriptionToken(' sk-ant-oat01-abc'))->toBeTrue()
        ->and(AgentProvider::Claude->isSubscriptionToken('sk-ant-api03-abc'))->toBeFalse()
        ->and(AgentProvider::Codex->isSubscriptionToken('sk-ant-oat01-abc'))->toBeFalse();
})->group('AI-005');

test('a Claude subscription puts no credential in the sandbox', function () {
    expect(AgentConnection::factory()->claudeLogin()->make()->sandboxEnvironment())->toBe([]);
})->group('AI-005');

test('a ChatGPT sign-in gives OpenCode its access token but never the refresh token', function () {
    $connection = AgentConnection::factory()->chatGpt(['expires' => 1_900_000_000])->make();

    $environment = $connection->sandboxEnvironment();

    expect(array_keys($environment))->toBe(['OPENCODE_AUTH_CONTENT'])
        ->and(json_decode($environment['OPENCODE_AUTH_CONTENT'], true))->toBe(['openai' => [
            'type' => 'oauth',
            'refresh' => '',
            'access' => 'chatgpt-access-token',
            'expires' => 1_900_000_000_000,
            'accountId' => 'acct-1234',
        ]])
        ->and($environment['OPENCODE_AUTH_CONTENT'])->not->toContain('chatgpt-refresh-token');
})->group('AI-003');
