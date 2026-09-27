<?php

namespace App\Http\Controllers;

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Jobs\RunAgentTask;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProjectMessageController extends Controller
{
    /**
     * Send a follow-up message to the project's agent.
     */
    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
        ]);

        // One run at a time: a second run on the same agent session corrupts it.
        if ($project->status === ProjectStatus::Working) {
            throw ValidationException::withMessages([
                'content' => __('The agent is still working. Send your message when it finishes.'),
            ]);
        }

        // Show "Thinking…" right away: the page only polls for updates while the agent is working,
        // and the queued run may take a moment to start.
        $project->update(['status' => ProjectStatus::Working]);

        $message = $project->messages()->create([
            'role' => MessageRole::User,
            'content' => $validated['content'],
        ]);

        RunAgentTask::dispatch($project, $message);

        return to_route('projects.show', $project);
    }
}
