<?php

namespace App\Sandbox\Agents;

use App\Enums\AgentProvider;
use App\Models\Project;

/**
 * Auto model and reasoning (AGT-011): the platform picks the model and reasoning level for each message from how
 * big the request is (Jev scores it when it's sent), using the provider the project is on (config sandbox.auto_models).
 */
class AutoModel
{
    /**
     * The sizes Jev scores a request on, smallest first; their positions index config sandbox.auto_models.
     *
     * @var list<string>
     */
    public const SCALE = [
        'A typo, a wording change or a tiny tweak (one value or line)',
        'A small change in one place',
        'A new feature or a change across several files',
        'A big redesign, a whole new app, or a hard bug to track down',
    ];

    /**
     * How each size reads in the chat ("for a small change").
     *
     * @var list<string>
     */
    public const SIZES = ['a tiny tweak', 'a small change', 'a new feature', 'a big change'];

    /** How the reasoning levels read in the chat, like the picker's. */
    protected const EFFORTS = ['none' => 'no', 'minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'extra high', 'max' => 'max'];

    public function __construct(protected ModelCatalog $catalog) {}

    /**
     * What to run a message of this size with (0 is a tiny tweak), on the project's provider. Without a size (Jev
     * couldn't say), the provider's default model; the same when the configured model isn't one the user can run.
     *
     * @return array{provider: AgentProvider, model: string, variant: string|null}|null
     */
    public function pick(Project $project, ?int $size): ?array
    {
        $provider = $this->catalog->selectionFor($project)['provider'] ?? null;

        if ($provider === null) {
            return null;
        }

        $default = ['provider' => $provider, 'model' => $this->catalog->defaultModel($provider, $project->user), 'variant' => null];
        $configured = $size === null ? null : config("sandbox.auto_models.{$provider->value}.{$size}");

        if (! is_array($configured) || ! is_string($configured[0] ?? null)) {
            return $default;
        }

        $model = $this->catalog->find($provider, $configured[0], $project->user);

        if ($model === null) {
            return $default;
        }

        $effort = $configured[1] ?? null;

        return ['provider' => $provider, 'model' => $model['id'], 'variant' => in_array($effort, $model['efforts'], true) ? $effort : null];
    }

    /**
     * The chat's line saying what Auto picked: "Auto picked Claude Sonnet 5 with low reasoning, for a small change".
     *
     * @param  array{provider: AgentProvider, model: string, variant: string|null}  $selection
     */
    public function note(array $selection, ?int $size): string
    {
        $name = $this->catalog->describe($selection)['name'];
        $effort = $selection['variant'] !== null ? ' with '.(self::EFFORTS[$selection['variant']] ?? $selection['variant']).' reasoning' : '';
        $reason = $size !== null && isset(self::SIZES[$size]) ? ', for '.self::SIZES[$size] : ", the default (the request couldn't be sized)";

        return "Auto picked {$name}{$effort}{$reason}";
    }

    /**
     * The size step a Jev score falls on (the score is the probability-weighted position on the scale).
     */
    public static function sizeFromScore(float $score): int
    {
        return max(0, min(count(self::SCALE) - 1, (int) round($score)));
    }
}
