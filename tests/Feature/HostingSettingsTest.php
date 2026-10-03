<?php

use App\Enums\HostedServiceKind;
use App\Enums\OrganizationRole;
use App\Models\HostedService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SystemSetting;
use App\Models\User;
use App\Sandbox\Hosting\HostingProviders;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config([
        'hosting.providers.fly.enabled' => false,
        'hosting.providers.fly.api_token' => null,
        'hosting.providers.cloudflare.enabled' => false,
        'hosting.providers.neon.enabled' => false,
        'hosting.providers.upstash.enabled' => false,
    ]);
});

test('admins see every hosting provider, what it is for and what it still needs', function () {
    HostedService::factory()->count(2)->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.hosting.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/hosting')
            ->has('providers', 4)
            ->where('providers.0.name', 'fly')
            ->where('providers.0.roles', ['server', 'volume'])
            ->where('providers.0.enabled', false)
            ->where('providers.0.missing', ['api_token'])
            ->where('providers.0.services', 2)
            ->where('providers.1.name', 'cloudflare')
            ->where('providers.2.name', 'neon')
            ->where('providers.3.name', 'upstash'));
})->group('ADMIN-007');

test('admins can set up a provider; keys are stored encrypted, never shown, and kept when left blank', function () {
    Artisan::spy();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put(route('admin.hosting.update', 'fly'), [
        'enabled' => true,
        'api_token' => 'fly-secret',
        'org_slug' => 'onedrop',
        'region' => 'ams',
        'memory_mb' => 2048,
    ])->assertRedirect(route('admin.hosting.index'));

    expect(config('hosting.providers.fly.api_token'))->toBe('fly-secret')
        ->and(config('hosting.providers.fly.region'))->toBe('ams')
        ->and(config('hosting.providers.fly.memory_mb'))->toBe(2048)
        ->and(app(HostingProviders::class)->platformAccount('fly'))->not->toBeNull()
        ->and(SystemSetting::query()->find('hosting')->getRawOriginal('value'))->not->toContain('fly-secret');

    // Workers restart to pick the settings up.
    Artisan::shouldHaveReceived('call')->with('queue:restart');

    $this->actingAs($admin)->put(route('admin.hosting.update', 'fly'), ['enabled' => true, 'api_token' => '', 'org_slug' => 'onedrop']);

    expect(config('hosting.providers.fly.api_token'))->toBe('fly-secret');

    $this->actingAs($admin)->get(route('admin.hosting.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('providers.0.enabled', true)
            ->where('providers.0.missing', [])
            ->where('providers.0.fields.0.key', 'api_token')
            ->where('providers.0.fields.0.value', null)
            ->where('providers.0.fields.0.set', true));
})->group('ADMIN-007');

test('non-admins cannot see or change the hosting providers', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.hosting.index'))->assertForbidden();
    $this->actingAs($user)->put(route('admin.hosting.update', 'fly'), ['enabled' => true])->assertForbidden();
})->group('ADMIN-007');

test("organization owners and admins can connect the organization's own account; keys are never shown", function () {
    $owner = User::factory()->create();
    $organization = Organization::factory()->create();
    $organization->addMember($owner, OrganizationRole::Owner);

    $this->actingAs($owner)
        ->put(route('organizations.hosting.update', [$organization, 'neon']), ['api_key' => 'neon-theirs', 'region' => 'aws-eu-central-1'])
        ->assertSessionHasNoErrors();

    $account = app(HostingProviders::class)->account($organization->fresh(), 'neon');

    expect($account->owner)->toBe(HostedService::OWNER_ORGANIZATION)
        ->and($account->get('api_key'))->toBe('neon-theirs')
        ->and($organization->fresh()->getRawOriginal('hosting_accounts'))->not->toContain('neon-theirs');

    $this->actingAs($owner)->get(route('organizations.edit', $organization))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('hosting.2.name', 'neon')
            ->where('hosting.2.connected', true)
            ->where('hosting.2.platform', false)
            ->where('hosting.2.fields.0.key', 'api_key')
            ->where('hosting.2.fields.0.value', null)
            ->where('hosting.2.fields.0.set', true));
})->group('HOST-003');

test("an organization's account needs its required settings", function () {
    $owner = User::factory()->create();
    $organization = Organization::factory()->create();
    $organization->addMember($owner, OrganizationRole::Owner);

    $this->actingAs($owner)
        ->put(route('organizations.hosting.update', [$organization, 'fly']), ['region' => 'iad'])
        ->assertSessionHasErrors(['api_token', 'org_slug']);

    expect($organization->fresh()->hosting_accounts)->toBeNull();
})->group('HOST-003');

test('members cannot change the organization hosting accounts', function () {
    $member = User::factory()->create();
    $organization = Organization::factory()->create();
    $organization->addMember($member, OrganizationRole::Member);

    $this->actingAs($member)
        ->put(route('organizations.hosting.update', [$organization, 'neon']), ['api_key' => 'x'])
        ->assertForbidden();
})->group('HOST-003');

test('an account can only be disconnected once its apps have nothing in it', function () {
    $owner = User::factory()->create();
    $organization = Organization::factory()->create();
    $organization->addMember($owner, OrganizationRole::Owner);
    $organization->forceFill(['hosting_accounts' => ['fly' => ['api_token' => 'theirs', 'org_slug' => 'acme']]])->save();
    $service = HostedService::factory()->for(Project::factory()->for($organization))->create(['owner' => HostedService::OWNER_ORGANIZATION, 'kind' => HostedServiceKind::App]);

    $this->actingAs($owner)
        ->delete(route('organizations.hosting.destroy', [$organization, 'fly']))
        ->assertSessionHasErrors('hosting');

    $service->delete();

    $this->actingAs($owner)
        ->delete(route('organizations.hosting.destroy', [$organization, 'fly']))
        ->assertSessionHasNoErrors();

    expect($organization->fresh()->hosting_accounts)->toBeNull();
})->group('HOST-003');
