import { useEffect, useState } from 'react';
import ProjectMonitoringController from '@/actions/App/Http/Controllers/ProjectMonitoringController';
import Histogram from '@/components/charts/histogram';
import StackedColumns from '@/components/charts/stacked-columns';
import TimeLineChart from '@/components/charts/time-line-chart';
import { cn } from '@/lib/utils';

type Range = '1h' | '24h' | '7d';

type Monitoring = {
    application: {
        bucket_seconds: number;
        total: number;
        unique_ips: number;
        error_rate: number | null;
        requests: { t: number; count: number }[];
        statuses: {
            t: number;
            '2xx': number;
            '3xx': number;
            '4xx': number;
            '5xx': number;
        }[];
        durations: { label: string; count: number }[];
    };
    infrastructure: {
        bucket_seconds: number;
        memory_limit_mb: number | null;
        cpu: { t: number; value: number | null }[];
        memory: { t: number; value: number | null }[];
    };
};

const RANGES: { value: Range; label: string }[] = [
    { value: '1h', label: 'Past hour' },
    { value: '24h', label: 'Past day' },
    { value: '7d', label: 'Past week' },
];

const STATUS_SERIES = [
    { key: '2xx', label: '2xx', color: 'var(--viz-1)' },
    { key: '3xx', label: '3xx', color: 'var(--viz-2)' },
    { key: '4xx', label: '4xx', color: 'var(--viz-3)' },
    { key: '5xx', label: '5xx', color: 'var(--viz-4)' },
];

