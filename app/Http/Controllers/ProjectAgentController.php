<?php

namespace App\Http\Controllers;

use App\Concerns\ValidatesAgentSelection;
use App\Models\Attachment;
use App\Models\Project;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\Agents\ModelCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ProjectAgentController extends Controller
{
    use ValidatesAgentSelection;

    /**
     * Change the model and reasoning level the project's agent uses from the next message on.
     */
    public function update(Request $request, Project $project, ModelCatalog $catalog): RedirectResponse
    {
        Gate::authorize('update', $project);

        $agent = $this->validatedAgentSelection($request, $request->user(), $catalog, required: true);

        $project->update($agent);
        $request->user()->rememberModel($agent['agent_provider'], $agent['agent_model']);

        return to_route('projects.show', $project);
    }

    /**
     * Stop the agent. Queued messages are cancelled and handed back so the user can edit them.
     */
    public function stop(Project $project, AgentQueue $queue): RedirectResponse
    {
        Gate::authorize('update', $project);

        $droppedAttachments = Attachment::query()->whereIn('message_id', $project->queuedMessages()->select('id'))->count();
        $queued = array_filter($queue->stop($project), fn (string $text) => $text !== '');

        if ($queued !== []) {
            Inertia::flash('draft', implode("\n\n", $queued));
        }

        if ($droppedAttachments > 0) {
            Inertia::flash('toast', ['type' => 'info', 'message' => trans_choice('The queued attachment was removed; attach it again to send it.|The :count queued attachments were removed; attach them again to send them.', $droppedAttachments)]);
        }

        return to_route('projects.show', $project);
    }
}
