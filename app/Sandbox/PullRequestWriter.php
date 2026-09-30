<?php

namespace App\Sandbox;

use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\Agents\ChatGptSignInFailed;
use App\Sandbox\Agents\OneOffPrompt;
use Illuminate\Support\Str;

/**
 * Writes a pull request's title and description for the current branch against a base (GIT-007): the project's AI
 * summarizes its commits and diff, and when it can't, the title is the only commit's message (or the branch name)
 * and the description lists the commits.
 */
class PullRequestWriter
{
    /** GitHub takes the description in the link, so it has to stay a reasonable length. */
    protected const BODY_MAX_CHARACTERS = 3000;

    public function __construct(protected WorkspaceGit $git, protected OneOffPrompt $ai) {}

    /**
     * @return array{title: string, body: string, commits: int}
     *
     * @throws SandboxException|GitException when the branch can't be compared with the base
     */
    public function write(Project $project, Sandbox $sandbox, string $branch, string $base): array
    {
        $compared = $this->git->compare($sandbox, $base);

        if ($compared['commits'] === []) {
            throw new GitException(__('This branch has no commits that :base doesn\'t have.', ['base' => $base]));
        }

        try {
            [$title, $body] = $this->parse($this->ai->ask($project, $this->prompt($compared)));
        } catch (SandboxException|ChatGptSignInFailed) {
            [$title, $body] = ['', ''];
        }

        return [
            'title' => $title !== '' ? $title : $this->fallbackTitle($compared['commits'], $branch),
            'body' => $body !== '' ? $body : $this->fallbackBody($compared['commits'], $compared['more']),
            'commits' => count($compared['commits']),
        ];
    }

    /**
     * @param  array{commits: list<array{subject: string}>, patch: string, truncated: bool}  $compared
     */
    protected function prompt(array $compared): string
    {
        $commits = collect($compared['commits'])->map(fn (array $commit) => "- {$commit['subject']}")->implode("\n");
        $truncated = $compared['truncated'] ? "\n(The diff was cut short.)" : '';

        return <<<PROMPT
        Write a GitHub pull request for these changes. Reply in exactly this format, and nothing else:

        Title: <one line of at most 72 characters saying what the pull request does>

        <a description in Markdown: one or two sentences on what changed and why, then a short bulleted list of the main changes>

        Don't use any tools.

        <commits>
        {$commits}
        </commits>

        <diff>
        {$compared['patch']}{$truncated}
        </diff>
        PROMPT;
    }

    /**
     * The model's answer as [title, body], or empty strings when it didn't follow the format.
     *
     * @return array{string, string}
     */
    protected function parse(string $text): array
    {
        $text = trim(preg_replace('/^```\w*\R|\R```$/', '', trim($text)) ?? '');

        if (! preg_match('/^\s*\**title:?\**\s*(.+)$/im', $text, $match, PREG_OFFSET_CAPTURE)) {
            return ['', ''];
        }

        $title = Str::of($match[1][0])->trim(" \t\"'`*")->squish()->limit(100, '')->toString();
        $body = trim(substr($text, $match[0][1] + strlen($match[0][0])));

        return [$title, Str::limit($body, self::BODY_MAX_CHARACTERS)];
    }

    /**
     * @param  list<array{subject: string}>  $commits
     */
    protected function fallbackTitle(array $commits, string $branch): string
    {
        if (count($commits) === 1) {
            return $commits[0]['subject'];
        }

        return Str::ucfirst(Str::of($branch)->afterLast('/')->replace(['-', '_'], ' ')->squish()->toString());
    }

    /**
     * @param  list<array{subject: string}>  $commits
     */
    protected function fallbackBody(array $commits, bool $more): string
    {
        $lines = collect($commits)->reverse()->take(30)->map(fn (array $commit) => "- {$commit['subject']}");

        if ($more || count($commits) > 30) {
            $lines->push('- …');
        }

        return Str::limit("## Changes\n\n".$lines->implode("\n"), self::BODY_MAX_CHARACTERS);
    }
}
