import { Deferred, Head, Link, router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useState } from 'react';
import type { AiCreditsSummary } from '@/components/ai-credits-balance';
import { formatCredits, formatRefill } from '@/components/ai-credits-balance';
import { ProviderIcon } from '@/components/agent-model-picker';
import AreaLinesChart from '@/components/charts/area-lines-chart';
import {
    formatCost,
    formatTokens,
    HARNESS_COLORS,
    HARNESS_LABELS,
} from '@/lib/usage';
import { cn } from '@/lib/utils';
import { index } from '@/routes/usage';
import type { AgentHarness, AgentProvider } from '@/types/agents';

type Range = '24h' | '7d' | '30d' | '90d';
type Metric = 'cost' | 'tokens';
type Breakdown = 'model' | 'project' | 'person' | 'day';

/** What paid for a run (USAGE-001); null for old runs whose provider is gone. */
type PaidBy = 'credits' | 'plan' | 'api_key' | 'own_server' | null;

const PAID_BY_LABELS: Record<Exclude<PaidBy, null>, string> = {
    credits: 'AI credits',
    plan: 'Your plan',
    api_key: 'API key',
    own_server: 'Your server',
};

type Sums = {
    /** Money spent: AI credits and API keys. */
    cost: number;
    /** What runs on a Claude or ChatGPT plan would have cost at API prices; not spent. */
    included: number;
    input: number;
    output: number;
    cache_read: number;
    cache_write: number;
    tokens: number;
    sessions: number;
};

type Props = {
    range: Range;
    since: string;
    until: string;
    bucket: 'hour' | 'day';
    totals: Sums;
    agents: (Sums & { harness: AgentHarness; label: string })[];
    models: (Sums & {
        harness: AgentHarness;
        provider: AgentProvider | null;
        name: string;
        paid_by: PaidBy;
    })[];
    projects: (Sums & { id: number | null; name: string })[];
    /** An organization's usage (ORG-005): whose runs they were. */
    people?: (Sums & { id: number; name: string })[];
    /** Whose usage it is, when it isn't the user's own. */
    title?: string;
    /** Whether the install offers AI credits (CREDIT-001), and whose they are. */
    creditsOn?: boolean;
    creditsFor?: string;
    /** Deferred; null when they can't be checked right now. */
    credits?: AiCreditsSummary | null;
    series: {
        t: number;
        cost: Partial<Record<AgentHarness, number>>;
        tokens: Partial<Record<AgentHarness, number>>;
    }[];
};

const RANGES: { value: Range; label: string }[] = [
    { value: '24h', label: 'Past 24h' },
    { value: '7d', label: '7 days' },
    { value: '30d', label: '30 days' },
    { value: '90d', label: '90 days' },
];

function formatShare(part: number, whole: number): string {
    return `${whole > 0 ? ((part / whole) * 100).toFixed(1) : '0.0'}%`;
}

function formatDay(iso: string): string {
    return new Date(iso).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });
}

function Segmented<T extends string>({
    label,
    options,
    value,
    onChange,
    href,
}: {
    label: string;
    options: { value: T; label: string }[];
    value: T;
    onChange?: (value: T) => void;
    /** Options that are pages (the range) link there instead. */
    href?: (value: T) => string;
}) {
    const className = (active: boolean) =>
        cn(
            'rounded-md px-3 py-1 text-sm transition-colors',
            active
                ? 'bg-background font-medium text-foreground shadow-xs'
                : 'text-muted-foreground hover:text-foreground',
        );

    return (
        <div
            role="group"
            aria-label={label}
            className="flex rounded-lg bg-muted p-0.5"
        >
            {options.map((option) =>
                href ? (
                    <Link
                        key={option.value}
                        href={href(option.value)}
                        preserveScroll
                        prefetch
                        aria-current={option.value === value}
                        className={className(option.value === value)}
                    >
                        {option.label}
                    </Link>
                ) : (
                    <button
                        key={option.value}
                        type="button"
                        aria-pressed={option.value === value}
                        onClick={() => onChange?.(option.value)}
                        className={className(option.value === value)}
                    >
                        {option.label}
                    </button>
                ),
            )}
        </div>
    );
}

