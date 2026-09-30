import { Deferred, Head, router, usePoll } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AreaLinesChart from '@/components/charts/area-lines-chart';
import TimeLineChart from '@/components/charts/time-line-chart';
import Heading from '@/components/heading';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

type Range = '1h' | '24h' | '7d';

type Point = {
    t: number;
    cpu: number | null;
    memory_used: number | null;
    disk_used: number | null;
    disk_read: number | null;
    disk_written: number | null;
    network_in: number | null;
    network_out: number | null;
};

type Metrics = {
    range: Range;
    bucket_seconds: number;
    current: {
        recorded_at?: number;
        cpu?: number | null;
        memory_used?: number | null;
        memory_total?: number | null;
        disk_used?: number | null;
        disk_total?: number | null;
        disk_read?: number | null;
        disk_written?: number | null;
        network_in?: number | null;
        network_out?: number | null;
    };
    series: Point[];
};

type DockerRow = {
    type: string;
    total: number;
    active: number;
    size: string;
    reclaimable: string;
};

type Counts = {
    users: number;
    projects: number;
    sandboxes: number;
    running: number;
    providers: Record<string, number>;
};

const RANGES: { value: Range; label: string }[] = [
    { value: '1h', label: 'Past hour' },
    { value: '24h', label: 'Past day' },
    { value: '7d', label: 'Past week' },
];

