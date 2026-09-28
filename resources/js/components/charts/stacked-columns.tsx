import { useState } from 'react';
import {
    axisLeft,
    DataTable,
    formatTime,
    formatTimeLong,
    HEIGHT,
    niceTicks,
    PAD,
    roundedTop,
    Tooltip,
    useWidth,
    YAxis,
} from '@/components/charts/chart-kit';

export type StackSeries = { key: string; label: string; color: string };

/**
 * Counts per time bucket, stacked by category, with a 2px surface gap between segments.
 */
export default function StackedColumns({
    title,
    rows,
    series,
    bucketSeconds,
    testId,
}: {
    title: string;
    rows: ({ t: number } & Record<string, number>)[];
    series: StackSeries[];
    bucketSeconds: number;
    testId?: string;
}) {
    const { ref, width } = useWidth<HTMLDivElement>();
    const [hover, setHover] = useState<number | null>(null);
    const totals = rows.map((row) =>
        series.reduce((sum, s) => sum + (row[s.key] ?? 0), 0),
    );
    const ticks = niceTicks(Math.max(...totals, 0));
    const top = ticks.at(-1)!;
    const left = axisLeft(ticks);
    const plotWidth = Math.max(0, width - left - PAD.right);
    const band = rows.length ? plotWidth / rows.length : 0;
    const barWidth = Math.max(1, Math.min(24, band * 0.7));
    const plotHeight = HEIGHT - PAD.top - PAD.bottom;
    const y = (v: number) => PAD.top + (1 - v / top) * plotHeight;
    const labelEvery = Math.max(
        1,
        Math.ceil(rows.length / Math.max(1, Math.floor(plotWidth / 90))),
    );
    const hovered = hover !== null ? rows[hover] : null;

    return (
        <figure className="min-w-0" data-test={testId}>
            <figcaption className="mb-2 flex flex-wrap items-center justify-between gap-2 text-sm font-medium">
                {title}
                <span className="flex flex-wrap gap-3 text-xs font-normal text-muted-foreground">
                    {series.map((s) => (
                        <span
                            key={s.key}
                            className="inline-flex items-center gap-1.5"
                        >
                            <span
                                className="size-2.5 rounded-sm"
                                style={{ background: s.color }}
                            />
                            {s.label}
                        </span>
                    ))}
                </span>
            </figcaption>
            <div ref={ref} className="relative">
                {width > 0 && (
                    <svg
                        width={width}
                        height={HEIGHT}
                        role="img"
                        aria-label={title}
                        onMouseLeave={() => setHover(null)}
                    >
                        <YAxis
                            ticks={ticks}
                            scale={y}
                            width={width}
                            left={left}
                        />
                        {rows.map((row, i) => {
                            const x = left + i * band + (band - barWidth) / 2;
                            let base = 0;
                            const visible = series.filter(
                                (s) => (row[s.key] ?? 0) > 0,
                            );

                            return (
                                <g key={row.t} onMouseEnter={() => setHover(i)}>
                                    {/* Hit target taller and wider than the marks. */}
                                    <rect
                                        x={left + i * band}
                                        y={PAD.top}
                                        width={band}
                                        height={plotHeight}
                                        fill="transparent"
                                    />
                                    {visible.map((s, index) => {
                                        const value = row[s.key];
                                        const y0 = y(base);
                                        base += value;
                                        const y1 = y(base);
                                        const gap = index > 0 ? 2 : 0;
                                        const height = Math.max(
                                            0,
                                            y0 - y1 - gap,
                                        );

                                        return index === visible.length - 1 ? (
                                            <path
                                                key={s.key}
                                                d={roundedTop(
                                                    x,
                                                    y1,
                                                    barWidth,
                                                    height,
                                                )}
                                                fill={s.color}
                                            />
                                        ) : (
                                            <rect
                                                key={s.key}
                                                x={x}
                                                y={y1 + gap}
                                                width={barWidth}
                                                height={height}
                                                fill={s.color}
                                            />
                                        );
                                    })}
                                    {i % labelEvery === 0 && (
                                        <text
                                            x={x + barWidth / 2}
                                            y={HEIGHT - 8}
                                            textAnchor="middle"
                                            fontSize={11}
                                            fill="var(--viz-muted)"
                                        >
                                            {formatTime(row.t, bucketSeconds)}
                                        </text>
                                    )}
                                </g>
                            );
                        })}
                    </svg>
                )}
                {hovered && (
                    <Tooltip x={left + hover! * band + band / 2} width={width}>
                        <div className="mb-1 text-muted-foreground">
                            {formatTimeLong(hovered.t)}
                        </div>
                        {series.map((s) => (
                            <div
                                key={s.key}
                                className="flex items-center gap-1.5"
                            >
                                <span
                                    className="size-2 rounded-sm"
                                    style={{ background: s.color }}
                                />
                                <span className="flex-1">{s.label}</span>
                                <span className="font-medium tabular-nums">
                                    {hovered[s.key] ?? 0}
                                </span>
                            </div>
                        ))}
                    </Tooltip>
                )}
            </div>
            <DataTable
                caption={title}
                columns={['Time', ...series.map((s) => s.label)]}
                rows={rows.map((row) => [
                    formatTimeLong(row.t),
                    ...series.map((s) => row[s.key] ?? 0),
                ])}
            />
        </figure>
    );
}
