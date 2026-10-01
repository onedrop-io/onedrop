<?php

namespace App\Sandbox\Agents;

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Http\Controllers\ProjectRequirementsController;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxProvider;
use App\Sandbox\SecretsException;
use App\Sandbox\WorkspaceFiles;
use App\Sandbox\WorkspaceSecrets;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * What the platform decides about a chat message as it's sent, by asking Jev once, with every question that applies:
 * - whether it contains a secret, to offer saving it in Secrets instead of sending it to the agent (SECRET-002);
 * - whether it changes an earlier decision in REQ.md, to confirm before the run starts (REQ-003);
 * - while the agent works, whether it corrects the work in progress, to send it now instead of queuing it (AGT-012);
 * - with Auto on, how big the request is, to pick the model and reasoning level (AGT-011).
 * Without a key, or when Jev is slow, fails or leaves a question out, the message is sent as it would be without it.
 */
class MessageChecks
{
    /** The user is waiting, so Jev gets this long (it usually answers in about 300 ms). */
    public const TIMEOUT_SECONDS = 3;

    /** How sure Jev must be that the message changes an earlier decision before asking the user. */
    public const DECISION_THRESHOLD = 0.8;

    /** How sure Jev must be that the message corrects the work in progress before interrupting it. */
    public const INTERRUPT_THRESHOLD = 0.8;

    /** How sure Jev must be that the message contains a secret before holding it. */
    public const SECRET_THRESHOLD = 0.7;

    /** The most recent decisions Jev chooses from. */
    public const MAX_DECISIONS = 40;

    /** The most parts of a message offered to Jev as the secret. */
    public const MAX_SECRET_CANDIDATES = 8;

    /** How long a held message's answers are kept for its follow-up (Go ahead, Save in Secrets). */
    public const HOLD_MINUTES = 15;

    /** The chat's line when Jev's answer sent a message now. */
    public const NOW_NOTE = 'Sent now: it changes what the agent is doing';

    public function __construct(
        protected Jev $jev,
        protected AgentQueue $queue,
        protected AutoModel $auto,
        protected SandboxProvider $provider,
        protected WorkspaceSecrets $secrets,
    ) {}

    /**
     * The request fields for the composer's checks and its replies to a held message.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'checks' => ['sometimes', 'boolean'],
            'check' => ['nullable', 'string', 'max:64'],
            'confirm_decision' => ['sometimes', 'boolean'],
            'send_secret' => ['sometimes', 'boolean'],
            'secret_name' => ['nullable', 'string', 'max:100'],
            'secret_value' => ['nullable', 'string', 'max:20000'],
            'agent_context' => ['nullable', 'string', 'max:20000'],
        ];
    }

    /**
     * Send a message, or hold it for the user to answer first. $mode is "queue" or "now" (as today) or "auto" (Jev
     * decides whether to queue it while the agent works). Without $interactive (messages the platform writes, e.g.
     * Feature Flags' agent actions) nothing is held or interrupted; only Auto's model is picked.
     *
     * @param  list<UploadedFile>  $attachments
     * @param  array{check?: string|null, confirm_decision?: bool, send_secret?: bool, secret_name?: string|null, secret_value?: string|null}  $replies
     * @param  string|null  $agentContext  for the agent only, not shown in the chat (e.g. what a marked-up preview points at, AGT-013)
     * @return array<string, mixed>|null the hold to show the user, or null when the message went to the agent
     *
     * @throws ValidationException when a secret can't be saved
     */
    public function send(Conversation $conversation, string $content, string $mode = 'queue', array $attachments = [], bool $interactive = false, array $replies = [], ?string $agentContext = null): ?array
    {
        $askInterrupt = $interactive && $mode === 'auto' && $conversation->getAttribute('status') === ProjectStatus::Working;
        $token = $replies['check'] ?? null;
        $answers = ($token ? $this->heldAnswers($token, $conversation, $content) : null)
            ?? $this->ask($conversation, $content, $interactive, $askInterrupt);
        $savingSecret = filled($replies['secret_name'] ?? null);

        if ($interactive && $answers['secret'] !== null && ! $savingSecret && ! ($replies['send_secret'] ?? false)) {
            return $this->hold($conversation, $content, $answers, [
                'kind' => 'secret',
                'name' => $answers['secret']['name'],
                'preview' => ($value = self::secretCandidates($content)[$answers['secret']['candidate'] ?? -1] ?? null) !== null
                    ? mb_substr($value, 0, 4).'…'
                    : null,
            ]);
        }

        if ($interactive && $answers['decision'] !== null && ! ($replies['confirm_decision'] ?? false)) {
            return $this->hold($conversation, $content, $answers, ['kind' => 'decision', 'decision' => $answers['decision']]);
        }

        if ($savingSecret) {
            $content = $this->saveSecret($conversation, $content, (string) $replies['secret_name'], $replies['secret_value'] ?? null, $answers['secret']['candidate'] ?? null);
        }

        if ($token) {
            Cache::forget(self::holdKey($token));
        }

        $meta = [];

        if (filled($agentContext)) {
            $meta['agent_context'] = $agentContext;
        }

        if ($interactive && $answers['decision'] !== null) {
            $meta['changes_decision'] = $answers['decision'];
        }

        if ($conversation->ownerProject()->agent_auto && ($selection = $this->auto->pick($conversation->ownerProject(), $answers['size'])) !== null) {
            $meta['selection'] = [...$selection, 'provider' => $selection['provider']->value];
            $meta['auto_note'] = $this->auto->note($selection, $answers['size']);
        }

        $interrupt = $askInterrupt && $answers['interrupt'];

        $this->queue->send(
            $conversation,
            $content,
            now: $mode === 'now' || $interrupt,
            attachments: $attachments,
            meta: $meta !== [] ? $meta : null,
            nowNote: $interrupt ? self::NOW_NOTE : null,
        );

        return null;
    }

