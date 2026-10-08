<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\HostingProviderController;
use App\Http\Middleware\ResolveOrganization;
use App\Models\HostedService;
use App\Sandbox\Hosting\HostingProviders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * An organization's own hosting accounts (HOST-003): its apps are deployed there instead of the install's.
 */
class OrganizationHostingController extends Controller
{
    /**
     * Its own hosting accounts, and how much of its apps' hosting is in each.
     */
    public function index(Request $request, HostingProviders $providers): Response
    {
        $organization = ResolveOrganization::current($request);
        abort_unless($organization->isManagedBy($request->user()), 403);

        return Inertia::render('organizations/hosting', [
            'hosting' => $providers->describeFor(
                $organization,
                HostedService::query()->where('owner', HostedService::OWNER_ORGANIZATION)
                    ->whereHas('project', fn ($query) => $query->where('organization_id', $organization->id))
                    ->selectRaw('provider, COUNT(*) as count')->groupBy('provider')
                    ->pluck('count', 'provider')->map(fn ($count) => (int) $count)->all(),
            ),
        ]);
    }

    /**
     * Connect (or change) the organization's own account for a provider.
     */
    public function update(Request $request, HostingProviders $providers, string $provider): RedirectResponse
    {
        abort_unless(isset(HostingProviders::PROVIDERS[$provider]), 404);
        $organization = ResolveOrganization::current($request);
        abort_unless($organization->isManagedBy($request->user()), 403);

        $fields = array_filter(HostingProviders::PROVIDERS[$provider]['fields'], fn (array $field) => $field['account'] ?? false);
        $validated = $request->validate(HostingProviderController::rules($fields));
        $saved = $organization->hosting_accounts[$provider] ?? [];

        // Everything it needs, counting a key saved before (left blank to keep it).
        $missing = $providers->missing($provider, [...$saved, ...Arr::where($validated, fn ($value) => filled($value))]);

        if ($missing !== []) {
            throw ValidationException::withMessages(array_fill_keys($missing, __('Required.')));
        }

        $providers->connect($organization, $provider, $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connected :provider.', ['provider' => HostingProviders::PROVIDERS[$provider]['label']])]);

        return back();
    }

    /**
     * Forget the organization's own account. Not while apps still have something in it: OneDrop couldn't manage or
     * delete them any more.
     */
    public function destroy(Request $request, HostingProviders $providers, string $provider): RedirectResponse
    {
        abort_unless(isset(HostingProviders::PROVIDERS[$provider]), 404);
        $organization = ResolveOrganization::current($request);
        abort_unless($organization->isManagedBy($request->user()), 403);

        $inUse = HostedService::query()
            ->where('provider', $provider)
            ->where('owner', HostedService::OWNER_ORGANIZATION)
            ->whereHas('project', fn ($query) => $query->where('organization_id', $organization->id))
            ->count();

        if ($inUse > 0) {
            throw ValidationException::withMessages(['hosting' => trans_choice('{1} An app still has something in this account. Delete its hosted data first (Publish panel).|[2,*] :count things in this account still belong to apps. Delete their hosted data first (Publish panel).', $inUse)]);
        }

        $providers->disconnect($organization, $provider);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Disconnected :provider.', ['provider' => HostingProviders::PROVIDERS[$provider]['label']])]);

        return back();
    }
}
