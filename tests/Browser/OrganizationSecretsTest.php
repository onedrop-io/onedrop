<?php

use App\Models\AgentConnection;
use App\Models\OrganizationSecret;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('an owner shares an AWS key with selected projects, changes it, and deletes it', function () {
    $this->seed(DatabaseSeeder::class);
    $dev = User::where('email', 'dev@example.com')->sole();
    AgentConnection::factory()->for($dev)->create();
    $billing = Project::factory()->for($dev)->create(['name' => 'Billing']);
    $this->actingAs($dev);

    $page = visit(orgPath('/settings'))
        ->click('@organization-secret-new')
        ->assertScript('document.activeElement?.dataset.test', 'organization-secret-name')
        ->type('@organization-secret-name', 'AWS_ACCESS_KEY_ID')
        ->type('@organization-secret-value', 'AKIAEXAMPLE')
        ->press('@organization-secret-save')
        ->assertSee('Added AWS_ACCESS_KEY_ID.')
        ->assertSeeIn('@organization-secret-AWS_ACCESS_KEY_ID', 'All projects')
        ->assertDontSee('AKIAEXAMPLE');

    $page->click('@organization-secret-AWS_ACCESS_KEY_ID-edit')
        ->click('@organization-secret-selected-projects')
        ->click("@organization-secret-project-{$billing->id}")
        ->press('@organization-secret-save')
        ->assertSee('Saved AWS_ACCESS_KEY_ID.')
        ->assertSeeIn('@organization-secret-AWS_ACCESS_KEY_ID', 'Billing')
        ->assertNoJavaScriptErrors();

    expect(OrganizationSecret::sole()->value)->toBe('AKIAEXAMPLE')
        ->and(OrganizationSecret::sole()->projects->pluck('id')->all())->toBe([$billing->id]);

    $page->script('window.confirm = () => true');
    $page->click('@organization-secret-AWS_ACCESS_KEY_ID-delete')
        ->assertSee('Deleted AWS_ACCESS_KEY_ID.')
        ->assertMissing('@organization-secret-AWS_ACCESS_KEY_ID')
        ->assertNoJavaScriptErrors();

    expect(OrganizationSecret::count())->toBe(0);
})->group('SECRET-003');
