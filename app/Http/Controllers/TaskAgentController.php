<?php

namespace App\Http\Controllers;

use App\Actions\ChooseAgent;
use App\Concerns\ValidatesAgentSelection;
use App\Models\Project;
use App\Models\Task;
use App\Sandbox\Agents\ModelCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TaskAgentController extends Controller
{
    use ValidatesAgentSelection;

    /**
     * Change the agent, model and reasoning level a task uses from its next message on; Main and the project's other
     * tasks keep theirs (TASK-007).
     */
    public function update(Request $request, Project $project, Task $task, ModelCatalog $catalog, ChooseAgent $choose): RedirectResponse
    {
        Gate::authorize('update', $project);

        $agent = $this->validatedAgentSelection($request, $request->user(), $catalog, required: true);
        $request->validate(['agent_auto' => ['sometimes', 'boolean']]);

        $choose->handle($request->user(), $task, $agent, $request->boolean('agent_auto'));

        return to_route('projects.tasks.show', [$project, $task]);
    }
}
