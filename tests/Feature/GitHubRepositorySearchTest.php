<?php

use App\Enums\SocialProvider;
use App\Models\AgentConnection;
use App\Models\SocialAccount;
use App\Models\User;
use App\Sandbox\GitHubRepositorySearch;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->repository = fn (string $fullName, array $overrides = []) => [
        'full_name' => $fullName, 'private' => false, 'size' => 10,
        'html_url' => "https://github.com/{$fullName}", 'pushed_at' => '2026-11-01T00:00:00Z', ...$overrides,
    ];
});

test('typing an owner suggests their public repositories, then name matches, without connecting GitHub', function () {
    Http::fake([
        'api.github.com/users/pixwel/repos*' => Http::response([
            ($this->repository)('Pixwel/platform'),
            ($this->repository)('Pixwel/secret', ['private' => true]),
            ($this->repository)('Pixwel/blank', ['size' => 0]),
        ]),
        'api.github.com/search/repositories*' => Http::response(['items' => [
            ($this->repository)('someone/pixwel-tools'),
            ($this->repository)('Pixwel/platform'),
        ]]),
    ]);

    $this->actingAs($this->user)
        ->getJson(route('github.search', ['q' => 'pixwel']))
        ->assertOk()
        ->assertExactJson(['repositories' => [
            ['full_name' => 'Pixwel/platform', 'private' => false, 'html_url' => 'https://github.com/Pixwel/platform', 'pushed_at' => '2026-11-01T00:00:00Z'],
            ['full_name' => 'someone/pixwel-tools', 'private' => false, 'html_url' => 'https://github.com/someone/pixwel-tools', 'pushed_at' => '2026-11-01T00:00:00Z'],
        ]]);

    Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));
})->group('PRJ-009');

test('typing owner/name searches that owner\'s repositories by name, with the user\'s GitHub sign-in token', function () {
    SocialAccount::factory()->for($this->user)->create(['provider' => SocialProvider::GitHub, 'token' => 'gho_mine']);
    Http::fake(['api.github.com/search/repositories*' => Http::response(['items' => [($this->repository)('Pixwel/platform')]])]);

    $this->actingAs($this->user)
        ->getJson(route('github.search', ['q' => 'https://github.com/pixwel/pla']))
        ->assertOk()
        ->assertJsonPath('repositories.0.full_name', 'Pixwel/platform');

    Http::assertSent(fn (Request $request) => $request['q'] === 'pla in:name user:pixwel'
        && $request->hasHeader('Authorization', 'Bearer gho_mine'));
})->group('PRJ-009');

test('a failed or rate-limited search suggests nothing instead of erroring', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'API rate limit exceeded'], 403)]);

    $this->actingAs($this->user)
        ->getJson(route('github.search', ['q' => 'acme/']))
        ->assertOk()
        ->assertExactJson(['repositories' => []]);
})->group('PRJ-009');

test('what isn\'t a GitHub repository being typed isn\'t searched', function (string $query) {
    Http::fake();

    expect(app(GitHubRepositorySearch::class)->search($this->user, $query))->toBe([]);

    Http::assertNothingSent();
})->with(['a', 'file:///tmp/repo.git', 'https://gitlab.com/acme/site', 'two words'])->group('PRJ-009');

test('guests can\'t search', function () {
    $this->getJson(route('github.search', ['q' => 'acme']))->assertUnauthorized();
})->group('PRJ-009');
