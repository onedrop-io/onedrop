<?php

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Jobs\RunAgentTask;
use App\Models\AgentConnection;
use App\Models\Message;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\ClaudeCodeRunner;
use App\Sandbox\Agents\Conversation;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\Agents\Jev;
use App\Sandbox\Agents\MessageChecks;
use App\Sandbox\Agents\ModelCatalog;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use App\Sandbox\WorkspaceFiles;
use App\Sandbox\WorkspaceSecrets;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

const DECISIONS_FILE = <<<'MD'
# Requirements

## Data

### REQ-001: Save notes
- User should be able to save a note.

### Decisions
- 2026-09-30: **Use SQLite for the database, not Postgres.** The app is small and one file is easier to back up.

## Look

### Decisions
- 2026-09-30: **The header is dark blue to match the logo.** The user asked for it.
MD;

beforeEach(function () {
    Queue::fake();
    config(['services.openrouter.key' => 'sk-or-system']);

    $this->runner = new class extends FakeAgentRunner
    {
        public int $stops = 0;

        public function stop(Conversation $conversation): void
        {
            $this->stops++;
        }
    };
    app()->instance(AgentRunner::class, $this->runner);

    $this->workspace = sys_get_temp_dir().'/onedrop-checks-'.bin2hex(random_bytes(4));
    mkdir($this->workspace);
    file_put_contents($this->workspace.'/.env', "APP_NAME=Demo\n");
    $root = $this->workspace;
    register_shutdown_function(fn () => exec('rm -rf '.escapeshellarg($root)));

    $this->provider = new FakeSandboxProvider;
    $this->requirements = DECISIONS_FILE;
    $this->provider->execUsing = fn (array $command, array $env) => match (true) {
        $command[0] === 'cat' => $this->requirements === null ? new ExecResult(1, '', 'No such file') : new ExecResult(0, $this->requirements),
        $command === ['php', WorkspaceSecrets::SCRIPT] => new ExecResult(0, runSecretsScript($root, $env['APP_SECRETS_REQUEST'])),
        default => new ExecResult(0, ''),
    };
    app()->instance(SandboxProvider::class, $this->provider);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Idle]);
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);

    /** Jev answers with these (keyed by question). */
    $this->jev = function (array $answers): void {
        Http::fake([Jev::URL => Http::response(['answers' => $answers])]);
    };

    $this->none = [
        'secret' => ['type' => 'noul', 'noul' => 0.02],
        'decision' => ['type' => 'choice', 'choice' => 'none', 'confidence' => 0.99, 'probabilities' => ['d0' => 0.01, 'd1' => 0.0, 'none' => 0.99]],
    ];

    $this->send = function (string $content, array $data = []): TestResponse {
        return $this->actingAs($this->user)
            ->post(route('projects.messages.store', $this->project), ['content' => $content, 'checks' => '1', ...$data]);
    };

    // What the next page load would show (and so clear) for the send; the tests don't follow the redirect.
    $this->prompts = new WeakMap;
    $this->held = function (TestResponse $response, ?Conversation $conversation = null): ?array {
        if (! isset($this->prompts[$response])) {
            $this->prompts[$response] = ['held' => MessageChecks::pullPrompt($this->user, $conversation ?? $this->project)];
        }

        return $this->prompts[$response]['held'];
    };

    $this->jevRequests = fn (): int => Http::recorded(fn (Request $request) => $request->url() === Jev::URL)->count();
});

