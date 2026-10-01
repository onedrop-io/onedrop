<?php

use App\Models\AgentConnection;
use App\Models\User;
use App\Sandbox\Templates\AppScreenshots;
use App\Sandbox\Templates\TemplateCatalog;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['sandbox.app_screenshots.github_url' => 'https://stores.test']);
    Http::fake([
        'stores.test/repos/getumbrel/umbrel-apps-gallery/git/trees/master*' => Http::response(['tree' => [
            ['path' => 'n8n/2.jpg', 'type' => 'blob'],
            ['path' => 'n8n/10.jpg', 'type' => 'blob'],
            ['path' => 'n8n/1.jpg', 'type' => 'blob'],
            ['path' => 'n8n/icon.svg', 'type' => 'blob'],
            ['path' => 'ghost', 'type' => 'tree'],
        ]]),
        'stores.test/repos/IceWhaleTech/CasaOS-AppStore/git/trees/main*' => Http::response(['tree' => [
            ['path' => 'Apps/N8n/screenshot-1.png', 'type' => 'blob'],
            ['path' => 'Apps/N8n/icon.png', 'type' => 'blob'],
            ['path' => 'Apps/Ghost/screenshot-1.png', 'type' => 'blob'],
        ]]),
    ]);

    $this->template = fn (array $overrides = []) => [
        'value' => 'dokploy/n8n', 'label' => 'n8n', 'description' => '', 'prompt' => '', 'registry' => 'Dokploy', 'logo' => null,
        'tags' => [], 'compose' => true, 'version' => null, 'links' => ['website' => null, 'github' => null, 'docs' => null],
        ...$overrides,
    ];
});

test('an app\'s screenshots come from each app store that lists it, matched by id or name, in order', function () {
    expect(app(AppScreenshots::class)->for(($this->template)()))->toBe([
        ['url' => 'https://cdn.jsdelivr.net/gh/getumbrel/umbrel-apps-gallery@master/n8n/1.jpg', 'source' => 'Umbrel App Store'],
        ['url' => 'https://cdn.jsdelivr.net/gh/getumbrel/umbrel-apps-gallery@master/n8n/2.jpg', 'source' => 'Umbrel App Store'],
        ['url' => 'https://cdn.jsdelivr.net/gh/getumbrel/umbrel-apps-gallery@master/n8n/10.jpg', 'source' => 'Umbrel App Store'],
        ['url' => 'https://cdn.jsdelivr.net/gh/IceWhaleTech/CasaOS-AppStore@main/Apps/N8n/screenshot-1.png', 'source' => 'CasaOS App Store'],
    ])
        ->and(app(AppScreenshots::class)->for(($this->template)(['value' => 'dokploy/ghost-blog', 'label' => 'Ghost'])))->toBe([
            ['url' => 'https://cdn.jsdelivr.net/gh/IceWhaleTech/CasaOS-AppStore@main/Apps/Ghost/screenshot-1.png', 'source' => 'CasaOS App Store'],
        ]);

    // Each store's file list is read once.
    app(AppScreenshots::class)->for(($this->template)());
    Http::assertSentCount(2);
})->group('PRJ-012');

test('an app store that is off, or can\'t be read, adds no screenshots', function () {
    config(['sandbox.app_screenshots.galleries.umbrel.enabled' => false, 'sandbox.app_screenshots.galleries.casaos.repository' => 'gone/store']);
    Http::fake(['stores.test/repos/gone/*' => Http::response('', 403)]);

    expect(app(AppScreenshots::class)->for(($this->template)()))->toBe([]);
})->group('PRJ-012');

test('the website\'s preview image comes first, as an absolute https URL, credited to the site', function () {
    config(['sandbox.app_screenshots.website_previews' => true, 'sandbox.app_screenshots.galleries' => []]);
    Http::fake(['93.184.216.34/*' => Http::response('<html><head><meta content="/og.png?v=1&amp;x=2" property="og:image"><meta name="twitter:image" content="https://cdn.test/t.png"></head></html>')]);

    expect(app(AppScreenshots::class)->for(($this->template)(['links' => ['website' => 'https://93.184.216.34/app/', 'github' => null, 'docs' => null]])))
        ->toBe([['url' => 'https://93.184.216.34/og.png?v=1&x=2', 'source' => '93.184.216.34']]);
})->group('PRJ-012');

test('a website preview is cached, and none is asked of private addresses or when there is no image', function () {
    config(['sandbox.app_screenshots.website_previews' => true]);
    Http::fake(['93.184.216.35/*' => Http::response('<html><head><title>No image</title></head></html>')]);
    $screenshots = app(AppScreenshots::class);

    expect($screenshots->preview('https://93.184.216.35/'))->toBeNull()
        ->and($screenshots->preview('https://93.184.216.35/'))->toBeNull()
        ->and($screenshots->preview('https://127.0.0.1/'))->toBeNull()
        ->and($screenshots->preview('https://10.0.0.5/'))->toBeNull();

    Http::assertSentCount(1);
})->group('PRJ-012');

test('the new-project page asks for a free app\'s screenshots, and only registry templates have them', function () {
    $user = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($user)->getJson(route('templates.screenshots', ['template' => 'dokploy/n8n']))
        ->assertOk()
        ->assertJsonPath('screenshots.0.source', 'Umbrel App Store')
        ->assertJsonCount(4, 'screenshots');

    $this->actingAs($user)->getJson(route('templates.screenshots', ['template' => 'crm']))->assertNotFound();
    $this->actingAs($user)->getJson(route('templates.screenshots', ['template' => 'dokploy/missing']))->assertNotFound();
})->group('PRJ-012');

test('guests can\'t ask for screenshots', function () {
    $this->getJson(route('templates.screenshots', ['template' => 'dokploy/n8n']))->assertUnauthorized();
})->group('PRJ-012');

test('the coverflow shows the popular apps that have a picture, a store screenshot before the website\'s', function () {
    config([
        'sandbox.template_registries.dokploy.popular' => ['ghost', 'n8n'],
        'sandbox.app_screenshots.galleries.casaos.enabled' => false,
    ]);

    expect(collect(app(TemplateCatalog::class)->featured())->map(fn (array $app) => Arr::only($app, ['value', 'cover']))->all())->toBe([
        ['value' => 'dokploy/n8n', 'cover' => 'https://cdn.jsdelivr.net/gh/getumbrel/umbrel-apps-gallery@master/n8n/1.jpg'],
    ]);

    $user = User::factory()->has(AgentConnection::factory())->create();

    $this->actingAs($user)->followingRedirects()->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->missing('featured')
            ->loadDeferredProps('featured', fn ($reload) => $reload->has('featured', 1)->where('featured.0.label', 'n8n')));
})->group('PRJ-012');

test('an app with no store screenshot is shown with its website\'s preview image', function () {
    config(['sandbox.app_screenshots.website_previews' => true, 'sandbox.app_screenshots.galleries' => []]);
    Http::fake(['93.184.216.36/*' => Http::response('<meta property="og:image" content="https://93.184.216.36/og.png">')]);

    expect(app(AppScreenshots::class)->cover(($this->template)(['links' => ['website' => 'https://93.184.216.36/', 'github' => null, 'docs' => null]])))
        ->toBe('https://93.184.216.36/og.png')
        ->and(app(AppScreenshots::class)->cover(($this->template)(['value' => 'dokploy/nothing', 'label' => 'Nothing'])))->toBeNull();
})->group('PRJ-012');
