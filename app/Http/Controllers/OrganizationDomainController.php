<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveOrganization;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Sandbox\Domains\DomainDns;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * An organization's email domains (ORG-008): people whose verified email is at a verified one can join it.
 * Hosted install only; a self-hosted install's one organization has everyone already.
 */
class OrganizationDomainController extends Controller
{
    /**
     * Add a domain, waiting for its TXT record.
     */
    public function store(Request $request): RedirectResponse
    {
        $organization = $this->managed($request);

        $request->merge(['domain' => Str::of((string) $request->input('domain'))->trim()->lower()->after('@')->rtrim('.')->toString()]);

        $domain = $request->validate([
            'domain' => [
                'required', 'string', 'max:253',
                'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',
                Rule::unique('organization_domains', 'domain')->where('organization_id', $organization->id),
                Rule::unique('organization_domains', 'domain')->whereNotNull('verified_at'),
            ],
        ], [
            'domain.regex' => __('Enter a domain, like acme.com.'),
            'domain.unique' => __('That domain has already been added.'),
        ])['domain'];

        $organization->domains()->create(['domain' => $domain]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Added :domain. Add its TXT record, then check it.', ['domain' => $domain])]);

        return to_route('organizations.edit', $organization);
    }

    /**
     * Look for its TXT record, and verify it once it's there.
     */
    public function verify(Request $request, OrganizationDomain $domain): RedirectResponse
    {
        $organization = $this->managed($request);

        abort_unless($domain->organization_id === $organization->id, 404);

        if ($domain->takenElsewhere()) {
            throw ValidationException::withMessages(['domain' => __('Another organization has already verified :domain.', ['domain' => $domain->domain])]);
        }

        if (! in_array($domain->txtValue(), app(DomainDns::class)->txt($domain->domain), true)) {
            throw ValidationException::withMessages(['domain' => __("We couldn't find the TXT record on :domain yet. DNS changes can take a few minutes to show up.", ['domain' => $domain->domain])]);
        }

        $domain->forceFill(['verified_at' => now()])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Verified :domain.', ['domain' => $domain->domain])]);

        return to_route('organizations.edit', $organization);
    }

    /**
     * Remove a domain. People who joined through it stay.
     */
    public function destroy(Request $request, OrganizationDomain $domain): RedirectResponse
    {
        $organization = $this->managed($request);

        abort_unless($domain->organization_id === $organization->id, 404);

        $domain->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Removed :domain.', ['domain' => $domain->domain])]);

        return to_route('organizations.edit', $organization);
    }

    protected function managed(Request $request): Organization
    {
        abort_unless(Organization::multiTenant(), 404);

        $organization = ResolveOrganization::current($request);

        abort_unless($organization->isManagedBy($request->user()), 403);

        return $organization;
    }
}
