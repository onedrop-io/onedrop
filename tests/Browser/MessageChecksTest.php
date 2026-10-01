<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\Agents\Jev;
use App\Sandbox\ExecResult;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.openrouter.key' => 'sk-or-system']);

    $this->workspace = databaseWorkspace();
    $provider = fakeDatabaseSandbox($this->workspace);
    $secrets = $provider->execUsing;
    // REQ.md has one decision; everything else (Secrets) goes to the real scripts.
    $provider->execUsing = fn (array $command, array $env) => $command[0] === 'cat'
        ? new ExecResult(0, "## Data\n\n### Decisions\n- 2026-09-30: **Use SQLite for the database, not Postgres.** One file is easier to back up.\n")
        : $secrets($command, $env);
    app()->instance(SandboxProvider::class, $provider);
    app()->instance(AgentRunner::class, new FakeAgentRunner);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($this->project)->create(['preview_url' => null]);
    $this->actingAs($this->user);
});

test('a message that changes an earlier decision waits for Go ahead, and Cancel keeps it in the box', function () {
    Http::fake([Jev::URL => Http::response(['answers' => [
        'secret' => ['type' => 'noul', 'noul' => 0.01],
        'decision' => ['type' => 'choice', 'choice' => 'd0', 'confidence' => 0.97, 'probabilities' => ['d0' => 0.97, 'none' => 0.03]],
    ]])]);

    $page = visit("/projects/{$this->project->id}")
        ->fill('#composer-content', 'Switch the database to Postgres')
        ->keys('#composer-content', 'Enter')
        ->assertSeeIn('@held-decision', 'This changes an earlier decision: Use SQLite for the database, not Postgres. Go ahead?')
        ->assertValue('#composer-content', 'Switch the database to Postgres')
        ->click('@held-cancel')
        ->assertMissing('@held-decision')
        ->assertValue('#composer-content', 'Switch the database to Postgres');

    expect($this->project->messages()->count())->toBe(0);

    $page->keys('#composer-content', 'Enter')
        ->assertVisible('@held-decision')
        ->click('@held-go-ahead')
        ->assertSeeIn('@message-user', 'Switch the database to Postgres')
        ->assertMissing('@held-decision')
        ->assertValue('#composer-content', '')
        ->assertNoJavaScriptErrors();

    expect($this->project->messages()->where('role', 'user')->sole()->meta)
        ->toBe(['changes_decision' => 'Use SQLite for the database, not Postgres.']);
})->group('REQ-003');

test('a pasted secret is saved in Secrets and the message refers to it instead', function () {
    Http::fake([Jev::URL => Http::response(['answers' => [
        'secret' => ['type' => 'noul', 'noul' => 0.93],
        'decision' => ['type' => 'choice', 'choice' => 'none', 'confidence' => 0.99, 'probabilities' => ['d0' => 0.01, 'none' => 0.99]],
    ]])]);

    visit("/projects/{$this->project->id}")
        ->fill('#composer-content', 'Add payments with '.fakeStripeKey())
        ->keys('#composer-content', 'Enter')
        ->assertSeeIn('@held-secret', 'This looks like a secret')
        ->assertSeeIn('@held-secret', 'Save it in Secrets instead?')
        ->assertValue('@held-secret-name', 'STRIPE_SECRET_KEY')
        // The name field is focused, so a new name can be typed straight away.
        ->assertScript('document.activeElement?.dataset.test', 'held-secret-name')
        ->click('@held-save-secret')
        ->assertSeeIn('@message-user', 'Add payments with (saved as STRIPE_SECRET_KEY in Secrets)')
        ->assertDontSeeIn('@message-user', 'sk_live_51')
        ->assertMissing('@held-secret')
        ->assertNoJavaScriptErrors();

    expect(file_get_contents($this->workspace.'/.env'))->toContain('STRIPE_SECRET_KEY='.fakeStripeKey());
})->group('SECRET-002');

test('a secret can be sent anyway', function () {
    Http::fake([Jev::URL => Http::response(['answers' => [
        'secret' => ['type' => 'noul', 'noul' => 0.93],
        'decision' => ['type' => 'choice', 'choice' => 'none', 'confidence' => 0.99, 'probabilities' => ['d0' => 0.01, 'none' => 0.99]],
    ]])]);

    visit("/projects/{$this->project->id}")
        ->fill('#composer-content', 'the admin password is hunter2-Blue-77')
        ->keys('#composer-content', 'Enter')
        ->assertValue('@held-secret-name', 'ADMIN_PASSWORD')
        ->click('@held-send-anyway')
        ->assertSeeIn('@message-user', 'the admin password is hunter2-Blue-77')
        ->assertNoJavaScriptErrors();
})->group('SECRET-002');

test('Auto can be picked in the model menu', function () {
    visit("/projects/{$this->project->id}")
        ->click('@model-picker')
        ->click('@model-auto')
        ->assertSeeIn('@model-picker', 'Auto')
        ->assertMissing('@reasoning-picker')
        ->assertNoJavaScriptErrors();

    expect($this->project->fresh()->agent_auto)->toBeTrue();
})->group('AGT-011');
