<?php

namespace App\Providers;

use App\Tables\Tables;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The table kit: its endpoints, broadcast channel and attachments disk.
 */
class TablesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerDisk();
        $this->registerRoutes();
        $this->registerChannel();
    }

    /**
     * A private local disk for attachments, unless the app defines config('tables.disk') itself.
     */
    private function registerDisk(): void
    {
        $disk = config('tables.disk', 'table-attachments');

        if (config("filesystems.disks.{$disk}") !== null) {
            return;
        }

        config(["filesystems.disks.{$disk}" => [
            'driver' => 'local',
            'root' => rtrim(env('APP_STORAGE_DIR', storage_path('app/onedrop-storage')), '/').'/table-attachments',
            'visibility' => 'private',
            'serve' => false,
            'throw' => false,
        ]]);
    }

    private function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware(['web', 'auth'])
            ->prefix(config('tables.prefix', 'tables'))
            ->name('tables.')
            ->group(base_path('routes/tables.php'));
    }

    /**
     * "tables.{key}", for people who may view the table. Skipped when broadcasting isn't set up.
     */
    private function registerChannel(): void
    {
        if (in_array(config('broadcasting.default'), [null, '', 'null'], true)) {
            return;
        }

        rescue(fn () => Broadcast::channel(
            'tables.{table}',
            fn ($user, string $table) => Tables::find($table)?->can($user, 'view') ?? false,
        ), report: false);
    }
}
