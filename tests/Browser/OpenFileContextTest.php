<?php

use App\Models\AgentConnection;
use App\Models\Message;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $provider = new FakeSandboxProvider;
    $provider->execUsing = fn (array $command) => $command[0] === 'find'
        ? new ExecResult(0, "d src\nf src/App.tsx\nf package.json\n")
        : new ExecResult(0, "export default function App() {}\n");
    app()->instance(SandboxProvider::class, $provider);
    app()->instance(AgentRunner::class, new FakeAgentRunner);

    $this->seed(DatabaseSeeder::class);
    $this->user = User::where('email', 'dev@example.com')->sole();
    AgentConnection::factory()->for($this->user)->create();
    $this->project = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($this->project)->create(['preview_url' => null]);
    $this->actingAs($this->user);
});

test('the open file shows above the chat box and the agent is told about it', function () {
    visit("/projects/{$this->project->id}")
        ->resize(1600, 900)
        ->navigate("/projects/{$this->project->id}")
        ->assertMissing('@composer-context-file')
        ->click('@file-src')
        ->click('[data-test="file-src/App.tsx"]')
        ->assertSeeIn('@composer-context-file', 'App.tsx')
        ->type('#composer-content', 'Rename this component')
        ->click('@composer-send')
        ->assertSee('Rename this component')
        ->assertSeeIn('@composer-context-file', 'App.tsx')
        ->assertNoJavaScriptErrors();

    $message = Message::where('content', 'Rename this component')->sole();

    expect($message->meta['agent_context'])->toBe('(The user has src/App.tsx open in the editor.)');
})->group('AGT-015');

test('a removed open file is not sent, until another file is opened', function () {
    $page = visit("/projects/{$this->project->id}")
        ->resize(1600, 900)
        ->navigate("/projects/{$this->project->id}")
        ->click('@file-src')
        ->click('[data-test="file-src/App.tsx"]')
        ->click('@remove-context-file')
        ->assertMissing('@composer-context-file')
        ->type('#composer-content', 'Just a question')
        ->click('@composer-send')
        ->assertSee('Just a question')
        ->assertMissing('@composer-context-file');

    expect(Message::where('content', 'Just a question')->sole()->meta['agent_context'] ?? null)->toBeNull();

    $page->click('[data-test="file-package.json"]')
        ->assertSeeIn('@composer-context-file', 'package.json')
        ->click('[data-test="tab-file"] + button[aria-label="Close tab"]')
        ->assertMissing('@composer-context-file')
        ->assertNoJavaScriptErrors();
})->group('AGT-015');
