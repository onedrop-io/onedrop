import { useState } from 'react';
import {
    axisLeft,
    DataTable,
    HEIGHT,
    niceTicks,
    PAD,
    roundedTop,
    Tooltip,
    useWidth,
    YAxis,
} from '@/components/charts/chart-kit';

/**
 * Counts per labelled bin (e.g. request durations).
 */
export default function Histogram({
    title,
    bins,
    unit,
    testId,
}: {
    title: string;
    bins: { label: string; count: number }[];
    unit: string;
    testId?: string;
}) {
    const { ref, width } = useWidth<HTMLDivElement>();
    const [hover, setHover] = useState<number | null>(null);
    const ticks = niceTicks(Math.max(...bins.map((b) => b.count), 0));
    const top = ticks.at(-1)!;
    const left = axisLeft(ticks);
    const plotWidth = Math.max(0, width - left - PAD.right);
    const band = bins.length ? plotWidth / bins.length : 0;
    const barWidth = Math.max(1, Math.min(48, band - 2));
    const plotHeight = HEIGHT - PAD.top - PAD.bottom;
    const y = (v: number) => PAD.top + (1 - v / top) * plotHeight;
    const total = bins.reduce((sum, b) => sum + b.count, 0);

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
                        onMouseLeave={() => setHover(null)}
                    >
                        <YAxis
                            ticks={ticks}
                            scale={y}
                            width={width}
                            left={left}
                        />
                        {bins.map((bin, i) => {
                            const x = left + i * band + (band - barWidth) / 2;
                            const height = y(0) - y(bin.count);

                            return (
                                <g
                                    key={bin.label}
                                    onMouseEnter={() => setHover(i)}
                                >
                                    <rect
                                        x={left + i * band}
                                        y={PAD.top}
                                        width={band}
                                        height={plotHeight}
                                        fill="transparent"
                                    />
                                    {bin.count > 0 && (
                                        <path
                                            d={roundedTop(
                                                x,
                                                y(bin.count),
                                                barWidth,
                                                height,
                                            )}
                                            fill="var(--viz-1)"
                                        />
                                    )}
                                    <text
                                        x={x + barWidth / 2}
                                        y={HEIGHT - 8}
                                        textAnchor="middle"
                                        fontSize={11}
                                        fill="var(--viz-muted)"
                                    >
                                        {bin.label}
                                    </text>
                                </g>
                            );
                        })}
                    </svg>
                )}
                {hover !== null && (
                    <Tooltip x={left + hover * band + band / 2} width={width}>
                        <div className="text-muted-foreground">
                            {bins[hover].label} {unit}
                        </div>
                        <div className="font-medium">
                            {bins[hover].count} requests
                            {total > 0 &&
                                ` (${Math.round((bins[hover].count / total) * 100)}%)`}
                        </div>
                    </Tooltip>
                )}
            </div>
            <DataTable
                caption={title}
                columns={[`Duration (${unit})`, 'Requests']}
                rows={bins.map((b) => [b.label, b.count])}
            />
        </figure>
    );
}
