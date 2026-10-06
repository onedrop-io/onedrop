<?php

namespace App\Http\Controllers;

use App\Actions\DeleteProject;
use App\Concerns\RendersWorkspace;
use App\Enums\ProjectKind;
use App\Enums\SandboxStatus;
use App\Http\Middleware\ResolveOrganization;
use App\Jobs\CreateSandbox;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Sandbox\Agents\ModelCatalog;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Each person's own computer (CMP-001): a project of kind `computer` whose sandbox runs a desktop, with the chat
 * beside it for giving the AI tasks on it (CMP-002).
 */
class ComputerController extends Controller
{
    use RendersWorkspace;

    /** What the desktop script in the sandbox does when asked to restart it. */
    public const RESTART = ['/opt/onedrop/desktop', 'restart'];

    /**
     * Open the user's computer in the organization, making it the first time.
     */
    public function show(Request $request, ModelCatalog $catalog): Response
    {
        $organization = $this->organization($request);
        $user = $request->user();
        // Two tabs opening it for the first time make one computer, not two.
        $computer = $user->computerIn($organization)
            ?? Cache::lock("computer:{$user->id}:{$organization->id}", 10)->block(5, fn () => $user->computerIn($organization) ?? $this->create($user, $organization, $catalog));

        return $this->renderWorkspace($request, $computer, $computer);
    }

    /**
     * Start the computer again after it failed to start.
     */
    public function retry(Request $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $computer = $request->user()->computerIn($organization) ?? abort(404);
        $sandbox = $computer->sandbox;

        if ($sandbox === null || $sandbox->status === SandboxStatus::Failed) {
            $computer->sandbox()->updateOrCreate([], ['provider' => $computer->sandboxProvider(), 'status' => SandboxStatus::Creating, 'error' => null]);
            CreateSandbox::dispatch($computer);
        }

        return to_route('computers.show', $organization);
    }

    /**
     * Close every program on the desktop and start it again; files stay (CMP-001).
     */
    public function restart(Request $request, SandboxProvider $provider): RedirectResponse
    {
        $organization = $this->organization($request);
        $sandbox = $request->user()->computerIn($organization)?->sandbox;

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            return back()->withErrors(['computer' => __("Your computer isn't running.")]);
        }

        try {
            $sandbox->wake($provider);
            $provider->exec($sandbox->external_id, self::RESTART, detach: true);
        } catch (SandboxException $e) {
            return back()->withErrors(['computer' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Restarting your desktop…')]);

        return back();
    }

    /**
     * Throw the computer away and make a new one: its Home, sign-ins and chat go; Drive stays (CMP-001).
     */
    public function destroy(Request $request, DeleteProject $deleteProject): RedirectResponse
    {
        $organization = $this->organization($request);
        $computer = $request->user()->computerIn($organization);

        if ($computer) {
            $deleteProject->handle($computer);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your computer was reset.')]);

        return to_route('computers.show', $organization);
    }

    /**
     * The organization in the address, when its people have computers (CMP-003).
     */
    protected function organization(Request $request): Organization
    {
        $organization = ResolveOrganization::current($request);

        abort_unless($organization->computersEnabled(), 404);

        return $organization;
    }

    /**
     * The user's new computer, on the agent they'd use for a new project, with its sandbox on the way.
     */
    protected function create(User $user, Organization $organization, ModelCatalog $catalog): Project
    {
        $computer = $user->computers()->create([
            'organization_id' => $organization->id,
            'kind' => ProjectKind::Computer,
            'name' => __('Computer'),
            'prompt' => '',
            // Only an app's requirements are kept (REQ-001), and only an app's preview has errors to fix (ERR-001).
            'track_requirements' => false,
            'autofix' => false,
            ...($catalog->newProjectAgent($user) ?? []),
        ]);

        $computer->sandbox()->create([
            'provider' => $computer->sandboxProvider(),
            'status' => SandboxStatus::Creating,
        ]);

        CreateSandbox::dispatch($computer);

        return $computer;
    }
}
