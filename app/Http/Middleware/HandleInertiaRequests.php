<?php

namespace App\Http\Middleware;

use App\Concerns\DescribesRealtime;
use App\Concerns\SummarizesProjects;
use App\Models\Impersonation;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Sandbox\Branding;
use App\Sandbox\Templates\AppScreenshots;
use App\Sandbox\Templates\TemplateCatalog;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    use DescribesRealtime, SummarizesProjects;

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'logo' => app(Branding::class)->logoUrl(),
            'auth' => [
                'user' => $request->user(),
            ],
            // The organization the page is in and the others the user can switch to (ORG-002). Named so no page's own
            // props (a user's organizations on their admin page, every organization on Admin → Organizations) replace them.
            'currentOrganization' => fn () => $request->user() ? $this->organization($request->user(), ResolveOrganization::current($request)) : null,
            'userOrganizations' => fn () => $request->user()?->organizations()->orderBy('name')->get(['organizations.id', 'name', 'slug', 'logo_hash'])
                ->map(fn (Organization $organization): array => [...$organization->only('id', 'name', 'slug'), 'logo_url' => $organization->logoUrl()])->all(),
            'multiTenant' => Organization::multiTenant(),
            'impersonator' => fn () => $request->user() && $request->session()->has(Impersonation::SESSION_KEY)
                ? Impersonation::current()?->admin?->only('id', 'name')
                : null,
            'sidebarProjects' => fn () => $request->user() ? $this->sidebarProjects($request->user(), ResolveOrganization::current($request)) : null,
            // The project the user "opened" (TASK-001): the sidebar shows just it while they're on its pages.
            'openProject' => fn () => $request->user() ? $this->openProject($request->user(), (int) $request->cookie('open_project')) : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'sidebarWidth' => ((int) $request->cookie('sidebar_width')) ?: null,
            'realtime' => $this->realtime(),
            // What they picked on the home page, shown beside signing up and on the AI onboarding until the new-project page takes it (HOME-004).
            'pendingStart' => fn () => $this->pendingStart($request),
        ];
    }

    /**
     * @return array{prompt: string|null, template: array<string, mixed>|null}|null
     */
    protected function pendingStart(Request $request): ?array
    {
        $start = $request->session()->get('start');

        if (! is_array($start)) {
            return null;
        }

        $catalog = app(TemplateCatalog::class);
        $template = is_string($start['template'] ?? null) ? $catalog->find($start['template']) : null;

        if ($template && $catalog->registryFor($template['value'])) {
            $template['cover'] = app(AppScreenshots::class)->cover($template);
        }

        return ['prompt' => $start['prompt'] ?? null, 'template' => $template];
    }

    /**
     * @return array{id: int, name: string, slug: string, logo_url: string|null, role: string|null, manages: bool}
     */
    protected function organization(User $user, Organization $organization): array
    {
        return [
            ...$organization->only('id', 'name', 'slug'),
            'logo_url' => $organization->logoUrl(),
            'role' => $user->organizationRole($organization)?->value,
            'manages' => $organization->isManagedBy($user),
        ];
    }
}
