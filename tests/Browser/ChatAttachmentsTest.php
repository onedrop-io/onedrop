<?php

use App\Models\AgentConnection;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    app()->instance(AgentRunner::class, new FakeAgentRunner);

    $this->seed(DatabaseSeeder::class);
    $this->user = User::where('email', 'dev@example.com')->sole();
    AgentConnection::factory()->for($this->user)->create();
    $this->project = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($this->project)->create(['preview_url' => null]);
    $this->actingAs($this->user);

    $this->image = sys_get_temp_dir().'/zap-logo.png';
    imagepng(imagecreatetruecolor(8, 8), $this->image);
    $this->notes = sys_get_temp_dir().'/zap-notes.txt';
    file_put_contents($this->notes, 'brand colors');
});

test('the user pastes and picks files, sees and removes them, and sees sent images in the chat', function () {
    $message = $this->project->messages()->create(['role' => 'user', 'content' => 'here is the logo']);
    $sent = Attachment::factory()->for($message)->create(['name' => 'sent-logo.png', 'path' => 'attachments/sent-logo']);
    Storage::disk(Attachment::disk())->put($sent->path, file_get_contents($this->image));

    $page = visit("/projects/{$this->project->id}")
        ->attach('[data-test="composer-file-input"]', $this->notes);

    // Paste an image, as when a screenshot is on the clipboard.
    $page->script(<<<'JS'
        () => {
            const bytes = Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='), (c) => c.charCodeAt(0));
            const data = new DataTransfer();
            data.items.add(new File([bytes], 'pasted.png', { type: 'image/png' }));
            document.querySelector('#composer-content').dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
        }
        JS);

    $page->assertCount('form [data-test="attachment"]', 2)
        ->assertVisible('form img[alt="pasted.png"]')
        ->assertVisible('[aria-label="Remove zap-notes.txt"]')
        ->click('[aria-label="Remove zap-notes.txt"]')
        ->assertCount('form [data-test="attachment"]', 1)
        ->assertEnabled('@composer-send')
        ->assertVisible('[data-test="message-user"] img[alt="sent-logo.png"]')
        ->assertScript('document.querySelector(\'[data-test="message-user"] img\').naturalWidth', 8)
        ->assertNoJavaScriptErrors();

    // Pest's browser server doesn't pass multipart uploads through yet; AttachmentTest covers sending them.
})->group('AGT-006');
