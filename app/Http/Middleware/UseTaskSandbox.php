<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On a task's page, the workspace's tools (files, console, database, git, secrets, ...) work on the task's own
 * copy of the app (TASK-003): their requests come from that page, so the project's sandbox becomes the copy's for
 * them. The task is named by a `task` query parameter or, for the tools' own requests, the page they came from.
 * Publishing, the agent and the chat always use Main's.
 */
class UseTaskSandbox
{
    /** The routes that act on whatever sandbox the page is showing. */
    public const ROUTES = [
        'projects.files.*', 'projects.logs.*', 'projects.monitoring.*', 'projects.database.*', 'projects.auth.*',
        'projects.flags.*', 'projects.secrets.*', 'projects.storage.*', 'projects.developer.*', 'projects.gateway.open',
        // All of Source Control, so what it lists is what it acts on. Push and Pull go through the task's pull request.
        'projects.git.index', 'projects.git.show', 'projects.git.diff', 'projects.git.log', 'projects.git.change-diff',
        'projects.git.checkpoints', 'projects.git.restore-checkpoint', 'projects.git.commit', 'projects.git.draft-message',
        'projects.git.stage', 'projects.git.unstage', 'projects.git.stage-lines', 'projects.git.discard',
        'projects.git.discard-hunk', 'projects.git.switch', 'projects.git.restore', 'projects.git.undo-commit',
        'projects.git.combine', 'projects.git.combine-draft', 'projects.git.pull-request', 'projects.git.push',
        'projects.git.pull', 'projects.git.task-changes',
    ];

    /** The request attribute holding the task whose copy the page shows. */
    public const TASK = 'task_copy';

    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->route('project');

        if ($project instanceof Project && $request->routeIs(...self::ROUTES) && ($taskId = $this->taskId($request, $project))) {
            $copy = $project->sandboxes()->where('task_id', $taskId)->first();

            if ($copy) {
                $project->setRelation('sandbox', $copy);
                $request->attributes->set(self::TASK, $project->tasks()->find($taskId));
            }
        }

        return $next($request);
    }

    protected function taskId(Request $request, Project $project): ?int
    {
        if ($request->filled('task')) {
            return (int) $request->query('task');
        }

        $referer = parse_url((string) $request->headers->get('referer'), PHP_URL_PATH);

        return is_string($referer) && preg_match('#^/projects/'.$project->id.'/tasks/(\d+)/?$#', $referer, $match)
            ? (int) $match[1]
            : null;
    }
}
