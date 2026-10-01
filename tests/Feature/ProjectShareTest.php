<?php

use App\Actions\DeleteProject;
use App\Enums\MessageRole;
use App\Enums\PublishStatus;
use App\Enums\PublishVisibility;
use App\Enums\SandboxStatus;
use App\Enums\ShareCardStatus;
use App\Jobs\CaptureShareCard;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\ProjectShare;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;
use App\Sandbox\SandboxProvider;
use App\Sandbox\ShareCards;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/** A 1×1 PNG. */
const SHARE_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

/**
 * A sandbox whose share-card script works (or fails with $error), leaving app.png and card.png to copy out.
 */
function shareSandbox(?string $error = null): FakeSandboxProvider
{
    $provider = new class extends FakeSandboxProvider
    {
        public function copyOut(string $id, string $path, string $directory): void
        {
            parent::copyOut($id, $path, $directory);
            File::put("{$directory}/app.png", base64_decode(SHARE_PNG));
            File::put("{$directory}/card.png", base64_decode(SHARE_PNG));
        }
    };
    $provider->execUsing = fn (array $command) => $error ? new ExecResult(1, '', $error) : new ExecResult(0, '');
    app()->instance(SandboxProvider::class, $provider);

    return $provider;
}

function shareCardCall(FakeSandboxProvider $provider): ?array
{
    return collect($provider->executed)->firstWhere(fn ($call) => $call['command'][0] === '/opt/onedrop/share-card');
}

beforeEach(function () {
    Storage::fake(ShareCards::disk());
    app()->instance(Publisher::class, new FakePublisher);
    $this->user = User::factory()->has(AgentConnection::factory())->create(['name' => 'Jeff Loiselle']);
    $this->project = Project::factory()->for($this->user)->create(['name' => 'Team CRM', 'prompt' => 'A CRM for our sales team']);
    $this->sandbox = Sandbox::factory()->for($this->project)->create(['external_id' => 'sbx-1']);
});

test('the owner can share a project, which starts making its card', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->post(route('projects.share.store', $this->project), ['prompt' => '  A CRM for our sales team  ', 'page_path' => '/deals'])
        ->assertRedirect(route('projects.show', $this->project));

    $share = $this->project->share()->first();

    expect($share->slug)->toMatch('/^team-crm-[a-z0-9]{8}$/')
        ->and($share->prompt)->toBe('A CRM for our sales team')
        ->and($share->page_path)->toBe('/deals')
        ->and($share->card_status)->toBe(ShareCardStatus::Capturing);

    Queue::assertPushed(CaptureShareCard::class, fn ($job) => $job->share->is($share));
})->group('SHARE-001');

test('the page to screenshot must be a path in the app', function (string $path) {
    $this->actingAs($this->user)
        ->post(route('projects.share.store', $this->project), ['prompt' => 'Hi', 'page_path' => $path])
        ->assertSessionHasErrors('page_path');

    expect($this->project->share()->exists())->toBeFalse();
})->with(['dashboard', '//evil.test', '/a b', 'https://evil.test/'])->group('SHARE-001');

test('only the owner can share, change, or stop sharing a project', function () {
    $share = ProjectShare::factory()->for($this->project)->create();
    $other = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($other)->post(route('projects.share.store', $this->project), ['prompt' => 'Mine now'])->assertForbidden();
    $this->actingAs($other)->post(route('projects.share.refresh', $this->project))->assertForbidden();
    $this->actingAs($other)->delete(route('projects.share.destroy', $this->project))->assertForbidden();

    expect($share->fresh()->prompt)->not->toBe('Mine now');
})->group('SHARE-001');

test('saving an unchanged share keeps its card; a new prompt or page makes a new one', function () {
    Queue::fake();
    $share = ProjectShare::factory()->for($this->project)->create([
        'prompt' => 'Same', 'page_path' => '/', 'card_file' => 'x.png', 'card_status' => ShareCardStatus::Ready,
    ]);

    $this->actingAs($this->user)->post(route('projects.share.store', $this->project), ['prompt' => 'Same', 'page_path' => '/']);
    Queue::assertNothingPushed();

    $this->actingAs($this->user)->post(route('projects.share.store', $this->project), ['prompt' => 'Different', 'page_path' => '/']);
    Queue::assertPushed(CaptureShareCard::class, 1);
    expect($share->fresh()->card_status)->toBe(ShareCardStatus::Capturing)
        ->and($share->fresh()->slug)->toBe($share->slug);
})->group('SHARE-001');

test('the owner can refresh the card', function () {
    Queue::fake();
    ProjectShare::factory()->for($this->project)->create(['card_status' => ShareCardStatus::Failed, 'card_error' => 'Nope']);

    $this->actingAs($this->user)->post(route('projects.share.refresh', $this->project))->assertRedirect();

    expect($this->project->share->fresh())
        ->card_status->toBe(ShareCardStatus::Capturing)
        ->card_error->toBeNull();
    Queue::assertPushed(CaptureShareCard::class);
})->group('SHARE-001');

