<?php

use App\Enums\AgentProvider;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Jobs\RunAgentTask;
use App\Models\AgentConnection;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\Agents\ModelCatalog;
use App\Sandbox\Agents\OpenCodeRunner;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(Attachment::disk());
    Queue::fake();

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create();
    $this->actingAs($this->user);
});

test('a message can carry images and files, with or without text', function () {
    $this->post(route('projects.messages.store', $this->project), [
        'content' => '',
        'attachments' => [UploadedFile::fake()->image('logo.png'), UploadedFile::fake()->createWithContent('notes.txt', 'hello')],
    ])->assertRedirect(route('projects.show', $this->project));

    $message = $this->project->messages()->sole();
    [$logo, $notes] = $message->attachments;

    expect($message->content)->toBe('')
        ->and($logo->only('name', 'mime_type'))->toBe(['name' => 'logo.png', 'mime_type' => 'image/png'])
        ->and($logo->isVisibleImage())->toBeTrue()
        ->and($notes->isVisibleImage())->toBeFalse()
        ->and($notes->contents())->toBe('hello');
    Storage::disk(Attachment::disk())->assertExists([$logo->path, $notes->path]);
    Queue::assertPushed(RunAgentTask::class, fn ($job) => $job->message->is($message));
})->group('AGT-006');

test('a message needs text or an attachment, and attachments have limits', function (array $data, string $error) {
    $this->post(route('projects.messages.store', $this->project), $data)->assertSessionHasErrors($error);

    expect(Message::count())->toBe(0);
})->with([
    'nothing' => [['content' => ''], 'content'],
    'too many' => [fn () => ['content' => 'hi', 'attachments' => array_fill(0, 11, UploadedFile::fake()->create('a.txt', 1))], 'attachments'],
    'too big' => [fn () => ['content' => 'hi', 'attachments' => [UploadedFile::fake()->create('big.zip', 10_241)]], 'attachments.0'],
])->group('AGT-006');

test('a new project can start from an attached mockup', function () {
    $this->post(route('projects.store', $this->user->currentOrganization()), [
        'prompt' => 'build this',
        'attachments' => [UploadedFile::fake()->image('mockup.png')],
    ])->assertRedirect();

    expect(Project::latest('id')->first()->messages()->sole()->attachments->pluck('name')->all())->toBe(['mockup.png']);
})->group('AGT-006');

