<?php

namespace App\Http\Controllers;

use App\Enums\AgentHarness;
use App\Models\AgentUsage;
use App\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tokens and estimated cost of the user's agent runs (USAGE-001).
 */
class UsageController extends Controller
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
     * Show usage for the past 24 hours, 7, 30 (the default) or 90 days: totals, each agent's share,
     * a chart over time (hourly for a day, daily otherwise) and a breakdown by model and by project.
     */
    public function index(Request $request): Response
    {
        $range = $request->validate(['range' => ['nullable', Rule::in(array_keys(self::RANGES))]])['range'] ?? '30d';
        $hourly = $range === '24h';
        $until = now();
        $since = $hourly ? $until->subHours(23)->startOfHour() : $until->subDays(self::RANGES[$range] / 24 - 1)->startOfDay();
        $usages = fn (): HasMany => $request->user()->agentUsages()->where('created_at', '>=', $since);

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

        return Inertia::render('usage/index', [
            'range' => $range,
            'since' => $since->toIso8601String(),
            'until' => $until->toIso8601String(),
            'bucket' => $hourly ? 'hour' : 'day',
            'totals' => $totals,
            'agents' => $agents,
            'models' => $models,
            'projects' => $projects,
            'series' => $this->series($usages(), $since, $until, $hourly),
        ]);
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
            $buckets[$time->timestamp] = ['t' => $time->timestamp, 'cost' => [], 'tokens' => []];
        }

        $rows = $usages->toBase()->select(['created_at', 'harness', 'cost', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens'])->cursor();

        foreach ($rows as $row) {
            $time = Carbon::parse($row->created_at);
            $key = ($hourly ? $time->startOfHour() : $time->startOfDay())->timestamp;

            if (! isset($buckets[$key])) {
                continue;
            }

            $buckets[$key]['cost'][$row->harness] = ($buckets[$key]['cost'][$row->harness] ?? 0) + (float) $row->cost;
            $buckets[$key]['tokens'][$row->harness] = ($buckets[$key]['tokens'][$row->harness] ?? 0)
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
