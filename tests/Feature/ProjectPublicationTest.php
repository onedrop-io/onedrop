<?php

use App\Enums\PublishStatus;
use App\Enums\PublishVisibility;
use App\Enums\SandboxStatus;
use App\Jobs\ConfirmPublication;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Publishing\FakePublisher;
use App\Sandbox\Publishing\Publisher;
use App\Sandbox\Publishing\PublishException;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->publisher = new FakePublisher;
    app()->instance(Publisher::class, $this->publisher);

    $this->user = User::factory()->has(AgentConnection::factory())->create(['name' => 'Jeff']);
    $this->project = Project::factory()->for($this->user)->create(['name' => 'Time Tracker']);
    Sandbox::factory()->for($this->project)->create();
});

test('publishing makes the project live at its own url', function (string $visibility) {
    $this->actingAs($this->user)
        ->post(route('projects.publication.store', $this->project), ['visibility' => $visibility])
        ->assertRedirect(route('projects.show', $this->project));

    $project = $this->project->fresh();

    expect($project->publish_status)->toBe(PublishStatus::Live)
        ->and($project->publish_visibility)->toBe(PublishVisibility::from($visibility))
        ->and($project->published_url)->toBe("https://time-tracker-{$project->id}.example.ts.net")
        ->and($project->published_at)->not->toBeNull()
        ->and($project->publisher->is($this->user))->toBeTrue()
        ->and($this->publisher->published[$project->id])->toBe(PublishVisibility::from($visibility));
})->with(['private', 'public'])->group('PUB-001');

test('the workspace shows the publication', function () {
    $this->project->update([
        'publish_status' => PublishStatus::Live,
        'publish_visibility' => PublishVisibility::Public,
        'published_url' => 'https://x.ts.net',
        'published_at' => now(),
        'published_by' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page
            ->where('publication.status', 'live')
            ->where('publication.visibility', 'public')
            ->where('publication.url', 'https://x.ts.net')
            ->where('publication.published_by', 'Jeff')
            ->where('publication.unavailable', null));
})->group('PUB-001');

test('publishing explains when it is not set up', function () {
    $this->publisher->unavailable = 'Publishing needs Tailscale.';

    $this->actingAs($this->user)
        ->post(route('projects.publication.store', $this->project), ['visibility' => 'public'])
        ->assertSessionHasErrors(['publish' => 'Publishing needs Tailscale.']);

    expect($this->project->fresh()->publish_status)->toBeNull();
})->group('PUB-001');

test('a stopped sandbox cannot be published', function () {
    $this->project->sandbox->update(['status' => SandboxStatus::Failed]);

    $this->actingAs($this->user)
        ->post(route('projects.publication.store', $this->project), ['visibility' => 'private'])
        ->assertSessionHasErrors(['publish' => "The project's sandbox isn't running."]);
})->group('PUB-001');

test('visibility must be private or public', function () {
    $this->actingAs($this->user)
        ->post(route('projects.publication.store', $this->project), ['visibility' => 'everyone'])
        ->assertSessionHasErrors('visibility');
})->group('PUB-001');

test('publish failures are shown on the project', function () {
    app()->instance(Publisher::class, new class extends FakePublisher
    {
        public function confirm(Project $project, PublishVisibility $visibility): ?string
        {
            throw new PublishException('Public publishing needs Tailscale Funnel enabled.');
        }
    });

    $this->actingAs($this->user)
        ->post(route('projects.publication.store', $this->project), ['visibility' => 'public']);

    expect($this->project->fresh()->publish_status)->toBe(PublishStatus::Failed)
        ->and($this->project->fresh()->publish_error)->toContain('Funnel');
})->group('PUB-001');

test('confirmation retries while the node comes up, then gives up', function () {
    Queue::fake();
    app()->instance(Publisher::class, new class extends FakePublisher
    {
        public function confirm(Project $project, PublishVisibility $visibility): ?string
        {
            return null;
        }
    });
    $this->project->update(['publish_status' => PublishStatus::Publishing, 'publish_visibility' => PublishVisibility::Private]);

    (new ConfirmPublication($this->project))->handle(app(Publisher::class));
    Queue::assertPushed(ConfirmPublication::class, fn ($job) => $job->attempt === 2);

    (new ConfirmPublication($this->project, ConfirmPublication::ATTEMPTS))->handle(app(Publisher::class));
    expect($this->project->fresh()->publish_status)->toBe(PublishStatus::Failed);
})->group('PUB-001');

test('publishing waits for browser approval, shows the link, then goes live', function () {
    Queue::fake();
    $this->publisher->loginUrl = 'https://login.tailscale.com/a/abc123';
    $this->project->update(['publish_status' => PublishStatus::Publishing, 'publish_visibility' => PublishVisibility::Public]);

    (new ConfirmPublication($this->project))->handle($this->publisher);

    expect($this->project->fresh()->publish_login_url)->toBe('https://login.tailscale.com/a/abc123');
    Queue::assertPushed(ConfirmPublication::class, fn ($job) => $job->attempt === 2);

    $this->actingAs($this->user)
        ->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page->where('publication.login_url', 'https://login.tailscale.com/a/abc123'));

    $this->publisher->approved = true;
    (new ConfirmPublication($this->project, 2))->handle($this->publisher);

    $project = $this->project->fresh();
    expect($project->publish_status)->toBe(PublishStatus::Live)
        ->and($project->publish_login_url)->toBeNull();
})->group('PUB-001');

test('waiting for approval eventually gives up', function () {
    $this->publisher->loginUrl = 'https://login.tailscale.com/a/abc123';
    $this->project->update(['publish_status' => PublishStatus::Publishing, 'publish_visibility' => PublishVisibility::Public]);

    (new ConfirmPublication($this->project, ConfirmPublication::LOGIN_ATTEMPTS))->handle($this->publisher);

    expect($this->project->fresh()->publish_status)->toBe(PublishStatus::Failed)
        ->and($this->project->fresh()->publish_error)->toContain('Nobody approved');
})->group('PUB-001');

test('a stale confirmation does nothing after unpublishing', function () {
    ConfirmPublication::dispatchSync($this->project);

    expect($this->project->fresh()->publish_status)->toBeNull()
        ->and($this->publisher->published)->toBe([]);
})->group('PUB-001');

test('unpublishing takes the project offline', function () {
    $this->actingAs($this->user)->post(route('projects.publication.store', $this->project), ['visibility' => 'public']);

    $this->actingAs($this->user)
        ->delete(route('projects.publication.destroy', $this->project))
        ->assertRedirect(route('projects.show', $this->project));

    $project = $this->project->fresh();
    expect($project->publish_status)->toBeNull()
        ->and($project->published_url)->toBeNull()
        ->and($project->published_at)->not->toBeNull()
        ->and($this->publisher->published)->toBe([]);
})->group('PUB-001');

test('only the owner can publish or unpublish', function () {
    $other = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($other)->post(route('projects.publication.store', $this->project), ['visibility' => 'public'])->assertForbidden();
    $this->actingAs($other)->delete(route('projects.publication.destroy', $this->project))->assertForbidden();

    expect($this->project->fresh()->publish_status)->toBeNull();
})->group('PUB-001');

test('publish hostnames are dns-safe and unique per project', function () {
    $project = Project::factory()->create(['name' => 'A Time-Off Tracker For My Team!!']);

    expect($project->publishHostname())->toBe("a-time-off-tracker-for-my-team-{$project->id}")
        ->and(Project::factory()->create(['name' => '!!!'])->publishHostname())->toStartWith('project-');
})->group('PUB-001');
