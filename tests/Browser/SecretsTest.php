<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Agents\AgentRunner;
use App\Sandbox\Agents\FakeAgentRunner;
use App\Sandbox\SandboxProvider;

beforeEach(function () {
    $this->workspace = databaseWorkspace();
    file_put_contents($this->workspace.'/.env', "STRIPE_SECRET_KEY=sk_test_123\n", FILE_APPEND);
    app()->instance(SandboxProvider::class, fakeDatabaseSandbox($this->workspace));
    app()->instance(AgentRunner::class, new FakeAgentRunner);

    $this->user = User::factory()->has(AgentConnection::factory())->create();
    $this->project = Project::factory()->for($this->user)->create();
    Sandbox::factory()->for($this->project)->create(['preview_url' => 'http://127.0.0.1:49152']);
    $this->actingAs($this->user);
});

test('the user filters, reveals, adds, edits and deletes secrets', function () {
    $page = visit("/projects/{$this->project->id}")
        ->resize(1920, 1080)
        ->click('@tab-tools')
        ->click('@tool-secrets')
        ->assertSeeIn('@secrets-list', 'STRIPE_SECRET_KEY')
        ->assertSeeIn('@secrets-list', 'APP_NAME')
        ->assertDontSee('sk_test_123')
        ->type('@secrets-filter', 'stripe')
        ->assertDontSeeIn('@secrets-list', 'APP_NAME')
        ->click('@secret-reveal')
        ->assertSeeIn('@secret-value', 'sk_test_123')
        ->click('@secret-reveal')
        ->assertDontSee('sk_test_123')
        ->clear('@secrets-filter');

    // Add a secret; the name field is focused when the dialog opens.
    $page->click('@secrets-new')
        ->assertVisible('@secret-dialog')
        ->assertScript('document.activeElement?.dataset.test', 'secret-name-input')
        ->type('@secret-name-input', 'APP_NAME')
        ->assertSeeIn('@secret-row-replaces', 'APP_NAME already exists. Saving replaces its value.')
        ->clear('@secret-name-input')
        ->type('@secret-name-input', 'OPENAI_API_KEY')
        ->type('@secret-value-input', 'sk proj 1')
        ->click('@secret-save')
        ->assertMissing('@secret-dialog')
        ->assertSeeIn('@secrets-list', 'OPENAI_API_KEY');

    expect(file_get_contents($this->workspace.'/.env'))->toContain("OPENAI_API_KEY='sk proj 1'");

    // Edit it: the dialog starts from the current value.
    $page->click('[aria-label="Edit OPENAI_API_KEY"]')
        ->assertValue('@secret-value-input', 'sk proj 1')
        ->clear('@secret-value-input')
        ->type('@secret-value-input', 'sk-new')
        ->click('@secret-save')
        ->assertMissing('@secret-dialog');

    expect(file_get_contents($this->workspace.'/.env'))->toContain("OPENAI_API_KEY=sk-new\n");

    $page->click('[aria-label="Delete OPENAI_API_KEY"]')
        ->click('@secret-delete-confirm')
        ->assertDontSeeIn('@secrets-list', 'OPENAI_API_KEY')
        ->assertNoJavaScriptErrors();

    expect(file_get_contents($this->workspace.'/.env'))->not->toContain('OPENAI_API_KEY');
})->group('SECRET-001');

test('pasting NAME=value lines fills in a row for each secret', function () {
    $env = <<<'ENV'
        # From Vercel
        export STRIPE_SECRET_KEY="sk_test_new"
        RESEND_API_KEY=re_123 # email
        PEM="-----BEGIN KEY-----
        abc
        -----END KEY-----"
        ENV;

    $page = visit("/projects/{$this->project->id}")
        ->resize(1920, 1080)
        ->click('@tab-tools')
        ->click('@tool-secrets')
        ->click('@secrets-new')
        ->assertVisible('@secret-dialog');

    // A paste, as the browser delivers it to the focused name field.
    $page->script('() => {
        const data = new DataTransfer();
        data.setData("text/plain", '.json_encode($env).');
        document.activeElement.dispatchEvent(new ClipboardEvent("paste", { clipboardData: data, bubbles: true, cancelable: true }));
    }');

    $page->assertCount('@secret-row', 3)
        ->assertValue('[data-test="secret-row"]:nth-child(1) [data-test="secret-name-input"]', 'STRIPE_SECRET_KEY')
        ->assertValue('[data-test="secret-row"]:nth-child(1) [data-test="secret-value-input"]', 'sk_test_new')
        ->assertValue('[data-test="secret-row"]:nth-child(2) [data-test="secret-value-input"]', 're_123')
        ->assertSeeIn('@secret-row-replaces', 'STRIPE_SECRET_KEY already exists')
        ->assertSeeIn('@secret-save', 'Save 3 secrets')
        ->click('@secret-save')
        ->assertMissing('@secret-dialog')
        ->assertSeeIn('@secrets-list', 'RESEND_API_KEY')
        ->assertSeeIn('@secrets-list', 'PEM')
        ->assertNoJavaScriptErrors();

    expect(file_get_contents($this->workspace.'/.env'))
        ->toContain("STRIPE_SECRET_KEY=sk_test_new\n")
        ->toContain("RESEND_API_KEY=re_123\n")
        ->toContain("PEM=\"-----BEGIN KEY-----\nabc\n-----END KEY-----\"\n");
})->group('SECRET-001');