test('a message that changes an earlier decision is held until the user goes ahead', function () {
    ($this->jev)([...$this->none, 'decision' => ['type' => 'choice', 'choice' => 'd0', 'confidence' => 0.97, 'probabilities' => ['d0' => 0.97, 'd1' => 0.01, 'none' => 0.02]]]);

    $response = ($this->send)('Switch the database to Postgres');

    $response->assertRedirect(route('projects.show', $this->project));
    expect(($this->held)($response))->toMatchArray(['kind' => 'decision', 'decision' => 'Use SQLite for the database, not Postgres.'])
        ->and($this->project->messages()->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertSent(fn (Request $request) => $request->url() === Jev::URL
        && $request['state']['new_message'] === 'Switch the database to Postgres'
        && $request['questions']['decision']['type'] === 'choice'
        && str_contains($request['questions']['decision']['criteria']['d0'], 'Use SQLite')
        && str_contains($request['questions']['decision']['criteria']['d1'], 'dark blue')
        && isset($request['questions']['decision']['criteria']['none'])
        && ! isset($request['questions']['interrupt']) && ! isset($request['questions']['size']));

    $check = ($this->held)($response)['check'];
    $response = ($this->send)('Switch the database to Postgres', ['check' => $check, 'confirm_decision' => '1']);

    expect(($this->held)($response))->toBeNull()
        ->and($this->project->messages()->sole()->only('content', 'meta'))->toBe([
            'content' => 'Switch the database to Postgres',
            'meta' => ['changes_decision' => 'Use SQLite for the database, not Postgres.'],
        ]);
    // Jev was asked once: the answer was kept for the reply.
    expect(($this->jevRequests)())->toBe(1);
    Queue::assertPushed(RunAgentTask::class);
})->group('REQ-003');

test('the agent is told a confirmed change to a decision is on purpose', function () {
    $message = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'Use Postgres', 'meta' => ['changes_decision' => 'Use SQLite for the database, not Postgres.']]);
    $runner = new class(app(SandboxProvider::class), app(ModelCatalog::class), app(WorkspaceFiles::class)) extends ClaudeCodeRunner
    {
        public function promptFor(Message $message): string
        {
            return $this->prompt($message, []);
        }
    };

    expect($runner->promptFor($message))->toStartWith('Use Postgres')
        ->toContain('intentionally changes an earlier decision in .onedrop/REQ.md: "Use SQLite for the database, not Postgres."')
        ->toContain('rewrite that decision');
})->group('REQ-003');

test('no decision question is asked without decisions or with requirements tracking off', function (?string $requirements, bool $tracking) {
    $this->requirements = $requirements;
    $this->project->update(['track_requirements' => $tracking]);
    ($this->jev)($this->none);

    $response = ($this->send)('Switch the database to Postgres');

    expect(($this->held)($response))->toBeNull()
        ->and($this->project->messages()->count())->toBe(1);
    Http::assertSent(fn (Request $request) => $request->url() === Jev::URL && ! isset($request['questions']['decision']));
})->with([
    'no REQ.md' => [null, true],
    'no decisions yet' => ["# Requirements\n\n### REQ-001: Notes\n- User should be able to save a note.\n", true],
    'tracking off' => [DECISIONS_FILE, false],
])->group('REQ-003');

test('REQ.md is read once until something new happens in the chat', function () {
    ($this->jev)($this->none);

    ($this->send)('one');
    ($this->send)('two');

    expect(collect($this->provider->executed)->where('command.0', 'cat')->count())->toBe(1);

    $this->project->messages()->create(['role' => MessageRole::Assistant, 'content' => 'Done.']);
    ($this->send)('three');

    expect(collect($this->provider->executed)->where('command.0', 'cat')->count())->toBe(2);
})->group('REQ-003');

test('decisions are parsed from every Decisions section, by their bold title', function () {
    expect(MessageChecks::parseDecisions(DECISIONS_FILE))->toBe([
        ['text' => '2026-09-30: **Use SQLite for the database, not Postgres.** The app is small and one file is easier to back up.', 'title' => 'Use SQLite for the database, not Postgres.'],
        ['text' => '2026-09-30: **The header is dark blue to match the logo.** The user asked for it.', 'title' => 'The header is dark blue to match the logo.'],
    ]);
})->group('REQ-003');

test('a message with a secret is held, and saving it puts it in Secrets and sends a reference instead', function () {
    ($this->jev)([...$this->none, 'secret' => ['type' => 'noul', 'noul' => 0.9]]);

    $response = ($this->send)('Add payments with my key '.fakeStripeKey().' please');

    expect(($this->held)($response))->toMatchArray(['kind' => 'secret', 'name' => 'STRIPE_SECRET_KEY', 'preview' => 'sk_l…'])
        ->and(json_encode(($this->held)($response)))->not->toContain('sk_live_51')
        ->and($this->project->messages()->count())->toBe(0);

    $response = ($this->send)('Add payments with my key '.fakeStripeKey().' please', [
        'check' => ($this->held)($response)['check'],
        'secret_name' => 'STRIPE_SECRET_KEY',
    ]);

    $response->assertSessionHasNoErrors();
    expect($this->project->messages()->sole()->content)->toBe('Add payments with my key (saved as STRIPE_SECRET_KEY in Secrets) please')
        ->and(file_get_contents($this->workspace.'/.env'))->toContain('STRIPE_SECRET_KEY='.fakeStripeKey());
    expect(($this->jevRequests)())->toBe(1);
})->group('SECRET-002');