    /**
     * Ask Jev every question that applies to the message, in one request.
     *
     * @return array{secret: array{candidate: int|null, name: string}|null, decision: string|null, interrupt: bool, size: int|null}
     */
    protected function ask(Conversation $conversation, string $content, bool $interactive, bool $askInterrupt): array
    {
        $answers = ['secret' => null, 'decision' => null, 'interrupt' => false, 'size' => null];
        $project = $conversation->ownerProject();

        if (trim($content) === '' || (! $interactive && ! $project->agent_auto) || ($endpoint = $this->jev->endpointFor($project)) === null) {
            return $answers;
        }

        $state = ['new_message' => Str::limit($content, 4000)];
        $questions = [];
        $candidates = [];
        $decisions = [];

        if ($interactive) {
            $candidates = self::secretCandidates($content);
            $questions['secret'] = Jev::yesOrNoQuestion(
                'Does the new message contain the actual value of a credential?',
                'It contains a real API key, password, access token, private key, or a database URL with a password in it.',
                'It has no credential values: it may mention keys, passwords or variable names, or use placeholders like YOUR_API_KEY.',
            );

            if (count($candidates) > 1) {
                $questions['secret_value'] = Jev::choiceQuestion(
                    'Which part of the new message is the credential?',
                    collect($candidates)->mapWithKeys(fn (string $candidate, int $index) => ["c{$index}" => Str::limit($candidate, 200)])->all(),
                );
            }

            $decisions = $project->track_requirements ? $this->decisions($project) : [];

            if ($decisions !== []) {
                $questions['decision'] = Jev::choiceQuestion(
                    'Which earlier decision would doing what the new message asks undo or change? Building on a decision or adding something new is not changing it.',
                    [
                        ...collect($decisions)->mapWithKeys(fn (array $decision, int $index) => ["d{$index}" => $decision['text']])->all(),
                        'none' => 'None: the message changes none of these decisions.',
                    ],
                );
            }
        }

        if ($askInterrupt) {
            $state['agent_is_working_on'] = Str::limit((string) $conversation->messages()->where('role', MessageRole::User)->reorder()->latest('id')->value('content'), 2000);
            $state['agent_recent_steps'] = $conversation->messages()->where('role', MessageRole::Activity)->reorder()->latest('id')->limit(10)->pluck('content')
                ->reverse()->map(fn (string $step) => Str::limit($step, 200))->values()->all();
            $questions['interrupt'] = Jev::yesOrNoQuestion(
                'Is the new message a correction or change to the work the agent is doing right now (agent_is_working_on)?',
                'It corrects, redirects or changes that work, so the agent should hear it now (e.g. "actually make it blue", "no, use the other page", "stop, do it with Postgres").',
                'It is a separate request, or a question, that can wait until the current work is done.',
            );
        }

        if ($project->agent_auto) {
            $questions['size'] = Jev::scoreQuestion('How much work is the new message for a coding agent?', AutoModel::SCALE);
        }

        try {
            $reply = $this->jev->decide($endpoint, $state, $questions, self::TIMEOUT_SECONDS);
        } catch (Throwable $e) {
            report($e);

            return $answers;
        }

        if (($reply['secret']['noul'] ?? 0) >= self::SECRET_THRESHOLD) {
            $candidate = match (true) {
                count($candidates) === 1 => 0,
                isset($reply['secret_value']) && ($reply['secret_value']['probabilities'][$reply['secret_value']['choice']] ?? 0) >= 0.5 => (int) Str::after($reply['secret_value']['choice'], 'c'),
                default => null,
            };
            $answers['secret'] = ['candidate' => $candidate, 'name' => self::suggestName($content, $candidates[$candidate] ?? null)];
        }

        $choice = $reply['decision']['choice'] ?? 'none';

        if ($choice !== 'none' && ($reply['decision']['probabilities'][$choice] ?? 0) >= self::DECISION_THRESHOLD) {
            $answers['decision'] = $decisions[(int) Str::after($choice, 'd')]['title'] ?? null;
        }

        $answers['interrupt'] = ($reply['interrupt']['noul'] ?? 0) >= self::INTERRUPT_THRESHOLD;
        $answers['size'] = isset($reply['size']['score']) ? AutoModel::sizeFromScore((float) $reply['size']['score']) : null;

        return $answers;
    }

