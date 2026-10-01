<?php

use App\Enums\OrganizationRole;
use App\Models\AgentConnection;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('the organization in the address decides the sidebar and the groups, and signing in lands in the last one', function () {
    $this->seed(DatabaseSeeder::class);
    $dev = User::where('email', 'dev@example.com')->sole();
    AgentConnection::factory()->for($dev)->create();

    $globex = Organization::factory()->create(['name' => 'Globex', 'slug' => 'globex']);
    $globex->addMember($dev);
    Project::factory()->for($dev)->create(['name' => 'Install CRM', 'read_at' => now()]);
    $theirs = Project::factory()->for($dev)->create(['name' => 'Globex CRM', 'read_at' => now(), 'organization_id' => $globex->id]);
    Group::factory()->ownedBy($dev)->create(['name' => 'Globex Ops', 'organization_id' => $globex->id]);
    $sidebar = '[data-sidebar="sidebar"]';

    $this->actingAs($dev);

    visit(orgPath())
        ->assertSeeIn($sidebar, 'Install CRM')
        ->assertDontSeeIn($sidebar, 'Globex CRM')
        ->assertNoJavaScriptErrors();

    visit('/o/globex')
        ->assertSeeIn($sidebar, 'Globex CRM')
        ->assertDontSeeIn($sidebar, 'Install CRM')
        ->click('@sidebar-menu-button')
        ->click('@settings-link')
        ->click('[data-test="settings-modal"] a:has-text("Groups")')
        ->assertPathIs('/o/globex/groups')
        ->assertSee('Globex Ops')
        ->assertDontSee('Engineering')
        ->assertNoJavaScriptErrors();

    visit("/projects/{$theirs->id}")->assertSee('Globex CRM');

    visit('/dashboard')
        ->assertPathIs('/o/globex')
        ->assertNoJavaScriptErrors();
})->group('ORG-001', 'ORG-002');

test('an owner renames the organization, makes a member an admin, and opens its usage', function () {
    $this->seed(DatabaseSeeder::class);
    $dev = User::where('email', 'dev@example.com')->sole();
    $sam = User::where('email', 'sam@example.com')->sole();
    AgentConnection::factory()->for($dev)->create();
    $this->actingAs($dev);

    $page = visit(orgPath())
        ->click('@sidebar-menu-button')
        ->click('@settings-link')
        ->click('[data-test="settings-modal"] a:has-text("Organization")')
        ->assertPathIs(orgPath('/settings'))
        ->assertSeeIn("@organization-member-{$sam->id}", 'Sam Member')
        ->fill('name', 'Acme Corp')
        ->fill('slug', 'acme-corp')
        ->press('@save-organization')
        ->assertSee('Organization updated.')
        ->assertPathIs('/o/acme-corp/settings')
        ->select("@role-{$sam->id}", 'admin')
        ->assertSelected("@role-{$sam->id}", 'admin')
        ->assertNoJavaScriptErrors();

    expect($sam->fresh()->organizationRole(Organization::sole()))->toBe(OrganizationRole::Admin);

    $page->navigate('/o/acme-corp')
        ->click('@sidebar-menu-button')
        ->click('@organization-usage-link')
        ->assertPathIs('/o/acme-corp/usage')
        ->assertSee('Acme Corp usage')
        ->assertNoJavaScriptErrors();
})->group('ORG-004', 'ORG-005');

test('on the hosted install the switcher creates an organization and switches between them', function () {
    config(['app.multi_tenant' => true]);
    $this->seed(DatabaseSeeder::class);
    $dev = User::where('email', 'dev@example.com')->sole();
    AgentConnection::factory()->for($dev)->create();
    Project::factory()->for($dev)->create(['name' => 'Install CRM', 'read_at' => now()]);
    $sidebar = '[data-sidebar="sidebar"]';
    $this->actingAs($dev);

    $page = visit(orgPath())
        ->assertSeeIn('@organization-switcher', Organization::install()->name)
        ->click('@organization-switcher')
        ->click('@create-organization')
        ->assertVisible('@organization-name')
        ->assertScript('document.activeElement?.dataset.test', 'organization-name')
        ->type('@organization-name', 'Acme Labs')
        ->press('@create-organization-button')
        ->assertPathIs('/o/acme-labs')
        ->assertSee('Created Acme Labs.')
        ->assertSeeIn('@organization-switcher', 'Acme Labs')
        ->assertDontSeeIn($sidebar, 'Install CRM');

    $page->click('@organization-switcher')
        ->click('@switch-to-'.Organization::install()->slug)
        ->assertPathIs(orgPath())
        ->assertSeeIn($sidebar, 'Install CRM')
        ->assertNoJavaScriptErrors();
})->group('ORG-002', 'ORG-003');
