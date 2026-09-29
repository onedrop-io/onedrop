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
        'projects.git.index', 'projects.git.show', 'projects.git.diff', 'projects.git.commit', 'projects.git.discard',
        'projects.git.switch', 'projects.git.restore',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->route('project');

        if ($project instanceof Project && $request->routeIs(...self::ROUTES) && ($taskId = $this->taskId($request, $project))) {
            $copy = $project->sandboxes()->where('task_id', $taskId)->first();

            if ($copy) {
                $project->setRelation('sandbox', $copy);
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