test('making the card screenshots the chosen page in the sandbox and keeps both images', function () {
    $provider = shareSandbox();
    $share = ProjectShare::factory()->for($this->project)->create(['prompt' => 'A CRM <with> "quotes"', 'page_path' => '/deals']);

    CaptureShareCard::dispatchSync($share);

    $call = shareCardCall($provider);
    $share->refresh();

    expect($call['id'])->toBe('sbx-1')
        ->and($call['command'])->toBe(['/opt/onedrop/share-card', '/deals'])
        ->and($call['env']['CARD_HTML'])->toContain('A CRM &lt;with&gt; &quot;quotes&quot;')
        ->and($call['env']['CARD_HTML'])->toContain('Team CRM')
        ->and($call['env']['CARD_HTML'])->toContain('by Jeff')
        ->and($call['env']['CARD_HTML'])->toContain('src="app.png"')
        ->and($provider->copied[0])->toMatchArray(['out', 'sbx-1', ShareCards::SANDBOX_DIRECTORY])
        ->and($share->card_status)->toBe(ShareCardStatus::Ready)
        ->and($share->captured_at)->not->toBeNull();

    Storage::disk(ShareCards::disk())->assertExists([$share->card_file, $share->screenshot_file]);
})->group('SHARE-001');

test('cards are kept on the app\'s default disk, which every instance can read', function () {
    shareSandbox();
    Storage::fake('shared');
    config(['filesystems.default' => 'shared']);
    $share = ProjectShare::factory()->for($this->project)->create();

    CaptureShareCard::dispatchSync($share);

    Storage::disk('shared')->assertExists($share->fresh()->card_file);
    $this->get($share->fresh()->cardUrl())->assertOk()->assertHeader('Content-Type', 'image/png');
})->group('SHARE-001');

test('a new card replaces the old images', function () {
    shareSandbox();
    Storage::disk(ShareCards::disk())->put("project-shares/{$this->project->id}/old-card.png", 'old');
    $share = ProjectShare::factory()->for($this->project)->create(['card_file' => "project-shares/{$this->project->id}/old-card.png"]);

    CaptureShareCard::dispatchSync($share);

    Storage::disk(ShareCards::disk())->assertMissing("project-shares/{$this->project->id}/old-card.png");
    Storage::disk(ShareCards::disk())->assertExists($share->fresh()->card_file);
})->group('SHARE-001');

test('a card that cannot be made says why', function () {
    shareSandbox(error: "Couldn't take a screenshot of the app. Is it running?");
    $share = ProjectShare::factory()->for($this->project)->create(['card_status' => ShareCardStatus::Capturing]);

    CaptureShareCard::dispatchSync($share);

    expect($share->fresh())
        ->card_status->toBe(ShareCardStatus::Failed)
        ->card_error->toBe("Couldn't take a screenshot of the app. Is it running?");
})->group('SHARE-001');

test('a card needs the sandbox running', function () {
    $provider = shareSandbox();
    $this->sandbox->update(['status' => SandboxStatus::Paused]);
    $share = ProjectShare::factory()->for($this->project)->create();

    CaptureShareCard::dispatchSync($share);

    expect($share->fresh()->card_status)->toBe(ShareCardStatus::Failed)
        ->and($share->fresh()->card_error)->toBe("The project's sandbox isn't running.")
        ->and(shareCardCall($provider))->toBeNull();
})->group('SHARE-001');

test('the workspace shows the share panel', function () {
    $this->actingAs($this->user)
        ->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page
            ->where('sharing.shared', false)
            ->where('sharing.prompt', 'A CRM for our sales team')
            ->where('sharing.page_path', '/'));

    $share = ProjectShare::factory()->for($this->project)->create([
        'prompt' => 'Shown', 'views' => 12, 'remixes' => 3, 'card_file' => 'c.png', 'card_status' => ShareCardStatus::Ready, 'captured_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page
            ->where('sharing.shared', true)
            ->where('sharing.prompt', 'Shown')
            ->where('sharing.url', route('shares.show', $share))
            ->where('sharing.card_url', $share->cardUrl())
            ->where('sharing.card_status', 'ready')
            ->where('sharing.views', 12)
            ->where('sharing.remixes', 3));
})->group('SHARE-001');

test('anyone can see a shared project, and nothing else from its chat', function () {
    $share = ProjectShare::factory()->for($this->project)->create(['prompt' => 'The shared prompt']);
    $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'A CRM for our sales team']);
    $this->project->messages()->create(['role' => MessageRole::Assistant, 'content' => 'Secret agent reply']);
    $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'Add a secret pipeline']);

    $this->get(route('shares.show', $share))
        ->assertOk()
        ->assertDontSee('Secret agent reply')
        ->assertDontSee('Add a secret pipeline')
        ->assertInertia(fn ($page) => $page
            ->component('share/show')
            ->where('share.name', 'Team CRM')
            ->where('share.author', 'Jeff')
            ->where('share.prompt', 'The shared prompt')
            ->where('share.prompts', 2)
            ->where('share.app_url', null)
            ->where('workspaceUrl', null));
})->group('SHARE-001');

