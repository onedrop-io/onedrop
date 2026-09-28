<?php

namespace App\Sandbox\Agents;

use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use App\Enums\MessageRole;
use App\Enums\SandboxStatus;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\WorkspaceFiles;
use Illuminate\Support\Number;

/**
 * Runs OpenCode inside the project's sandbox. A forwarder in the sandbox
 * (docker/sandbox/forwarder.mjs) streams its JSON events back to
 * SandboxEventController; this class only starts it and returns.
 */
class OpenCodeRunner implements AgentRunner
{
    /** Where a message's attachments go in the workspace (followed by /{message id}/{file name}). */
    public const ATTACHMENTS_DIR = '.zap/attachments';

    public function __construct(protected SandboxProvider $provider, protected ModelCatalog $catalog, protected ChatGptAuth $chatGpt, protected WorkspaceFiles $files) {}

    public function start(Project $project, Message $message): void
    {
        $sandbox = $project->sandbox;
        $selection = $this->catalog->selectionFor($project);
        // The connection for the chosen model's provider; the default one only explains why nothing is usable.
        $connection = $selection
            ? $project->user->agentConnections()->firstWhere('provider', $selection['provider'])
            : $project->user->agentConnections()->firstWhere('is_default', true);

        $problem = match (true) {
            $sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null => "Your app's sandbox isn't running, so I can't work on it right now.",
            $connection === null => 'Connect an AI in Settings → AI so I can start working.',
            $connection->credential_type === CredentialType::OAuthToken => "OpenCode can't use a Claude subscription token (Anthropic doesn't allow it). Connect an Anthropic API key or OpenRouter in Settings → AI.",
            default => null,
        };

        if ($problem) {
            $this->fail($project, $problem);

            return;
        }

        if ($connection->credential_type === CredentialType::ChatGpt) {
            try {
                $connection = $this->chatGpt->ensureFresh($connection);
            } catch (ChatGptSignInFailed $e) {
                $this->fail($project, $e->getMessage());

                return;
            }
        }

        try {
            $attached = $this->copyAttachments($sandbox, $message);
        } catch (SandboxException $e) {
            $this->fail($project, $e->getMessage());

            return;
        }

        $images = array_keys(array_filter($attached, fn (Attachment $attachment) => $attachment->isVisibleImage()));
        $vision = $images !== [] ? $this->selectionForImages($project, $selection) : $selection;

        if ($vision === null) {
            $images = [];
        } else {
            $selection = $vision;
        }

        $env = [
            ...$connection->sandboxEnvironment(),
            'APP_PROMPT' => $this->prompt($message, $attached),
            'APP_FILES' => json_encode($images, JSON_UNESCAPED_SLASHES),
            'APP_MODEL' => $this->catalog->opencodeId($selection['provider'], $selection['model']),
            'APP_VARIANT' => $selection['variant'] ?? '',
            'APP_EVENTS_URL' => rtrim(config('sandbox.callback_url'), '/').route('sandbox-events.store', $sandbox, absolute: false),
            'APP_EVENTS_TOKEN' => $sandbox->issueEventsToken(),
            'APP_SESSION_ID' => $project->agent_session_id ?? '',
        ];

        try {
            $result = $this->provider->exec($sandbox->external_id, ['node', '/opt/zap/forwarder.mjs'], $env, detach: true);
        } catch (SandboxException $e) {
            $this->fail($project, $e->getMessage());

            return;
        }

        if (! $result->successful()) {
            $this->fail($project, "Couldn't start the agent: ".(strtok(trim($result->errorOutput), "\n") ?: 'unknown error'));
        }
    }

    public function stop(Project $project): void
    {
        $sandbox = $project->sandbox;

        if (! $sandbox?->external_id) {
            return;
        }

        // Anything the stopped run still sends is rejected from now on.
        $sandbox->issueEventsToken();

        try {
            $this->provider->exec($sandbox->external_id, ['/opt/zap/stop-agent']);
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
     * The selection to use when the message has images: the chosen model if it can see them, otherwise
     * a vision model from the same provider for this message; null when none can. The chat says which.
     *
     * @param  array{provider: AgentProvider, model: string, variant: string|null}  $selection
     * @return array{provider: AgentProvider, model: string, variant: string|null}|null
     */
    protected function selectionForImages(Project $project, array $selection): ?array
    {
        $vision = $this->catalog->visionSelection($selection, $project->user);

        if ($vision === $selection) {
            return $selection;
        }

        $current = $this->catalog->describe($selection)['name'];

        if ($vision === null) {
            $project->messages()->create(['role' => MessageRole::Activity, 'content' => "{$current} can't see images, so I'm working from your text"]);

            return null;
        }

        $project->messages()->create(['role' => MessageRole::Activity, 'content' => 'Looking at your images with '.$this->catalog->describe($vision)['name']]);

        return $vision;
    }

    /**
     * The user's text, plus where their attachments are in the workspace.
     *
     * @param  array<string, Attachment>  $attached
     */
    protected function prompt(Message $message, array $attached): string
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
     * Tell the user why nothing will happen, then move on to the next queued message.
     */
    protected function fail(Project $project, string $reason): void
    {
        $project->messages()->create(['role' => MessageRole::Assistant, 'content' => $reason]);

        app(AgentQueue::class)->finished($project);
    }
}
