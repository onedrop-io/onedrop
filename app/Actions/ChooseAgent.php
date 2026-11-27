<?php

namespace App\Actions;

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Models\User;
use App\Sandbox\Agents\Conversation;
use App\Sandbox\Agents\ModelCatalog;
use Illuminate\Validation\ValidationException;

/**
 * Save the agent, model and reasoning level a chat runs from its next message on: a project's main chat (AGT-002,
 * AGT-007) or a task, which has its own (TASK-007). A different agent can't pick up the other one's conversation, so
 * switching starts that chat afresh, and only that chat.
 */
class ChooseAgent
{
    public function __construct(protected ModelCatalog $catalog) {}

    /**
     * @param  array{agent_harness: AgentHarness, agent_provider: AgentProvider, agent_model: string, agent_variant: string|null}  $agent
     *
     * @throws ValidationException when switching agents while the chat's agent is working
     */
    public function handle(User $user, Conversation $conversation, array $agent, bool $auto): void
    {
        $switching = $agent['agent_harness'] !== $this->catalog->harnessFor($conversation);

        if ($switching && $conversation->getAttribute('status') === ProjectStatus::Working) {
            throw ValidationException::withMessages(['agent_harness' => __('Wait for the agent to finish, or stop it, before switching agents.')]);
        }

        $conversation->update([...$agent, 'agent_auto' => $auto, ...($switching ? ['agent_session_id' => null] : [])]);

        if ($switching) {
            $conversation->messages()->create([
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
