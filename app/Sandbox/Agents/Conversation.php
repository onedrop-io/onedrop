<?php

namespace App\Sandbox\Agents;

use App\Enums\ProjectStatus;
use App\Models\Message;
use App\Models\Project;
use App\Models\Sandbox;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A chat with its own agent: a project's main chat, or one of its tasks (TASK-001). Each has its own
 * messages, queue, session and working status; all of a project's conversations run in its sandbox at once.
 * Both are models, so their `status` (a {@see ProjectStatus}) and `agent_session_id` (string|null) are read with
 * getAttribute(): an interface can't declare the magic properties themselves.
 */
interface Conversation
{
    /**
     * The project whose sandbox, AI and settings the conversation uses.
     */
    public function ownerProject(): Project;

    /**
     * The sandbox the conversation's agent works in: the project's main one, or a task's own copy (TASK-003).
     */
    public function agentSandbox(): ?Sandbox;

    /**
     * The chat's messages, oldest first (not counting queued ones).
     *
     * @return HasMany<Message, covariant \Illuminate\Database\Eloquent\Model>
     */
    public function messages(): HasMany;

    /**
     * Messages waiting for the conversation's current run to finish, in the order they'll run.
     *
     * @return HasMany<Message, covariant \Illuminate\Database\Eloquent\Model>
     */
    public function queuedMessages(): HasMany;

    /**
     * Names the conversation's agent run inside the sandbox, so stopping one leaves the others running.
     */
    public function runKey(): string;

    /**
     * Issue a fresh secret for the conversation's event forwarder, replacing any old one.
     */
    public function issueEventsToken(): string;

    /**
     * Update the conversation's own columns.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     * @return bool
     */
    public function update(array $attributes = [], array $options = []);

    /**
     * One of the conversation's columns, cast.
     *
     * @param  string  $key
     * @return mixed
     */
    public function getAttribute($key);

    /**
     * The conversation's id.
     *
     * @return mixed
     */
    public function getKey();
}
