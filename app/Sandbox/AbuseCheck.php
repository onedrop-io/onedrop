<?php

namespace App\Sandbox;

use App\Enums\AbuseReviewStatus;
use App\Enums\MessageRole;
use App\Enums\PublishStatus;
use App\Enums\PublishVisibility;
use App\Enums\SandboxStatus;
use App\Jobs\CheckForAbuse;
use App\Jobs\PublishProject;
use App\Models\AbuseReview;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Sandbox\Agents\Jev;
use App\Sandbox\Publishing\Publishers;
use App\Sandbox\Publishing\PublishException;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * On the hosted install, before a project goes public (published as Public, or shared), ask Jev whether it looks
 * like abuse: phishing, a scam or malware, or something else clearly harmful (PUB-003). Above THRESHOLD it's held
 * for a platform admin, who approves it or takes it down (ADMIN-006). Jev failing lets it through (fail open).
 */
class AbuseCheck
{
    /** How likely any kind of abuse must look before a person has to review it. */
    public const THRESHOLD = 0.8;

    /** Seconds Jev gets to answer (it usually takes well under one). */
    public const JEV_TIMEOUT = 15;

    /** Seconds after an agent turn before re-checking, after the other after-turn checks. */
    public const RECHECK_DELAY_SECONDS = 20;

    /** What the user sees when an admin took the app down. */
    public const TAKEN_DOWN_MESSAGE = 'OneDrop took this app down after a review, so it can\'t be published publicly or shared. Contact support if you think that\'s a mistake.';

    /**
     * What Jev is asked, each answered with how likely it is.
     *
     * @var array<string, array{instructions: string, true: string, false: string}>
     */
    public const QUESTIONS = [
        'phishing' => [
            'instructions' => 'Is this app a phishing page: does it pose as a real company, bank or service (or its login, payment or account page) to get visitors to hand over passwords, card numbers or other details?',
            'true' => "It uses a real brand it does not belong to (e.g. a bank, PayPal, Microsoft, Google, Apple, a shipping company or a government office) and asks for that service's sign-in, payment or personal details.",
            'false' => 'It is its own product under its own name. Its own sign-up or sign-in, a store taking payment for its own goods, or a clone of a well-known app built for practice with its own accounts is not phishing.',
        ],
        'scam' => [
            'instructions' => 'Is this app made to run a scam or spread malware?',
            'true' => 'It promises prizes, giveaways, crypto doubling or investment returns, shows fake security alerts or support numbers, asks for wallet seed phrases or upfront fees, or pushes visitors to download and run files or extensions.',
            'false' => 'It is an ordinary app, game, tool, store, portfolio or site. Selling real things, taking donations, or a crypto price tracker is not a scam.',
        ],
        'harmful' => [
            'instructions' => 'Is this app clearly harmful in some other way?',
            'true' => 'It sexualizes minors, harasses, threatens or exposes private details of a real person, sells illegal drugs or weapons, or helps people commit violence.',
            'false' => 'Nothing clearly harmful. Adult or edgy topics, humor, news, opinions, security education and games are fine.',
        ],
    ];

    /** What each question's key means, for admins and the user. */
    public const LABELS = [
        'phishing' => 'Phishing or impersonating a real brand',
        'scam' => 'A scam or malware',
        'harmful' => 'Otherwise clearly harmful',
    ];

    public function __construct(protected Jev $jev, protected SandboxProvider $provider, protected Publishers $publishers) {}

    /**
     * Whether projects are checked at all: only on the hosted install, and only with the platform's own key.
     */
    public function enabled(): bool
    {
        return Organization::multiTenant() && $this->jev->platformEndpoint() !== null;
    }

    /**
     * Re-check a project that's public after its agent changed it, at most once a day.
     */
    public static function afterTurn(Project $project): void
    {
        $check = app(self::class);

        if (! $check->enabled() || ! $check->isPublic($project) || $project->abuseReview?->status?->blocksPublic()
            || ! Cache::add("abuse-recheck:{$project->id}", true, now()->addDay())) {
            return;
        }

        CheckForAbuse::dispatch($project, 'recheck')->delay(now()->addSeconds(self::RECHECK_DELAY_SECONDS));
    }

