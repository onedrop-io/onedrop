<?php

namespace App\Sandbox;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Artisan;

/**
 * Lays the settings admins save in the app (ADMIN-001, ADMIN-002) over config/, so they win over `.env` and the
 * rest of the code keeps reading config().
 */
class SystemConfig
{
    /**
     * Apply the saved settings to this process's config.
     */
    public static function apply(): void
    {
        // The name from `.env`, kept for when an admin clears theirs.
        config(['app.default_name' => config('app.default_name', config('app.name'))]);
        $name = SystemSetting::group(Branding::SETTING)['name'] ?? null;
        config(['app.name' => filled($name) ? $name : config('app.default_name')]);

        $sandboxes = SystemSetting::group(SandboxProviders::SETTING);

        foreach (SandboxProviders::PROVIDERS as $provider => $definition) {
            foreach (array_intersect_key($sandboxes['providers'][$provider] ?? [], $definition['fields']) as $key => $value) {
                config(["sandbox.providers.{$provider}.{$key}" => $value]);
            }
        }

        // The test suite's fake provider is never swapped out.
        $active = $sandboxes['active'] ?? null;

        if (isset(SandboxProviders::PROVIDERS[$active]) && config('sandbox.provider') !== 'fake') {
            config(['sandbox.provider' => $active]);
        }

        // Built from config when first used; make it again with the new settings.
        app()->forgetInstance(SandboxProvider::class);
    }

    /**
     * Apply settings that were just saved here, and have queue workers (long-running, their config read at start)
     * restart after their current job so they use them too.
     */
    public static function saved(): void
    {
        static::apply();
        Artisan::call('queue:restart');
    }
}
