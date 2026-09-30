<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\BackUpDatabase;
use App\Jobs\RestoreDatabase;
use App\Sandbox\DatabaseBackups;
use Closure;
use Cron\CronExpression;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Backups of the app's own database (ADMIN-005).
 */
class DatabaseBackupController extends Controller
{
    /**
     * Show the backup settings and the backups at the destination (listed after the page loads; S3 can be slow).
     */
    public function index(DatabaseBackups $backups): Response
    {
        $settings = $backups->settings();

        return Inertia::render('admin/backups', [
            'settings' => [
                ...collect($settings)->except(['s3', 'last', 'last_restore'])->all(),
                's3' => [...collect($settings['s3'])->except('secret')->all(), 'secret_set' => filled($settings['s3']['secret'])],
            ],
            'database' => DB::connection()->getDriverName(),
            'last' => $settings['last'],
            'lastRestore' => $settings['last_restore'],
            'busy' => Cache::get(BackUpDatabase::BUSY),
            'backups' => Inertia::defer(function () use ($backups): array {
                try {
                    return ['items' => $backups->list(), 'error' => null];
                } catch (Throwable $e) {
                    return ['items' => [], 'error' => $e->getMessage()];
                }
            }),
        ]);
    }

    /**
     * Save the schedule, how many to keep, and where they go.
     */
    public function update(Request $request, DatabaseBackups $backups): RedirectResponse
    {
        $s3 = $request->input('destination') === 's3';

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'schedule' => ['required', 'string', 'max:100', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || ! CronExpression::isValidExpression($value)) {
                    $fail(__('Enter a cron expression, like 0 0 * * * (daily at midnight).'));
                }
            }],
            'keep' => ['required', 'integer', 'min:1', 'max:1000'],
            'destination' => ['required', Rule::in(['local', 's3'])],
            'prefix' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9._\/-]*$/', 'not_regex:/\.\./'],
            's3.bucket' => [Rule::requiredIf($s3), 'nullable', 'string', 'max:255'],
            's3.region' => ['nullable', 'string', 'max:100'],
            's3.endpoint' => ['nullable', 'url', 'max:255'],
            's3.key' => [Rule::requiredIf($s3), 'nullable', 'string', 'max:255'],
            's3.secret' => [Rule::requiredIf($s3 && ! filled($backups->settings()['s3']['secret'])), 'nullable', 'string', 'max:255'],
            's3.path_style' => ['nullable', 'boolean'],
        ]);

        $backups->update($validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Backup settings saved.')]);

        return to_route('admin.backups.index');
    }

    /**
     * Back up now.
     */
    public function store(): RedirectResponse
    {
        Cache::put(BackUpDatabase::BUSY, 'backup', now()->addHour());
        BackUpDatabase::dispatch();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Backing up the database.')]);

        return to_route('admin.backups.index');
    }

    /**
     * Download a backup.
     */
    public function download(DatabaseBackups $backups, string $backup): StreamedResponse
    {
        abort_unless(preg_match(DatabaseBackups::NAME, $backup) === 1 && $backups->disk()->exists($backups->path($backup)), 404);

        return $backups->disk()->download($backups->path($backup), $backup);
    }

    /**
     * Delete a backup.
     */
    public function destroy(DatabaseBackups $backups, string $backup): RedirectResponse
    {
        abort_unless(preg_match(DatabaseBackups::NAME, $backup) === 1, 404);

        $backups->delete($backup);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deleted :name.', ['name' => $backup])]);

        return to_route('admin.backups.index');
    }

    /**
     * Replace the database with a backup, once the admin has typed its name.
     */
    public function restore(Request $request, string $backup): RedirectResponse
    {
        abort_unless(preg_match(DatabaseBackups::NAME, $backup) === 1, 404);

        $request->validate(['confirm' => ['required', 'string', Rule::in([$backup])]], [
            'confirm.in' => __('Type the backup\'s name to restore it.'),
        ]);

        Cache::put(BackUpDatabase::BUSY, 'restore', now()->addHour());
        RestoreDatabase::dispatch($backup);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Restoring :name. Everything since will be replaced.', ['name' => $backup])]);

        return to_route('admin.backups.index');
    }
}
