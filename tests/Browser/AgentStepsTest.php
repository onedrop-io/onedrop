<?php

use App\Enums\BuildMode;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\AgentStream;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->user = User::where('email', 'dev@example.com')->sole();
    AgentConnection::factory()->for($this->user)->create();
    $this->project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Working]);
    Sandbox::factory()->for($this->project)->create(['preview_url' => null]);

    $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'Add a test']);
    $this->project->messages()->create([
        'role' => MessageRole::Activity,
        'content' => 'Thinking',
        'meta' => ['step' => ['kind' => 'thinking'], 'blocks' => ['r-1']],
        'detail' => ['text' => 'The **tests** folder needs one.'],
    ]);
    $this->project->messages()->create([
        'role' => MessageRole::Activity,
        'content' => 'Running Run the tests',
        'meta' => ['step' => ['kind' => 'tool', 'id' => 't-1', 'status' => 'failed']],
        'detail' => ['command' => 'npm test', 'error' => '1 test failed'],
    ]);
    $this->project->messages()->create([
        'role' => MessageRole::Activity,
        'content' => 'Editing src/app.test.js',
        'meta' => ['step' => ['kind' => 'tool', 'id' => 't-2', 'status' => 'running']],
        'detail' => ['path' => 'src/app.test.js', 'diff' => "@@ -1,1 +1,1 @@\n-expect(1).toBe(2)\n+expect(1).toBe(1)"],
    ]);
    app(AgentStream::class)->append($this->project, 'r-2', 'text', "Fixed the test. Here's the **summ");
});

test('the agent\'s steps expand to what they did, and the reply being written shows as it is written', function () {
    $this->actingAs($this->user);

    visit("/projects/{$this->project->id}")
        // Half-written bold shows without its asterisks.
        ->assertSeeIn('@message-streaming', "Here's the summ")
        ->assertDontSeeIn('@message-streaming', '**')
        ->assertVisible('[aria-label="Running"]')
        ->assertVisible('[aria-label="Failed"]')
        ->click('Running Run the tests')
        ->assertSeeIn('@step-detail', 'npm test')
        ->assertSeeIn('@step-detail', '1 test failed')
        ->click('[data-test="step-toggle"]:has-text("app.test.js")')
        ->assertSeeIn('[data-test="git-diff"]', 'expect(1).toBe(1)')
        ->click('[data-test="step-toggle"]:has-text("Thinking")')
        ->assertSeeIn('[data-test="step-detail"] strong', 'tests')
        ->assertNoJavaScriptErrors();
})->group('AGT-017');

test('in Simple mode steps stay plain lines that don\'t expand', function () {
    $this->user->forceFill(['build_mode' => BuildMode::Simple])->save();
    $this->actingAs($this->user);

    visit("/projects/{$this->project->id}")
        ->assertVisible('@message-activity')
        ->assertMissing('@step-toggle')
        ->assertSeeIn('@message-streaming', 'Fixed the test.')
        ->assertNoJavaScriptErrors();
})->group('AGT-017');
