<?php

use App\Enums\MessageRole;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->user = User::where('email', 'dev@example.com')->sole();
    AgentConnection::factory()->for($this->user)->create();
    $this->project = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($this->project)->create(['preview_url' => null]);
});

test('agent replies render as markdown with highlighted code and user messages stay plain', function () {
    $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'make it **bold**']);
    $this->project->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => "## Done\n\nI added **tags** and [docs](https://example.com).\n\n- `npm run dev`\n- <b>raw</b>\n\n```php\necho 'hi';\n```",
    ]);

    $this->actingAs($this->user);

    visit("/projects/{$this->project->id}")
        ->assertSeeIn('[data-test="message-assistant"] h2', 'Done')
        ->assertSeeIn('[data-test="message-assistant"] strong', 'tags')
        ->assertAttribute('[data-test="message-assistant"] a', 'target', '_blank')
        ->assertSeeIn('[data-test="message-assistant"] li code', 'npm run dev')
        ->assertSeeIn('[data-test="message-assistant"] pre', "echo 'hi';")
        ->assertSeeIn('[data-test="message-assistant"] pre .tok-string', "'hi'")
        ->assertSeeIn('@message-assistant', '<b>raw</b>')
        ->assertSeeIn('@message-user', 'make it **bold**')
        ->assertNoJavaScriptErrors();
})->group('AGT-005');
