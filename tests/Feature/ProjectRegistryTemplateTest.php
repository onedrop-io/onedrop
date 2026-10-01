<?php

use App\Enums\AppTemplate;
use App\Enums\MessageRole;
use App\Jobs\ApplyRegistryTemplate;
use App\Jobs\CreateSandbox;
use App\Jobs\RunAgentTask;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;
use App\Sandbox\Templates\TemplateCatalog;
use App\Sandbox\Templates\TemplateException;
use App\Sandbox\WorkspaceFiles;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['sandbox.provider' => 'docker', 'sandbox.providers.docker.nested_docker' => 'privileged']);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->actingAs($this->user);
    $this->store = fn (array $data) => $this->post(route('projects.store', $this->user->currentOrganization()), $data);
});

test('the catalog lists the built-in templates, then each registry\'s, skipping ids that aren\'t folder names', function () {
    $templates = app(TemplateCatalog::class)->all();

    expect($templates)->toHaveCount(count(AppTemplate::cases()) + 2)
        ->and($templates[0])->toMatchArray(['value' => 'crm', 'registry' => null, 'compose' => false])
        ->and(collect($templates)->firstWhere('value', 'dokploy/n8n'))->toBe([
            'value' => 'dokploy/n8n',
            'label' => 'n8n',
            'description' => 'n8n is an open source low-code platform for automating workflows and integrations.',
            'prompt' => 'Set up n8n: n8n is an open source low-code platform for automating workflows and integrations.',
            'registry' => 'Dokploy',
            'logo' => 'https://dokploy.test/blueprints/n8n/n8n.png',
            'tags' => ['automation'],
            'compose' => true,
            'link' => 'https://n8n.io/',
        ]);
})->group('PRJ-012');

test('the new-project page loads the catalog only when asked, and says whether registry templates can run', function () {
    $this->followingRedirects()->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->component('projects/create')
            ->missing('catalog')
            ->where('compose', true)
            ->reloadOnly('catalog', fn ($reload) => $reload->has('catalog', count(AppTemplate::cases()) + 2)));

    config(['sandbox.providers.docker.nested_docker' => 'off']);

    $this->followingRedirects()->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('compose', false));
})->group('PRJ-012');

test('a registry that can\'t be reached leaves the built-in templates, and is asked again later', function () {
    config(['sandbox.template_registries.dokploy.url' => 'https://dokploy-down.test']);
    Http::fake(['dokploy-down.test/*' => Http::response('', 500)]);

    expect(app(TemplateCatalog::class)->all())->toHaveCount(count(AppTemplate::cases()));

    Http::assertSentCount(1);
    app(TemplateCatalog::class)->all();
    Http::assertSentCount(1);
})->group('PRJ-012');

test('popular templates are each registry\'s configured ids, in order, skipping ones it doesn\'t list', function () {
    config(['sandbox.template_registries.dokploy.popular' => ['n8n', 'missing', 'ghost']]);

    expect(collect(app(TemplateCatalog::class)->popular())->pluck('value')->all())->toBe(['dokploy/n8n', 'dokploy/ghost']);

    $this->followingRedirects()->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->missing('popular')
            ->loadDeferredProps(fn ($reload) => $reload->has('popular', 2)->where('popular.0.label', 'n8n')));
})->group('PRJ-012');

test('a registry with no url is off', function () {
    config(['sandbox.template_registries.dokploy.url' => '']);

    expect(app(TemplateCatalog::class)->registries())->toBe([]);
})->group('PRJ-012');

test('starting from a registry template names the project, writes its files, then starts the agent with how to set it up', function () {
    Queue::fake();

    ($this->store)(['prompt' => 'Set up n8n: workflows. Use New York time.', 'template' => 'dokploy/n8n'])->assertSessionHasNoErrors();

    $project = $this->user->projects()->sole();
    $message = $project->messages()->sole();

    expect($project->name)->toBe('n8n')
        ->and($message->content)->toBe('Set up n8n: workflows. Use New York time.')
        ->and($message->meta['template'])->toBe('dokploy/n8n')
        ->and($message->meta['agent_context'])->toContain('/workspace/.onedrop/template.toml')
        ->toContain('/opt/onedrop/compose init --preview');

    Queue::assertPushedWithChain(CreateSandbox::class, [ApplyRegistryTemplate::class, RunAgentTask::class]);
})->group('PRJ-012');

test('a built-in template still starts without a registry step', function () {
    Queue::fake();

    ($this->store)(['prompt' => AppTemplate::Crm->prompt(), 'template' => 'crm']);

    expect($this->user->projects()->sole()->messages()->sole()->meta)->toBeNull();
    Queue::assertPushedWithChain(CreateSandbox::class, [RunAgentTask::class]);
})->group('PRJ-012');

test('registry templates are refused when new sandboxes can\'t run Docker, and unknown ones always', function () {
    config(['sandbox.providers.docker.nested_docker' => 'off']);

    ($this->store)(['prompt' => 'Set up n8n.', 'template' => 'dokploy/n8n'])
        ->assertSessionHasErrors(['template' => 'n8n runs with Docker Compose, and new projects\' sandboxes can\'t run Docker. An admin can turn on Docker inside sandboxes in Settings → Sandboxes.']);
    ($this->store)(['prompt' => 'Set up n8n.', 'template' => 'dokploy/nope'])->assertSessionHasErrors('template');
    ($this->store)(['prompt' => 'Set up n8n.', 'template' => 'elsewhere/n8n'])->assertSessionHasErrors('template');

    expect(Project::count())->toBe(0);
})->group('PRJ-012');

test('the template\'s compose file and settings are written into the sandbox', function () {
    $provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $provider);

    $project = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($project)->create(['external_id' => 'ctr-1']);
    $message = $project->messages()->create(['role' => MessageRole::User, 'content' => 'Set up n8n.']);

    (new ApplyRegistryTemplate($project, $message, 'dokploy/n8n'))->handle(app(TemplateCatalog::class), app(WorkspaceFiles::class));

    $written = collect($provider->executed)->pluck('env.APP_CONTENT')->filter()->implode('');

    expect($written)->toContain('docker.n8n.io/n8nio/n8n')->toContain('[[config.domains]]')
        ->and(collect($provider->executed)->pluck('command')->flatten()->filter(fn ($part) => is_string($part) && str_starts_with($part, '/workspace/'))->unique()->values()->all())
        ->toContain('/workspace/.onedrop', '/workspace/docker-compose.yml', '/workspace/.onedrop/template.toml');
})->group('PRJ-012');

test('when the template\'s files can\'t be fetched, the chat says why and the agent doesn\'t start', function () {
    // Ghost is listed, but the registry sends none of its files.
    $project = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($project)->create(['external_id' => 'ctr-1']);
    $message = $project->messages()->create(['role' => MessageRole::User, 'content' => 'Set up n8n.']);

    $job = (new ApplyRegistryTemplate($project, $message, 'dokploy/ghost'))->withFakeQueueInteractions();
    $job->handle(app(TemplateCatalog::class), app(WorkspaceFiles::class));
    $job->assertFailed();
    $job->failed(new TemplateException('Dokploy didn\'t send the template\'s files. Try again in a moment.'));

    expect($project->messages()->where('role', MessageRole::Assistant)->sole())
        ->role->toBe(MessageRole::Assistant)
        ->content->toBe('Couldn\'t start from the template: Dokploy didn\'t send the template\'s files. Try again in a moment.');
})->group('PRJ-012');
