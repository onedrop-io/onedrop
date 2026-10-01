<?php

namespace App\Actions;

use App\Enums\AgentHarness;
use App\Models\AgentUsage;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The tokens and estimated cost of agent runs over a range: a user's own (USAGE-001), an admin's look at them (USR-002),
 * and an organization's, for its owners and admins (ORG-005).
 */
class UsageReport
{
    /**
     * How far back each range looks, in hours.
     */
    public const RANGES = ['24h' => 24, '7d' => 24 * 7, '30d' => 24 * 30, '90d' => 24 * 90];

    /**
     * Sums every breakdown needs, in SQL both SQLite and Postgres run.
     */
    protected const SUMS = 'SUM(cost) as cost, SUM(input_tokens) as input, SUM(output_tokens) as output, '
        .'SUM(cache_read_tokens) as cache_read, SUM(cache_write_tokens) as cache_write, COUNT(DISTINCT session_id) as sessions';

    /**
     * Usage for the past 24 hours, 7, 30 or 90 days: totals, each agent's share, a chart over time (hourly for a day,
     * daily otherwise) and a breakdown by model and by project.
     *
     * @return array<string, mixed>
     */
    public function for(User $user, string $range): array
    {
        return $this->report(fn (): HasMany => $user->agentUsages(), $range);
    }

    /**
     * The same for every run in the organization's projects (deleted ones too), with a breakdown by person.
     *
     * @return array<string, mixed>
     */
    public function forOrganization(Organization $organization, string $range): array
    {
        $report = $this->report(fn (): Builder => AgentUsage::query()->where('organization_id', $organization->id), $range);
        $usages = AgentUsage::query()->where('organization_id', $organization->id)->where('created_at', '>=', $report['since']);

        $personRows = $usages->selectRaw('user_id, '.self::SUMS)->groupBy('user_id')->toBase()->get();
        $names = User::query()->whereKey($personRows->pluck('user_id'))->pluck('name', 'id');

        return [...$report, 'people' => $personRows
            ->map(fn (object $row) => ['id' => $row->user_id, 'name' => $names[$row->user_id] ?? 'Deleted account', ...$this->sums($row)])
            ->sortByDesc('cost')->values()];
    }

    /**
     * @param  Closure(): (HasMany<AgentUsage, User>|Builder<AgentUsage>)  $query
     * @return array<string, mixed>
     */
    protected function report(Closure $query, string $range): array
    {
        $hourly = $range === '24h';
        $until = now();
        $since = $hourly ? $until->subHours(23)->startOfHour() : $until->subDays(self::RANGES[$range] / 24 - 1)->startOfDay();
        $usages = fn (): HasMany|Builder => $query()->where('created_at', '>=', $since);

        $totals = $this->sums($usages()->selectRaw(self::SUMS)->toBase()->first());

        $agents = $usages()->selectRaw('harness, '.self::SUMS)->groupBy('harness')->toBase()->get()
            ->map(fn (object $row) => ['harness' => $row->harness, 'label' => AgentHarness::from($row->harness)->label(), ...$this->sums($row)])
            ->sortByDesc('cost')->values();

        $models = $usages()->selectRaw('harness, provider, model, '.self::SUMS)->groupBy('harness', 'provider', 'model')->toBase()->get()
            ->map(fn (object $row) => ['harness' => $row->harness, 'provider' => $row->provider, 'name' => $row->model, ...$this->sums($row)])
            ->sortByDesc('cost')->values();

        $projectRows = $usages()->selectRaw('project_id, '.self::SUMS)->groupBy('project_id')->toBase()->get();
        $names = Project::query()->whereKey($projectRows->pluck('project_id')->filter())->pluck('name', 'id');
        $projects = $projectRows
            ->map(fn (object $row) => ['id' => $row->project_id, 'name' => $names[$row->project_id] ?? 'Deleted project', ...$this->sums($row)])
            ->sortByDesc('cost')->values();

        return [
            'range' => $range,
            'since' => $since->toIso8601String(),
            'until' => $until->toIso8601String(),
            'bucket' => $hourly ? 'hour' : 'day',
            'totals' => $totals,
            'agents' => $agents,
            'models' => $models,
            'projects' => $projects,
            'series' => $this->series($usages(), $since, $until, $hourly),
        ];
    }

    /**
     * Totals over every run the user's AI connections paid for, and when the last one was.
     *
     * @return array{cost: float, input: int, output: int, cache_read: int, cache_write: int, tokens: int, sessions: int, runs: int, last_run_at: string|null}
     */
    public function lifetime(User $user): array
    {
        $row = $user->agentUsages()->selectRaw(self::SUMS.', COUNT(*) as runs, MAX(created_at) as last_run_at')->toBase()->first();

        return [
            ...$this->sums($row),
            'runs' => (int) ($row->runs ?? 0),
            'last_run_at' => isset($row->last_run_at) ? Carbon::parse($row->last_run_at)->toIso8601String() : null,
        ];
    }

    /**
     * Cost and tokens per agent in each hour or day of the range, empty ones included so the chart is continuous.
     *
     * @param  HasMany<AgentUsage, *>|Builder<AgentUsage>  $usages
     * @return list<array{t: int, cost: array<string, float>, tokens: array<string, int>}>
     */
    protected function series(HasMany|Builder $usages, CarbonInterface $since, CarbonInterface $until, bool $hourly): array
    {
        $buckets = [];

        for ($time = $since; $time <= $until; $time = $hourly ? $time->addHour() : $time->addDay()) {
            $buckets[$time->getTimestamp()] = ['t' => $time->getTimestamp(), 'cost' => [], 'tokens' => []];
        }

        $rows = $usages->toBase()->select(['created_at', 'harness', 'cost', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens'])->cursor();

        foreach ($rows as $row) {
            $time = Carbon::parse($row->created_at);
            $key = ($hourly ? $time->startOfHour() : $time->startOfDay())->getTimestamp();
            $harness = (string) $row->harness;

            if (! isset($buckets[$key])) {
                continue;
            }

            $buckets[$key]['cost'][$harness] = ($buckets[$key]['cost'][$harness] ?? 0) + (float) $row->cost;
            $buckets[$key]['tokens'][$harness] = ($buckets[$key]['tokens'][$harness] ?? 0)
                + (int) $row->input_tokens + (int) $row->output_tokens + (int) $row->cache_read_tokens + (int) $row->cache_write_tokens;
        }

        return array_values($buckets);
    }

    /**
     * A row of sums as numbers (drivers return strings, and null when nothing matched).
     *
     * @return array{cost: float, input: int, output: int, cache_read: int, cache_write: int, tokens: int, sessions: int}
     */
    protected function sums(?object $row): array
    {
        $input = (int) ($row->input ?? 0);
        $output = (int) ($row->output ?? 0);
        $cacheRead = (int) ($row->cache_read ?? 0);
        $cacheWrite = (int) ($row->cache_write ?? 0);

        return [
            'cost' => round((float) ($row->cost ?? 0), 6),
            'input' => $input,
            'output' => $output,
            'cache_read' => $cacheRead,
            'cache_write' => $cacheWrite,
            'tokens' => $input + $output + $cacheRead + $cacheWrite,
            'sessions' => (int) ($row->sessions ?? 0),
        ];
    }
}