test('the user can send a secret anyway', function () {
    ($this->jev)([...$this->none, 'secret' => ['type' => 'noul', 'noul' => 0.9]]);
    $content = 'the admin password is hunter2-Blue-77';

    $held = ($this->held)(($this->send)($content));

    expect($held['name'])->toBe('ADMIN_PASSWORD');

    ($this->send)($content, ['check' => $held['check'], 'send_secret' => '1']);

    expect($this->project->messages()->sole()->content)->toBe($content)
        ->and(file_get_contents($this->workspace.'/.env'))->toBe("APP_NAME=Demo\n");
})->group('SECRET-002');

test('Jev picks which part of the message is the secret when there are several candidates', function () {
    ($this->jev)([
        ...$this->none,
        'secret' => ['type' => 'noul', 'noul' => 0.95],
        'secret_value' => ['type' => 'choice', 'choice' => 'c1', 'confidence' => 0.9, 'probabilities' => ['c0' => 0.1, 'c1' => 0.9]],
    ]);
    $content = 'Order ABC12345XYZ failed; connect to postgres://admin:S3cretPass@db.example.com/shop';

    $held = ($this->held)(($this->send)($content));

    expect($held)->toMatchArray(['name' => 'DATABASE_URL', 'preview' => 'post…']);

    ($this->send)($content, ['check' => $held['check'], 'secret_name' => 'DATABASE_URL']);

    expect($this->project->messages()->sole()->content)->toBe('Order ABC12345XYZ failed; connect to (saved as DATABASE_URL in Secrets)');
})->group('SECRET-002');

test('a secret Jev found but the platform can\'t locate is pasted by the user', function () {
    ($this->jev)([...$this->none, 'secret' => ['type' => 'noul', 'noul' => 0.9]]);
    $content = 'my password is swordfish, use it for the seed user';

    $held = ($this->held)(($this->send)($content));

    expect($held['preview'])->toBeNull();

    ($this->send)($content, ['check' => $held['check'], 'secret_name' => 'SEED_PASSWORD', 'secret_value' => 'nothere'])
        ->assertSessionHasErrors(['secret_value' => "That value isn't in your message."]);
    ($this->send)($content, ['check' => $held['check'], 'secret_name' => '1BAD', 'secret_value' => 'swordfish'])
        ->assertSessionHasErrors('secret_name');
    expect($this->project->messages()->count())->toBe(0);

    ($this->send)($content, ['check' => $held['check'], 'secret_name' => 'SEED_PASSWORD', 'secret_value' => 'swordfish']);

    expect($this->project->messages()->sole()->content)->toBe('my password is (saved as SEED_PASSWORD in Secrets), use it for the seed user');
})->group('SECRET-002');

test('a secret that is held and changes a decision asks both, then saves and sends once', function () {
    ($this->jev)([
        'secret' => ['type' => 'noul', 'noul' => 0.9],
        'decision' => ['type' => 'choice', 'choice' => 'd0', 'confidence' => 0.97, 'probabilities' => ['d0' => 0.97, 'none' => 0.03]],
    ]);
    $content = 'Use Postgres: postgres://admin:S3cretPass@db.example.com/shop';

    $first = ($this->held)(($this->send)($content));
    $second = ($this->held)(($this->send)($content, ['check' => $first['check'], 'secret_name' => 'DATABASE_URL']));

    expect($first['kind'])->toBe('secret')
        ->and($second['kind'])->toBe('decision')
        ->and(file_get_contents($this->workspace.'/.env'))->not->toContain('DATABASE_URL');

    ($this->send)($content, ['check' => $second['check'], 'secret_name' => 'DATABASE_URL', 'confirm_decision' => '1'])->assertSessionHasNoErrors();

    expect($this->project->messages()->sole()->content)->toBe('Use Postgres: (saved as DATABASE_URL in Secrets)')
        ->and(file_get_contents($this->workspace.'/.env'))->toContain('DATABASE_URL=');
    expect(($this->jevRequests)())->toBe(1);
})->group('SECRET-002', 'REQ-003');

test('suggested secret names', function (string $content, string $value, string $name) {
    expect(MessageChecks::suggestName($content, $value))->toBe($name);
})->with([
    ['use sk-ant-api03-abcdef123456 for claude', 'sk-ant-api03-abcdef123456', 'ANTHROPIC_API_KEY'],
    ['MAILGUN_KEY=key-1234567890abcdef', 'key-1234567890abcdef', 'MAILGUN_KEY'],
    ['redis://:pass123@cache:6379', 'redis://:pass123@cache:6379', 'REDIS_URL'],
    ['the mailchimp token: abc123def456ghi', 'abc123def456ghi', 'MAILCHIMP_TOKEN'],
    ['abc123def456ghi', 'abc123def456ghi', 'SECRET'],
])->group('SECRET-002');

