<?php

namespace App\Concerns;

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\PublishStatus;
use App\Enums\SandboxStatus;
use App\Enums\TaskStage;
use App\Jobs\CheckTurnOutcome;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Sandbox\Agents\PlainActivity;
use App\Sandbox\Agents\ProjectNamer;
use App\Sandbox\ProjectIcons;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The user's projects as the sidebar lists them (PRJ-003, PRJ-006, PRJ-008, PRJ-010): for the web app's pages and the desktop app (DESK-002).
 */
trait SummarizesProjects
{
    /**
     * The user's projects in the organization for the sidebar: pinned ones, the first 10 others, and archived ones,
     * with pinned and others in the order the user chose (PRJ-010).
     *
     * @return array{pinned: list<array<string, mixed>>, recent: list<array<string, mixed>>, archived: list<array<string, mixed>>, sort: string}
     */
    protected function sidebarProjects(User $user, Organization $organization): array
    {
        $query = fn () => $user->projects()
            ->inOrganization($organization)
            ->select(['id', 'name', 'status', 'pinned_at', 'read_at', 'archived_at', 'publish_status', 'published_url', 'icon_path', 'icon_hash', 'turn_outcome'])
            ->with([
                'sandbox:id,project_id,status',
                'tasks' => fn ($query) => $query->where('stage', '!=', TaskStage::Done)->select(['id', 'project_id', 'title', 'stage', 'status', 'position', 'read_at', 'turn_outcome'])->withLastReply(),
            ])
            ->withExists(['tasks as task_working' => fn (Builder $query) => $query->where('status', ProjectStatus::Working)])
            ->withMax(['messages as last_reply_at' => fn (Builder $query) => $query->where('role', MessageRole::Assistant)], 'created_at');

        $sort = $user->project_sort;

        $lists = [
            'pinned' => $query()->whereNull('archived_at')->whereNotNull('pinned_at')->sortedBy($sort)->get(),
            'recent' => $query()->whereNull('archived_at')->whereNull('pinned_at')->sortedBy($sort)->limit(10)->get(),
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
            // The main chat or one of its open tasks has a reply the owner hasn't seen (PRJ-003, PRJ-008).
            'unread' => $this->mainChatUnread($project) || $project->tasks->contains(fn (Task $task): bool => $this->taskUnread($task)),
            'naming' => in_array($project->id, $naming, true),
            'published_url' => $project->publish_status === PublishStatus::Live ? $project->published_url : null,
            'working' => $project->status === ProjectStatus::Working || $project->task_working,
            'activity' => $project->status === ProjectStatus::Working ? $this->currentActivity($project) : null,
            ...$this->waiting($project, $project->tasks),
            'tasks' => $project->tasks->map($this->summarizeTask(...))->all(),
            'failed' => $project->sandbox?->status === SandboxStatus::Failed,
            'icon_url' => $this->projectIconUrl($project),
            'drawing_icon' => in_array($project->id, $drawing, true),
        ];

        return [
            ...array_map(fn ($projects) => array_values($projects->map($summarize)->all()), $lists),
            'sort' => $sort->value,
        ];
    }

    /**
     * Where the sidebar loads the project's icon from, or null when it has none.
     */
    protected function projectIconUrl(Project $project): ?string
    {
        return ProjectIcons::url($project);
    }

    /**
     * The opened project with every task, for the sidebar's project view; null when none is open or it isn't the user's.
     *
     * @return array{id: int, name: string, working: bool, unread: bool, main_waiting_for: string|null, waiting_for: string|null, checking: bool, tasks: list<array<string, mixed>>}|null
     */
    protected function openProject(User $user, int $projectId): ?array
    {
        $project = $projectId > 0 ? Project::query()->find($projectId) : null;

        if ($project === null || $user->cannot('view', $project)) {
            return null;
        }

        $tasks = $project->tasks()->withLastReply()->get();

        return [
            'id' => $project->id,
            'name' => $project->name,
            'working' => $project->status === ProjectStatus::Working,
            'unread' => $this->mainChatUnread($project->loadMax(['messages as last_reply_at' => fn (Builder $query) => $query->where('role', MessageRole::Assistant)], 'created_at')),
            'main_waiting_for' => $this->waitingFor($project),
            ...$this->waiting($project, $tasks),
            'tasks' => array_values($tasks->map($this->summarizeTask(...))->all()),
        ];
    }

    /**
     * @return array{id: int, title: string, stage: TaskStage, working: bool, unread: bool, activity: string|null, waiting_for: string|null}
     */
    protected function summarizeTask(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'stage' => $task->stage,
            'working' => $task->isWorking(),
            'unread' => $this->taskUnread($task),
            'activity' => $task->isWorking() ? $task->currentActivity() : null,
            'waiting_for' => $this->waitingFor($task),
        ];
    }

    /**
     * Whether the project's main chat or one of the given tasks is waiting for the owner (the main chat's outcome
     * first), and whether a turn that just ended there is still being checked, so the sidebar holds its
     * notification for the answer (PRJ-011).
     *
     * @param  iterable<Task>  $tasks
     * @return array{waiting_for: string|null, checking: bool}
     */
    protected function waiting(Project $project, iterable $tasks): array
    {
        $conversations = [$project, ...$tasks];

        return [
            'waiting_for' => collect($conversations)->map($this->waitingFor(...))->filter()->first(),
            'checking' => collect($conversations)->contains(fn (Project|Task $conversation): bool => CheckTurnOutcome::checking($conversation)),
        ];
    }

    /**
     * Why the conversation's agent is waiting for the owner after its last turn (PRJ-011), or null when it's working,
     * done, or wasn't checked.
     */
    protected function waitingFor(Project|Task $conversation): ?string
    {
        return $conversation->status !== ProjectStatus::Working && $conversation->turn_outcome?->waiting()
            ? $conversation->turn_outcome->value
            : null;
    }

    /**
     * The main chat has never been opened, was marked unread, or the agent replied there since (PRJ-003).
     */
    protected function mainChatUnread(Project $project): bool
    {
        return $project->read_at === null
            || ($project->last_reply_at && Carbon::parse($project->last_reply_at)->greaterThan($project->read_at));
    }

    /**
     * The task's agent replied since the owner last opened it (PRJ-008). Needs `withLastReply()`.
     */
    protected function taskUnread(Task $task): bool
    {
        return $task->last_reply_at !== null
            && ($task->read_at === null || Carbon::parse($task->last_reply_at)->greaterThan($task->read_at));
    }

    /**
     * The agent's latest step in its current run, in plain words (PlainActivity), or null before its first one.
     */
    protected function currentActivity(Project $project): ?string
    {
        $lastPromptId = $project->messages()->reorder()->where('role', MessageRole::User)->max('id') ?? 0;

        return PlainActivity::describe($project->messages()->reorder()
            ->where('role', MessageRole::Activity)
            ->where('id', '>', $lastPromptId)
            ->latest('id')
            ->value('content'));
    }
}
