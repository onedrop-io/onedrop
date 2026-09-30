<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SandboxStatus;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\ServerMetric;
use App\Models\User;
use App\Sandbox\SandboxMonitoring;
use App\Sandbox\ServerMetrics;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The server's CPU, memory, disk and network use, Docker's disk usage, and what the install holds (ADMIN-003).
 */
class ServerMonitoringController extends Controller
{
    /**
     * Show the Monitoring page.
     */
    public function show(Request $request, ServerMetrics $metrics): Response
    {
        $range = $request->validate(['range' => ['nullable', Rule::in(array_keys(SandboxMonitoring::RANGES))]])['range'] ?? '1h';

        // Without the scheduler (or before its first run) there'd be nothing to show yet.
        if (! ServerMetric::query()->where('recorded_at', '>', now()->subMinute())->exists()) {
            $metrics->record();
        }

        return Inertia::render('admin/monitoring', [
            'range' => $range,
            'metrics' => $metrics->summary($range),
            'counts' => [
                'users' => User::query()->count(),
                'projects' => Project::query()->count(),
                'sandboxes' => Sandbox::query()->count(),
                'running' => Sandbox::query()->where('status', SandboxStatus::Running)->count(),
                'providers' => Sandbox::query()->selectRaw('provider, COUNT(*) as count')->groupBy('provider')->pluck('count', 'provider')->map(fn ($count) => (int) $count),
            ],
            'docker' => Inertia::defer(fn () => $metrics->dockerDiskUsage()),
        ]);
    }
}
