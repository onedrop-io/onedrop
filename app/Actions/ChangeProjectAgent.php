<?php

namespace App\Actions;

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Enums\MessageRole;
use App\Models\Project;
use App\Models\User;
use App\Sandbox\Agents\ModelCatalog;
use Illuminate\Validation\ValidationException;

class ChangeProjectAgent
{
    public function __construct(protected ModelCatalog $catalog) {}

    /**
     * Change the agent, model and reasoning level the project uses from the next message on.
     * A different agent can't pick up the other one's conversation, so it starts a fresh one. With $auto (AGT-011),
     * the model and reasoning level are picked per message from the chosen provider.
     *
     * @param  array{agent_harness: AgentHarness, agent_provider: AgentProvider, agent_model: string, agent_variant: string|null}  $agent
     *
     * @throws ValidationException
     */
    public function handle(Project $project, User $user, array $agent, bool $auto = false): void
    {
        $switching = $agent['agent_harness'] !== $this->catalog->harnessFor($project);

        if ($switching && $project->agentBusy()) {
            throw ValidationException::withMessages(['agent_harness' => __('Wait for the agent to finish, or stop it, before switching agents.')]);
        }

        $project->update([...$agent, 'agent_auto' => $auto, ...($switching ? ['agent_session_id' => null] : [])]);

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

        if (! $auto) {
            $user->rememberModel($agent['agent_provider'], $agent['agent_model']);
        }
        $user->preferAgent($agent);
    }
}
