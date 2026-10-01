<?php

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\TaskStage;
use App\Enums\TurnOutcome;
use App\Jobs\CheckTurnOutcome;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Task;
use App\Models\User;
use App\Sandbox\Agents\Jev;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.openrouter.key' => 'sk-or-system', 'sandbox.task_copies' => false]);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Idle, 'read_at' => now()]);
    $this->turn = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'add a contact form']);
    $this->project->messages()->create(['role' => MessageRole::Activity, 'content' => 'Editing routes/web.php']);
    $this->project->messages()->create(['role' => MessageRole::Assistant, 'content' => 'Should the form email you, or save to the database?']);

    /** Jev picks $choice with probability $probability. */
    $this->jev = function (string $choice, float $probability = 0.9): void {
        Http::fake([Jev::URL => Http::response(['answers' => [
            'outcome' => ['type' => 'choice', 'choice' => $choice, 'confidence' => $probability, 'probabilities' => [$choice => $probability]],
        ]])]);
    };

    $this->check = fn (Project|Task|null $conversation = null, ?int $turn = null) => (new CheckTurnOutcome($conversation ?? $this->project, $turn ?? $this->turn->id))
        ->handle(app(Jev::class));
});

test('a turn that ends with a question is kept as waiting for the user', function () {
    ($this->jev)('question');

    ($this->check)();

    expect($this->project->fresh()->turn_outcome)->toBe(TurnOutcome::Question);

    Http::assertSent(fn (Request $request) => $request->url() === Jev::URL
        && $request->hasHeader('Authorization', 'Bearer sk-or-system')
        && $request['state'] === ['user_request' => 'add a contact form', 'agent_reply' => 'Should the form email you, or save to the database?']
        && $request['questions']['outcome']['type'] === 'choice'
        && array_keys($request['questions']['outcome']['criteria']) === ['done', 'question', 'needs_input', 'blocked']);
})->group('PRJ-011');

test('a task\'s turn is checked the same way', function () {
    $task = Task::factory()->for($this->project)->create(['stage' => TaskStage::Review]);
    $turn = $task->messages()->create(['role' => MessageRole::User, 'content' => 'connect Stripe']);
    $task->messages()->create(['role' => MessageRole::Assistant, 'content' => 'Add your Stripe secret key under Tools → Secrets, then tell me.']);
    ($this->jev)('needs_input');

    ($this->check)($task, $turn->id);

    expect($task->fresh()->turn_outcome)->toBe(TurnOutcome::NeedsInput)
        ->and($this->project->fresh()->turn_outcome)->toBeNull();
})->group('PRJ-011');

test('nothing is kept when Jev is unsure, fails, or there is nothing to judge', function (Closure $setUp) {
    $setUp->call($this);

    ($this->check)();

    expect($this->project->fresh()->turn_outcome)->toBeNull();
})->with([
    'unsure' => [fn () => ($this->jev)('question', 0.4)],
    'jev fails' => [fn () => Http::fake([Jev::URL => Http::response(['error' => 'down'], 500)])],
    'jev leaves the question out' => [fn () => Http::fake([Jev::URL => Http::response(['answers' => []])])],
    'an unknown choice' => [fn () => ($this->jev)('maybe')],
    'working again' => [function () {
        ($this->jev)('question');
        $this->project->update(['status' => ProjectStatus::Working]);
    }],
    'stopped by the user' => [function () {
        ($this->jev)('blocked');
        $this->project->messages()->create(['role' => MessageRole::Activity, 'content' => 'Stopped']);
    }],
    'no reply' => [function () {
        ($this->jev)('blocked');
        $this->project->messages()->where('role', MessageRole::Assistant)->delete();
    }],
    'no key' => [function () {
        ($this->jev)('question');
        config(['services.openrouter.key' => null]);
    }],
])->group('PRJ-011');

