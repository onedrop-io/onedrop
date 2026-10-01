<?php

use App\Models\AgentConnection;
use App\Models\Group;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

test('an owner can create a group, add a member, and delete it', function () {
    $newcomer = User::factory()->create(['name' => 'Nia New', 'email' => 'nia@example.com']);
    AgentConnection::factory()->for(User::where('email', 'dev@example.com')->sole())->create();

    $page = visit('/login')
        ->fill('email', 'dev@example.com')
        ->fill('password', 'password')
        ->press('@login-button')
        ->assertPathIs(orgPath())
        ->click('@sidebar-menu-button')
        ->click('@settings-link')
        ->click('[data-test="settings-modal"] a:has-text("Groups")')
        ->assertSee('Engineering')
        ->fill('name', 'Design')
        ->fill('description', 'Pixels and type')
        ->press('@create-group-button')
        ->assertSee('Group created.')
        ->assertSee('Pixels and type');

    $group = Group::where('name', 'Design')->firstOrFail();
    $page->assertPathIs(orgPath("/groups/{$group->id}"));

    $page->fill('email', 'nia@example.com')
        ->press('@add-member-button')
        ->assertSee('Nia New added.')
        ->assertSeeIn("@member-{$newcomer->id}", 'member');

    expect($group->members()->whereKey($newcomer->id)->exists())->toBeTrue();

    $page->press('Delete group')
        ->click('[role="dialog"]:not([data-test="settings-modal"]) button:has-text("Delete group")')
        ->assertPathIs(orgPath('/groups'))
        ->assertSee('Group deleted.')
        ->assertNoJavaScriptErrors();

    expect($group->fresh())->toBeNull();
})->group('GRP-001', 'GRP-002', 'GRP-003');
