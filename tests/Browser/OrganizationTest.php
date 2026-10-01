<?php

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
