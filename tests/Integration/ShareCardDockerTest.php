<?php

use App\Enums\ShareCardStatus;
use App\Jobs\CaptureShareCard;
use App\Jobs\CreateSandbox;
use App\Models\Project;
use App\Models\ProjectShare;
use App\Sandbox\Providers\DockerSandboxProvider;
use App\Sandbox\SandboxProvider;
use App\Sandbox\ShareCards;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
 * Talks to real Docker. Opt in with: RUN_DOCKER_TESTS=1 vendor/bin/pest tests/Integration
 * Needs `php artisan sandbox:build-image` first.
 */
uses(TestCase::class);

beforeEach(function () {
    if (! env('RUN_DOCKER_TESTS')) {
        $this->markTestSkipped('Set RUN_DOCKER_TESTS=1 to run Docker integration tests.');
    }
});

test('headless Chromium in the sandbox screenshots the app and renders its share card', function () {
    $this->artisan('migrate:fresh');
    Storage::fake(ShareCards::DISK);
    $storage = sys_get_temp_dir().'/zap-share-card-'.bin2hex(random_bytes(3));
    config(['sandbox.provider' => 'docker', 'sandbox.providers.docker.storage_path' => $storage]);
    $docker = new DockerSandboxProvider(config('sandbox.providers.docker'));
    app()->instance(SandboxProvider::class, $docker);

    $project = Project::factory()->create(['name' => 'Team CRM']);
    CreateSandbox::dispatchSync($project);
    $id = $project->sandbox()->firstOrFail()->external_id;

    try {
        // An app whose page is drawn by JavaScript, so the screenshot proves scripts ran first.
        $setup = 'cd /workspace && mkdir -p .zap public'
            .' && echo "<body><div id=app></div><script>app.textContent=\"Pipeline\"</script></body>" > public/index.html'
            .' && printf "#!/usr/bin/env bash\nexec php -S 0.0.0.0:\$PORT -t /workspace/public\n" > .zap/dev && chmod +x .zap/dev'
            .' && /opt/zap/restart';
        expect($docker->exec($id, ['bash', '-c', $setup])->successful())->toBeTrue();
        sleep(2);

        $share = ProjectShare::factory()->for($project)->create(['prompt' => 'A CRM for our sales team']);
        CaptureShareCard::dispatchSync($share);
        $share->refresh();

        expect($share->card_status)->toBe(ShareCardStatus::Ready, (string) $share->card_error);

        $card = getimagesizefromstring(Storage::disk(ShareCards::DISK)->get($share->card_file));
        $screenshot = getimagesizefromstring(Storage::disk(ShareCards::DISK)->get($share->screenshot_file));

        expect([$card[0], $card[1]])->toBe([1200, 630])
            ->and([$screenshot[0], $screenshot[1]])->toBe([1280, 800]);
    } finally {
        $docker->destroy($id);
        File::deleteDirectory($storage);
    }
})->group('SHARE-001');
