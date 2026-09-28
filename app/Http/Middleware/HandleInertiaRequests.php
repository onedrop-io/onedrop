<?php

namespace App\Http\Middleware;

use App\Enums\MessageRole;
use App\Enums\PublishStatus;
use App\Models\Project;
use App\Models\User;
use App\Sandbox\Agents\ProjectNamer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
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
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarProjects' => fn () => $request->user() ? $this->sidebarProjects($request->user()) : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'sidebarWidth' => ((int) $request->cookie('sidebar_width')) ?: null,
        ];
    }

    /**
     * The user's projects for the sidebar: pinned ones, the 10 most recent others, and archived ones.
     *
     * @return array{pinned: list<array<string, mixed>>, recent: list<array<string, mixed>>, archived: list<array<string, mixed>>}
     */
    protected function sidebarProjects(User $user): array
    {
        $query = fn () => $user->projects()
            ->select(['id', 'name', 'pinned_at', 'read_at', 'archived_at', 'publish_status', 'published_url'])
            ->withMax(['messages as last_reply_at' => fn (Builder $query) => $query->where('role', MessageRole::Assistant)], 'created_at');

        $lists = [
            'pinned' => $query()->whereNull('archived_at')->whereNotNull('pinned_at')->oldest('pinned_at')->get(),
            'recent' => $query()->whereNull('archived_at')->whereNull('pinned_at')->latest('updated_at')->limit(10)->get(),
            'archived' => $query()->whereNotNull('archived_at')->latest('archived_at')->limit(20)->get(),
        ];

        $naming = ProjectNamer::naming(collect($lists)->collapse()->pluck('id')->all());

        $summarize = fn (Project $project): array => [
            'id' => $project->id,
            'name' => $project->name,
            'pinned' => $project->pinned_at !== null,
            'archived' => $project->archived_at !== null,
            'unread' => $project->read_at === null
                || ($project->last_reply_at && Carbon::parse($project->last_reply_at)->greaterThan($project->read_at)),
            'naming' => in_array($project->id, $naming, true),
            'published_url' => $project->publish_status === PublishStatus::Live ? $project->published_url : null,
        ];

        return array_map(fn ($projects) => $projects->map($summarize)->all(), $lists);
    }
}
