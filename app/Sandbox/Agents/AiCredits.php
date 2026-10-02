<?php

namespace App\Sandbox\Agents;

use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * AI credits (CREDIT-001): people who haven't connected their own AI build on the platform's, paid from their
 * organization's credits at provider rates. Each organization gets its own OpenRouter key, limited before every
 * run to what it has left, so the key can't overspend even from the sandbox's shell; afterwards, what the key
 * spent (OpenRouter's count, which the sandbox can't change) is taken off the credits in Autumn.
 */
class AiCredits
{
    public function __construct(protected Autumn $autumn, protected OpenRouterKeys $keys) {}

    /**
     * Whether this install offers AI credits: it needs Autumn and an OpenRouter management key.
     */
    public function enabled(): bool
    {
        return $this->autumn->configured() && filled(config('services.openrouter.provisioning_key'));
    }

    /**
     * The sandbox environment for a run on the organization's credits, with its key limited to what's left.
     *
     * @return array<string, string>
     *
     * @throws OutOfAiCredits when there's nothing left, or the credits can't be checked
     */
    public function sandboxEnvironment(Organization $organization): array
    {
        return Cache::lock("ai-credits:{$organization->id}", 30)->block(20, function () use ($organization): array {
            try {
                $spent = $this->chargeSpent($organization);
                $balance = $this->autumn->balance($organization);

                if ($balance !== null && $balance['remaining'] < 1) {
                    throw new OutOfAiCredits(self::ranOutMessage($balance['resets_at']));
                }

                if ($balance === null && $organization->ai_credits_key === null) {
                    throw new OutOfAiCredits(__("AI credits can't be checked right now. Try again in a minute."));
                }

                if ($organization->ai_credits_key === null) {
                    $key = $this->keys->create("OneDrop {$organization->slug} ({$organization->id})", $balance['remaining'] / 100);
                    $organization->forceFill(['ai_credits_key' => $key['key'], 'ai_credits_key_hash' => $key['hash'], 'ai_credits_charged' => 0])->save();
                } elseif ($balance !== null) {
                    $this->keys->setLimit((string) $organization->ai_credits_key_hash, ($spent ?? 0) + $balance['remaining'] / 100);
                }
            } catch (OutOfAiCredits $e) {
                throw $e;
            } catch (Throwable $e) {
                report($e);

                throw new OutOfAiCredits(__("AI credits can't be checked right now. Try again in a minute."));
            }

            Cache::forget("ai-credits-left:{$organization->id}");

            return ['OPENROUTER_API_KEY' => (string) $organization->ai_credits_key];
        });
    }

    /**
     * Take what the organization's key spent since the last charge off its credits.
     */
    public function charge(Organization $organization): void
    {
        Cache::lock("ai-credits:{$organization->id}", 30)->block(20, fn () => $this->chargeSpent($organization));
        Cache::forget("ai-credits-left:{$organization->id}");
    }

    /**
     * What the organization has left, in dollars, cached for a minute; null when it can't be checked.
     */
    public function remaining(Organization $organization): ?float
    {
        $cents = Cache::remember("ai-credits-left:{$organization->id}", 60, function () use ($organization): float|false {
            try {
                return $this->autumn->balance($organization)['remaining'] ?? false;
            } catch (Throwable $e) {
                report($e);

                return false;
            }
        });

        return $cents === false ? null : round($cents / 100, 2);
    }

    /**
     * What a run says when the credits have run out.
     */
    public static function ranOutMessage(?Carbon $resetsAt): string
    {
        return $resetsAt
            ? __("This organization's AI credits have run out. More arrive on :date, or connect your own AI in Settings → AI to keep building.", ['date' => $resetsAt->format('F j')])
            : __("This organization's AI credits have run out. Connect your own AI in Settings → AI to keep building.");
    }

    /**
     * Charge the key's new spending (call with the organization's lock held). Returns what the key has spent in
     * all, or null when the organization has no key yet.
     */
    protected function chargeSpent(Organization $organization): ?float
    {
        if ($organization->ai_credits_key_hash === null) {
            return null;
        }

        $spent = $this->keys->usage($organization->ai_credits_key_hash);
        $new = $spent - $organization->ai_credits_charged;

        if ($new > 0.000001) {
            $this->autumn->track($organization, round($new * 100, 4));
            $organization->forceFill(['ai_credits_charged' => $spent])->save();
        }

        return $spent;
    }
}