    /**
     * Whether anyone on the internet can see the project: published as Public, or shared.
     */
    public function isPublic(Project $project): bool
    {
        return ($project->publish_visibility === PublishVisibility::Public && in_array($project->publish_status, [PublishStatus::Publishing, PublishStatus::Live], true))
            || $project->share()->exists();
    }

    /**
     * Check the project and hold it when it looks like abuse; returns where it stands. Already held or taken down
     * stays that way without asking again, and an approval stands until the project's chat moves on.
     *
     * @param  'publish'|'share'|'recheck'  $trigger
     */
    public function run(Project $project, string $trigger): AbuseReviewStatus
    {
        $review = $project->abuseReview()->first();

        if (! $this->enabled()) {
            return $review->status ?? AbuseReviewStatus::Clear;
        }

        if ($review?->status->blocksPublic()) {
            $this->keepOffline($project, $review->status);

            return $review->status;
        }

        if ($review?->status === AbuseReviewStatus::Approved && ! $this->changedSince($project, $review)) {
            return AbuseReviewStatus::Approved;
        }

        $state = $this->state($project);

        try {
            $answers = $this->jev->yesOrNo($this->jev->platformEndpoint(), $state, self::QUESTIONS, self::JEV_TIMEOUT);
        } catch (Throwable $e) {
            // Fail open: Jev being down shouldn't stop everyone publishing.
            report($e);

            return $review->status ?? AbuseReviewStatus::Clear;
        }

        $answers = array_map(fn (float $probability) => round($probability, 3), $answers);
        $score = $answers === [] ? 0.0 : max($answers);
        $held = $score >= self::THRESHOLD;

        $project->abuseReview()->updateOrCreate([], [
            'status' => $held ? AbuseReviewStatus::Held : AbuseReviewStatus::Clear,
            'trigger' => $trigger,
            'score' => $score,
            'reasons' => $answers,
            // Only kept when a person has to look at it.
            'evidence' => $held ? $state : null,
            'checked_at' => now(),
            'flagged_at' => $held ? now() : null,
            'decided_by' => null,
            'decided_at' => null,
        ]);

        if ($held) {
            $this->keepOffline($project, AbuseReviewStatus::Held);

            return AbuseReviewStatus::Held;
        }

        return AbuseReviewStatus::Clear;
    }

    /**
     * A platform admin let the project through: a held publish goes ahead.
     */
    public function approve(AbuseReview $review, User $admin): void
    {
        $review->update(['status' => AbuseReviewStatus::Approved, 'decided_by' => $admin->id, 'decided_at' => now()]);

        $project = $review->project;

        if ($project->publish_status === PublishStatus::Review) {
            $project->update(['publish_status' => PublishStatus::Publishing, 'publish_error' => null]);
            PublishProject::dispatch($project);
        }
    }

    /**
     * A platform admin took the project down: its public app and share page go, and stay gone until one approves it.
     */
    public function takeDown(AbuseReview $review, User $admin): void
    {
        $review->update(['status' => AbuseReviewStatus::TakenDown, 'decided_by' => $admin->id, 'decided_at' => now()]);

        $this->keepOffline($review->project, AbuseReviewStatus::TakenDown);
    }

    /**
     * Take the public app offline (held: waiting for review; taken down: failed with why), and for a takedown
     * remove the share page too. A held share page is just hidden (ShareController).
     */
    protected function keepOffline(Project $project, AbuseReviewStatus $status): void
    {
        $public = $project->publish_visibility === PublishVisibility::Public
            && in_array($project->publish_status, [PublishStatus::Publishing, PublishStatus::Live, PublishStatus::Review], true);

        if ($public) {
            if ($project->publish_status !== PublishStatus::Review) {
                try {
                    $this->publishers->forProject($project)->stop($project);
                } catch (PublishException $e) {
                    report($e);
                }
            }

            $project->update([
                'publish_status' => $status === AbuseReviewStatus::TakenDown ? PublishStatus::Failed : PublishStatus::Review,
                'publish_error' => $status === AbuseReviewStatus::TakenDown ? self::TAKEN_DOWN_MESSAGE : null,
                'publish_login_url' => null,
                'publish_waiting_for' => null,
            ]);
        }

        if ($status === AbuseReviewStatus::TakenDown && $project->share()->exists()) {
            $project->share()->delete();
            app(ShareCards::class)->delete($project->id);
        }
    }

