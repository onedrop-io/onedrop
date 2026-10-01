<?php

namespace App\Http\Controllers;

use App\Actions\UsageReport;
use App\Http\Middleware\ResolveOrganization;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tokens and estimated cost of the user's agent runs (USAGE-001), and of an organization's (ORG-005).
 */
class UsageController extends Controller
{
    /**
     * Show usage for the past 24 hours, 7, 30 (the default) or 90 days.
     */
    public function index(Request $request, UsageReport $report): Response
    {
        $range = $request->validate(['range' => ['nullable', Rule::in(array_keys(UsageReport::RANGES))]])['range'] ?? '30d';

        return Inertia::render('usage/index', $report->for($request->user(), $range));
    }

    /**
     * Usage across the organization's projects, for its owners and admins (ORG-005).
     */
    public function organization(Request $request, UsageReport $report): Response
    {
        $organization = ResolveOrganization::current($request);

        abort_unless($organization->isManagedBy($request->user()), 403);

        $range = $request->validate(['range' => ['nullable', Rule::in(array_keys(UsageReport::RANGES))]])['range'] ?? '30d';

        return Inertia::render('usage/index', [...$report->forOrganization($organization, $range), 'title' => $organization->name]);
    }
}