test('the end of the turn forgets the last outcome, and checks it only when there is a key', function () {
    Queue::fake([CheckTurnOutcome::class]);
    $this->project->update(['turn_outcome' => TurnOutcome::Question]);

    config(['services.openrouter.key' => null]);
    CheckTurnOutcome::afterTurn($this->project);

    expect($this->project->fresh()->turn_outcome)->toBeNull()
        ->and(CheckTurnOutcome::checking($this->project))->toBeFalse();
    Queue::assertNotPushed(CheckTurnOutcome::class);

    config(['services.openrouter.key' => 'sk-or-system']);
    CheckTurnOutcome::afterTurn($this->project);

    expect(CheckTurnOutcome::checking($this->project))->toBeTrue();
    Queue::assertPushed(CheckTurnOutcome::class, fn (CheckTurnOutcome $job) => $job->turn === $this->turn->id);

    // The check ends the hold on the notification, answered or not.
    Http::fake([Jev::URL => Http::response([], 500)]);
    ($this->check)();
    expect(CheckTurnOutcome::checking($this->project))->toBeFalse();
})->group('PRJ-011');

test('a run that ends checks its turn, in the main chat and in tasks', function () {
    Queue::fake([CheckTurnOutcome::class]);
    $this->project->update(['status' => ProjectStatus::Working]);
    $sandbox = Sandbox::factory()->for($this->project)->create();
    $task = Task::factory()->for($this->project)->working()->create();
    $task->messages()->create(['role' => MessageRole::User, 'content' => 'dark mode']);

    $this->withToken($sandbox->issueEventsToken())->postJson(route('sandbox-events.store', $sandbox), ['events' => [['type' => 'onedrop.exit', 'code' => 0, 'stderr' => '']]])->assertOk();
    $this->withToken($task->issueEventsToken())->postJson(route('sandbox-events.tasks.store', [$sandbox, $task]), ['events' => [['type' => 'onedrop.exit', 'code' => 0, 'stderr' => '']]])->assertOk();

    Queue::assertPushed(CheckTurnOutcome::class, fn (CheckTurnOutcome $job) => $job->conversation->is($this->project));
    Queue::assertPushed(CheckTurnOutcome::class, fn (CheckTurnOutcome $job) => $job->conversation->is($task));
})->group('PRJ-011');

test('the sidebar and the board show what a chat is waiting for, and hold notifications while it is checked', function () {
    Queue::fake();
    $this->actingAs($this->user);
    $task = Task::factory()->for($this->project)->create(['stage' => TaskStage::Review, 'turn_outcome' => TurnOutcome::Blocked]);

    $sidebar = fn () => $this->followingRedirects()->get(route('dashboard'))->inertiaProps('sidebarProjects.recent.0');

    expect($sidebar())
        ->waiting_for->toBe('blocked')
        ->checking->toBeFalse()
        ->tasks->{0}->waiting_for->toBe('blocked');

    // The main chat's outcome comes first; a done turn isn't waiting.
    $this->project->update(['turn_outcome' => TurnOutcome::Question]);
    $task->update(['turn_outcome' => TurnOutcome::Done]);
    expect($sidebar())->waiting_for->toBe('question')->tasks->{0}->waiting_for->toBeNull();

    // A chat that's working again isn't waiting.
    $this->project->update(['status' => ProjectStatus::Working]);
    expect($sidebar())->waiting_for->toBeNull();

    $task->messages()->create(['role' => MessageRole::User, 'content' => 'dark mode']);
    CheckTurnOutcome::afterTurn($task);
    expect($sidebar())->checking->toBeTrue();

    $task->update(['turn_outcome' => TurnOutcome::NeedsInput]);
    $this->get(route('projects.board', $this->project))->assertInertia(fn ($page) => $page
        ->where('tasks.0.waiting_for', 'needs_input')
        ->where('tasks.0.checking', true));

    $this->withUnencryptedCookie('open_project', (string) $this->project->id)
        ->get(route('projects.board', $this->project))
        ->assertInertia(fn ($page) => $page
            ->where('openProject.main_waiting_for', null)
            ->where('openProject.waiting_for', 'needs_input')
            ->where('openProject.checking', true)
            ->where('openProject.tasks.0.waiting_for', 'needs_input'));
})->group('PRJ-011');

test('tests do not ask Jev with the key in .env', function () {
    config(['services.openrouter.key' => env('OPENROUTER_API_KEY') ?: null]);

    expect(app(Jev::class)->platformEndpoint())->toBeNull();
})->group('PRJ-011');