    /**
     * Whether someone sent the project's agent a message since the admin's decision.
     */
    protected function changedSince(Project $project, AbuseReview $review): bool
    {
        return $review->decided_at === null
            || $project->allMessages()->where('role', MessageRole::User)->where('created_at', '>', $review->decided_at)->exists();
    }

    /**
     * What Jev sees: the project's name, its first and latest prompts, and its home page. Kept small.
     *
     * @return array{project_name: string, prompts: list<string>, page: array{title: string, text: string, fields: list<string>, form_actions: list<string>}|null}
     */
    public function state(Project $project): array
    {
        $prompts = $project->allMessages()->where('role', MessageRole::User)->reorder()->orderBy('id')->pluck('content');
        $prompts = $prompts->count() > 10 ? $prompts->take(2)->merge($prompts->take(-8)) : $prompts;

        return [
            'project_name' => Str::limit($project->name, 100),
            'prompts' => array_values($prompts->map(fn (string $prompt) => Str::limit(Str::squish($prompt), 500))->all()),
            'page' => $this->page($project),
        ];
    }

    /**
     * The app's home page as a browser renders it (headless Chromium in the sandbox, else its raw HTML), boiled
     * down to its title, visible text, form fields and where its forms send. Null when it can't be read.
     *
     * @return array{title: string, text: string, fields: list<string>, form_actions: list<string>}|null
     */
    protected function page(Project $project): ?array
    {
        $sandbox = $project->sandbox;

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            return null;
        }

        try {
            $result = $this->provider->exec($sandbox->external_id, ['sh', '-c', self::PAGE_COMMAND]);
        } catch (SandboxException $e) {
            report($e);

            return null;
        }

        return $result->successful() && trim($result->output) !== '' ? self::readPage($result->output) : null;
    }

    /** Loads the home page with headless Chromium (so client-rendered apps show their text), else curl; at most 400 KB. */
    protected const PAGE_COMMAND = 'url="http://localhost:${PORT:-8000}/"; profile=$(mktemp -d); '
        .'{ timeout 20 chromium --headless --no-sandbox --disable-gpu --disable-dev-shm-usage --no-first-run --mute-audio '
        .'--user-data-dir="$profile" --virtual-time-budget=5000 --dump-dom "$url" 2>/dev/null || curl -s --max-time 10 "$url"; } '
        .'| head -c 400000; rm -rf "$profile"; true';

    /**
     * @return array{title: string, text: string, fields: list<string>, form_actions: list<string>}
     */
    public static function readPage(string $html): array
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);

        $title = Str::squish(self::text($xpath, '//title'));

        foreach (self::nodes($xpath, '//script|//style|//noscript|//template|//svg|//title') as $node) {
            $node->parentNode?->removeChild($node);
        }

        $fields = collect(self::nodes($xpath, '//input|//select|//textarea'))
            ->filter(fn (DOMNode $node) => $node instanceof DOMElement && $node->getAttribute('type') !== 'hidden')
            ->map(fn (DOMElement $field) => Str::limit(Str::squish(implode(' ', array_filter([
                $field->getAttribute('type') ?: $field->tagName,
                $field->getAttribute('name'),
                $field->getAttribute('placeholder'),
                $field->getAttribute('aria-label'),
            ]))), 120))
            ->unique()->take(30)->values()->all();

        $actions = collect(self::nodes($xpath, '//form[@action]'))
            ->filter(fn (DOMNode $node) => $node instanceof DOMElement)
            ->map(fn (DOMElement $form) => Str::limit($form->getAttribute('action'), 200))
            ->filter()->unique()->take(10)->values()->all();

        return [
            'title' => Str::limit($title, 200),
            'text' => Str::limit(Str::squish(self::text($xpath, '//body')), 3000),
            'fields' => array_values($fields),
            'form_actions' => array_values($actions),
        ];
    }

    /**
     * The elements an XPath query finds (none when it's invalid).
     *
     * @return list<DOMNode>
     */
    protected static function nodes(DOMXPath $xpath, string $query): array
    {
        $found = $xpath->query($query);

        return $found === false ? [] : array_values(array_filter(iterator_to_array($found), fn ($node) => $node instanceof DOMNode));
    }

    /**
     * The text of the first element an XPath query finds, or an empty string.
     */
    protected static function text(DOMXPath $xpath, string $query): string
    {
        return self::nodes($xpath, $query)[0]->textContent ?? '';
    }
}
