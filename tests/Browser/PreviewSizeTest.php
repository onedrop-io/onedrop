<?php

use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;

test('the preview can be shown at tablet and mobile widths, without reloading, and the choice is remembered', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();
    $project = Project::factory()->for($user)->create();
    Sandbox::factory()->for($project)->create(['preview_url' => 'data:text/html,<p>app</p>']);
    $this->actingAs($user);

    $frame = "document.querySelector('[data-test=\"preview-frame\"]')";
    $frameWidth = "Math.round({$frame}.getBoundingClientRect().width)";

    $page = visit("/projects/{$project->id}")->resize(1600, 900);

    // Set on the preview's iframe; an iframe that remounted (a reload) loses it.
    $page->script("{$frame}.dataset.kept = 'yes'");

    $page->click('@preview-size')
        ->click('@preview-size-mobile')
        ->assertScript($frameWidth, 390)
        ->assertScript("{$frame}.dataset.kept", 'yes')
        ->assertMissing('@preview-size-mobile')
        ->click('@preview-size')
        ->click('@preview-size-tablet')
        ->assertScript($frameWidth, 768)
        ->navigate("/projects/{$project->id}")
        ->assertScript($frameWidth, 768)
        ->click('@preview-size')
        ->click('@preview-size-desktop')
        ->assertScript("{$frameWidth} === {$frame}.parentElement.clientWidth", true)
        ->assertNoJavaScriptErrors();
})->group('LAYOUT-004');
