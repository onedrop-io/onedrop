<?php

namespace App\Sandbox\Agents;

use App\Enums\AgentHarness;
use App\Models\Project;
use App\Models\Skill;
use Illuminate\Support\Str;

/**
 * The project agent's own slash commands (AGT-016): which ones the chat offers, and how `/name details` runs in
 * each agent. Claude Code reports its list on every run (kept on the project); OpenCode and Codex don't, so they
 * get their usual built-ins plus the skills turned on in the project.
 */
class AgentCommands
{
    /** Descriptions are cut to this, so a long skill description doesn't fill the menu. */
    public const MAX_DESCRIPTION = 200;

    /**
     * Claude Code's built-ins worth running from the chat. The rest duplicate the app's own controls (model,
     * effort, clear, config, sign-in) or only work in its terminal UI.
     */
    public const CLAUDE_BUILT_INS = ['compact', 'context', 'init', 'review', 'security-review', 'usage', 'cost'];

    /**
     * Each agent's usual built-ins, offered before it reports its own.
     *
     * @var array<string, array<string, array{description: string, hint: string}>>
     */
    protected const BUILT_INS = [
        'claude_code' => [
            'compact' => ['description' => 'Free up context by summarizing the conversation so far', 'hint' => '<optional custom summarization instructions>'],
            'context' => ['description' => 'Show current context usage', 'hint' => ''],
            'init' => ['description' => 'Initialize a new CLAUDE.md file with codebase documentation', 'hint' => ''],
            'security-review' => ['description' => 'Complete a security review of the pending changes on the current branch', 'hint' => ''],
            'usage' => ['description' => 'Show plan usage limits', 'hint' => ''],
        ],
        'opencode' => [
            'init' => ['description' => 'Create or update AGENTS.md for this project', 'hint' => ''],
            'review' => ['description' => 'Review changes (uncommitted by default, or a commit, branch or pull request)', 'hint' => '[commit|branch|pr]'],
        ],
        'codex' => [
            'init' => ['description' => 'Create an AGENTS.md file with instructions for Codex', 'hint' => ''],
            'review' => ['description' => 'Review the current changes and find issues', 'hint' => '<optional focus>'],
        ],
    ];

    /** What Codex's own `/init` and `/review` ask for; `codex exec` has no slash commands. */
    protected const CODEX_PROMPTS = [
        'init' => 'Generate a file named AGENTS.md in the project root that serves as a contributor guide for this repository: its structure, how to build, test and run it, coding style and naming, testing guidelines, and commit and pull request guidelines. Keep it concise (200 to 400 words) and specific to this repository. If AGENTS.md exists, improve it instead.',
        'review' => 'Review the current code changes (staged, unstaged and untracked files) and report prioritized, actionable findings: bugs, security issues, missing tests and risky changes, each with the file and line. Don\'t change any files.',
    ];

    public function __construct(protected ModelCatalog $catalog) {}

    /**
     * The commands a chat offers for its agent (the main chat's, or a task's own: TASK-007), built-ins first.
     *
     * @return list<array{name: string, description: string, hint: string}>
     */
    public function for(Conversation $conversation): array
    {
        $project = $conversation->ownerProject();
        $harness = $this->catalog->harnessFor($conversation);
        $reported = $project->agent_commands[$harness->value] ?? null;

        $commands = collect(self::BUILT_INS[$harness->value])
            ->map(fn (array $command, string $name) => ['name' => $name, ...$command])
            ->values();

        if (is_array($reported)) {
            // What the agent reported replaces the usual built-ins: its own list is the up-to-date one.
            $commands = collect($reported);
        }

        $skills = $project->skills()->get()->map(fn (Skill $skill) => [
            'name' => $skill->name,
            'description' => Str::limit($skill->description, self::MAX_DESCRIPTION),
            'hint' => '',
        ]);

        return array_values($commands->concat($skills)->unique('name')->all());
    }

    /**
     * Keep the commands Claude Code reported at the start of a run (its `commands_changed` event), minus the
     * built-ins the chat doesn't offer.
     *
     * @param  array<int, mixed>  $commands
     */
    public function remember(Project $project, AgentHarness $harness, array $commands): void
    {
        $kept = collect($commands)
            ->filter(fn ($command) => is_array($command) && is_string($command['name'] ?? null) && $command['name'] !== '')
            ->reject(fn (array $command) => ($command['builtin'] ?? false) && ! in_array($command['name'], self::CLAUDE_BUILT_INS, true))
            // Internal ones, like "__remote-workflow".
            ->reject(fn (array $command) => str_starts_with($command['name'], '_'))
            ->map(fn (array $command) => [
                'name' => $command['name'],
                // Claude Code says where a skill came from, e.g. "Reviews code (user)"; the menu doesn't need it.
                'description' => Str::limit((string) preg_replace('/\s*\((user|project|plugin|managed)\)$/', '', trim((string) ($command['description'] ?? ''))), self::MAX_DESCRIPTION),
                'hint' => (string) ($command['argumentHint'] ?? ''),
            ])
            ->sortBy(fn (array $command) => [! in_array($command['name'], self::CLAUDE_BUILT_INS, true), $command['name']])
            ->values()
            ->all();

        if (($project->agent_commands[$harness->value] ?? null) !== $kept) {
            $project->update(['agent_commands' => [...($project->agent_commands ?? []), $harness->value => $kept]]);
        }
    }

    /**
     * Split a message starting with one of the agent's commands into the command and its details, e.g.
     * "/review the API" into ['review', 'the API']. Null for anything else, which goes as plain text.
     *
     * @return array{0: string, 1: string}|null
     */
    public function parse(Conversation $conversation, string $content): ?array
    {
        if (! preg_match('/^\/([\w:.-]+)(?:\s+(.*))?$/s', trim($content), $match)) {
            return null;
        }

        $names = array_column($this->for($conversation), 'name');

        return in_array($match[1], $names, true) ? [$match[1], trim($match[2] ?? '')] : null;
    }

    /**
     * What Codex is sent for `/name details`: its prompt for a built-in, or a `$skill` mention.
     */
    public function codexPrompt(string $name, string $details): string
    {
        $prompt = self::CODEX_PROMPTS[$name] ?? '$'.$name;

        return trim($details === '' ? $prompt : "{$prompt}\n\n{$details}");
    }
}
