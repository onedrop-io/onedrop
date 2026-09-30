<?php

namespace App\Sandbox\Agents;

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Enums\MessageRole;
use App\Enums\SandboxStatus;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Task;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SandboxSkills;
use App\Sandbox\WorkspaceBrowser;
use App\Sandbox\WorkspaceFiles;
use Illuminate\Support\Number;

/**
 * Runs a coding agent inside the project's sandbox. A forwarder in the sandbox
 * (docker/sandbox/forwarder.mjs) streams its JSON events back to
 * SandboxEventController; runners only start it and return.
 */
abstract class SandboxAgentRunner implements AgentRunner
{
    /** Where a message's attachments go in the workspace (followed by /{message id}/{file name}). */
    public const ATTACHMENTS_DIR = '.onedrop/attachments';

    public function __construct(protected SandboxProvider $provider, protected ModelCatalog $catalog, protected WorkspaceFiles $files) {}

    public function stop(Conversation $conversation): void
    {
        $sandbox = $conversation->agentSandbox();

        if (! $sandbox?->external_id) {
            return;
        }

        // Anything the stopped run still sends is rejected from now on.
        $conversation->issueEventsToken();

        try {
            // Only this conversation's run: the project's other chats keep working.
            $this->provider->exec($sandbox->external_id, ['/opt/onedrop/stop-agent', $conversation->runKey()]);
        } catch (SandboxException) {
            // The sandbox is gone or unreachable; there's nothing left to stop.
        }
    }

    /**
     * Put the message's attachments in the workspace, where the agent can look at them and move them into the app.
     *
     * @return array<string, Attachment> absolute sandbox path => attachment
     *
     * @throws SandboxException
     */
    protected function copyAttachments(Sandbox $sandbox, Message $message): array
    {
        $attached = [];
        $names = [];

        foreach ($message->attachments as $attachment) {
            $name = $this->fileName($attachment->name, $names);
            $names[] = $name;
            $path = self::ATTACHMENTS_DIR."/{$message->id}/{$name}";

            $this->files->upload($sandbox, $path, $attachment->contents());
            $attached[WorkspaceFiles::ROOT.'/'.$path] = $attachment;
        }

        return $attached;
    }

    /**
     * A safe file name for the sandbox that isn't taken yet ("logo.png", then "logo-2.png").
     *
     * @param  list<string>  $taken
     */
    protected function fileName(string $name, array $taken): string
    {
        $safe = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name), '.-') ?: 'file';
        $base = pathinfo($safe, PATHINFO_FILENAME);
        $extension = pathinfo($safe, PATHINFO_EXTENSION);
        $candidate = $safe;

        for ($n = 2; in_array($candidate, $taken, true); $n++) {
            $candidate = $base."-{$n}".($extension !== '' ? ".{$extension}" : '');
        }

        return $candidate;
    }

    /**
     * The user's text, plus where their attachments are in the workspace.
     *
     * @param  array<string, Attachment>  $attached
     */
    protected function prompt(Message $message, array $attached): string
    {
        return $this->withBrowserNote($message, $this->withAttachments($message, $attached));
    }

    /**
     * @param  array<string, Attachment>  $attached
     */
    protected function withAttachments(Message $message, array $attached): string
    {
        if ($attached === []) {
            return $message->content;
        }

        $list = collect($attached)
            ->map(fn (Attachment $attachment, string $path) => "- {$path} ({$attachment->mime_type}, ".Number::fileSize($attachment->size).')')
            ->implode("\n");

        $text = $message->content !== '' ? $message->content : '(No message, just the attached files.)';

        return "{$text}\n\n---\nAttached files (see \"Attachments\" in your instructions):\n{$list}";
    }

    /**
     * While the user has a test's page open in the Browser tab (TEST-005), tell the agent: "this" in their message
     * is probably on that page.
     */
    protected function withBrowserNote(Message $message, string $prompt): string
    {
        $sandbox = $message->conversation()->agentSandbox();
        $note = $sandbox ? WorkspaceBrowser::agentNote($sandbox) : null;

        return $note ? "{$prompt}\n\n{$note}" : $prompt;
    }

    /**
     * The selection to use when the message has images: the chosen model if it can see them, otherwise
     * a vision model from the same provider for this message; null when none can. The chat says which.
     *
     * @param  array{provider: AgentProvider, model: string, variant: string|null}  $selection
     * @return array{provider: AgentProvider, model: string, variant: string|null}|null
     */
    protected function selectionForImages(Project $project, Conversation $conversation, array $selection): ?array
    {
        $vision = $this->catalog->visionSelection($selection, $project->user);

        if ($vision === $selection) {
            return $selection;
        }

        $current = $this->catalog->describe($selection)['name'];

        if ($vision === null) {
            $conversation->messages()->create(['role' => MessageRole::Activity, 'content' => "{$current} can't see images, so I'm working from your text"]);

            return null;
        }

        $conversation->messages()->create(['role' => MessageRole::Activity, 'content' => 'Looking at your images with '.$this->catalog->describe($vision)['name']]);

        return $vision;
    }

    /**
     * Tell the user why nothing will happen, then move on to the next queued message.
     */
    protected function fail(Conversation $conversation, string $reason): void
    {
        $conversation->messages()->create(['role' => MessageRole::Assistant, 'content' => $reason]);

        app(AgentQueue::class)->finished($conversation);
    }

    /**
     * Why the sandbox can't take a run right now, if it can't.
     */
    protected function sandboxProblem(?Sandbox $sandbox): ?string
    {
        return $sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null
            ? "Your app's sandbox isn't running, so I can't work on it right now."
            : null;
    }

    /**
     * Put the project's skills in place, then start the forwarder with the run's environment, plus where to send events and which run it is,
     * so the conversation's events come back to it and stopping it leaves other runs alone. Whether the agent
     * keeps the project's requirements (REQ-001) goes along too.
     *
     * @param  array<string, string>  $env
     */
    protected function launch(Conversation $conversation, Sandbox $sandbox, array $env): void
    {
        $route = $conversation instanceof Task
            ? route('sandbox-events.tasks.store', [$sandbox, $conversation], absolute: false)
            : route('sandbox-events.store', $sandbox, absolute: false);

        $env = [
            ...$env,
            'APP_EVENTS_URL' => rtrim(config('sandbox.callback_url'), '/').$route,
            'APP_EVENTS_TOKEN' => $conversation->issueEventsToken(),
            'APP_SESSION_ID' => $conversation->agent_session_id ?? '',
            'APP_RUN' => $conversation->runKey(),
            'APP_REQUIREMENTS' => $conversation->ownerProject()->track_requirements ? '1' : '',
        ];

        app(SandboxSkills::class)->install($sandbox, $conversation->ownerProject(), $env['APP_AGENT'] ?? AgentHarness::OpenCode->value);

        try {
            $result = $this->provider->exec($sandbox->external_id, ['node', '/opt/onedrop/forwarder.mjs'], $env, detach: true);
        } catch (SandboxException $e) {
            $this->fail($conversation, $e->getMessage());

            return;
        }

        if (! $result->successful()) {
            $this->fail($conversation, "Couldn't start the agent: ".(strtok(trim($result->errorOutput), "\n") ?: 'unknown error'));
        }
    }
}
