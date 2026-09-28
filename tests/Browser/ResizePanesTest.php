<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Providers\FakeSandboxProvider;
use App\Sandbox\SandboxProvider;

test('the chat and files panel can be resized and keep their widths', function () {
    app()->instance(SandboxProvider::class, new FakeSandboxProvider);
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => null]);
    $this->actingAs($user);

    $chatWidth = "Math.round(document.querySelector('[aria-label=\"Chat\"]').getBoundingClientRect().width)";
    $filesWidth = "Math.round(document.querySelector('[data-test=\"files-panel\"]').getBoundingClientRect().width)";

    $page = visit("/projects/{$project->id}")
        ->resize(1600, 900)
        ->navigate("/projects/{$project->id}")
        ->assertScript($chatWidth, 448)
        ->assertScript($filesWidth, 224)
        ->keys('@chat-resize', ['ArrowRight', 'ArrowRight'])
        ->assertAttribute('@chat-resize', 'aria-valuenow', 480)
        ->assertScript($chatWidth, 480)
        ->keys('@files-resize', 'ArrowLeft')
        ->assertScript($filesWidth, 240)
        ->navigate("/projects/{$project->id}")
        ->assertScript($chatWidth, 480)
        ->assertScript($filesWidth, 240)
        ->assertNoJavaScriptErrors();

    $page->script("document.querySelector('[data-test=\"chat-resize\"]').dispatchEvent(new MouseEvent('dblclick', { bubbles: true }))");
    $page->assertScript($chatWidth, 448);
})->group('PRJ-002');

test('the left sidebar can be resized and keeps its width', function () {
    $this->actingAs(User::factory()->has(AgentConnection::factory())->create());
    $sidebarWidth = "Math.round(document.querySelector('[data-sidebar=\"sidebar\"]').getBoundingClientRect().width)";

    visit('/dashboard')
        ->resize(1280, 800)
        ->navigate('/dashboard')
        ->assertAttribute('@sidebar-resize', 'aria-valuenow', 256)
        ->keys('@sidebar-resize', ['ArrowRight', 'ArrowRight', 'ArrowRight', 'ArrowRight'])
        ->assertAttribute('@sidebar-resize', 'aria-valuenow', 320)
        ->navigate('/dashboard')
        ->assertAttribute('@sidebar-resize', 'aria-valuenow', 320)
        ->assertScript($sidebarWidth, 320 - 16)
        ->click('[data-sidebar="trigger"]')
        ->assertMissing('@sidebar-resize')
        ->assertNoJavaScriptErrors();
})->group('PRJ-002');
