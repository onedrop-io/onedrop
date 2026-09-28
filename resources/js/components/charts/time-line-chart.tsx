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

export type TimePoint = { t: number; value: number | null };

/**
 * One series over time: 2px line, gaps where there's no data, crosshair tooltip.
 */
export default function TimeLineChart({
    title,
    points,
    bucketSeconds,
    format = (v) => String(v),
    yMax,
    color = 'var(--viz-1)',
    testId,
}: {
    title: string;
    points: TimePoint[];
    bucketSeconds: number;
    format?: (value: number) => string;
    /** Fixed axis top (e.g. 100 for percentages). */
    yMax?: number;
    color?: string;
    testId?: string;
}) {
    const { ref, width } = useWidth<HTMLDivElement>();
    const [hover, setHover] = useState<number | null>(null);
    const values = points.map((p) => p.value ?? 0);
    // A fixed ceiling (e.g. the memory limit, 100%) gets quarter ticks up to exactly that value.
    const ticks = yMax
        ? [0, 0.25, 0.5, 0.75, 1].map((f) => Math.round(yMax * f))
        : niceTicks(Math.max(...values, 0));
    const top = ticks.at(-1)!;
    const left = axisLeft(ticks, format);
    const plotWidth = Math.max(0, width - left - PAD.right);
    const x = (i: number) =>
        left + (points.length <= 1 ? 0 : (i / (points.length - 1)) * plotWidth);
    const y = (v: number) =>
        PAD.top + (1 - v / top) * (HEIGHT - PAD.top - PAD.bottom);

    // Break the line wherever a bucket has no sample.
    const path = points
        .map((p, i) => {
            if (p.value === null) {
                return '';
            }

            const previous = i > 0 ? points[i - 1].value : null;

            return `${previous === null ? 'M' : 'L'}${x(i)},${y(p.value)}`;
        })
        .join('');

    const labelEvery = Math.max(
        1,
        Math.ceil(points.length / Math.max(1, Math.floor(plotWidth / 90))),
    );
    const hovered = hover !== null ? points[hover] : null;

    return (
        <figure className="min-w-0" data-test={testId}>
            <figcaption className="mb-2 text-sm font-medium">
                {title}
            </figcaption>
            <div ref={ref} className="relative">
                {width > 0 && (
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
                        <path
                            d={path}
                            fill="none"
                            stroke={color}
                            strokeWidth={2}
                            strokeLinejoin="round"
                            strokeLinecap="round"
                        />
                        {hover !== null && (
                            <g>
                                <line
                                    x1={x(hover)}
                                    x2={x(hover)}
                                    y1={PAD.top}
                                    y2={HEIGHT - PAD.bottom}
                                    stroke="var(--viz-axis)"
                                />
                                {hovered?.value !== null && hovered && (
                                    <circle
                                        cx={x(hover)}
                                        cy={y(hovered.value!)}
                                        r={4}
                                        fill={color}
                                        stroke="var(--background)"
                                        strokeWidth={2}
                                    />
                                )}
                            </g>
                        )}
                    </svg>
                )}
                {hovered && (
                    <Tooltip x={x(hover!)} width={width}>
                        <div className="text-muted-foreground">
                            {formatTimeLong(hovered.t)}
                        </div>
                        <div className="font-medium">
                            {hovered.value === null
                                ? 'No data'
                                : format(hovered.value)}
                        </div>
                    </Tooltip>
                )}
            </div>
            <DataTable
                caption={title}
                columns={['Time', title]}
                rows={points.map((p) => [
                    formatTimeLong(p.t),
                    p.value === null ? '—' : format(p.value),
                ])}
            />
        </figure>
    );
}