test('the share page links to the app only while it is published as public', function (PublishVisibility $visibility, PublishStatus $status, bool $linked) {
    $share = ProjectShare::factory()->for($this->project)->create();
    $this->project->update(['publish_status' => $status, 'publish_visibility' => $visibility, 'published_url' => 'https://team-crm.example.ts.net']);

    $this->get(route('shares.show', $share))
        ->assertInertia(fn ($page) => $page->where('share.app_url', $linked ? 'https://team-crm.example.ts.net' : null));
})->with([
    'public and live' => [PublishVisibility::Public, PublishStatus::Live, true],
    'private' => [PublishVisibility::Private, PublishStatus::Live, false],
    'still publishing' => [PublishVisibility::Public, PublishStatus::Publishing, false],
])->group('SHARE-001');

test('link previews show the card, or OneDrop\'s own card until it is ready', function () {
    $share = ProjectShare::factory()->for($this->project)->create(['prompt' => 'A CRM for our sales team']);

    $this->get(route('shares.show', $share))
        ->assertSee('<meta property="og:title" content="Team CRM, built with OneDrop">', false)
        ->assertSee('<meta property="og:description" content="A CRM for our sales team">', false)
        ->assertSee('<meta property="og:image" content="'.asset('images/og.png').'">', false);

    $share->update(['card_file' => 'c.png', 'captured_at' => now()]);

    $this->get(route('shares.show', $share))
        ->assertSee('<meta property="og:image" content="'.e($share->cardUrl()).'">', false)
        ->assertSee('<meta name="twitter:image" content="'.e($share->cardUrl()).'">', false);
})->group('SHARE-001');

test('the card and screenshot are served publicly', function () {
    Storage::disk(ShareCards::disk())->put('project-shares/1/card.png', base64_decode(SHARE_PNG));
    $share = ProjectShare::factory()->for($this->project)->create(['card_file' => 'project-shares/1/card.png', 'captured_at' => now()]);

    $this->get(route('shares.card', $share))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
    $this->get(route('shares.screenshot', $share))->assertNotFound();
})->group('SHARE-001');

test('views count once per visitor, not for the owner or link previews', function () {
    $share = ProjectShare::factory()->for($this->project)->create();

    $this->get(route('shares.show', $share));
    $this->get(route('shares.show', $share));
    $this->withHeader('User-Agent', 'Slackbot-LinkExpanding 1.0')->get(route('shares.show', $share));
    $this->actingAs($this->user)->get(route('shares.show', $share))
        ->assertInertia(fn ($page) => $page->where('workspaceUrl', route('projects.show', $this->project)));

    expect($share->fresh()->views)->toBe(1);
})->group('SHARE-001');

test('stopping sharing takes the page and its images down, and sharing again gives a new link', function () {
    Queue::fake();
    Storage::disk(ShareCards::disk())->put("project-shares/{$this->project->id}/card.png", 'png');
    $share = ProjectShare::factory()->for($this->project)->create(['card_file' => "project-shares/{$this->project->id}/card.png"]);

    $this->actingAs($this->user)->delete(route('projects.share.destroy', $this->project))->assertRedirect();

    $this->get(route('shares.show', $share->slug))->assertNotFound();
    $this->get(route('shares.card', $share->slug))->assertNotFound();
    Storage::disk(ShareCards::disk())->assertMissing("project-shares/{$this->project->id}/card.png");

    $this->actingAs($this->user)->post(route('projects.share.store', $this->project), ['prompt' => 'Again'])->assertSessionHasNoErrors()->assertRedirect();
    expect($this->project->share()->first()->slug)->not->toBe($share->slug);
})->group('SHARE-001');

test('deleting a project deletes its share page and images', function () {
    Storage::disk(ShareCards::disk())->put("project-shares/{$this->project->id}/card.png", 'png');
    ProjectShare::factory()->for($this->project)->create();

    app(DeleteProject::class)->handle($this->project);

    expect(ProjectShare::count())->toBe(0);
    Storage::disk(ShareCards::disk())->assertMissing("project-shares/{$this->project->id}/card.png");
})->group('SHARE-001');

test('a visitor who remixes signs up, then gets the prompt on the new-project page', function () {
    $share = ProjectShare::factory()->for($this->project)->create(['prompt' => 'Remix me']);

    $this->post(route('shares.remix', $share))->assertRedirect(route('register'));

    expect($share->fresh()->remixes)->toBe(1)
        ->and(session('url.intended'))->toBe(route('dashboard'))
        ->and(session('remix'))->toBe(['name' => 'Team CRM', 'prompt' => 'Remix me']);

    $visitor = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($visitor)->followingRedirects()->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('remix', ['name' => 'Team CRM', 'prompt' => 'Remix me']));

    // Only once: the next visit starts empty.
    $this->actingAs($visitor)->followingRedirects()->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('remix', null));
})->group('SHARE-002');

test('a signed-in user who remixes goes straight to the new-project page', function () {
    $share = ProjectShare::factory()->for($this->project)->create(['prompt' => 'Remix me']);
    $visitor = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($visitor)->post(route('shares.remix', $share))->assertRedirect(route('dashboard'));

    $this->actingAs($visitor)->followingRedirects()->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('remix.prompt', 'Remix me'));
})->group('SHARE-002');
