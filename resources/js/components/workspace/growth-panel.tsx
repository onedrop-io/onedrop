import {
    ArrowDownRight,
    ArrowUpRight,
    ChartSpline,
    ChevronDown,
    CircleAlert,
    CircleCheck,
    CircleX,
    Minus,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import ProjectGrowthController from '@/actions/App/Http/Controllers/ProjectGrowthController';
import TimeLineChart from '@/components/charts/time-line-chart';
import WorldMap from '@/components/charts/world-map';
import type { MapCity } from '@/components/charts/world-map';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { askAgent } from '@/lib/ask-agent';
import { jsonRequest } from '@/lib/json-request';
import { cn } from '@/lib/utils';

type Range = '24h' | '7d' | '30d';

type Row = { label: string; count: number };

type SeoCheck = {
    title: string;
    status: 'pass' | 'warn' | 'fail';
    detail: string | null;
};

type CustomEvent = {
    name: string;
    description: string | null;
    count: number;
    visitors: number;
    previous_count: number;
    over_time: { t: number; value: number }[];
    props: { key: string; values: Row[] }[];
};

type Growth = {
    analytics: {
        bucket_seconds: number;
        since: number;
        visitors: number;
        previous_visitors: number;
        page_views: number;
        visitors_over_time: { t: number; value: number }[];
        pages: Row[];
        referrers: Row[];
        countries: Row[];
        browsers: Row[];
        devices: Row[];
        locations: { countries: Row[]; cities: MapCity[] };
    };
    events: { set_up: boolean; events: CustomEvent[] };
    signed_in_users: number | null;
    seo: {
        score: number;
        scanned_at: string | null;
        summary: string | null;
        checks: SeoCheck[];
    } | null;
};

const RANGES: { value: Range; label: string; previous: string }[] = [
    { value: '24h', label: 'Past day', previous: 'previous day' },
    { value: '7d', label: 'Past week', previous: 'previous 7 days' },
    { value: '30d', label: 'Past 30 days', previous: 'previous 30 days' },
];

const countryNames =
    typeof Intl.DisplayNames === 'function'
        ? new Intl.DisplayNames(undefined, { type: 'region' })
        : null;

function countryName(code: string): string {
    try {
        return countryNames?.of(code) ?? code;
    } catch {
        return code;
    }
}

/**
 * Growth: an agent-run SEO check, and who visits the app (from the sandbox's own request log).
 */
export default function GrowthPanel({
    projectId,
    running,
    working,
}: {
    projectId: number;
    running: boolean;
    /** The agent is running a task. */
    working: boolean;
}) {
    const [range, setRange] = useState<Range>('7d');
    const [traffic, setTraffic] = useState<'all' | 'published'>('all');
    const [data, setData] = useState<Growth | null>(null);
    const [error, setError] = useState<string | null>(null);

    const load = useCallback(
        () =>
            jsonRequest<Growth>(
                ProjectGrowthController.show.url(projectId, {
                    query: { range, traffic },
                }),
            ),
        [projectId, range, traffic],
    );

    useEffect(() => {
        if (!running) {
            return;
        }

        let cancelled = false;
        const refresh = () =>
            load()
                .then((body) => {
                    if (!cancelled) {
                        setData(body);
                        setError(null);
                    }
                })
                .catch((e: Error) => !cancelled && setError(e.message));

        void refresh();
        const timer = window.setInterval(() => void refresh(), 30_000);

        return () => {
            cancelled = true;
            window.clearInterval(timer);
        };
        // Refresh when the agent finishes, e.g. after an SEO scan.
    }, [load, running, working]);

    if (!running) {
        return <Empty>Growth starts when the sandbox is running.</Empty>;
    }

    if (error) {
        return <Empty tone="error">{error}</Empty>;
    }

    if (!data) {
        return <Empty>Loading…</Empty>;
    }

    const { analytics } = data;
    const rangeInfo = RANGES.find((r) => r.value === range)!;

    return (
        <div className="@container space-y-6" data-test="growth-panel">
            <SeoCard projectId={projectId} seo={data.seo} />

            <section className="space-y-5 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                <header className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h3 className="font-medium">Analytics</h3>
                        <p className="text-xs text-muted-foreground">
                            Visitors are counted by IP address.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <div
                            role="radiogroup"
                            aria-label="Traffic"
                            className="flex rounded-md border border-input p-0.5 text-xs"
                        >
                            {(
                                [
                                    ['all', 'All traffic'],
                                    ['published', 'Published only'],
                                ] as const
                            ).map(([value, label]) => (
                                <button
                                    key={value}
                                    type="button"
                                    role="radio"
                                    aria-checked={traffic === value}
                                    onClick={() => setTraffic(value)}
                                    className={cn(
                                        'rounded px-2 py-1',
                                        traffic === value
                                            ? 'bg-muted font-medium'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                        <select
                            value={range}
                            onChange={(event) =>
                                setRange(event.target.value as Range)
                            }
                            className="h-8 rounded-md border border-input bg-transparent px-2 text-sm"
                            aria-label="Analytics range"
                        >
                            {RANGES.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                </header>

                <dl
                    className="grid gap-3 @xl:grid-cols-3"
                    data-test="growth-stats"
                >
                    <Stat
                        label="Visitors"
                        testId="growth-visitors"
                        value={analytics.visitors.toLocaleString()}
                        note={
                            <Change
                                current={analytics.visitors}
                                previous={analytics.previous_visitors}
                                period={rangeInfo.previous}
                            />
                        }
                    />
                    <Stat
                        label="Page views"
                        value={analytics.page_views.toLocaleString()}
                    />
                    <Stat
                        label="Signed-in users"
                        value={
                            data.signed_in_users === null
                                ? '—'
                                : data.signed_in_users.toLocaleString()
                        }
                        note={
                            data.signed_in_users === null
                                ? 'Shows once your app has sign-in (Users & Auth).'
                                : "Your app's users who signed in during this period."
                        }
                    />
                </dl>

                {analytics.visitors === 0 ? (
                    <Empty>
                        No visitors in this period yet. Open the preview or the
                        published URL to see them here.
                    </Empty>
                ) : (
                    <>
                        <TimeLineChart
                            title={`Visitors over time (${analytics.bucket_seconds >= 86400 ? 'day' : 'hour'})`}
                            points={analytics.visitors_over_time}
                            bucketSeconds={analytics.bucket_seconds}
                            testId="chart-visitors"
                        />
                        {analytics.locations.countries.length === 0 ? (
                            <figure data-test="growth-map">
                                <figcaption className="mb-2 text-sm font-medium">
                                    Where visitors are
                                </figcaption>
                                <p className="rounded-lg border border-dashed p-4 text-center text-xs text-muted-foreground">
                                    No location data yet. Visitors are placed on
                                    the map when the app is reached through
                                    OneDrop's Cloudflare gateway or a CDN that
                                    adds location headers (Cloudflare,
                                    CloudFront, Vercel).
                                </p>
                            </figure>
                        ) : (
                            <WorldMap
                                title="Where visitors are"
                                countries={analytics.locations.countries}
                                cities={analytics.locations.cities}
                                countryName={countryName}
                                testId="growth-map"
                            />
                        )}
                        <div className="grid gap-6 @3xl:grid-cols-2 @6xl:grid-cols-3">
                            <TopList
                                title="Top pages"
                                unit="views"
                                rows={analytics.pages}
                                empty="No page views in this period."
                                testId="top-pages"
                            />
                            <TopList
                                title="Top referrers"
                                unit="visitors"
                                rows={analytics.referrers}
                                empty="No visitors came from other sites in this period."
                                testId="top-referrers"
                            />
                            <TopList
                                title="Top countries"
                                unit="visitors"
                                rows={analytics.countries.map((row) => ({
                                    ...row,
                                    label: countryName(row.label),
                                }))}
                                empty="No country data. Countries show when a CDN such as Cloudflare sits in front of the published app."
                                testId="top-countries"
                            />
                            <TopList
                                title="Top cities"
                                unit="visitors"
                                rows={analytics.locations.cities
                                    .slice(0, 10)
                                    .map((city) => ({
                                        label: `${city.city}, ${city.region && city.region !== city.city ? `${city.region}, ` : ''}${city.country}`,
                                        count: city.count,
                                    }))}
                                empty="No city data. Cities show when the app is reached through OneDrop's Cloudflare gateway or a CDN that adds them."
                                testId="top-cities"
                            />
                            <TopList
                                title="Top browsers"
                                unit="visitors"
                                rows={analytics.browsers}
                                empty="No browser data in this period."
                                testId="top-browsers"
                            />
                            <TopList
                                title="Top devices"
                                unit="visitors"
                                rows={analytics.devices}
                                empty="No device data in this period."
                                testId="top-devices"
                            />
                        </div>
                    </>
                )}
            </section>

            <EventsCard
                projectId={projectId}
                events={data.events}
                bucketSeconds={analytics.bucket_seconds}
                period={`${rangeInfo.label}${traffic === 'published' ? ', published only' : ''}`}
                previous={rangeInfo.previous}
            />
        </div>
    );
}

/** Asks the agent for something in the chat and says what happens next. */
function useAgentRequest(url: string) {
    const [sending, setSending] = useState(false);
    const [sent, setSent] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const send = (body: object, startedMessage: string) => {
        setSending(true);

        return askAgent(url, body)
            .then(({ queued }) => {
                setError(null);
                setSent(
                    queued
                        ? 'Asked the agent. It runs after the current task; follow along in the chat.'
                        : startedMessage,
                );

                return true;
            })
            .catch((e: Error) => {
                setError(e.message);

                return false;
            })
            .finally(() => setSending(false));
    };

    return { send, sending, sent, error };
}

/** Custom events the agent added to the app: how often each happened, over time, and its property values. */
function EventsCard({
    projectId,
    events,
    bucketSeconds,
    period,
    previous,
}: {
    projectId: number;
    events: Growth['events'];
    bucketSeconds: number;
    period: string;
    previous: string;
}) {
    const [selected, setSelected] = useState<string | null>(null);
    const [more, setMore] = useState('');
    const request = useAgentRequest(
        ProjectGrowthController.addEvents.url(projectId),
    );
    const event =
        events.events.find((e) => e.name === selected) ?? events.events[0];

    const setUp = (description?: string) =>
        request
            .send(
                { events: description || null },
                'The agent is adding events to your app. Follow along in the chat; they show up here as people use the app.',
            )
            .then((ok) => ok && setMore(''));

    const status = (request.sent || request.error) && (
        <p
            className={cn(
                'text-sm',
                request.error ? 'text-red-600' : 'text-muted-foreground',
            )}
            data-test="events-sent"
        >
            {request.error ?? request.sent}
        </p>
    );

    if (!events.set_up) {
        return (
            <section
                className="flex flex-col items-center gap-3 rounded-xl border border-sidebar-border/70 p-6 text-center dark:border-sidebar-border"
                data-test="events-card"
            >
                <ChartSpline
                    className="size-8"
                    style={{ color: 'var(--viz-1)' }}
                    aria-hidden
                />
                <h3 className="text-lg font-medium">
                    Understand how people use your app
                </h3>
                <p className="max-w-md text-sm text-muted-foreground">
                    The agent adds custom events for your app's key moments,
                    like signing up or creating something, and they show up here
                    as people use it. No personal data is collected.
                </p>
                <Button
                    onClick={() => void setUp()}
                    disabled={request.sending}
                    data-test="events-set-up"
                >
                    Set up with agent
                </Button>
                {status}
            </section>
        );
    }

    return (
        <section
            className="space-y-5 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
            data-test="events-card"
        >
            <header>
                <h3 className="font-medium">Custom events</h3>
                <p className="text-xs text-muted-foreground">
                    Key moments in your app. {period}.
                </p>
            </header>

            {events.events.length === 0 ? (
                <Empty>No events yet.</Empty>
            ) : (
                <div className="grid gap-6 @3xl:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                    <table className="w-full text-sm" data-test="events-table">
                        <thead className="text-left text-xs text-muted-foreground">
                            <tr>
                                <th className="py-1.5 font-normal">Event</th>
                                <th className="py-1.5 pl-3 text-right font-normal">
                                    Count
                                </th>
                                <th className="py-1.5 pl-3 text-right font-normal">
                                    Visitors
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {events.events.map((row) => (
                                <tr
                                    key={row.name}
                                    className={cn(
                                        'cursor-pointer align-top hover:bg-muted/50',
                                        row.name === event?.name && 'bg-muted',
                                    )}
                                    onClick={() => setSelected(row.name)}
                                >
                                    <td className="min-w-0 py-2 pr-3 pl-1">
                                        <button
                                            type="button"
                                            className="text-left font-mono text-xs font-medium"
                                            aria-pressed={
                                                row.name === event?.name
                                            }
                                            onClick={() =>
                                                setSelected(row.name)
                                            }
                                        >
                                            {row.name}
                                        </button>
                                        {row.description && (
                                            <p className="text-xs text-muted-foreground">
                                                {row.description}
                                            </p>
                                        )}
                                    </td>
                                    <td className="py-2 text-right tabular-nums">
                                        {row.count.toLocaleString()}
                                    </td>
                                    <td className="py-2 pr-1 text-right tabular-nums">
                                        {row.visitors.toLocaleString()}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    {event && (
                        <div
                            className="min-w-0 space-y-4"
                            data-test="event-detail"
                        >
                            <p className="text-xs text-muted-foreground">
                                <Change
                                    current={event.count}
                                    previous={event.previous_count}
                                    period={previous}
                                />
                            </p>
                            <TimeLineChart
                                title={`${event.name} over time (${bucketSeconds >= 86400 ? 'day' : 'hour'})`}
                                points={event.over_time}
                                bucketSeconds={bucketSeconds}
                                testId="chart-event"
                            />
                            {event.props.map((prop) => (
                                <TopList
                                    key={prop.key}
                                    title={prop.key}
                                    unit="times"
                                    rows={prop.values}
                                    empty=""
                                    testId={`event-prop-${prop.key}`}
                                />
                            ))}
                        </div>
                    )}
                </div>
            )}

            <form
                className="flex flex-wrap gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    void setUp(more.trim());
                }}
            >
                <Input
                    value={more}
                    onChange={(e) => setMore(e.target.value)}
                    placeholder="Track more, e.g. when someone shares a project"
                    aria-label="Events to add"
                    maxLength={1000}
                    className="min-w-60 flex-1"
                    data-test="events-more"
                />
                <Button
                    type="submit"
                    variant="outline"
                    disabled={request.sending || more.trim() === ''}
                    data-test="events-add"
                >
                    Add with agent
                </Button>
            </form>
            {status}
        </section>
    );
}

/** The SEO rating from the agent's last scan, with a button to run another. */
function SeoCard({
    projectId,
    seo,
}: {
    projectId: number;
    seo: Growth['seo'];
}) {
    const [open, setOpen] = useState(true);
    const { send, sending, sent, error } = useAgentRequest(
        ProjectGrowthController.scan.url(projectId),
    );

    const scan = (fix: boolean) =>
        void send(
            { fix },
            "The agent is checking your app. Follow along in the chat; the rating updates when it's done.",
        );

    return (
        <section
            className="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            data-test="seo-card"
        >
            <header className="flex flex-wrap items-center justify-between gap-2 p-4">
                <button
                    type="button"
                    onClick={() => setOpen(!open)}
                    aria-expanded={open}
                    className="flex items-center gap-2 font-medium"
                >
                    <ChevronDown
                        className={cn(
                            'size-4 text-muted-foreground transition-transform',
                            !open && '-rotate-90',
                        )}
                    />
                    SEO rating
                    {seo && (
                        <span
                            className="ml-1 rounded-md bg-muted px-1.5 py-0.5 text-sm tabular-nums"
                            data-test="seo-score"
                        >
                            {seo.score}/100
                        </span>
                    )}
                </button>
                <div className="flex">
                    <Button
                        size="sm"
                        variant="outline"
                        className="rounded-r-none"
                        onClick={() => scan(false)}
                        disabled={sending}
                        data-test="seo-scan"
                    >
                        Run scan with agent
                    </Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                size="sm"
                                variant="outline"
                                className="rounded-l-none border-l-0 px-2"
                                disabled={sending}
                                aria-label="More scan options"
                                data-test="seo-scan-more"
                            >
                                <ChevronDown />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                onSelect={() => scan(true)}
                                data-test="seo-scan-fix"
                            >
                                Scan and fix issues
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </header>

            {(sent || error) && (
                <p
                    className={cn(
                        'px-4 pb-3 text-sm',
                        error ? 'text-red-600' : 'text-muted-foreground',
                    )}
                    data-test="seo-scan-sent"
                >
                    {error ?? sent}
                </p>
            )}

            {open && (
                <div className="border-t border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    {seo ? (
                        <div className="space-y-3">
                            <p className="text-sm text-muted-foreground">
                                {seo.summary}
                                {seo.scanned_at && (
                                    <>
                                        {seo.summary && ' '}
                                        Scanned{' '}
                                        {new Date(
                                            seo.scanned_at,
                                        ).toLocaleString()}
                                        .
                                    </>
                                )}
                            </p>
                            <ul className="divide-y" data-test="seo-checks">
                                {seo.checks.map((check) => (
                                    <li
                                        key={check.title}
                                        className="flex gap-3 py-2 text-sm"
                                    >
                                        <CheckStatus status={check.status} />
                                        <div className="min-w-0">
                                            <p className="font-medium">
                                                {check.title}
                                            </p>
                                            {check.detail && (
                                                <p className="text-muted-foreground">
                                                    {check.detail}
                                                </p>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Not scanned yet. The agent checks what search
                            engines and link previews see: page titles,
                            descriptions, headings, sitemap, robots.txt, image
                            alt text and more.
                        </p>
                    )}
                </div>
            )}
        </section>
    );
}

const STATUS = {
    pass: { label: 'Passed', icon: CircleCheck, className: 'text-green-600' },
    warn: {
        label: 'Needs work',
        icon: CircleAlert,
        className: 'text-amber-600',
    },
    fail: { label: 'Failed', icon: CircleX, className: 'text-red-600' },
} as const;

function CheckStatus({ status }: { status: SeoCheck['status'] }) {
    const { label, icon: Icon, className } = STATUS[status];

    return (
        <span
            className={cn(
                'flex w-24 shrink-0 items-center gap-1.5 text-xs',
                className,
            )}
        >
            <Icon className="size-4" /> {label}
        </span>
    );
}

function Change({
    current,
    previous,
    period,
}: {
    current: number;
    previous: number;
    period: string;
}) {
    const difference = current - previous;
    const Icon =
        difference > 0 ? ArrowUpRight : difference < 0 ? ArrowDownRight : Minus;

    return (
        <span className="flex items-center gap-1" data-test="growth-change">
            <Icon className="size-3" />
            {difference > 0 ? '+' : ''}
            {difference.toLocaleString()} vs {period}
        </span>
    );
}

function Stat({
    label,
    value,
    note,
    testId,
}: {
    label: string;
    value: string;
    note?: React.ReactNode;
    testId?: string;
}) {
    return (
        <div className="rounded-lg bg-muted/50 p-3">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd
                className="text-xl font-semibold tabular-nums"
                data-test={testId}
            >
                {value}
            </dd>
            {note && (
                <dd className="mt-1 text-xs text-muted-foreground">{note}</dd>
            )}
        </div>
    );
}

/** A ranked list with a bar per row; the numbers are always visible, so it doubles as its own table. */
function TopList({
    title,
    unit,
    rows,
    empty,
    testId,
}: {
    title: string;
    unit: string;
    rows: Row[];
    empty: string;
    testId: string;
}) {
    const max = Math.max(1, ...rows.map((row) => row.count));

    return (
        <figure className="min-w-0" data-test={testId}>
            <figcaption className="mb-2 text-sm font-medium">
                {title}
            </figcaption>
            {rows.length === 0 ? (
                <p className="rounded-lg border border-dashed p-4 text-center text-xs text-muted-foreground">
                    {empty}
                </p>
            ) : (
                <ol className="divide-y text-sm">
                    {rows.map((row) => (
                        <li
                            key={row.label}
                            className="grid grid-cols-[minmax(0,1fr)_5rem_3rem] items-center gap-3 py-1.5"
                            title={`${row.label}: ${row.count.toLocaleString()} ${unit}`}
                        >
                            <span className="truncate">{row.label}</span>
                            <span
                                className="h-1.5 rounded-full bg-muted"
                                aria-hidden
                            >
                                <span
                                    className="block h-full rounded-full"
                                    style={{
                                        width: `${(row.count / max) * 100}%`,
                                        background: 'var(--viz-1)',
                                    }}
                                />
                            </span>
                            <span className="text-right tabular-nums">
                                {row.count.toLocaleString()}
                                <span className="sr-only"> {unit}</span>
                            </span>
                        </li>
                    ))}
                </ol>
            )}
        </figure>
    );
}

function Empty({
    children,
    tone,
}: {
    children: React.ReactNode;
    tone?: 'error';
}) {
    return (
        <p
            className={cn(
                'rounded-lg border border-dashed p-6 text-center text-sm',
                tone === 'error' ? 'text-red-600' : 'text-muted-foreground',
            )}
            data-test="growth-empty"
        >
            {children}
        </p>
    );
}
