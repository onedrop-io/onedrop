<?php

namespace App\Sandbox\Agents;

use App\Enums\CredentialType;
use App\Enums\MessageRole;
use App\Enums\SandboxStatus;
use App\Models\Message;
use App\Models\Project;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Asks the project's AI for a short title from its chat, with a one-off OpenCode run in the sandbox
 * (where the user's AI credentials already go). The run gets no tools and doesn't touch the agent's session.
 */
class ProjectNamer
{
    /** Keep the chat excerpt small: the title only needs the gist. */
    protected const MAX_PROMPT_CHARACTERS = 6000;

    public function __construct(protected SandboxProvider $provider, protected ModelCatalog $catalog, protected ChatGptAuth $chatGpt) {}

    /**
     * Mark the project as being named, so the sidebar shows it and polls for the result.
     */
    public static function markNaming(Project $project): void
    {
        Cache::put("project-naming:{$project->id}", true, now()->addMinutes(5));
    }

    /**
     * Which of the given projects are being named right now.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public static function naming(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $flags = Cache::many(array_map(fn (int $id) => "project-naming:{$id}", $ids));

        return array_values(array_filter($ids, fn (int $id) => (bool) ($flags["project-naming:{$id}"] ?? false)));
    }

    public static function doneNaming(Project $project): void
    {
        Cache::forget("project-naming:{$project->id}");
    }

    /**
     * A fresh title for the project.
     *
     * @throws SandboxException when the AI can't be asked or doesn't give a usable title
     * @throws ChatGptSignInFailed when a ChatGPT sign-in can't be refreshed
     */
    public function suggest(Project $project): string
    {
        $sandbox = $project->sandbox;
        $selection = $this->catalog->selectionFor($project);
        $connection = $selection ? $project->user->agentConnections()->firstWhere('provider', $selection['provider']) : null;

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            throw new SandboxException("The project's sandbox isn't running.");
        }

        if ($selection === null || $connection === null || $connection->credential_type === CredentialType::OAuthToken) {
            throw new SandboxException('No connected AI can name the project.');
        }

        if ($connection->credential_type === CredentialType::ChatGpt) {
            $connection = $this->chatGpt->ensureFresh($connection);
        }

        $result = $this->provider->exec($sandbox->external_id, [
            'bash', '-c', 'cd /tmp && exec opencode run --format json -m "$APP_MODEL" -- "$APP_PROMPT"',
        ], [
            ...$connection->sandboxEnvironment(),
            'APP_MODEL' => $this->catalog->opencodeId($selection['provider'], $selection['model']),
            'APP_PROMPT' => $this->prompt($project),
            // No project instructions and no tools: just answer.
            'OPENCODE_CONFIG_CONTENT' => json_encode([
                'autoupdate' => false,
                'share' => 'disabled',
                'permission' => ['edit' => 'deny', 'bash' => 'deny', 'webfetch' => 'deny'],
            ]),
        ]);

        $title = $this->titleFrom($result->output);

        if (! $result->successful() || $title === '') {
            throw new SandboxException("Couldn't come up with a title: ".(strtok(trim($result->errorOutput), "\n") ?: 'no answer'));
        }

        return $title;
    }

    /**
     * The request, with the start and end of the chat (where the gist and the latest direction are).
     */
    protected function prompt(Project $project): string
    {
        $chat = $project->messages()
            ->whereIn('role', [MessageRole::User, MessageRole::Assistant])
            ->get(['role', 'content'])
            ->map(fn (Message $message): string => ($message->role === MessageRole::User ? 'User' : 'Agent').': '.Str::squish($message->content))
            ->implode("\n");

        if ($chat === '') {
            $chat = 'User: '.Str::squish($project->prompt);
        }

        if (mb_strlen($chat) > self::MAX_PROMPT_CHARACTERS) {
            $half = intdiv(self::MAX_PROMPT_CHARACTERS, 2);
            $chat = mb_substr($chat, 0, $half)."\n…\n".mb_substr($chat, -$half);
        }

        return <<<PROMPT
        Write a title for this app-building chat, for a sidebar list: 2 to 6 words, Title Case, naming the app being built.
        Reply with only the title: no quotes, no punctuation at the end, no explanation. Don't use any tools.

        <chat>
        {$chat}
        </chat>
        PROMPT;
    }

    /**
     * The model's answer from OpenCode's JSON events, cleaned up into a title.
     */
    protected function titleFrom(string $output): string
    {
        $text = collect(explode("\n", $output))
            ->map(fn (string $line) => json_decode($line, true))
            ->filter(fn ($event) => is_array($event) && ($event['type'] ?? null) === 'text')
            ->map(fn (array $event) => (string) ($event['part']['text'] ?? ''))
            ->implode('');

        $line = collect(preg_split('/\R/', $text) ?: [])->map(fn (string $line) => trim($line))->first(fn (string $line) => $line !== '') ?? '';
        $title = Str::of($line)->replaceMatches('/^(title:\s*)/i', '')->trim(" \t\"'`*#.")->squish()->toString();

        return Str::limit($title, 60, '');
    }
}