    /**
     * The decisions in the project's REQ.md (the bullets under each "### Decisions"), most recent last. Read from Main's
     * sandbox, and kept until anything new appears in the project's chats, since only the agent changes the file.
     *
     * @return list<array{text: string, title: string}>
     */
    public function decisions(Project $project): array
    {
        $sandbox = $project->sandbox;

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            return [];
        }

        $latest = $project->allMessages()->where('role', '!=', MessageRole::User)->max('id') ?? 0;

        return Cache::remember("requirements-decisions:{$project->id}:{$latest}", now()->addHour(), function () use ($sandbox) {
            try {
                $result = $this->provider->exec($sandbox->external_id, ['cat', WorkspaceFiles::ROOT.'/'.ProjectRequirementsController::PATH]);
            } catch (SandboxException) {
                return [];
            }

            return $result->successful() ? self::parseDecisions($result->output) : [];
        });
    }

    /**
     * @return list<array{text: string, title: string}>
     */
    public static function parseDecisions(string $requirements): array
    {
        $decisions = [];
        $inDecisions = false;

        foreach (preg_split('/\R/', $requirements) ?: [] as $line) {
            if (preg_match('/^#{1,6}\s+(.*)$/', $line, $heading)) {
                $inDecisions = (bool) preg_match('/^decisions\b/i', trim($heading[1]));

                continue;
            }

            if ($inDecisions && preg_match('/^\s*[-*]\s+(.+)$/', $line, $bullet)) {
                $text = trim($bullet[1]);
                $title = preg_match('/\*\*(.+?)\*\*/', $text, $bold) ? $bold[1] : $text;
                $title = trim((string) preg_replace('/^\d{4}-\d{2}-\d{2}:?\s*/', '', $title));

                $decisions[] = ['text' => Str::limit($text, 300), 'title' => Str::limit($title, 200)];
            }
        }

        return array_slice($decisions, -self::MAX_DECISIONS);
    }

    /**
     * The parts of a message that could be a secret's value: a database URL with a password, a NAME=value's value,
     * or a long word that mixes letters and digits (keys, tokens, most passwords).
     *
     * @return list<string>
     */
    public static function secretCandidates(string $content): array
    {
        preg_match_all('/[^\s"\'`<>]+/u', $content, $matches);
        $candidates = [];

        foreach ($matches[0] as $word) {
            $word = trim($word, '.,;!?()[]{}');

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*[=:](.+)$/', $word, $assignment) && ! str_contains($word, '://')) {
                $word = trim($assignment[1], '.,;!?()[]{}');
            }

            $isUrl = (bool) preg_match('#^[a-z][a-z0-9+.-]*://#i', $word);
            $hasCredentials = (bool) preg_match('#^[a-z][a-z0-9+.-]*://[^/\s:@]*:[^/\s@]+@#i', $word);

            if ($hasCredentials || (! $isUrl && mb_strlen($word) >= 8 && preg_match('/\d/', $word) && preg_match('/[A-Za-z]/', $word))) {
                $candidates[] = $word;
            }
        }

        return array_slice(array_values(array_unique($candidates)), 0, self::MAX_SECRET_CANDIDATES);
    }

    /**
     * A name to suggest for the secret: the name it's assigned to in the message, the usual one for a known kind of key
     * or URL, or the words just before it ("the admin password is …" → ADMIN_PASSWORD).
     */
    public static function suggestName(string $content, ?string $value): string
    {
        if ($value === null || $value === '') {
            return 'SECRET';
        }

        if (preg_match('/([A-Za-z_][A-Za-z0-9_]*)\s*[=:]\s*["\']?'.preg_quote($value, '/').'/', $content, $assigned) && preg_match('/[A-Z_]/', $assigned[1]) && strtoupper($assigned[1]) === $assigned[1]) {
            return $assigned[1];
        }

        $prefixes = [
            'sk_live_' => 'STRIPE_SECRET_KEY', 'sk_test_' => 'STRIPE_SECRET_KEY', 'rk_live_' => 'STRIPE_SECRET_KEY', 'rk_test_' => 'STRIPE_SECRET_KEY',
            'pk_live_' => 'STRIPE_PUBLISHABLE_KEY', 'pk_test_' => 'STRIPE_PUBLISHABLE_KEY', 'whsec_' => 'STRIPE_WEBHOOK_SECRET',
            'sk-ant-' => 'ANTHROPIC_API_KEY', 'sk-or-' => 'OPENROUTER_API_KEY', 'sk-' => 'OPENAI_API_KEY',
            'ghp_' => 'GITHUB_TOKEN', 'github_pat_' => 'GITHUB_TOKEN', 'glpat-' => 'GITLAB_TOKEN', 'xox' => 'SLACK_TOKEN',
            'AKIA' => 'AWS_ACCESS_KEY_ID', 'AIza' => 'GOOGLE_API_KEY', 'SG.' => 'SENDGRID_API_KEY', 're_' => 'RESEND_API_KEY',
        ];

        foreach ($prefixes as $prefix => $name) {
            if (str_starts_with($value, $prefix)) {
                return $name;
            }
        }

        if (preg_match('#^([a-z][a-z0-9+.-]*)://#i', $value, $scheme)) {
            $scheme = strtolower(Str::before($scheme[1], '+'));

            return in_array($scheme, ['postgres', 'postgresql', 'mysql', 'mariadb', 'mongodb', 'sqlserver'], true) ? 'DATABASE_URL' : strtoupper($scheme).'_URL';
        }

        $before = Str::before($content, $value);
        $stopWords = ['my', 'the', 'is', 'are', 'here', 'heres', "here's", 'this', 'our', 'a', 'an', 'use', 'it', 'its', 'for', 'and', 'with', 'to', 'of', 'as', 'be', 'set', 'should', 'please', 'value'];
        $words = collect(preg_split('/[^A-Za-z0-9]+/', $before) ?: [])
            ->filter(fn (string $word) => $word !== '')
            ->take(-5)
            ->reject(fn (string $word) => in_array(strtolower($word), $stopWords, true) || ctype_digit($word))
            ->take(-3)
            ->map(fn (string $word) => strtoupper($word));

        $name = $words->implode('_');

        return preg_match('/^[A-Z_][A-Z0-9_]*$/', $name) ? $name : 'SECRET';
    }

    /**
     * Save the secret in the app's .env (Tools → Secrets) and return the message with a reference in its place.
     *
     * @throws ValidationException
     */
    protected function saveSecret(Conversation $conversation, string $content, string $name, ?string $value, ?int $candidate): string
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw ValidationException::withMessages(['secret_name' => __('Use letters, digits and underscores, not starting with a digit.')]);
        }

        $value = filled($value) ? $value : (self::secretCandidates($content)[$candidate ?? -1] ?? null);

        if ($value === null || ! str_contains($content, $value)) {
            throw ValidationException::withMessages(['secret_value' => $value === null ? __("Paste the secret's value.") : __("That value isn't in your message.")]);
        }

        $sandbox = $this->secretsSandbox($conversation);

        if ($sandbox === null) {
            throw ValidationException::withMessages(['secret_name' => __("The project's sandbox isn't running, so the secret can't be saved.")]);
        }

        try {
            $this->secrets->set($sandbox, [$name => $value]);
        } catch (SecretsException|SandboxException $e) {
            throw ValidationException::withMessages(['secret_name' => $e->getMessage()]);
        }

        return str_replace($value, "(saved as {$name} in Secrets)", $content);
    }

    /**
     * Where the agent will read the secret: the conversation's own sandbox (a task's copy) when it's running,
     * otherwise Main's, which a task's copy is made from.
     */
    protected function secretsSandbox(Conversation $conversation): ?Sandbox
    {
        foreach ([$conversation->agentSandbox(), $conversation->ownerProject()->sandbox] as $sandbox) {
            if ($sandbox?->status === SandboxStatus::Running && $sandbox->external_id !== null) {
                return $sandbox;
            }
        }

        return null;
    }

    /**
     * Keep the answers for the user's reply to the hold (never the message or the secret itself), and say what to show.
     *
     * @param  array<string, mixed>  $answers
     * @param  array<string, mixed>  $hold
     * @return array<string, mixed>
     */
    protected function hold(Conversation $conversation, string $content, array $answers, array $hold): array
    {
        $token = Str::random(40);

        Cache::put(self::holdKey($token), [
            'conversation' => $conversation::class.':'.$conversation->getKey(),
            'hash' => hash('sha256', $content),
            'answers' => $answers,
        ], now()->addMinutes(self::HOLD_MINUTES));

        return ['check' => $token, ...$hold];
    }

    /**
     * The answers kept for a held message, while it's the same message in the same chat.
     *
     * @return array{secret: array{candidate: int|null, name: string}|null, decision: string|null, interrupt: bool, size: int|null}|null
     */
    protected function heldAnswers(string $token, Conversation $conversation, string $content): ?array
    {
        $held = Cache::get(self::holdKey($token));

        return is_array($held)
            && $held['conversation'] === $conversation::class.':'.$conversation->getKey()
            && hash_equals($held['hash'], hash('sha256', $content))
            ? $held['answers']
            : null;
    }

    /**
     * Keep what to show for a held message until the user's next page load in that chat picks it up
     * (pullPrompt()). Not flash data: the session is shared by the workspace's background requests, and one
     * running alongside the send (e.g. the sandbox activity ping) can save its copy over the flash and lose it.
     *
     * @param  array<string, mixed>  $held
     */
    public static function rememberPrompt(User $user, Conversation $conversation, array $held): void
    {
        Cache::put(self::promptKey($user, $conversation), $held, now()->addMinutes(self::HOLD_MINUTES));
    }

    /**
     * What to show for the user's held message in this chat, once.
     *
     * @return array<string, mixed>|null
     */
    public static function pullPrompt(User $user, Conversation $conversation): ?array
    {
        $held = Cache::pull(self::promptKey($user, $conversation));

        return is_array($held) ? $held : null;
    }

    protected static function promptKey(User $user, Conversation $conversation): string
    {
        return "message-check-prompt:{$user->id}:".$conversation::class.':'.$conversation->getKey();
    }

    protected static function holdKey(string $token): string
    {
        return "message-check:{$token}";
    }
}
