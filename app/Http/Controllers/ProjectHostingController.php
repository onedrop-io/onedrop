<?php

namespace App\Http\Controllers;

use App\Enums\PublishStatus;
use App\Enums\PublishTarget;
use App\Jobs\ConfirmPublication;
use App\Models\Deployment;
use App\Models\Project;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\Hosting\Deployer;
use App\Sandbox\Hosting\HostedServices;
use App\Sandbox\Hosting\HostingChanges;
use App\Sandbox\Hosting\HostingException;
use App\Sandbox\Hosting\MachineSizes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ProjectHostingController extends Controller
{
    /** What the agent is asked to do for Move to Postgres (HOST-009); guides/hosting.md says how. */
    public const MOVE_TO_POSTGRES_REQUEST = "Move this app from SQLite to Postgres so its hosted copy can scale. Follow \"Moving to Postgres\" in /opt/onedrop/guides/hosting.md. Keep the SQLite file where it is: the hosted app's data is copied from it when it's next published.";

    /**
     * Delete everything the project has at hosting providers, its data included (HOST-002). Only once it isn't
     * published there.
     */
    public function destroy(Project $project, HostedServices $services): RedirectResponse
    {
        Gate::authorize('update', $project);

        if ($project->publish_target === PublishTarget::Hosting && $project->publish_status !== null) {
            throw ValidationException::withMessages(['hosting' => __('Unpublish it first.')]);
        }

        $services->destroyAll($project);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deleting its hosted data.')]);

        return to_route('projects.show', $project);
    }

    /**
     * Turn updating the hosted app by itself after a turn that went well on or off (HOST-006).
     */
    public function update(Request $request, Project $project, Deployer $deployer, HostingChanges $changes): RedirectResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'auto_deploy' => ['sometimes', 'boolean'],
            'size' => ['sometimes', Rule::in(array_keys(MachineSizes::SIZES))],
        ]);

        if (isset($validated['auto_deploy'])) {
            $project->update(['auto_deploy' => $validated['auto_deploy']]);

            Inertia::flash('toast', ['type' => 'success', 'message' => $project->auto_deploy
                ? __('The hosted app will update itself after each turn that goes well.')
                : __('The hosted app only updates when you publish.')]);
        }

        // A new machine size restarts the live version on it (HOST-010).
        if (isset($validated['size']) && $validated['size'] !== ($project->hosting_size ?? MachineSizes::DEFAULT)) {
            $project->update(['hosting_size' => $validated['size']]);

            // Hosted with a server now: restart it on the new size. Otherwise the next deploy uses it.
            if ($changes->live($project)?->kind === 'server') {
                try {
                    $deployer->resize($project, $request->user());
                } catch (HostingException $e) {
                    throw ValidationException::withMessages(['publish' => $e->getMessage()]);
                }

                $project->update(['publish_status' => PublishStatus::Publishing, 'publish_error' => null]);
                ConfirmPublication::dispatch($project)->delay(now()->addSeconds(2));
            }

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Machine size: :size.', ['size' => MachineSizes::SIZES[$validated['size']]['label']])]);
        }

        return to_route('projects.show', $project);
    }

    /**
     * Move the hosted app from SQLite to Postgres (HOST-009): the agent switches the app over in the sandbox, and the
     * next deploy makes a Neon database and copies the hosted SQLite data into it, once.
     */
    public function moveToPostgres(Project $project, Deployer $deployer, AgentQueue $queue): RedirectResponse
    {
        Gate::authorize('update', $project);

        $sqlite = $deployer->hostedSqlite($project);

        if ($sqlite === null) {
            throw ValidationException::withMessages(['publish' => __('Only a hosted app that keeps its data in SQLite can move to Postgres.')]);
        }

        $project->update(['hosting_sqlite_import' => $sqlite]);
        $queue->send($project, self::MOVE_TO_POSTGRES_REQUEST);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The agent is switching the app to Postgres. Update the hosted app once it\'s done.')]);

        return to_route('projects.show', $project);
    }

    /**
     * Put an earlier deploy's version back (HOST-005).
     */
    public function rollBack(Request $request, Project $project, Deployment $deployment, Deployer $deployer): RedirectResponse
    {
        Gate::authorize('update', $project);

        if ($project->publish_target !== PublishTarget::Hosting || $project->publish_status === null) {
            throw ValidationException::withMessages(['publish' => __('It isn\'t published to hosting.')]);
        }

        try {
            $put = $deployer->putBack($project, $deployment, $request->user());
        } catch (HostingException $e) {
            throw ValidationException::withMessages(['publish' => $e->getMessage()]);
        }

        $project->update(['publish_status' => PublishStatus::Publishing, 'publish_error' => null, 'published_by' => $request->user()->id]);
        ConfirmPublication::dispatch($project)->delay(now()->addSeconds(2));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Putting deploy #:number back.', ['number' => $deployment->number])]);

        return to_route('projects.show', $project);
    }

    /**
     * The builder machine's log, sent back when it's done (a signed link, so only that builder can).
     */
    public function log(Request $request, Deployment $deployment): Response
    {
        $log = trim(substr((string) $request->getContent(), -8000));

        if ($log !== '') {
            $deployment->note("Builder log:\n{$log}");
        }

        return response()->noContent();
    }
}