export default function MonitoringPanel({
    projectId,
    running,
}: {
    projectId: number;
    running: boolean;
}) {
    const [range, setRange] = useState<Range>('24h');
    const [infraRange, setInfraRange] = useState<Range>('1h');
    const [traffic, setTraffic] = useState<'all' | 'published'>('all');
    const [data, setData] = useState<Monitoring | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!running) {
            return;
        }

        let cancelled = false;

        const load = () =>
            fetch(
                ProjectMonitoringController.show.url(projectId, {
                    query: { range, infra_range: infraRange, traffic },
                }),
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                },
            )
                .then(async (response) => {
                    const body = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        throw new Error(
                            body.message ?? 'Could not load monitoring data.',
                        );
                    }

                    return body as Monitoring;
                })
                .then((body) => {
                    if (!cancelled) {
                        setData(body);
                        setError(null);
                    }
                })
                .catch((e: Error) => !cancelled && setError(e.message));

        void load();
        const timer = window.setInterval(() => void load(), 30_000);

        return () => {
            cancelled = true;
            window.clearInterval(timer);
        };
    }, [projectId, running, range, infraRange, traffic]);

    if (!running) {
        return <Empty>Monitoring starts when the sandbox is running.</Empty>;
    }

    if (error) {
        return <Empty tone="error">{error}</Empty>;
    }

    if (!data) {
        return <Empty>Loading…</Empty>;
    }

    const { application: app, infrastructure: infra } = data;
    const memoryLimit = infra.memory_limit_mb;

    return (
        // Lay charts out by the panel's own width (it shares the screen with chat and files).
        <div className="@container space-y-6" data-test="monitoring-panel">
            <section className="space-y-5 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                <header className="flex flex-wrap items-center justify-between gap-2">
                    <h3 className="font-medium">Application</h3>
                    <div className="flex flex-wrap items-center gap-2">
                        <Segmented
                            label="Traffic"
                            value={traffic}
                            onChange={setTraffic}
                            options={[
                                { value: 'all', label: 'All traffic' },
                                { value: 'published', label: 'Published only' },
                            ]}
                        />
                        <RangeSelect
                            label="Application range"
                            value={range}
                            onChange={setRange}
                        />
                    </div>
                </header>

                <dl
                    className="grid grid-cols-3 gap-3"
                    data-test="monitoring-stats"
                >
                    <Stat label="Requests" value={app.total.toLocaleString()} />
                    <Stat
                        label="Unique visitors"
                        value={app.unique_ips.toLocaleString()}
                    />
                    <Stat
                        label="Server errors (5xx)"
                        value={
                            app.error_rate === null
                                ? '—'
                                : `${(app.error_rate * 100).toFixed(1)}%`
                        }
                    />
                </dl>

                {app.total === 0 ? (
                    <Empty>
                        No requests in this period yet. Open the preview or the
                        published URL to see traffic here.
                    </Empty>
                ) : (
                    <>
                        <TimeLineChart
                            title="Requests"
                            points={app.requests.map((p) => ({
                                t: p.t,
                                value: p.count,
                            }))}
                            bucketSeconds={app.bucket_seconds}
                            testId="chart-requests"
                        />
                        <div className="grid gap-6 @3xl:grid-cols-2">
                            <StackedColumns
                                title="HTTP statuses"
                                rows={app.statuses}
                                series={STATUS_SERIES}
                                bucketSeconds={app.bucket_seconds}
                                testId="chart-statuses"
                            />
                            <Histogram
                                title="Request durations"
                                bins={app.durations}
                                unit="ms"
                                testId="chart-durations"
                            />
                        </div>
                    </>
                )}
            </section>

            <section className="space-y-5 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                <header className="flex flex-wrap items-center justify-between gap-2">
                    <h3 className="font-medium">Infrastructure</h3>
                    <RangeSelect
                        label="Infrastructure range"
                        value={infraRange}
                        onChange={setInfraRange}
                    />
                </header>

                {infra.cpu.every((p) => p.value === null) ? (
                    <Empty>
                        No samples yet. The sandbox records CPU and memory once
                        a minute.
                    </Empty>
                ) : (
                    <div className="grid gap-6 @3xl:grid-cols-2">
                        <TimeLineChart
                            title="CPU utilization"
                            points={infra.cpu}
                            bucketSeconds={infra.bucket_seconds}
                            yMax={100}
                            format={(v) => `${Math.round(v)}%`}
                            testId="chart-cpu"
                        />
                        <TimeLineChart
                            title={
                                memoryLimit
                                    ? `Memory (of ${memoryLimit.toLocaleString()} MB)`
                                    : 'Memory'
                            }
                            points={infra.memory}
                            bucketSeconds={infra.bucket_seconds}
                            yMax={memoryLimit ?? undefined}
                            format={(v) =>
                                `${Math.round(v).toLocaleString()} MB`
                            }
                            testId="chart-memory"
                        />
                    </div>
                )}
            </section>
        </div>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-lg bg-muted/50 p-3">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-xl font-semibold tabular-nums">{value}</dd>
        </div>
    );
}

function RangeSelect({
    label,
    value,
    onChange,
}: {
    label: string;
    value: Range;
    onChange: (range: Range) => void;
}) {
    return (
        <label className="text-sm">
            <span className="sr-only">{label}</span>
            <select
                value={value}
                onChange={(event) => onChange(event.target.value as Range)}
                className="h-8 rounded-md border border-input bg-transparent px-2 text-sm"
                aria-label={label}
            >
                {RANGES.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </label>
    );
}

function Segmented<T extends string>({
    label,
    value,
    onChange,
    options,
}: {
    label: string;
    value: T;
    onChange: (value: T) => void;
    options: { value: T; label: string }[];
}) {
    return (
        <div
            role="radiogroup"
            aria-label={label}
            className="flex rounded-md border border-input p-0.5 text-xs"
        >
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    role="radio"
                    aria-checked={value === option.value}
                    onClick={() => onChange(option.value)}
                    className={cn(
                        'rounded px-2 py-1',
                        value === option.value
                            ? 'bg-muted font-medium'
                            : 'text-muted-foreground',
                    )}
                >
                    {option.label}
                </button>
            ))}
        </div>
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
            data-test="monitoring-empty"
        >
            {children}
        </p>
    );
}
