<?php

namespace App\Sandbox\Agents;

use App\Models\Organization;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Autumn, which keeps each organization's AI credits (CREDIT-001). The catalog (features and plans) is
 * `autumn/autumn.config.ts`, pushed with `npm run atmn push`; this only reads balances and records usage.
 * Credits are cents of AI usage at provider rates: the `ai_usage` feature draws from the `ai_credits` balance.
 */
class Autumn
{
    public const FEATURE = 'ai_usage';

    /**
     * Whether a key is set.
     */
    public function configured(): bool
    {
        return filled(config('services.autumn.key'));
    }

    /**
     * The organization's id at Autumn.
     */
    public static function customerId(Organization $organization): string
    {
        return "org_{$organization->id}";
    }

    /**
     * Make sure Autumn knows the organization; its Free plan (and its credits) attach when it's first made.
     *
     * @throws ConnectionException|RequestException
     */
    public function ensureCustomer(Organization $organization): void
    {
        Cache::rememberForever("autumn-customer:{$organization->id}", function () use ($organization): bool {
            $this->request()->post('customers', [
                'id' => self::customerId($organization),
                'name' => $organization->name,
            ])->throw();

            return true;
        });
    }

    /**
     * What's left, in cents, in all and of the monthly and one-off (welcome) grants, and when the monthly credits
     * refill. Null when Autumn can't be reached or is down (fail open, so an outage doesn't stop building); a request
     * Autumn refuses still throws.
     *
     * @return array{remaining: float, monthly: array{left: float, granted: float}, welcome: array{left: float, granted: float}, resets_at: Carbon|null}|null
     *
     * @throws RequestException
     */
    public function balance(Organization $organization): ?array
    {
        try {
            $this->ensureCustomer($organization);
            $balance = $this->request()->post('check', [
                'customer_id' => self::customerId($organization),
                'feature_id' => self::FEATURE,
            ])->throw()->json('balance');
        } catch (ConnectionException) {
            return null;
        } catch (RequestException $e) {
            if ($e->response->serverError()) {
                return null;
            }

            throw $e;
        }

        $resetsAt = $balance['next_reset_at'] ?? null;
        $breakdown = is_array($balance['breakdown'] ?? null) ? $balance['breakdown'] : [];
        $grants = collect($breakdown)->filter(fn (mixed $grant) => is_array($grant));
        $part = fn (bool $monthly): array => [
            'left' => (float) $grants->filter(fn (array $grant) => (($grant['reset']['interval'] ?? null) === 'one_off') !== $monthly)->sum('remaining'),
            'granted' => (float) $grants->filter(fn (array $grant) => (($grant['reset']['interval'] ?? null) === 'one_off') !== $monthly)->sum('included_grant'),
        ];

        return [
            'remaining' => (float) ($balance['remaining'] ?? 0),
            'monthly' => $part(true),
            'welcome' => $part(false),
            'resets_at' => is_numeric($resetsAt) ? Carbon::createFromTimestampMs((int) $resetsAt) : null,
        ];
    }

    /**
     * Take AI usage off the organization's credits.
     *
     * @throws ConnectionException|RequestException
     */
    public function track(Organization $organization, float $cents): void
    {
        $this->ensureCustomer($organization);
        $this->request()->post('track', [
            'customer_id' => self::customerId($organization),
            'feature_id' => self::FEATURE,
            'value' => $cents,
        ])->throw();
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl((string) config('services.autumn.url'))
            ->withToken((string) config('services.autumn.key'))
            ->acceptJson()
            ->asJson()
            ->timeout(10);
    }
}
