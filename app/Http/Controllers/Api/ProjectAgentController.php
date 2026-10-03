<?php

namespace App\Http\Controllers\Api;

use App\Actions\ChangeProjectAgent;
use App\Concerns\ValidatesAgentSelection;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Project;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\Agents\ModelCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ProjectAgentController extends Controller
{
    use ValidatesAgentSelection;

    /**
     * Change the agent, model and reasoning level the project uses from the next message on.
     */
    public function update(Request $request, Project $project, ModelCatalog $catalog, ChangeProjectAgent $changeAgent): Response
    {
        Gate::authorize('update', $project);

        $agent = $this->validatedAgentSelection($request, $request->user(), $catalog, required: true);
        $request->validate(['agent_auto' => ['sometimes', 'boolean']]);

        $changeAgent->handle($project, $request->user(), $agent, $request->boolean('agent_auto'));

        return response()->noContent();
    }

    /**
     * Stop the agent. Queued messages are cancelled and handed back (as `draft`) so the user can edit them; their
     * attachments are dropped.
     */
    public function stop(Project $project, AgentQueue $queue): JsonResponse
    {
        Gate::authorize('update', $project);

        $droppedAttachments = Attachment::query()->whereIn('message_id', $project->queuedMessages()->select('id'))->count();
        $queued = array_filter($queue->stop($project), fn (string $text) => $text !== '');

        return response()->json([
            'draft' => $queued !== [] ? implode("\n\n", $queued) : null,
            'dropped_attachments' => $droppedAttachments,
        ]);
    }
}