test('the chat shows attachments with a link only the project\'s viewers can open', function () {
    $message = $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'use this']);
    $image = Attachment::factory()->for($message)->create(['name' => 'logo.png']);
    $text = Attachment::factory()->for($message)->text('<script>alert(1)</script>')->create(['name' => 'page.html', 'mime_type' => 'text/html']);

    $this->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page
            ->where('messages.0.attachments.0.name', 'logo.png')
            ->where('messages.0.attachments.0.image', true)
            ->where('messages.0.attachments.0.url', route('projects.attachments.show', [$this->project, $image]))
            ->where('messages.0.attachments.1.image', false));

    $this->get(route('projects.attachments.show', [$this->project, $image]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Content-Security-Policy', 'sandbox')
        ->assertHeader('Content-Disposition', 'inline; filename=logo.png');

    // Anything but a plain image downloads, never renders.
    $this->get(route('projects.attachments.show', [$this->project, $text]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream')
        ->assertHeader('Content-Disposition', 'attachment; filename=page.html');

    $other = Project::factory()->for($this->user)->create();
    $this->get(route('projects.attachments.show', [$other, $image]))->assertNotFound();

    $this->actingAs(User::factory()->has(AgentConnection::factory())->create())
        ->get(route('projects.attachments.show', [$this->project, $image]))
        ->assertForbidden();
})->group('AGT-006');

test('a queued message keeps its attachments when it runs', function () {
    app()->instance(AgentRunner::class, new FakeAgentRunner);
    $this->project->update(['status' => ProjectStatus::Working]);

    $queued = app(AgentQueue::class)->send($this->project, 'add this logo', attachments: [UploadedFile::fake()->image('logo.png')]);
    $path = $queued->attachments()->sole()->path;

    app(AgentQueue::class)->finished($this->project->fresh());

    $message = $this->project->messages()->sole();

    expect(Message::find($queued->id))->toBeNull()
        ->and($message->content)->toBe('add this logo')
        ->and($message->attachments()->sole()->path)->toBe($path);
    Storage::disk(Attachment::disk())->assertExists($path);
})->group('AGT-006');

test('stopping drops attachments on queued messages and says so', function () {
    app()->instance(AgentRunner::class, new FakeAgentRunner);
    $this->project->update(['status' => ProjectStatus::Working]);
    $queued = app(AgentQueue::class)->send($this->project, 'add this logo', attachments: [UploadedFile::fake()->image('logo.png')]);
    $path = $queued->attachments()->sole()->path;

    $this->post(route('projects.agent.stop', $this->project))
        ->assertInertiaFlash('draft', 'add this logo')
        ->assertInertiaFlash('toast.message', 'The queued attachment was removed; attach it again to send it.');

    expect(Attachment::count())->toBe(0);
    Storage::disk(Attachment::disk())->assertMissing($path);
})->group('AGT-006');

describe('the agent', function () {
    beforeEach(function () {
        $this->provider = new FakeSandboxProvider;
        app()->instance(SandboxProvider::class, $this->provider);
        $this->user->agentConnections()->delete();
        AgentConnection::factory()->for($this->user)->provider(AgentProvider::OpenRouter)->create(['credential' => 'sk-or-key']);
        Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
        $this->project->update(['status' => ProjectStatus::Working]);
    });

    function startWith(Project $project, string $content, array $attachments): array
    {
        $message = $project->messages()->create(['role' => MessageRole::User, 'content' => $content]);

        foreach ($attachments as $factory) {
            $factory->for($message)->create();
        }

        app(OpenCodeRunner::class)->start($project->fresh(), $message->fresh());

        $provider = app(SandboxProvider::class);

        return [$message, collect($provider->executed)->firstWhere('command', ['node', '/opt/onedrop/forwarder.mjs'])['env'] ?? null, $provider];
    }

    test('gets the files in the workspace and sees the images', function () {
        $this->project->update(['agent_provider' => AgentProvider::OpenRouter, 'agent_model' => 'anthropic/claude-sonnet-5']);

        [$message, $env, $provider] = startWith($this->project, 'put the logo in the header', [
            Attachment::factory()->state(['name' => 'My Logo.png']),
            Attachment::factory()->state(['name' => 'My Logo.png']),
            Attachment::factory()->text('brand colors')->state(['name' => 'brand.txt']),
        ]);

        $dir = "/workspace/.onedrop/attachments/{$message->id}";
        $written = collect($provider->executed)->pluck('env.APP_CONTENT')->filter()->map(base64_decode(...))->all();

        expect(json_decode($env['APP_FILES']))->toBe(["{$dir}/My-Logo.png", "{$dir}/My-Logo-2.png"])
            ->and($env['APP_MODEL'])->toBe('openrouter/anthropic/claude-sonnet-5')
            ->and($env['APP_PROMPT'])->toStartWith("put the logo in the header\n\n---\nAttached files")
            ->and($env['APP_PROMPT'])->toContain("- {$dir}/brand.txt (text/plain, 12 B)")
            ->and($written)->toContain('brand colors', "\x89PNG");
    })->group('AGT-006');

    test('switches to a vision model for images when the chosen one can\'t see them', function () {
        $this->project->update(['agent_provider' => AgentProvider::OpenRouter, 'agent_model' => 'moonshotai/kimi-k3', 'agent_variant' => 'high']);

        [, $env] = startWith($this->project, 'match this', [Attachment::factory()]);

        expect($env['APP_MODEL'])->toBe('openrouter/anthropic/claude-sonnet-5')
            ->and($env['APP_VARIANT'])->toBe('')
            ->and(json_decode($env['APP_FILES']))->toHaveCount(1)
            ->and($this->project->messages()->where('role', MessageRole::Activity)->value('content'))->toBe('Looking at your images with Anthropic: Claude Sonnet 5')
            ->and($this->project->fresh()->agent_model)->toBe('moonshotai/kimi-k3');
    })->group('AGT-006');

    test('works from the text when no model of the provider can see images', function () {
        config(['sandbox.featured_models.openrouter' => []]);
        $this->project->update(['agent_provider' => AgentProvider::OpenRouter, 'agent_model' => 'moonshotai/kimi-k3']);
        $catalog = json_decode(file_get_contents(base_path('tests/Fixtures/models-dev.json')), true);
        $catalog['openrouter']['models']['anthropic/claude-sonnet-5']['modalities']['input'] = ['text'];
        cache()->put(ModelCatalog::CACHE_KEY, $catalog, now()->addHour());

        [, $env] = startWith($this->project, 'match this', [Attachment::factory()]);

        expect($env['APP_MODEL'])->toBe('openrouter/moonshotai/kimi-k3')
            ->and($env['APP_FILES'])->toBe('[]')
            ->and($env['APP_PROMPT'])->toContain('/workspace/.onedrop/attachments/')
            ->and($this->project->messages()->where('role', MessageRole::Activity)->value('content'))->toBe("MoonshotAI: Kimi K3 can't see images, so I'm working from your text");
    })->group('AGT-006');

    test('messages without attachments run as before', function () {
        [, $env] = startWith($this->project, 'build a timer', []);

        expect($env['APP_PROMPT'])->toBe('build a timer')->and($env['APP_FILES'])->toBe('[]');
    })->group('AGT-006');
});
