<?php

use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use App\Models\AgentConnection;
use App\Models\User;
use App\Sandbox\Agents\ModelCatalog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
});

function ollamaServerConnection(User $user, string $url = 'https://8.8.8.8', string $key = 'server-key'): AgentConnection
{
    return AgentConnection::factory()->for($user)->provider(AgentProvider::Ollama)->create([
        'credential_type' => CredentialType::OllamaServer,
        'credential' => json_encode(['url' => $url, 'key' => $key]),
        'hint' => parse_url($url, PHP_URL_HOST),
    ]);
}

test('the user connects their own Ollama server by URL and key', function () {
    Http::fake(['8.8.8.8:11434/api/tags' => Http::response(['models' => [['name' => 'qwen3-coder:30b'], ['name' => 'llama4:scout']]])]);

    $this->actingAs($this->user)
        ->post(route('agent-connections.ollama-server'), ['url' => 'http://8.8.8.8:11434/v1/', 'credential' => 'server-key-1234'])
        ->assertSessionHasNoErrors();

    $connection = $this->user->agentConnections()->sole();

    expect($connection->provider)->toBe(AgentProvider::Ollama)
        ->and($connection->credential_type)->toBe(CredentialType::OllamaServer)
        ->and($connection->ollamaServer())->toBe(['url' => 'http://8.8.8.8:11434', 'key' => 'server-key-1234'])
        ->and($connection->hint)->toBe('8.8.8.8');
    Http::assertSent(fn (Request $request) => $request->url() === 'http://8.8.8.8:11434/api/tags' && $request->hasHeader('Authorization', 'Bearer server-key-1234'));
})->group('AI-006');

test('a server without a key is connected without one', function () {
    Http::fake(['8.8.8.8:11434/api/tags' => Http::response(['models' => [['name' => 'qwen3-coder:30b']]])]);

    $this->actingAs($this->user)
        ->post(route('agent-connections.ollama-server'), ['url' => 'http://8.8.8.8:11434'])
        ->assertSessionHasNoErrors();

    expect($this->user->agentConnections()->sole()->ollamaServer()['key'])->toBe('');
    Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));
})->group('AI-006');

test('private and internal addresses are refused without calling them', function (string $url) {
    Http::fake();

    $this->actingAs($this->user)
        ->post(route('agent-connections.ollama-server'), ['url' => $url])
        ->assertSessionHasErrors(['url' => "Use a public address for your Ollama server: your sandboxes run on a server and can't reach private networks."]);

    Http::assertNothingSent();
    expect($this->user->agentConnections()->count())->toBe(0);
})->with([
    'loopback' => 'http://127.0.0.1:11434',
    'localhost' => 'http://localhost:11434',
    'cloud metadata' => 'http://169.254.169.254',
    'private network' => 'http://192.168.1.20:11434',
    'ipv6 loopback' => 'http://[::1]:11434',
])->group('AI-006');

test('a local install can use an Ollama server on its own machine', function () {
    config(['sandbox.ollama_private_servers' => true]);
    Http::fake(['127.0.0.1:11434/api/tags' => Http::response(['models' => [['name' => 'qwen3-coder:30b']]])]);

    $this->actingAs($this->user)
        ->post(route('agent-connections.ollama-server'), ['url' => 'http://127.0.0.1:11434'])
        ->assertSessionHasNoErrors();

    expect($this->user->agentConnections()->count())->toBe(1);
})->group('AI-006');

test('the server has to answer like Ollama, and with the key', function (int $status, string $field, string $message) {
    Http::fake(['8.8.8.8:11434/api/tags' => Http::response(['error' => 'nope'], $status)]);

    $this->actingAs($this->user)
        ->post(route('agent-connections.ollama-server'), ['url' => 'http://8.8.8.8:11434'])
        ->assertSessionHasErrors([$field => $message]);

    expect($this->user->agentConnections()->count())->toBe(0);
})->with([
    'needs a key' => [401, 'credential', 'Your Ollama server needs a key.'],
    'not Ollama' => [404, 'url', "That doesn't look like an Ollama server: http://8.8.8.8:11434/api/tags didn't list any models."],
])->group('AI-006');

test('the model picker lists the models on the user\'s server, at no cost', function () {
    Http::fake(['8.8.8.8/api/tags' => Http::response(['models' => [['name' => 'qwen3-coder:30b'], ['name' => 'llama4:scout']]])]);
    ollamaServerConnection($this->user);

    $catalog = app(ModelCatalog::class);
    $models = $catalog->models(AgentProvider::Ollama, $this->user);

    expect(array_column($models, 'id'))->toBe(['qwen3-coder:30b', 'llama4:scout'])
        ->and($models[0]['cost'])->toBe(['input' => 0.0, 'output' => 0.0])
        ->and($catalog->defaultModel(AgentProvider::Ollama, $this->user))->toBe('qwen3-coder:30b');
})->group('AI-006');

test('sandboxes get an OpenCode config that points Ollama at the user\'s server', function () {
    Http::fake(['8.8.8.8/api/tags' => Http::response(['models' => [['name' => 'qwen3-coder:30b']]])]);

    $env = ollamaServerConnection($this->user)->sandboxEnvironment();

    expect($env['OLLAMA_API_KEY'])->toBe('server-key')
        ->and(json_decode($env['OPENCODE_CONFIG_CONTENT'], true))->toBe([
            'provider' => [
                'ollama-cloud' => [
                    'name' => 'Ollama',
                    'options' => ['baseURL' => 'https://8.8.8.8/v1'],
                    'models' => ['qwen3-coder:30b' => ['name' => 'qwen3-coder:30b', 'tool_call' => true, 'cost' => ['input' => 0, 'output' => 0]]],
                ],
            ],
        ]);
})->group('AI-006');

test('in Docker sandboxes, a localhost server is reached on the host machine', function () {
    config(['sandbox.provider' => 'docker', 'sandbox.ollama_private_servers' => true]);
    Http::fake(['localhost:11434/api/tags' => Http::response(['models' => []])]);

    $env = ollamaServerConnection($this->user, 'http://localhost:11434', '')->sandboxEnvironment();

    expect($env['OLLAMA_API_KEY'])->toBe('ollama')
        ->and(json_decode($env['OPENCODE_CONFIG_CONTENT'], true)['provider']['ollama-cloud']['options']['baseURL'])->toBe('http://host.docker.internal:11434/v1');
})->group('AI-006');
