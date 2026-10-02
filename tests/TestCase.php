<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The model catalog (models.dev) is served from a fixture, never the network. ChatGPT doesn't
        // list a ChatGPT sign-in's models, so they're the ones OpenAI includes with Codex.
        Http::fake([
            'models.test/*' => Http::response(file_get_contents(__DIR__.'/Fixtures/models-dev.json'), 200, ['Content-Type' => 'application/json']),
            'chatgpt.test/*' => Http::response('', 503),
            // Dokploy's template registry (PRJ-012): two templates, and n8n's files.
            'dokploy.test/meta.json' => Http::response(file_get_contents(__DIR__.'/Fixtures/dokploy-meta.json'), 200, ['Content-Type' => 'application/json']),
            'dokploy.test/blueprints/n8n/docker-compose.yml' => Http::response(file_get_contents(__DIR__.'/Fixtures/dokploy-n8n-compose.yml')),
            'dokploy.test/blueprints/n8n/template.toml' => Http::response(file_get_contents(__DIR__.'/Fixtures/dokploy-n8n-template.toml')),
            'dokploy.test/*' => Http::response('', 404),
            // App stores' screenshot galleries (PRJ-012): none unless a test lists some.
            'github.test/*' => Http::response('', 404),
        ]);

        // Never the real storage/app/sandboxes: signing out of Claude there (SignOutOfClaude) emptied
        // the dev user's shared Claude login on every test run.
        config(['sandbox.providers.docker.storage_path' => self::sandboxStoragePath()]);
    }

    /**
     * A throwaway Docker storage folder for this test process, deleted when it exits.
     */
    protected static function sandboxStoragePath(): string
    {
        static $path = null;

        if ($path === null) {
            $path = sys_get_temp_dir().'/onedrop-test-sandboxes-'.getmypid();
            @mkdir($path);
            register_shutdown_function(fn () => exec('rm -rf '.escapeshellarg($path)));
        }

        return $path;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
