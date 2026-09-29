<?php

namespace App\Http\Controllers;

use App\Concerns\ValidatesAgentSelection;
use App\Enums\MessageRole;
use App\Models\Attachment;
use App\Models\Project;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\Agents\ModelCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ProjectAgentController extends Controller
{
    use ValidatesAgentSelection;

    /**
     * Change the agent, model and reasoning level the project uses from the next message on.
     * A different agent can't pick up the other one's conversation, so it starts a fresh one.
     */
    public function update(Request $request, Project $project, ModelCatalog $catalog): RedirectResponse
    {
        Gate::authorize('update', $project);

        $agent = $this->validatedAgentSelection($request, $request->user(), $catalog, required: true);
        $switching = $agent['agent_harness'] !== $catalog->harnessFor($project);

        if ($switching && $project->agentBusy()) {
            throw ValidationException::withMessages(['agent_harness' => __('Wait for the agent to finish, or stop it, before switching agents.')]);
        }

        $project->update($switching ? [...$agent, 'agent_session_id' => null] : $agent);

        // Tasks use the project's agent too, so theirs start fresh conversations as well.
        if ($switching) {
            $project->tasks()->reorder()->update(['agent_session_id' => null]);
        }

        if ($switching) {
            $project->messages()->create([
                'role' => MessageRole::Activity,
                'content' => __('Switched to :agent. It starts a fresh conversation; your files are kept.', ['agent' => $agent['agent_harness']->label()]),
            ]);
        }

        $request->user()->rememberModel($agent['agent_provider'], $agent['agent_model']);
        $request->user()->preferAgent($agent);

        return to_route('projects.show', $project);
    }

    /**
     * Turn autofix on or off: whether errors the preview shows after a turn go back to the agent (ERR-001).
     */
    public function autofix(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $project->update($request->validate(['autofix' => ['required', 'boolean']]));

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
