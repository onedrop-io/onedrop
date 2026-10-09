<?php

namespace App\Sandbox;

use App\Models\SystemSetting;
use App\Sandbox\Providers\E2bSandboxProvider;
use App\Sandbox\Providers\RoutingSandboxProvider;

/**
 * Sandbox images the app builds itself (SBX-014): E2B makes its template from the published sandbox image
 * (docker/sandbox, pushed to GitHub's registry by the `images` workflow) whenever its size changes in Settings →
 * Sandboxes or a new image is published (the workflow tells the app: SandboxImageController). Nothing waits on a
 * build: E2B moves the template's name to it once it's ready, and the settings page asks how it's doing when opened.
 */
class SandboxTemplates
{
    /** The last build started for each provider, and the settings it was started with. */
    public const SETTING = 'sandbox_templates';

    /** Providers whose sandbox image the app builds. */
    public const PROVIDERS = ['e2b'];

    /** Settings a build is made from: changing one needs a new build. */
    protected const BUILD_SETTINGS = ['image', 'source_image', 'vcpu', 'memory_mib', 'disk_mib'];

    public function __construct(protected SandboxProvider $provider) {}

    /**
     * Whether the app can build this provider's image now: it builds it, and the provider has its key.
     */
    public function builds(string $name): bool
    {
        return in_array($name, self::PROVIDERS, true) && app(SandboxProviders::class)->missing($name) === [];
    }

    /**
     * Whether the image was never built here, its last build failed, or it was built with other settings than it has now.
     */
    public function needsBuild(string $name): bool
    {
        $last = SystemSetting::group(self::SETTING)[$name] ?? [];

        return $this->builds($name) && (($last['settings'] ?? null) !== $this->settings($name) || ($last['status'] ?? null) === 'error');
    }

    /**
     * Start a build with the provider's current settings.
     *
     * @throws SandboxException
     */
    public function build(string $name): void
    {
        $build = $this->e2b()->buildTemplate();

        SystemSetting::merge(self::SETTING, [$name => [
            'template_id' => $build['templateID'],
            'build_id' => $build['buildID'],
            'settings' => $this->settings($name),
            'status' => 'building',
            'reason' => null,
            'started_at' => now()->toIso8601String(),
        ]]);
    }

    /**
     * How the last build is doing, for Settings → Sandboxes: E2B is asked while it's still building.
     *
     * @return array{status: string, reason: ?string, started_at: ?string}|null
     */
    public function status(string $name): ?array
    {
        $build = SystemSetting::group(self::SETTING)[$name] ?? null;

        if (! is_array($build) || ! isset($build['template_id'], $build['build_id'])) {
            return null;
        }

        if (($build['status'] ?? 'building') === 'building' && $this->builds($name)) {
            try {
                ['status' => $status, 'reason' => $reason] = $this->e2b()->buildStatus($build['template_id'], $build['build_id']);
                $build = [...$build, 'status' => $status === 'waiting' ? 'building' : $status, 'reason' => $reason];
                SystemSetting::merge(self::SETTING, [$name => $build]);
            } catch (SandboxException $e) {
                // Shown as it was last known; the next visit asks again.
                report($e);
            }
        }

        return ['status' => (string) $build['status'], 'reason' => $build['reason'] ?? null, 'started_at' => $build['started_at'] ?? null];
    }

    /**
     * @return array<string, mixed>
     */
    protected function settings(string $name): array
    {
        $config = config("sandbox.providers.{$name}", []);

        return array_map(fn (string $key) => $config[$key] ?? null, array_combine(self::BUILD_SETTINGS, self::BUILD_SETTINGS));
    }

    /**
     * @throws SandboxException
     */
    protected function e2b(): E2bSandboxProvider
    {
        $provider = $this->provider instanceof RoutingSandboxProvider ? $this->provider->provider('e2b') : $this->provider;

        if (! $provider instanceof E2bSandboxProvider) {
            throw new SandboxException("E2B isn't available here.");
        }

        return $provider;
    }
}
