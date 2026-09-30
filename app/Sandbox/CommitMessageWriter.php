<?php

namespace App\Sandbox;

use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\Agents\ChatGptSignInFailed;
use App\Sandbox\Agents\OneOffPrompt;
use Illuminate\Support\Str;

/**
 * Writes the message for a commit made without one (GIT-006): the project's AI summarizes the diff, and when it
 * can't, the message names the files ("Update app.tsx and 2 other files").
 */
class CommitMessageWriter
{
    public function __construct(protected WorkspaceGit $git, protected OneOffPrompt $ai) {}

    /**
     * A one-line message for committing every change, or only those at $paths.
     *
     * @param  list<string>|null  $paths
     *
     * @throws SandboxException|GitException when the sandbox's changes can't be read
     */
    public function write(Project $project, Sandbox $sandbox, ?array $paths = null): string
    {
        $changes = array_values(array_filter(
            $this->git->status($sandbox)['changes'],
            fn (array $change) => $paths === null || in_array($change['path'], $paths, true),
        ));

        try {
            $message = $this->messageFrom($this->ai->ask($project, $this->prompt($this->git->changesDiff($sandbox, $paths))));
        } catch (SandboxException|GitException|ChatGptSignInFailed) {
            $message = '';
        }

        return $message !== '' ? $message : $this->summary($changes);
    }

    /**
     * The message for combining commits (GIT-008): a summary line, then the combined commits' messages listed.
     * Without the AI, the summary is the newest commit's message.
     *
     * @param  array{commits: list<array{subject: string}>, patch: string, truncated: bool}  $preview
     */
    public function forCombining(Project $project, array $preview): string
    {
        try {
            $summary = $this->messageFrom($this->ai->ask($project, $this->prompt([
                'patch' => $preview['patch'],
                'new_files' => [],
                'truncated' => $preview['truncated'],
            ], array_column($preview['commits'], 'subject'))));
        } catch (SandboxException|ChatGptSignInFailed) {
            $summary = '';
        }

        $subjects = collect($preview['commits'])->reverse()->map(fn (array $commit) => "- {$commit['subject']}")->implode("\n");

        return ($summary !== '' ? $summary : $preview['commits'][0]['subject'])."\n\n".$subjects;
    }

    /**
     * @param  array{patch: string, new_files: list<string>, truncated: bool}  $diff
     * @param  list<string>  $commits  the messages of commits being combined, if any
     */
    protected function prompt(array $diff, array $commits = []): string
    {
        $newFiles = $diff['new_files'] === [] ? 'none' : implode("\n", $diff['new_files']);
        $truncated = $diff['truncated'] ? "\n(The diff was cut short.)" : '';
        $combining = $commits === [] ? '' : "\n\nThese commits are being combined into one:\n<commits>\n- ".implode("\n- ", $commits)."\n</commits>";

        return <<<PROMPT
        Write a git commit message for these changes: one line of at most 72 characters, in the imperative mood
        ("Add…", "Fix…", "Update…"), saying what changed for the app, not listing files.
        Reply with only the message: no quotes, no prefix, no explanation. Don't use any tools.

        <diff>
        {$diff['patch']}{$truncated}
        </diff>

        <new-files>
        {$newFiles}
        </new-files>{$combining}
        PROMPT;
    }

    /**
     * The model's answer cleaned up into one line.
     */
    protected function messageFrom(string $text): string
    {
        $line = collect(preg_split('/\R/', $text) ?: [])
            ->map(fn (string $line) => trim($line))
            ->first(fn (string $line) => $line !== '' && ! str_starts_with($line, '```')) ?? '';

        return Str::of($line)->replaceMatches('/^(commit message:\s*)/i', '')->trim(" \t\"'`*")->squish()->limit(100, '')->toString();
    }

    /**
     * "Add a.tsx", "Remove a.tsx and b.tsx", "Update a.tsx and 3 other files".
     *
     * @param  list<array{path: string, status: string}>  $changes
     */
    protected function summary(array $changes): string
    {
        $statuses = array_unique(array_column($changes, 'status'));
        $verb = match (true) {
            $statuses !== [] && array_diff($statuses, ['?', 'A']) === [] => 'Add',
            $statuses === ['D'] => 'Remove',
            default => 'Update',
        };
        $names = array_map(fn (array $change) => basename(rtrim($change['path'], '/')), $changes);

        return match (count($names)) {
            0 => 'Update files',
            1 => "{$verb} {$names[0]}",
            2 => "{$verb} {$names[0]} and {$names[1]}",
            default => "{$verb} {$names[0]} and ".(count($names) - 1).' other files',
        };
    }
}
