<?php

namespace App\Providers;

use App\Http\Controllers\Tables\FormController;
use App\Tables\Tables;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The table kit: its endpoints, public form links, broadcast channel and attachments disk.
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

        $this->registerPublicFormRoutes();
    }

    /**
     * Public form links (TABLE-009), for people without an account: no auth, and sends and uploads throttled per visitor.
     */
    private function registerPublicFormRoutes(): void
    {
        RateLimiter::for('table-forms', fn (Request $request) => Limit::perMinute((int) config('tables.form_submissions_per_minute', 10))->by($request->ip()));
        RateLimiter::for('table-form-uploads', fn (Request $request) => Limit::perMinute((int) config('tables.form_uploads_per_minute', 20))->by($request->ip()));

        Route::middleware('web')
            ->prefix(config('tables.forms_prefix', 'forms'))
            ->name('tables.public-forms.')
            ->group(function () {
                Route::get('{token}', [FormController::class, 'showPublic'])->name('show');
                Route::post('{token}', [FormController::class, 'submitPublic'])->middleware('throttle:table-forms')->name('submit');
                Route::post('{token}/attachments', [FormController::class, 'uploadPublic'])->middleware('throttle:table-form-uploads')->name('attachments');
            });
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