function formatBytes(bytes: number, digits = 1): string {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = 0;

    while (Math.abs(value) >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(unit === 0 ? 0 : digits)} ${units[unit]}`;
}

const formatRate = (bytes: number) => `${formatBytes(bytes)}/s`;

/** The server's resources, Docker's disk usage, and what the install holds (ADMIN-003). */
export default function Monitoring({
    range,
    metrics,
    counts,
    docker,
}: {
    range: Range;
    metrics: Metrics;
    counts: Counts;
    docker?: DockerRow[] | null;
}) {
    usePoll(60_000, { only: ['metrics', 'counts'] });

    const { current, series, bucket_seconds: bucket } = metrics;
    const changeRange = (value: Range) =>
        router.reload({
            data: { range: value },
            only: ['metrics', 'range'],
        });

    return (
        <>
            <Head title="Monitoring" />

            <div className="space-y-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <Heading
                        variant="small"
                        title="Monitoring"
                        description="This server's resources, sampled every minute"
                    />
                    <div
                        className="flex rounded-md border p-0.5 text-sm"
                        role="group"
                        aria-label="Time range"
                    >
                        {RANGES.map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                onClick={() => changeRange(option.value)}
                                className={cn(
                                    'rounded px-2.5 py-1 text-muted-foreground',
                                    range === option.value &&
                                        'bg-muted font-medium text-foreground',
                                )}
                                aria-pressed={range === option.value}
                            >
                                {option.label}
                            </button>
                        ))}
                    </div>
                </div>

                <div
                    className="grid grid-cols-2 gap-3 sm:grid-cols-4"
                    data-test="install-counts"
                >
                    <Stat label="Users" value={counts.users} />
                    <Stat label="Projects" value={counts.projects} />
                    <Stat
                        label="Sandboxes running"
                        value={`${counts.running} of ${counts.sandboxes}`}
                    />
                    <Stat
                        label="By provider"
                        value={
                            Object.entries(counts.providers)
                                .map(([name, count]) => `${name} ${count}`)
                                .join(', ') || 'None'
                        }
                    />
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Panel
                        title="CPU usage"
                        summary={
                            current.cpu != null
                                ? `Used: ${current.cpu.toFixed(1)}%`
                                : null
                        }
                        share={current.cpu != null ? current.cpu / 100 : null}
                        testId="cpu-panel"
                    >
                        {hasAny(series, 'cpu') && (
                            <TimeLineChart
                                title="Share of all CPUs"
                                points={series.map((p) => ({
                                    t: p.t,
                                    value: p.cpu,
                                }))}
                                bucketSeconds={bucket}
                                yMax={100}
                                format={(v) => `${v}%`}
                            />
                        )}
                    </Panel>

                    <Panel
                        title="Memory usage"
                        summary={
                            current.memory_used != null && current.memory_total
                                ? `Used: ${formatBytes(current.memory_used, 2)} / Total: ${formatBytes(current.memory_total, 2)}`
                                : null
                        }
                        share={ratio(current.memory_used, current.memory_total)}
                        testId="memory-panel"
                    >
                        {hasAny(series, 'memory_used') && (
                            <TimeLineChart
                                title="Used over time"
                                points={series.map((p) => ({
                                    t: p.t,
                                    value: p.memory_used,
                                }))}
                                bucketSeconds={bucket}
                                yMax={current.memory_total ?? undefined}
                                format={(v) => formatBytes(v, 0)}
                                color="var(--viz-2)"
                            />
                        )}
                    </Panel>

                    <Panel
                        title="Disk space"
                        summary={
                            current.disk_used != null && current.disk_total
                                ? `Used: ${formatBytes(current.disk_used, 2)} / Total: ${formatBytes(current.disk_total, 2)}`
                                : null
                        }
                        share={ratio(current.disk_used, current.disk_total)}
                        testId="disk-panel"
                    >
                        {hasAny(series, 'disk_used') && (
                            <TimeLineChart
                                title="Used over time"
                                points={series.map((p) => ({
                                    t: p.t,
                                    value: p.disk_used,
                                }))}
                                bucketSeconds={bucket}
                                yMax={current.disk_total ?? undefined}
                                format={(v) => formatBytes(v, 0)}
                                color="var(--viz-3)"
                            />
                        )}
                    </Panel>

                    <section
                        className="space-y-3 rounded-xl border p-4"
                        data-test="docker-panel"
                    >
                        <h3 className="text-sm font-medium">
                            Docker disk usage
                        </h3>
                        <Deferred
                            data="docker"
                            fallback={
                                <div className="space-y-2">
                                    <Skeleton className="h-6 w-full animate-pulse" />
                                    <Skeleton className="h-6 w-full animate-pulse" />
                                    <Skeleton className="h-6 w-full animate-pulse" />
                                    <Skeleton className="h-6 w-full animate-pulse" />
                                </div>
                            }
                        >
                            <DockerUsage rows={docker ?? null} />
                        </Deferred>
                    </section>

                    <Panel
                        title="Block I/O"
                        summary={
                            current.disk_read != null &&
                            current.disk_written != null
                                ? `Read: ${formatBytes(current.disk_read, 2)} / Written: ${formatBytes(current.disk_written, 2)} since boot`
                                : null
                        }
                        testId="block-io-panel"
                    >
                        {hasAny(series, 'disk_read') && (
                            <AreaLinesChart
                                title="Read and written per second"
                                series={[
                                    {
                                        key: 'read',
                                        label: 'Read',
                                        color: 'var(--viz-1)',
                                    },
                                    {
                                        key: 'written',
                                        label: 'Written',
                                        color: 'var(--viz-4)',
                                    },
                                ]}
                                points={series.map((p) => ({
                                    t: p.t,
                                    values: {
                                        read: p.disk_read ?? 0,
                                        written: p.disk_written ?? 0,
                                    },
                                }))}
                                bucketSeconds={bucket}
                                format={formatRate}
                            />
                        )}
                    </Panel>

                    <Panel
                        title="Network I/O"
                        summary={
                            current.network_in != null &&
                            current.network_out != null
                                ? `In: ${formatBytes(current.network_in, 2)} / Out: ${formatBytes(current.network_out, 2)} since boot`
                                : null
                        }
                        testId="network-panel"
                    >
                        {hasAny(series, 'network_in') && (
                            <AreaLinesChart
                                title="In and out per second"
                                series={[
                                    {
                                        key: 'in',
                                        label: 'In',
                                        color: 'var(--viz-1)',
                                    },
                                    {
                                        key: 'out',
                                        label: 'Out',
                                        color: 'var(--viz-4)',
                                    },
                                ]}
                                points={series.map((p) => ({
                                    t: p.t,
                                    values: {
                                        in: p.network_in ?? 0,
                                        out: p.network_out ?? 0,
                                    },
                                }))}
                                bucketSeconds={bucket}
                                format={formatRate}
                            />
                        )}
                    </Panel>
                </div>
            </div>
        </>
    );
}

function ratio(used?: number | null, total?: number | null): number | null {
    return used != null && total ? used / total : null;
}

function hasAny(series: Point[], key: keyof Point): boolean {
    return series.some((point) => point[key] !== null);
}

function Stat({ label, value }: { label: string; value: string | number }) {
    return (
        <div className="rounded-xl border p-3">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="mt-1 truncate text-lg font-semibold tabular-nums">
                {value}
            </p>
        </div>
    );
}

function Panel({
    title,
    summary,
    share,
    testId,
    children,
}: {
    title: string;
    summary: string | null;
    share?: number | null;
    testId?: string;
    children: ReactNode;
}) {
    return (
        <section className="space-y-3 rounded-xl border p-4" data-test={testId}>
            <div className="space-y-1">
                <h3 className="text-sm font-medium">{title}</h3>
                <p className="text-sm text-muted-foreground tabular-nums">
                    {summary ?? 'Not available on this server.'}
                </p>
            </div>
            {share != null && (
                <div className="h-2 overflow-hidden rounded-full bg-muted">
                    <div
                        className="h-full rounded-full bg-foreground/80"
                        style={{ width: `${Math.min(100, share * 100)}%` }}
                    />
                </div>
            )}
            {summary !== null && children}
        </section>
    );
}

function DockerUsage({ rows }: { rows: DockerRow[] | null }) {
    if (rows === null) {
        return (
            <p className="text-sm text-muted-foreground">
                Docker isn't available on this server.
            </p>
        );
    }

    return (
        <table className="w-full text-left text-sm">
            <thead className="text-muted-foreground">
                <tr>
                    <th className="py-1.5 font-medium">Type</th>
                    <th className="py-1.5 font-medium">Count</th>
                    <th className="py-1.5 font-medium">Size</th>
                    <th className="py-1.5 font-medium">Reclaimable</th>
                </tr>
            </thead>
            <tbody className="divide-y tabular-nums">
                {rows.map((row) => (
                    <tr key={row.type}>
                        <td className="py-1.5">{row.type}</td>
                        <td className="py-1.5">
                            {row.total}
                            {row.active > 0 && (
                                <span className="text-muted-foreground">
                                    {' '}
                                    ({row.active} active)
                                </span>
                            )}
                        </td>
                        <td className="py-1.5">{row.size}</td>
                        <td className="py-1.5">{row.reclaimable}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}
