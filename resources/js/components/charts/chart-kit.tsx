import { useEffect, useRef, useState } from 'react';

/** Chart geometry shared by every chart: padding for axes, fixed plot height. */
export const PAD = { top: 12, right: 12, bottom: 28, left: 44 };
export const HEIGHT = 220;

/** Track an element's width so SVG charts can draw at real pixel size (crisp text). */
export function useWidth<T extends HTMLElement>() {
    const ref = useRef<T>(null);
    const [width, setWidth] = useState(0);

    useEffect(() => {
        const element = ref.current;

        if (!element) {
            return;
        }

        const observer = new ResizeObserver(([entry]) =>
            setWidth(Math.floor(entry.contentRect.width)),
        );
        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    return { ref, width };
}

/** Left margin wide enough for the widest y-axis label (11px tabular digits ≈ 6.6px each). */
export function axisLeft(
    ticks: number[],
    format: (value: number) => string = String,
): number {
    const longest = Math.max(...ticks.map((tick) => format(tick).length));

    return Math.max(32, Math.ceil(longest * 6.6) + 14);
}

/** "Nice" axis maximum and ~4 ticks for a value range starting at 0. */
export function niceTicks(max: number, count = 4): number[] {
    if (max <= 0) {
        return [0, 1];
    }

    const rough = max / count;
    const magnitude = 10 ** Math.floor(Math.log10(rough));
    const step =
        [1, 2, 2.5, 5, 10].find((m) => m * magnitude >= rough)! * magnitude;
    const top = Math.ceil(max / step) * step;

    return Array.from(
        { length: Math.round(top / step) + 1 },
        (_, i) => +(i * step).toFixed(10),
    );
}

export function formatTime(t: number, bucketSeconds: number): string {
    const date = new Date(t * 1000);

    return bucketSeconds >= 3600 * 2
        ? date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
        : date.toLocaleTimeString(undefined, {
              hour: 'numeric',
              minute: '2-digit',
          });
}

export function formatTimeLong(t: number): string {
    return new Date(t * 1000).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

/** A rect with 4px rounded top corners, square at the baseline. */
export function roundedTop(
    x: number,
    y: number,
    width: number,
    height: number,
    radius = 4,
): string {
    const r = Math.min(radius, width / 2, height);

    return `M${x},${y + height}V${y + r}Q${x},${y} ${x + r},${y}H${x + width - r}Q${x + width},${y} ${x + width},${y + r}V${y + height}Z`;
}

export function YAxis({
    ticks,
    scale,
    width,
    left,
    format = String,
}: {
    ticks: number[];
    scale: (value: number) => number;
    width: number;
    /** Left edge of the plot (see axisLeft). */
    left: number;
    format?: (value: number) => string;
}) {
    return (
        <g>
            {ticks.map((tick) => (
                <g key={tick}>
                    <line
                        x1={left}
                        x2={width - PAD.right}
                        y1={scale(tick)}
                        y2={scale(tick)}
                        stroke={
                            tick === 0 ? 'var(--viz-axis)' : 'var(--viz-grid)'
                        }
                        strokeWidth={1}
                    />
                    <text
                        x={left - 8}
                        y={scale(tick)}
                        dy="0.32em"
                        textAnchor="end"
                        fontSize={11}
                        fill="var(--viz-muted)"
                        style={{ fontVariantNumeric: 'tabular-nums' }}
                    >
                        {format(tick)}
                    </text>
                </g>
            ))}
        </g>
    );
}

export function Tooltip({
    x,
    width,
    children,
}: {
    x: number;
    width: number;
    children: React.ReactNode;
}) {
    const left = x > width / 2;

    return (
        <div
            className="pointer-events-none absolute top-2 z-10 rounded-md border surface-floating px-2.5 py-1.5 text-xs"
            style={left ? { right: width - x + 12 } : { left: x + 12 }}
            role="status"
        >
            {children}
        </div>
    );
}

export function DataTable({
    caption,
    columns,
    rows,
    summary = 'Show data table',
}: {
    caption: string;
    summary?: string;
    columns: string[];
    rows: (string | number)[][];
}) {
    return (
        <details className="mt-2 text-xs">
            <summary className="cursor-pointer text-muted-foreground select-none">
                {summary}
            </summary>
            <div className="mt-2 max-h-56 overflow-auto rounded-md border">
                <table className="w-full text-left">
                    <caption className="sr-only">{caption}</caption>
                    <thead className="sticky top-0 bg-background">
                        <tr>
                            {columns.map((column) => (
                                <th
                                    key={column}
                                    className="px-2 py-1 font-medium"
                                >
                                    {column}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row, index) => (
                            <tr key={index} className="border-t">
                                {row.map((cell, cellIndex) => (
                                    <td
                                        key={cellIndex}
                                        className="px-2 py-1"
                                        style={{
                                            fontVariantNumeric: 'tabular-nums',
                                        }}
                                    >
                                        {cell}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </details>
    );
}
