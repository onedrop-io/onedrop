<?php

use App\Enums\AgentProvider;
use App\Enums\MessageRole;
use App\Enums\OrganizationRole;
use App\Enums\ProjectStatus;
use App\Jobs\ChargeAiCredits;
use App\Models\AgentConnection;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\OpenCodeRunner;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fakes Autumn (with $cents left, refilling on $resetsAt) and OpenRouter's key management (the key has spent $spent).
 */
function fakeCredits(float $cents, float $spent = 0, ?DateTimeInterface $resetsAt = null): void
{
    Http::fake([
        'autumn.test/v1/customers' => Http::response(['id' => 'org']),
        'autumn.test/v1/check' => Http::response(['allowed' => $cents > 0, 'balance' => [
            'remaining' => $cents,
            'next_reset_at' => $resetsAt ? $resetsAt->getTimestamp() * 1000 : null,
        ]]),
        'autumn.test/v1/track' => Http::response(['value' => 1]),
        'openrouter.ai/api/v1/keys' => Http::response(['key' => 'sk-or-org-key', 'data' => ['hash' => 'hash-1']], 201),
        'openrouter.ai/api/v1/keys/*' => fn (Request $request) => Http::response(['data' => ['hash' => 'hash-1', 'usage' => $spent]]),
    ]);
}

beforeEach(function () {
    config([
        'services.autumn.key' => 'am_sk_test',
        'services.autumn.url' => 'https://autumn.test/v1',
        'services.openrouter.provisioning_key' => 'sk-or-mgmt',
        'sandbox.models.credits' => 'openrouter/deepseek/deepseek-v4.1-flash',
    ]);

    $this->provider = new class extends FakeSandboxProvider
    {
        /** @var list<array<string, string>> */
        public array $envs = [];

        public function exec(string $id, array $command, array $env = [], bool $detach = false): ExecResult
        {
            if ($command !== ['php', '/opt/onedrop/skills.php']) {
                $this->envs[] = $env;
            }

            return parent::exec($id, $command, $env, $detach);
        }
    };
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->create();
    $this->project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Working]);
    $this->organization = $this->project->organization;
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
});

test('someone without their own AI builds on the organization\'s credits, with a key limited to what is left', function () {
    fakeCredits(600);
    $message = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'build a timer']);

    app(OpenCodeRunner::class)->start($this->project, $message);

    $env = $this->provider->envs[0];
    expect($env['OPENROUTER_API_KEY'])->toBe('sk-or-org-key')
        ->and($env['APP_MODEL'])->toBe('openrouter/deepseek/deepseek-v4.1-flash')
        ->and($this->organization->fresh()->ai_credits_key)->toBe('sk-or-org-key')
        ->and($this->organization->fresh()->ai_credits_key_hash)->toBe('hash-1');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://openrouter.ai/api/v1/keys'
        && $request->method() === 'POST' && $request['limit'] == 6.0 && $request->hasHeader('Authorization', 'Bearer sk-or-mgmt'));
    Http::assertSent(fn (Request $request) => $request->url() === 'https://autumn.test/v1/customers'
        && $request['id'] === "org_{$this->organization->id}");
})->group('CREDIT-001');

test('before a run, new spending is charged and the key is limited to what it spent plus what is left', function () {
    $this->organization->forceFill(['ai_credits_key' => 'sk-or-org-key', 'ai_credits_key_hash' => 'hash-1', 'ai_credits_charged' => 0.10])->save();
    fakeCredits(400, spent: 0.25);
    $message = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'add tags']);

    app(OpenCodeRunner::class)->start($this->project, $message);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://autumn.test/v1/track'
        && $request['feature_id'] === 'ai_usage' && abs($request['value'] - 15) < 0.0001);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://openrouter.ai/api/v1/keys/hash-1'
        && $request->method() === 'PATCH' && abs($request['limit'] - 4.25) < 0.0001);
    expect($this->organization->fresh()->ai_credits_charged)->toBe(0.25)
        ->and($this->provider->envs[0]['OPENROUTER_API_KEY'])->toBe('sk-or-org-key');
})->group('CREDIT-001');

test('a run stops with a message when the credits have run out', function () {
    fakeCredits(0.4, resetsAt: now()->setDate(2026, 11, 2));
    $message = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'build a timer']);

    app(OpenCodeRunner::class)->start($this->project, $message);

    expect($this->provider->envs)->toBe([])
        ->and($this->project->messages()->reorder()->latest('id')->first()->content)
        ->toContain('AI credits have run out')->toContain('November 2')->toContain('Settings → AI');
    Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://openrouter.ai/api/v1/keys'));
})->group('CREDIT-001');

test('after a run, what the key spent is charged to the organization', function () {
    $this->organization->forceFill(['ai_credits_key' => 'sk-or-org-key', 'ai_credits_key_hash' => 'hash-1'])->save();
    fakeCredits(600, spent: 0.05);

    ChargeAiCredits::dispatchSync($this->organization);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://autumn.test/v1/track' && abs($request['value'] - 5) < 0.0001);
    expect($this->organization->fresh()->ai_credits_charged)->toBe(0.05);
})->group('CREDIT-001');

test('nothing new spent means nothing is charged', function () {
    $this->organization->forceFill(['ai_credits_key' => 'sk-or-org-key', 'ai_credits_key_hash' => 'hash-1', 'ai_credits_charged' => 0.05])->save();
    fakeCredits(600, spent: 0.05);

    ChargeAiCredits::dispatchSync($this->organization);

    Http::assertNotSent(fn (Request $request) => $request->url() === 'https://autumn.test/v1/track');
})->group('CREDIT-001');

test('the AI onboarding is skipped only while credits are on', function (bool $creditsOn) {
    if (! $creditsOn) {
        config(['services.openrouter.provisioning_key' => null]);
    }

    $response = $this->actingAs($this->user)->get(route('dashboard'));

    expect($response->headers->get('Location') === route('onboarding.ai'))->toBe(! $creditsOn);
})->with(['credits on' => true, 'credits off' => false])->group('CREDIT-001');

test('the model picker offers AI credits after the user\'s own AI, with what is left', function () {
    AgentConnection::factory()->for($this->user)->provider(AgentProvider::OpenRouter)->create();
    fakeCredits(482);

    $response = $this->actingAs($this->user)->getJson(route('agent-models.index'))->assertOk();

    expect($response->json('harnesses.0.providers'))->toBe(['openrouter', 'credits'])
        ->and(collect($response->json('providers'))->firstWhere('id', 'credits'))
        ->label->toBe('AI credits · $4.82 left')
        ->models->toHaveCount(1);
})->group('CREDIT-001');

test('AI credits can not be connected like a provider', function () {
    $this->actingAs($this->user)
        ->post(route('agent-connections.store'), ['provider' => 'credits', 'credential' => 'sk-anything'])
        ->assertSessionHasErrors('provider');
})->group('CREDIT-001');

test('while credits are on, someone can own only one organization', function () {
    config(['app.multi_tenant' => true]);
    Organization::factory()->create()->addMember($this->user, OrganizationRole::Owner);

    $this->actingAs($this->user)->post(route('organizations.store'), ['name' => 'Second'])->assertSessionHasErrors('name');

    expect(Organization::query()->where('name', 'Second')->exists())->toBeFalse();
})->group('CREDIT-001', 'ORG-003');
