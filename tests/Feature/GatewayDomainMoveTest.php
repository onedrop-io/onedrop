<?php

use App\Enums\PublishTarget;
use App\Models\Project;

function moveToGatewayDomain(): void
{
    (require database_path('migrations/2026_10_13_171422_move_domain_published_apps_to_the_gateway_domain.php'))->up();
}

beforeEach(function () {
    config(['sandbox.gateway_domain' => 'onedrop.dev']);
});

it('moves an app published to the domain to the same name under the new gateway domain', function () {
    $project = Project::factory()->create(['publish_target' => PublishTarget::Domain]);
    $project->forceFill([
        'published_default_url' => "https://bake-sale-{$project->id}.onedrop.io",
        'published_url' => "https://bake-sale-{$project->id}.onedrop.io",
    ])->save();

    moveToGatewayDomain();

    expect($project->fresh())
        ->published_default_url->toBe("https://bake-sale-{$project->id}.onedrop.dev")
        ->published_url->toBe("https://bake-sale-{$project->id}.onedrop.dev");
})->group('GW-002');

it('keeps a custom domain as the published address', function () {
    $project = Project::factory()->create(['publish_target' => PublishTarget::Domain]);
    $project->forceFill([
        'published_default_url' => "https://bake-sale-{$project->id}.onedrop.io",
        'published_url' => 'https://bakesale.example.com',
    ])->save();

    moveToGatewayDomain();

    expect($project->fresh())
        ->published_default_url->toBe("https://bake-sale-{$project->id}.onedrop.dev")
        ->published_url->toBe('https://bakesale.example.com');
})->group('GW-002');

it('leaves apps published elsewhere alone', function () {
    $project = Project::factory()->create(['publish_target' => PublishTarget::Hosting]);
    $project->forceFill([
        'published_default_url' => 'https://onedrop-bake-sale.fly.dev',
        'published_url' => 'https://onedrop-bake-sale.fly.dev',
    ])->save();

    moveToGatewayDomain();

    expect($project->fresh()->published_default_url)->toBe('https://onedrop-bake-sale.fly.dev');
})->group('GW-002');