/** The organization's AI credits (CREDIT-001): what's left in all, of this month's grant and of the welcome one. */
function CreditsCard({
    credits,
    owner,
}: {
    credits: AiCreditsSummary | null;
    owner?: string;
}) {
    if (credits === null) {
        return (
            <section
                className="rounded-xl border p-5 text-sm text-muted-foreground"
                data-test="ai-credits-card"
            >
                AI credits can't be checked right now. Try again in a minute.
            </section>
        );
    }

    const refill = formatRefill(credits.resets_at);
    const parts = [
        {
            label: "This month's credits",
            ...credits.monthly,
            note: refill
                ? `Next ${formatCredits(credits.monthly.granted)} on ${refill}`
                : null,
        },
        {
            label: 'Welcome credits',
            ...credits.welcome,
            note: 'Never expire, used after the monthly ones',
        },
    ].filter((part) => part.granted > 0);

    return (
        <section
            className="flex flex-wrap items-start gap-x-10 gap-y-5 rounded-xl border border-violet-500/30 bg-violet-500/5 p-5"
            data-test="ai-credits-card"
        >
            <div className="min-w-48">
                <div className="text-sm text-muted-foreground">AI credits</div>
                <div className="mt-1 text-5xl font-semibold tracking-tight tabular-nums">
                    {formatCredits(credits.left)}
                    <span className="ml-2 text-lg font-normal tracking-normal text-muted-foreground">
                        left
                    </span>
                </div>
                <p className="mt-2 max-w-xs text-xs text-muted-foreground">
                    Free credits for building without your own AI
                    {owner ? `, shared by everyone in ${owner}` : ''}. Your own
                    AI (Settings → AI) never uses them.
                </p>
            </div>
            {parts.map((part) => (
                <div key={part.label} className="min-w-44 flex-1">
                    <div className="flex items-baseline justify-between gap-3 text-sm">
                        <span className="text-muted-foreground">
                            {part.label}
                        </span>
                        <span className="tabular-nums">
                            {formatCredits(part.left)} of{' '}
                            {formatCredits(part.granted)}
                        </span>
                    </div>
                    <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
                        <div
                            className="h-full rounded-full bg-violet-500"
                            style={{
                                width: `${Math.min(100, (part.left / part.granted) * 100)}%`,
                            }}
                        />
                    </div>
                    {part.note && (
                        <div className="mt-1.5 text-xs text-muted-foreground">
                            {part.note}
                        </div>
                    )}
                </div>
            ))}
        </section>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <div className="text-sm text-muted-foreground">{label}</div>
            <div className="mt-1 text-xl font-medium tabular-nums">{value}</div>
        </div>
    );
}

