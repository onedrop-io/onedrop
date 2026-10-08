<?php

use App\Enums\OrganizationRole;
use App\Models\AgentConnection;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Models\Project;
use App\Models\User;
use App\Sandbox\Domains\DomainDns;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    config(['app.multi_tenant' => true, 'auth.verify_email' => true]);

    $this->acme = Organization::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $this->owner = User::factory()->has(AgentConnection::factory())->create();
    $this->acme->addMember($this->owner, OrganizationRole::Owner);
    $this->txt = [];
    $this->mock(DomainDns::class)->shouldReceive('txt')->andReturnUsing(fn (string $host) => $this->txt[$host] ?? []);
});

/**
 * Sign up through the form, as Ada at the given email.
 */
function signUpAs(string $email): User
{
    test()->post(route('register.store'), [
        'name' => 'Ada Lovelace',
        'email' => $email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    return User::query()->latest('id')->firstOrFail();
}

function verifyEmail(User $user): void
{
    test()->actingAs($user)->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]));
}

test('an owner adds a domain, sees its TXT record, and verifies it once the record is there', function () {
    $this->actingAs($this->owner)
        ->post(route('organizations.domains.store', $this->acme), ['domain' => ' @Acme.com. '])
        ->assertSessionHasNoErrors();

    $domain = $this->acme->domains()->sole();
    expect($domain->domain)->toBe('acme.com')->and($domain->verified_at)->toBeNull();

    $this->get(route('organizations.members.index', $this->acme))
        ->assertInertia(fn ($page) => $page->where('domains.0', ['id' => $domain->id, 'domain' => 'acme.com', 'verified' => false, 'txt' => $domain->txtValue()]));

    $this->post(route('organizations.domains.verify', [$this->acme, $domain]))->assertSessionHasErrors('domain');
    expect($domain->fresh()->verified_at)->toBeNull();

    $this->txt['acme.com'] = ['v=spf1 -all', $domain->txtValue()];
    $this->post(route('organizations.domains.verify', [$this->acme, $domain]))->assertSessionHasNoErrors();
    expect($domain->fresh()->verified_at)->not->toBeNull();

    $this->delete(route('organizations.domains.destroy', [$this->acme, $domain]))->assertRedirect();
    expect($this->acme->domains()->exists())->toBeFalse();
})->group('ORG-008');

test('a domain is refused when it is not a domain, already added, or verified by another organization', function () {
    $globex = Organization::factory()->create();
    OrganizationDomain::factory()->verified()->for($globex)->create(['domain' => 'globex.com']);
    OrganizationDomain::factory()->for($this->acme)->create(['domain' => 'acme.com']);

    $this->actingAs($this->owner);

    foreach (['not a domain', 'acme.com', 'globex.com'] as $taken) {
        $this->post(route('organizations.domains.store', $this->acme), ['domain' => $taken])->assertSessionHasErrors('domain');
    }

    expect($this->acme->domains()->count())->toBe(1);
})->group('ORG-008');

test('only one organization can verify a domain', function () {
    $globex = Organization::factory()->create();
    $theirs = OrganizationDomain::factory()->for($globex)->create(['domain' => 'shared.com']);
    $ours = OrganizationDomain::factory()->for($this->acme)->create(['domain' => 'shared.com']);
    $this->txt['shared.com'] = [$theirs->txtValue(), $ours->txtValue()];
    $theirs->forceFill(['verified_at' => now()])->save();

    $this->actingAs($this->owner)
        ->post(route('organizations.domains.verify', [$this->acme, $ours]))
        ->assertSessionHasErrors('domain');

    expect($ours->fresh()->verified_at)->toBeNull();
})->group('ORG-008');

