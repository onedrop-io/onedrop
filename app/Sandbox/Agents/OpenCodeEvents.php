<?php

namespace App\Sandbox\Agents;

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Support\Str;

/**
 * Turns OpenCode `run --format json` events (plus the forwarder's zap.* events)
 * into chat messages. Unknown events are ignored so OpenCode upgrades degrade gracefully.
 *
 * @see https://github.com/sst/opencode/blob/dev/packages/opencode/src/cli/cmd/run.ts
 */
class OpenCodeEvents
{
    /**
     * Apply one event to the project.
     *
     * @param  array<string, mixed>  $event
     */
    public function apply(Project $project, array $event): void
    {
        if (is_string($event['sessionID'] ?? null) && $project->agent_session_id !== $event['sessionID']) {
            $project->update(['agent_session_id' => $event['sessionID']]);
        }

        $part = is_array($event['part'] ?? null) ? $event['part'] : [];

        match ($event['type'] ?? null) {
            'zap.start' => $project->update(['status' => ProjectStatus::Working]),
            'reasoning' => $this->say($project, MessageRole::Activity, 'Thinking'),
            'text' => $this->say($project, MessageRole::Assistant, trim((string) ($part['text'] ?? ''))),
            'tool_use' => $this->say($project, MessageRole::Activity, $this->describeTool($part)),
            'error' => $this->say($project, MessageRole::Assistant, $this->explainError($this->errorMessage($event['error'] ?? null))),
            'zap.exit' => $this->finish($project, (int) ($event['code'] ?? 0), (string) ($event['stderr'] ?? '')),
            default => null,
        };
    }

    /**
     * A short, plain-language line for a tool call, e.g. "Editing src/App.tsx".
     *
     * @param  array<string, mixed>  $part
     */
    public function describeTool(array $part): string
    {
        $state = is_array($part['state'] ?? null) ? $part['state'] : [];
        $input = is_array($state['input'] ?? null) ? $state['input'] : [];
        $path = Str::after((string) ($input['filePath'] ?? $input['path'] ?? ''), '/workspace/');
        $failed = ($state['status'] ?? null) === 'error';

        $line = match ($part['tool'] ?? null) {
            'write' => "Creating {$path}",
            'edit', 'multiedit', 'patch' => $path !== '' ? "Editing {$path}" : 'Editing files',
            'read' => "Reading {$path}",
            'bash' => 'Running '.($input['description'] ?? '`'.Str::limit((string) ($input['command'] ?? ''), 80).'`'),
            'glob', 'grep', 'list' => 'Looking through the code',
            'todowrite', 'todoread' => 'Planning next steps',
            'webfetch', 'websearch' => 'Looking things up',
            'task' => 'Working on a subtask',
            default => 'Using '.($part['tool'] ?? 'a tool'),
        };

        return $failed ? "{$line} (failed)" : $line;
    }

    /**
     * Store a chat line, skipping empty ones and repeated "Thinking" lines.
     */
    protected function say(Project $project, MessageRole $role, string $content): void
    {
        if ($content === '') {
            return;
        }

        $last = $project->messages()->reorder()->latest('id')->first();

        if ($role === MessageRole::Activity && $last?->role === $role && $last->content === $content) {
            return;
        }

        $project->messages()->create(['role' => $role, 'content' => $content]);
    }

    /**
     * Plain-language version of a provider error from OpenCode.
     */
    protected function explainError(string $message): string
    {
        return match (true) {
            Str::contains($message, ['exceed your available credits', 'insufficient credits', 'Insufficient balance', 'credit balance is too low'], ignoreCase: true) => 'Your AI provider says your balance is too low for this request. Try again in a minute, or add credits (for OpenRouter: openrouter.ai/settings/credits).',
            default => 'Something went wrong: '.$message,
        };
    }

    /**
     * The run is over; explain failures and stop showing "working".
     */
    protected function finish(Project $project, int $code, string $stderr): void
    {
        if ($code !== 0) {
            $this->say($project, MessageRole::Assistant, $this->explainExit($stderr));
        }

        app(AgentQueue::class)->finished($project);
    }

    /**
     * Turn the tail of OpenCode's stderr into something the user can act on.
     */
    protected function explainExit(string $stderr): string
    {
        return match (true) {
            Str::contains($stderr, ['401', 'Unauthorized', 'invalid x-api-key', 'invalid_api_key', 'No auth credentials'], ignoreCase: true) => 'Your AI provider rejected the key. Reconnect it in Settings → AI.',
            Str::contains($stderr, ['402', 'insufficient', 'credits'], ignoreCase: true) => 'Your AI account is out of credits. Top it up and try again.',
            Str::contains($stderr, ['ModelNotFound', 'model not found', 'not a valid model'], ignoreCase: true) => "The AI model isn't available on your account. Ask an admin to change SANDBOX_MODEL_* in .env.",
            default => 'The agent stopped unexpectedly.'.(($line = $this->errorLine($stderr)) !== null ? ' Error: '.Str::limit($line, 200) : ''),
        };
    }

    /**
     * The most useful line of a crash: the first that names an error, skipping
     * runtime banners like "Bun v1.3.14 (Linux arm64)".
     */
    protected function errorLine(string $stderr): ?string
    {
        $lines = collect(explode("\n", $stderr))
            ->map(fn (string $line) => trim($line))
            ->reject(fn (string $line) => $line === '' || preg_match('/^(Bun|Node\.js) v\d/', $line));

        return $lines->first(fn (string $line) => Str::contains($line, ['error', 'Error', 'EACCES', 'ENOENT', 'denied', 'panic']))
            ?? $lines->last();
    }

    /**
     * @param  mixed  $error  OpenCode error payload, e.g. {name, data: {message}}
     */
    protected function errorMessage(mixed $error): string
    {
        if (is_array($error)) {
            return (string) ($error['data']['message'] ?? $error['message'] ?? $error['name'] ?? 'unknown error');
        }

        return is_string($error) && $error !== '' ? $error : 'unknown error';
    }
}