export default function Usage({
    range,
    since,
    until,
    bucket,
    totals,
    agents,
    models,
    projects,
    people,
    title,
    creditsOn,
    creditsFor,
    credits,
    series,
}: Props) {
    const [metric, setMetric] = useState<Metric>('cost');
    const [breakdown, setBreakdown] = useState<Breakdown>('model');
    const [refreshing, setRefreshing] = useState(false);
    const format = metric === 'cost' ? formatCost : formatTokens;
    const whole = totals[metric];
    const harnesses = (
        agents.length > 0
            ? agents.map((agent) => agent.harness)
            : (['claude_code', 'opencode', 'codex'] as AgentHarness[])
    ).map((harness) => ({
        key: harness,
        label: HARNESS_LABELS[harness],
        color: HARNESS_COLORS[harness],
    }));
    const input = totals.input + totals.cache_read + totals.cache_write;

    const rows: {
        key: string;
        name: string;
        icon?: React.ReactNode;
        paidBy?: PaidBy;
        cost: number;
        included?: number;
        tokens: number;
    }[] =
        breakdown === 'model'
            ? models.map((model) => ({
                  key: `${model.harness}:${model.provider}:${model.name}:${model.paid_by}`,
                  name: model.name,
                  icon: model.provider ? (
                      <ProviderIcon provider={model.provider} />
                  ) : null,
                  paidBy: model.paid_by,
                  included: model.included,
                  cost: model.cost,
                  tokens: model.tokens,
              }))
            : breakdown === 'project'
              ? projects.map((project) => ({
                    key: String(project.id),
                    name: project.name,
                    cost: project.cost,
                    tokens: project.tokens,
                }))
              : breakdown === 'person'
                ? (people ?? []).map((person) => ({
                      key: String(person.id),
                      name: person.name,
                      cost: person.cost,
                      tokens: person.tokens,
                  }))
                : series
                      .map((point) => ({
                          key: String(point.t),
                          name: new Date(point.t * 1000).toLocaleString(
                              undefined,
                              bucket === 'hour'
                                  ? { hour: 'numeric', minute: '2-digit' }
                                  : {
                                        weekday: 'short',
                                        month: 'short',
                                        day: 'numeric',
                                    },
                          ),
                          cost: Object.values(point.cost).reduce(
                              (sum, value) => sum + (value ?? 0),
                              0,
                          ),
                          tokens: Object.values(point.tokens).reduce(
                              (sum, value) => sum + (value ?? 0),
                              0,
                          ),
                      }))
                      .filter((row) => row.tokens > 0 || row.cost > 0)
                      .reverse();

    if (breakdown !== 'day') {
        rows.sort((a, b) => b[metric] - a[metric]);
    }

    const refresh = () =>
        router.reload({
            onStart: () => setRefreshing(true),
            onFinish: () => setRefreshing(false),
        });

    return (
        <>
            <Head title={title ? `${title} usage` : 'Usage'} />

            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-10 p-4 md:p-6">
                <header className="flex flex-wrap items-center gap-3">
                    <h1 className="text-lg font-medium">
                        {title ? `${title} usage` : 'Usage'}
                        <span className="px-2 text-muted-foreground">/</span>
                        <span className="font-normal text-muted-foreground">
                            {formatDay(since)} to {formatDay(until)}
                        </span>
                    </h1>
                    <div className="ml-auto flex flex-wrap items-center gap-2">
                        <Segmented
                            label="Show"
                            options={[
                                { value: 'cost', label: 'Cost' },
                                { value: 'tokens', label: 'Tokens' },
                            ]}
                            value={metric}
                            onChange={setMetric}
                        />
                        <Segmented
                            label="Period"
                            options={RANGES}
                            value={range}
                            href={(value) =>
                                index.url({ query: { range: value } })
                            }
                        />
                        <button
                            type="button"
                            onClick={refresh}
                            aria-label="Refresh"
                            className="rounded-md p-2 text-muted-foreground hover:bg-muted hover:text-foreground"
                        >
                            <RefreshCw
                                className={cn(
                                    'size-4',
                                    refreshing && 'animate-spin',
                                )}
                            />
                        </button>
                    </div>
                </header>

                {creditsOn && (
                    <Deferred
                        data="credits"
                        fallback={
                            <div className="h-28 animate-pulse rounded-xl border bg-muted/40" />
                        }
                    >
                        <CreditsCard
                            credits={credits ?? null}
                            owner={creditsFor}
                        />
                    </Deferred>
                )}

                <section className="grid gap-10 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                    <div>
                        <div
                            className="text-3xl font-medium tabular-nums"
                            data-test="usage-total"
                        >
                            {metric === 'cost'
                                ? formatCost(totals.cost)
                                : formatTokens(totals.tokens)}
                        </div>
                        <div className="mt-1 text-sm text-muted-foreground">
                            {metric === 'cost' ? 'spent' : 'processed tokens'} ·{' '}
                            {totals.sessions.toLocaleString()}{' '}
                            {totals.sessions === 1 ? 'session' : 'sessions'}
                        </div>
                        {metric === 'cost' && totals.included > 0 && (
                            <p
                                className="mt-3 max-w-sm text-sm text-muted-foreground"
                                data-test="usage-included"
                            >
                                Plus use included in your Claude or ChatGPT
                                plan, which costs nothing per run (
                                {formatCost(totals.included)} at API prices).
                            </p>
                        )}

                        <ul className="mt-8 space-y-5">
                            {agents.map((agent) => (
                                <li
                                    key={agent.harness}
                                    data-test={`usage-agent-${agent.harness}`}
                                >
                                    <div className="flex items-baseline gap-2">
                                        <span
                                            className="size-2 shrink-0 self-center rounded-full"
                                            style={{
                                                background:
                                                    HARNESS_COLORS[
                                                        agent.harness
                                                    ],
                                            }}
                                        />
                                        <span className="font-medium">
                                            {agent.label}
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            {agent.sessions}{' '}
                                            {agent.sessions === 1
                                                ? 'session'
                                                : 'sessions'}
                                        </span>
                                        <span className="ml-auto font-medium tabular-nums">
                                            {format(agent[metric])}
                                        </span>
                                    </div>
                                    <div className="mt-0.5 pl-4 text-sm text-muted-foreground">
                                        {formatShare(agent[metric], whole)} of{' '}
                                        {metric === 'cost' ? 'cost' : 'tokens'}{' '}
                                        ·{' '}
                                        {metric === 'cost'
                                            ? `${formatTokens(agent.tokens)} tokens`
                                            : formatCost(agent.cost)}
                                        {metric === 'cost' &&
                                            agent.included > 0 &&
                                            ` · ${formatCost(agent.included)} at API prices included in your plan`}
                                    </div>
                                </li>
                            ))}
                        </ul>

                        {agents.length === 0 && (
                            <p className="mt-8 text-sm text-muted-foreground">
                                No agent runs in this period yet. Usage shows up
                                here after each run.
                            </p>
                        )}
                    </div>

                    <AreaLinesChart
                        title={
                            metric === 'cost'
                                ? bucket === 'hour'
                                    ? 'Hourly cost'
                                    : 'Daily cost'
                                : bucket === 'hour'
                                  ? 'Hourly tokens'
                                  : 'Daily tokens'
                        }
                        series={harnesses}
                        points={series.map((point) => ({
                            t: point.t,
                            values: point[metric],
                        }))}
                        bucketSeconds={bucket === 'hour' ? 3600 : 86400}
                        format={format}
                        testId="usage-chart"
                    />
                </section>

                <section>
                    <h2 className="mb-4 font-medium">Totals</h2>
                    <div className="grid grid-cols-2 gap-6 sm:grid-cols-3 lg:grid-cols-5">
                        <Stat
                            label="Processed tokens"
                            value={formatTokens(totals.tokens)}
                        />
                        <Stat
                            label="Cached input"
                            value={formatTokens(totals.cache_read)}
                        />
                        <Stat
                            label="Uncached input"
                            value={formatTokens(
                                totals.input + totals.cache_write,
                            )}
                        />
                        <Stat
                            label="Output"
                            value={formatTokens(totals.output)}
                        />
                        <Stat
                            label="From cache"
                            value={formatShare(totals.cache_read, input)}
                        />
                    </div>
                </section>

                <section>
                    <div className="mb-2 flex items-center">
                        <h2 className="font-medium">Breakdown</h2>
                        <div className="ml-auto">
                            <Segmented
                                label="Break down by"
                                options={[
                                    { value: 'model', label: 'Model' },
                                    { value: 'project', label: 'Project' },
                                    ...(people
                                        ? [
                                              {
                                                  value: 'person' as const,
                                                  label: 'Person',
                                              },
                                          ]
                                        : []),
                                    {
                                        value: 'day',
                                        label:
                                            bucket === 'hour' ? 'Hour' : 'Day',
                                    },
                                ]}
                                value={breakdown}
                                onChange={setBreakdown}
                            />
                        </div>
                    </div>
                    <table
                        className="w-full text-sm"
                        data-test="usage-breakdown"
                    >
                        <thead className="text-muted-foreground">
                            <tr className="border-b">
                                <th className="py-3 text-left font-normal">
                                    {breakdown === 'model'
                                        ? 'Model'
                                        : breakdown === 'project'
                                          ? 'Project'
                                          : breakdown === 'person'
                                            ? 'Person'
                                            : bucket === 'hour'
                                              ? 'Hour'
                                              : 'Day'}
                                </th>
                                <th className="py-3 text-right font-normal">
                                    Cost
                                </th>
                                <th className="py-3 text-right font-normal">
                                    Share
                                </th>
                                <th className="py-3 text-right font-normal">
                                    Tokens
                                </th>
                            </tr>
                        </thead>
                        <tbody className="tabular-nums">
                            {rows.map((row) => (
                                <tr key={row.key} className="border-b">
                                    <td className="py-3">
                                        <span className="flex items-center gap-2">
                                            {row.icon}
                                            {row.name}
                                            {row.paidBy && (
                                                <span className="rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
                                                    {PAID_BY_LABELS[row.paidBy]}
                                                </span>
                                            )}
                                        </span>
                                    </td>
                                    <td className="py-3 text-right">
                                        {row.paidBy === 'plan' ? (
                                            <span title="What it would have cost on an API key">
                                                Included
                                                <span className="block text-xs text-muted-foreground">
                                                    {formatCost(
                                                        row.included ?? 0,
                                                    )}{' '}
                                                    at API prices
                                                </span>
                                            </span>
                                        ) : row.paidBy === 'own_server' ? (
                                            'Free'
                                        ) : (
                                            formatCost(row.cost)
                                        )}
                                    </td>
                                    <td className="py-3 text-right text-muted-foreground">
                                        {formatShare(row[metric], whole)}
                                    </td>
                                    <td className="py-3 text-right text-muted-foreground">
                                        {formatTokens(row.tokens)}
                                    </td>
                                </tr>
                            ))}
                            {rows.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="py-6 text-center text-muted-foreground"
                                    >
                                        Nothing yet.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </section>
            </div>
        </>
    );
}