test('a correction while the agent works is sent now, and the chat says why', function () {
    $this->project->update(['status' => ProjectStatus::Working]);
    $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'Add a contact form']);
    $this->project->messages()->create(['role' => MessageRole::Activity, 'content' => 'Editing contact.blade.php']);
    ($this->jev)([...$this->none, 'interrupt' => ['type' => 'noul', 'noul' => 0.97]]);

    ($this->send)('actually put the form on the about page', ['mode' => 'auto']);

    expect($this->runner->stops)->toBe(1)
        ->and($this->project->queuedMessages()->count())->toBe(0)
        ->and($this->project->messages()->pluck('content')->take(-3)->values()->all())
        ->toBe(['Stopped', MessageChecks::NOW_NOTE, 'actually put the form on the about page']);
    Http::assertSent(fn (Request $request) => $request->url() === Jev::URL && $request['state']['agent_is_working_on'] === 'Add a contact form'
        && $request['state']['agent_recent_steps'] === ['Editing contact.blade.php']
        && $request['questions']['interrupt']['type'] === 'noul');
})->group('AGT-012');

test('a separate request while the agent works is queued', function () {
    $this->project->update(['status' => ProjectStatus::Working]);
    ($this->jev)([...$this->none, 'interrupt' => ['type' => 'noul', 'noul' => 0.5]]);

    ($this->send)('also add a footer', ['mode' => 'auto']);

    expect($this->runner->stops)->toBe(0)
        ->and($this->project->queuedMessages()->pluck('content')->all())->toBe(['also add a footer']);
})->group('AGT-012');

test('explicit queue and send now are kept, without asking Jev about interrupting', function (string $mode, int $stops, int $queued) {
    $this->project->update(['status' => ProjectStatus::Working]);
    ($this->jev)($this->none);

    ($this->send)('also add a footer', ['mode' => $mode]);

    expect($this->runner->stops)->toBe($stops)
        ->and($this->project->queuedMessages()->count())->toBe($queued);
    Http::assertSent(fn (Request $request) => $request->url() === Jev::URL && ! isset($request['questions']['interrupt']));
})->with([
    'queue' => ['queue', 0, 1],
    'now' => ['now', 1, 0],
])->group('AGT-012');

test('without a key, or when Jev fails, messages are sent as they would be without it', function (Closure $setUp) {
    $this->project->update(['status' => ProjectStatus::Working]);
    $setUp($this);

    ($this->send)('Switch to Postgres with postgres://admin:S3cretPass@db.example.com/shop', ['mode' => 'auto'])
        ->assertSessionHasNoErrors();

    expect($this->project->queuedMessages()->count())->toBe(1)
        ->and($this->runner->stops)->toBe(0);
})->with([
    'no key' => [fn () => fn ($test) => config(['services.openrouter.key' => null])],
    'Jev errors' => [fn () => fn ($test) => Http::fake([Jev::URL => Http::response('', 500)])],
    'Jev leaves a question out' => [fn () => fn ($test) => Http::fake([Jev::URL => Http::response(['answers' => ['secret' => ['type' => 'noul', 'noul' => 0.99]]])])],
])->group('AGT-012', 'SECRET-002', 'REQ-003');

test('messages the platform writes are never held', function () {
    ($this->jev)([...$this->none, 'secret' => ['type' => 'noul', 'noul' => 0.99]]);

    $this->actingAs($this->user)->post(route('projects.messages.store', $this->project), ['content' => 'Put the feature behind a flag '.fakeStripeKey()]);

    expect($this->project->messages()->count())->toBe(1);
    expect(($this->jevRequests)())->toBe(0);
})->group('SECRET-002');

test('with Auto, Jev sizes the request and the run uses the model picked for it', function (float $score, string $model, ?string $variant, string $note) {
    $this->project->update(['agent_auto' => true, 'agent_provider' => 'claude', 'agent_model' => 'claude-sonnet-5']);
    ($this->jev)([...$this->none, 'size' => ['type' => 'score', 'score' => $score, 'confidence' => 0.9, 'probabilities' => [0.1, 0.2, 0.3, 0.4]]]);

    ($this->send)('Rebuild the whole dashboard');

    $message = $this->project->messages()->where('role', MessageRole::User)->sole();

    expect($message->meta['selection'])->toBe(['provider' => 'claude', 'model' => $model, 'variant' => $variant])
        ->and(app(ModelCatalog::class)->selectionFor($this->project->fresh(), $message))->toMatchArray(['model' => $model, 'variant' => $variant])
        ->and($this->project->messages()->reorder()->latest('id')->first()->only('role', 'content'))
        ->toBe(['role' => MessageRole::Activity, 'content' => $note]);
    Http::assertSent(fn (Request $request) => $request->url() === Jev::URL && $request['questions']['size']['type'] === 'score' && count($request['questions']['size']['criteria']) === 4);
})->with([
    'tiny' => [0.2, 'claude-sonnet-5', 'low', 'Auto picked Claude Sonnet 5 with low reasoning, for a tiny tweak'],
    'feature' => [2.1, 'claude-opus-5-5', 'high', 'Auto picked Claude Opus 5.5 with high reasoning, for a new feature'],
    'big' => [2.8, 'claude-opus-5-5', 'xhigh', 'Auto picked Claude Opus 5.5 with extra high reasoning, for a big change'],
])->group('AGT-011');

