<?php

namespace App\Http\Middleware;

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\PublishStatus;
use App\Enums\SandboxStatus;
use App\Enums\TaskStage;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Sandbox\Agents\ProjectNamer;
use App\Sandbox\Branding;
use App\Sandbox\ProjectIcons;
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
            'logo' => app(Branding::class)->logoUrl(),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarProjects' => fn () => $request->user() ? $this->sidebarProjects($request->user()) : null,
            // The project the user "opened" (TASK-001): the sidebar shows just it while they're on its pages.
            'openProject' => fn () => $request->user() ? $this->openProject($request->user(), (int) $request->cookie('open_project')) : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'sidebarWidth' => ((int) $request->cookie('sidebar_width')) ?: null,
            'realtime' => $this->realtime(),
        ];
    }

    /**
     * Where the browser connects for live updates (LIVE-001), or null when the app doesn't broadcast (pages poll instead).
     * Read at runtime rather than built into the assets, so one build works at any address.
     *
     * @return array{key: string, host: string|null, port: int, scheme: string}|null
     */
    protected function realtime(): ?array
    {
        if (config('broadcasting.default') !== 'reverb' || ! config('broadcasting.connections.reverb.key')) {
            return null;
        }

        $browser = config('broadcasting.connections.reverb.browser');

        return [
            'key' => (string) config('broadcasting.connections.reverb.key'),
            'host' => ($browser['host'] ?? null) ?: null,
            'port' => (int) $browser['port'],
            'scheme' => (string) $browser['scheme'],
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
            ->select(['id', 'name', 'status', 'pinned_at', 'read_at', 'archived_at', 'publish_status', 'published_url', 'icon_path', 'icon_hash'])
            ->with([
                'sandbox:id,project_id,status',
                'tasks' => fn ($query) => $query->where('stage', '!=', TaskStage::Done)->select(['id', 'project_id', 'title', 'stage', 'status', 'position']),
            ])
            ->withExists(['tasks as task_working' => fn (Builder $query) => $query->where('status', ProjectStatus::Working)])
            ->withMax(['allMessages as last_reply_at' => fn (Builder $query) => $query->where('role', MessageRole::Assistant)], 'created_at');

        $lists = [
            'pinned' => $query()->whereNull('archived_at')->whereNotNull('pinned_at')->oldest('pinned_at')->get(),
            'recent' => $query()->whereNull('archived_at')->whereNull('pinned_at')->latest('updated_at')->limit(10)->get(),
            'archived' => $query()->whereNotNull('archived_at')->latest('archived_at')->limit(20)->get(),
        ];

        $ids = array_values(collect($lists)->collapse()->map(fn (Project $project): int => $project->id)->all());
        $naming = ProjectNamer::naming($ids);
        $drawing = ProjectIcons::drawing($ids);

        $summarize = fn (Project $project): array => [
            'id' => $project->id,
            'name' => $project->name,
            'pinned' => $project->pinned_at !== null,
            'archived' => $project->archived_at !== null,
            'unread' => $project->read_at === null
                || ($project->last_reply_at && Carbon::parse($project->last_reply_at)->greaterThan($project->read_at)),
            'naming' => in_array($project->id, $naming, true),
            'published_url' => $project->publish_status === PublishStatus::Live ? $project->published_url : null,
            'working' => $project->status === ProjectStatus::Working || $project->task_working,
            'activity' => $project->status === ProjectStatus::Working ? $this->currentActivity($project) : null,
            'tasks' => $project->tasks->map($this->summarizeTask(...))->all(),
            'failed' => $project->sandbox?->status === SandboxStatus::Failed,
            'icon_url' => ProjectIcons::url($project),
            'drawing_icon' => in_array($project->id, $drawing, true),
        ];

        return array_map(fn ($projects) => array_values($projects->map($summarize)->all()), $lists);
    }

    /**
     * The opened project with every task, for the sidebar's project view; null when none is open or it isn't the user's.
     *
     * @return array{id: int, name: string, working: bool, tasks: list<array<string, mixed>>}|null
     */
    protected function openProject(User $user, int $projectId): ?array
    {
        $project = $projectId > 0 ? Project::query()->find($projectId) : null;

        if ($project === null || $user->cannot('view', $project)) {
            return null;
        }

        return [
            'id' => $project->id,
            'name' => $project->name,
            'working' => $project->status === ProjectStatus::Working,
            'tasks' => array_values($project->tasks()->get()->map($this->summarizeTask(...))->all()),
        ];
    }

    /**
     * @return array{id: int, title: string, stage: TaskStage, working: bool, activity: string|null}
     */
    protected function summarizeTask(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'stage' => $task->stage,
            'working' => $task->isWorking(),
            'activity' => $task->isWorking() ? $task->currentActivity() : null,
        ];
    }

    /**
     * The agent's latest step in its current run, or null before its first one.
     */
    protected function currentActivity(Project $project): ?string
    {
        $lastPromptId = $project->messages()->reorder()->where('role', MessageRole::User)->max('id') ?? 0;

        return $project->messages()->reorder()
            ->where('role', MessageRole::Activity)
            ->where('id', '>', $lastPromptId)
            ->latest('id')
            ->value('content');
    }
}