test('members, other organizations and self-hosted installs cannot manage domains', function () {
    $member = User::factory()->create();
    $this->acme->addMember($member);
    $domain = OrganizationDomain::factory()->for($this->acme)->create(['domain' => 'acme.com']);
    $globex = Organization::factory()->create();
    $globexDomain = OrganizationDomain::factory()->for($globex)->create();
    $globex->addMember($this->owner, OrganizationRole::Owner);

    $this->actingAs($member)->get(route('organizations.members.index', $this->acme))->assertInertia(fn ($page) => $page->where('domains', null));
    $this->post(route('organizations.domains.store', $this->acme), ['domain' => 'evil.com'])->assertForbidden();
    $this->delete(route('organizations.domains.destroy', [$this->acme, $domain]))->assertForbidden();

    $this->actingAs($this->owner)->delete(route('organizations.domains.destroy', [$this->acme, $globexDomain]))->assertNotFound();

    config(['app.multi_tenant' => false]);
    $this->post(route('organizations.domains.store', $this->acme), ['domain' => 'acme.com'])->assertNotFound();
    expect(OrganizationDomain::count())->toBe(2);
})->group('ORG-008');

test('signing up with a verified email at a verified domain joins that organization instead of making one', function () {
    OrganizationDomain::factory()->verified()->for($this->acme)->create(['domain' => 'acme.com']);
    $before = Organization::count();

    $ada = signUpAs('ada@Acme.com');
    $this->get('/settings/profile');
    verifyEmail($ada);

    $ada = $ada->fresh();
    expect($ada->organizationRole($this->acme))->toBe(OrganizationRole::Member)
        ->and($ada->currentOrganization()->is($this->acme))->toBeTrue()
        ->and($ada->organizations()->count())->toBe(1)
        ->and(Organization::count())->toBe($before);
})->group('ORG-008');

test('an account made already verified joins straight away', function () {
    OrganizationDomain::factory()->verified()->for($this->acme)->create(['domain' => 'acme.com']);

    $ada = User::factory()->create(['email' => 'ada@acme.com']);

    expect($ada->currentOrganization()->is($this->acme))->toBeTrue()
        ->and($ada->organizationRole($this->acme))->toBe(OrganizationRole::Member);
})->group('ORG-008');

test('waiting domains, subdomains and other domains do not join, and verifying keeps an organization already used', function () {
    OrganizationDomain::factory()->for($this->acme)->create(['domain' => 'waiting.com']);
    OrganizationDomain::factory()->verified()->for($this->acme)->create(['domain' => 'acme.com']);

    foreach (['ada@waiting.com', 'ada@eu.acme.com', 'ada@example.com'] as $email) {
        $user = User::factory()->create(['email' => $email]);
        expect($user->currentOrganization()->is($this->acme))->toBeFalse();
    }

    $ada = signUpAs('ada@acme.com');
    $own = $ada->currentOrganization();
    Project::factory()->for($ada)->create(['organization_id' => $own->id]);
    verifyEmail($ada);

    expect($ada->fresh()->belongsToOrganization($this->acme))->toBeFalse()
        ->and($own->fresh())->not->toBeNull();
})->group('ORG-008');

test('someone already signed up joins from the Organization submenu, and nobody else can', function () {
    OrganizationDomain::factory()->verified()->for($this->acme)->create(['domain' => 'acme.com']);
    $ada = User::factory()->has(AgentConnection::factory())->create(['email' => 'ada@example.com']);
    $own = $ada->currentOrganization();
    $ada->forceFill(['email' => 'ada@acme.com'])->save();

    $this->actingAs($ada)->get(route('organizations.home', $own))
        ->assertInertia(fn ($page) => $page->where('joinableOrganizations', [['id' => $this->acme->id, 'name' => 'Acme', 'slug' => 'acme', 'logo_url' => null]]));

    $this->post(route('organizations.join', $this->acme))->assertRedirect(route('organizations.home', $this->acme));
    expect($ada->fresh()->organizationRole($this->acme))->toBe(OrganizationRole::Member)
        ->and($own->fresh())->not->toBeNull();

    $this->get(route('organizations.home', $this->acme))->assertInertia(fn ($page) => $page->where('joinableOrganizations', []));

    $eve = User::factory()->create(['email' => 'eve@example.com']);
    $this->actingAs($eve)->post(route('organizations.join', $this->acme))->assertNotFound();

    $unverified = User::factory()->unverified()->create(['email' => 'mallory@acme.com']);
    $this->actingAs($unverified)->post(route('organizations.join', $this->acme))->assertRedirect(route('verification.notice'));
    expect($unverified->belongsToOrganization($this->acme))->toBeFalse();
})->group('ORG-008');