test('with Auto and no answer from Jev, the provider default model runs', function () {
    $this->project->update(['agent_auto' => true, 'agent_provider' => 'claude', 'agent_model' => 'claude-opus-5-5']);
    Http::fake([Jev::URL => Http::response('', 500)]);

    ($this->send)('Rebuild the whole dashboard');

    expect($this->project->messages()->where('role', MessageRole::User)->sole()->meta['selection'])
        ->toBe(['provider' => 'claude', 'model' => 'claude-sonnet-5', 'variant' => null]);
})->group('AGT-011');

test('a queued Auto message keeps the model picked for it when it runs', function () {
    $this->project->update(['status' => ProjectStatus::Working, 'agent_auto' => true, 'agent_provider' => 'claude', 'agent_model' => 'claude-sonnet-5']);
    ($this->jev)([...$this->none, 'interrupt' => ['type' => 'noul', 'noul' => 0.1], 'size' => ['type' => 'score', 'score' => 2.0, 'confidence' => 0.9, 'probabilities' => [0, 0, 1, 0]]]);

    ($this->send)('add a blog', ['mode' => 'auto']);
    app(AgentQueue::class)->finished($this->project->fresh());

    expect($this->project->messages()->where('role', MessageRole::User)->sole()->meta['selection']['model'])->toBe('claude-opus-5-5')
        ->and($this->project->messages()->reorder()->latest('id')->value('content'))->toBe('Auto picked Claude Opus 5.5 with high reasoning, for a new feature');
})->group('AGT-011');

test('the user can turn Auto on and off in the picker', function () {
    $this->actingAs($this->user)
        ->patch(route('projects.agent.update', $this->project), ['agent_provider' => 'claude', 'agent_model' => 'claude-sonnet-5', 'agent_auto' => true])
        ->assertSessionHasNoErrors();

    expect($this->project->fresh()->agent_auto)->toBeTrue();
    $this->get(route('projects.show', $this->project))->assertInertia(fn ($page) => $page->where('agent.auto', true));

    $this->patch(route('projects.agent.update', $this->project), ['agent_provider' => 'claude', 'agent_model' => 'claude-opus-5-5']);

    expect($this->project->fresh()->agent_auto)->toBeFalse();
})->group('AGT-011');

test('without Auto no size is asked, and the chosen model runs', function () {
    ($this->jev)($this->none);

    ($this->send)('Rebuild the whole dashboard');

    expect($this->project->messages()->sole()->meta)->toBeNull();
    Http::assertSent(fn (Request $request) => $request->url() === Jev::URL && ! isset($request['questions']['size']));
})->group('AGT-011');

test('task chats get the same checks', function () {
    $task = $this->project->tasks()->create(['title' => 'Payments']);
    ($this->jev)([...$this->none, 'secret' => ['type' => 'noul', 'noul' => 0.9]]);

    $response = $this->actingAs($this->user)->post(route('projects.tasks.messages.store', [$this->project, $task]), ['content' => 'key '.fakeStripeKey(), 'checks' => '1']);

    expect(($this->held)($response, $task)['kind'])->toBe('secret')
        ->and($task->messages()->count())->toBe(0);
})->group('SECRET-002');

test('the held prompt reaches the next workspace page load once, whatever else uses the session meanwhile', function () {
    ($this->jev)([...$this->none, 'secret' => ['type' => 'noul', 'noul' => 0.9]]);

    ($this->send)('key '.fakeStripeKey());

    // A background request that saves the session alongside the send can't lose it (it isn't flash data).
    session()->flush();

    $this->actingAs($this->user)->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page->where('held.kind', 'secret')->where('held.name', 'STRIPE_SECRET_KEY'));

    $this->actingAs($this->user)->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page->where('held', null));
})->group('SECRET-002');
