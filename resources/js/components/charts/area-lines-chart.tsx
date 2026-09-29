import { useState } from 'react';
import {
    axisLeft,
    DataTable,
    formatTime,
    formatTimeLong,
    HEIGHT,
    niceTicks,
    PAD,
    Tooltip,
    useWidth,
    YAxis,
} from '@/components/charts/chart-kit';

export type AreaSeries = { key: string; label: string; color: string };

/**
 * A smooth path through the points that never overshoots them (monotone cubic, Fritsch–Carlson),
 * so a curve between two zero days stays on the baseline.
 */
function smoothPath(points: [number, number][]): string {
    if (points.length < 3) {
        return points
            .map(([px, py], i) => `${i === 0 ? 'M' : 'L'}${px},${py}`)
            .join('');
    }

    const slopes = points
        .slice(1)
        .map(([px, py], i) => (py - points[i][1]) / (px - points[i][0]));
    const tangents = points.map((_, i) => {
        if (i === 0) {
            return slopes[0];
        }

        if (i === points.length - 1) {
            return slopes[i - 1];
        }

        return slopes[i - 1] * slopes[i] <= 0
            ? 0
            : (slopes[i - 1] + slopes[i]) / 2;
    });

    slopes.forEach((slope, i) => {
        if (slope === 0) {
            tangents[i] = tangents[i + 1] = 0;

            return;
        }

        const a = tangents[i] / slope;
        const b = tangents[i + 1] / slope;
        const h = a * a + b * b;

        if (h > 9) {
            tangents[i] = (3 / Math.sqrt(h)) * a * slope;
            tangents[i + 1] = (3 / Math.sqrt(h)) * b * slope;
        }
    });

    return points
        .map(([px, py], i) => {
            if (i === 0) {
                return `M${px},${py}`;
            }

            const [x0, y0] = points[i - 1];
            const third = (px - x0) / 3;

            return `C${x0 + third},${y0 + tangents[i - 1] * third} ${px - third},${py - tangents[i] * third} ${px},${py}`;
        })
        .join('');
}

/**
 * A few series over the same time buckets: a 2px line each over a faint fill, crosshair tooltip.
 * Every point has a value (0 when nothing happened), so the lines never break.
 */
export default function AreaLinesChart({
    title,
    series,
    points,
    bucketSeconds,
    format = (v) => String(v),
    testId,
}: {
    title: string;
    series: AreaSeries[];
    points: { t: number; values: Record<string, number> }[];
    bucketSeconds: number;
    format?: (value: number) => string;
    testId?: string;
}) {
    const { ref, width } = useWidth<HTMLDivElement>();
    const [hover, setHover] = useState<number | null>(null);
    const value = (i: number, key: string) => points[i].values[key] ?? 0;
    const ticks = niceTicks(
        Math.max(
            0,
            ...points.flatMap((_, i) => series.map((s) => value(i, s.key))),
        ),
    );
    const top = ticks.at(-1)!;
    const left = axisLeft(ticks, format);
    const plotWidth = Math.max(0, width - left - PAD.right);
    const baseline = HEIGHT - PAD.bottom;
    const x = (i: number) =>
        left + (points.length <= 1 ? 0 : (i / (points.length - 1)) * plotWidth);
    const y = (v: number) =>
        PAD.top + (1 - v / top) * (HEIGHT - PAD.top - PAD.bottom);

    const line = (key: string) =>
        smoothPath(points.map((_, i) => [x(i), y(value(i, key))]));

    const labelEvery = Math.max(
        1,
        Math.ceil(points.length / Math.max(1, Math.floor(plotWidth / 90))),
    );

    return (
        <figure className="min-w-0" data-test={testId}>
            <figcaption className="mb-2 text-sm font-medium">
                {title}
            </figcaption>
            <div ref={ref} className="relative">
                {width > 0 && points.length > 0 && (
                    <svg
                        width={width}
                        height={HEIGHT}
                        role="img"
                        aria-label={title}
                        onMouseMove={(event) => {
                            const box =
                                event.currentTarget.getBoundingClientRect();
                            const ratio =
                                (event.clientX - box.left - left) / plotWidth;
                            setHover(
                                Math.min(
                                    points.length - 1,
                                    Math.max(
                                        0,
                                        Math.round(ratio * (points.length - 1)),
                                    ),
                                ),
                            );
                        }}
                        onMouseLeave={() => setHover(null)}
                    >
                        <YAxis
                            ticks={ticks}
                            scale={y}
                            width={width}
                            left={left}
                            format={format}
                        />
                        {points.map((p, i) =>
                            i % labelEvery === 0 ? (
                                <text
                                    key={p.t}
                                    x={x(i)}
                                    y={HEIGHT - 8}
                                    textAnchor="middle"
                                    fontSize={11}
                                    fill="var(--viz-muted)"
                                >
                                    {formatTime(p.t, bucketSeconds)}
                                </text>
                            ) : null,
                        )}
                        {series.map((s) => (
                            <g key={s.key}>
                                <path
                                    d={`${line(s.key)}L${x(points.length - 1)},${baseline}L${x(0)},${baseline}Z`}
                                    fill={s.color}
                                    fillOpacity={0.12}
                                />
                                <path
                                    d={line(s.key)}
                                    fill="none"
                                    stroke={s.color}
                                    strokeWidth={2}
                                    strokeLinejoin="round"
                                    strokeLinecap="round"
                                />
                            </g>
                        ))}
                        {hover !== null && (
                            <g>
                                <line
                                    x1={x(hover)}
                                    x2={x(hover)}
                                    y1={PAD.top}
                                    y2={baseline}
                                    stroke="var(--viz-axis)"
                                />
                                {series.map((s) => (
                                    <circle
                                        key={s.key}
                                        cx={x(hover)}
                                        cy={y(value(hover, s.key))}
                                        r={4}
                                        fill={s.color}
                                        stroke="var(--background)"
                                        strokeWidth={2}
                                    />
                                ))}
                            </g>
                        )}
                    </svg>
                )}
                {hover !== null && (
                    <Tooltip x={x(hover)} width={width}>
                        <div className="text-muted-foreground">
                            {formatTimeLong(points[hover].t)}
                        </div>
                        {series.map((s) => (
                            <div
                                key={s.key}
                                className="flex items-center gap-1.5"
                            >
                                <span
                                    className="size-2 rounded-full"
                                    style={{ background: s.color }}
                                />
                                {s.label}
                                <span className="ml-auto pl-3 font-medium tabular-nums">
                                    {format(value(hover, s.key))}
                                </span>
                            </div>
                        ))}
                    </Tooltip>
                )}
            </div>
            <DataTable
                caption={title}
                columns={['Time', ...series.map((s) => s.label)]}
                rows={points.map((p, i) => [
                    formatTimeLong(p.t),
                    ...series.map((s) => format(value(i, s.key))),
                ])}
            />
        </figure>
    );
}
