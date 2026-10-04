import { useEffect, useMemo, useState } from 'react';
import { DataTable, useWidth } from '@/components/charts/chart-kit';
import {
    MAP_HEIGHT,
    MAP_WIDTH,
    mapOutline,
    naturalEarth,
} from '@/components/charts/natural-earth';
import shapesUrl from '@/components/charts/world-map-data.json?url';

export type MapCountry = { label: string; count: number };

export type MapCity = {
    city: string;
    region: string | null;
    country: string;
    lat: number | null;
    lon: number | null;
    count: number;
};

type Shape = { code: string; name: string; d: string };

/** Fewest to most (app.css): each country's shade is its visitors on a log scale up to the busiest. */
const STEPS = 5;
/** Bubble sizes in screen pixels, the biggest growing with the map (a narrow panel gets small bubbles). */
const MIN_RADIUS = 3;
const maxRadius = (width: number) => Math.min(14, Math.max(6, width * 0.018));

const OUTLINE = mapOutline();

let shapes: Promise<Shape[]> | null = null;

/** The country shapes (scripts/world-map.mjs), fetched once, only when a map is shown. */
function loadShapes(): Promise<Shape[]> {
    shapes ??= fetch(shapesUrl)
        .then((response) => response.json())
        .then((data: { countries: Shape[] }) => data.countries)
        .catch((error) => {
            shapes = null;
            throw error;
        });

    return shapes;
}

type Hover = {
    /** The hovered country's code, or city's key. */
    key: string;
    x: number;
    y: number;
    title: string;
    detail: string | null;
    count: number;
};

/**
 * Visitors on a world map: countries shaded by visitors, a bubble per city sized by visitors, hover or focus
 * for details, and a table of the values.
 */
export default function WorldMap({
    title,
    countries,
    cities,
    countryName,
    unit = 'visitors',
    testId,
}: {
    title: string;
    countries: MapCountry[];
    cities: MapCity[];
    countryName: (code: string) => string;
    unit?: string;
    testId?: string;
}) {
    const { ref, width } = useWidth<HTMLDivElement>();
    const [countryShapes, setCountryShapes] = useState<Shape[] | null>(null);
    const [failed, setFailed] = useState(false);
    const [hover, setHover] = useState<Hover | null>(null);

    useEffect(() => {
        let cancelled = false;

        loadShapes()
            .then((loaded) => !cancelled && setCountryShapes(loaded))
            .catch(() => !cancelled && setFailed(true));

        return () => {
            cancelled = true;
        };
    }, []);

    const counts = useMemo(
        () => new Map(countries.map((row) => [row.label, row.count])),
        [countries],
    );
    const maxCountry = Math.max(1, ...countries.map((row) => row.count));
    const step = (count: number) =>
        maxCountry <= 1
            ? STEPS
            : Math.max(
                  1,
                  Math.ceil(
                      (STEPS * Math.log(1 + count)) / Math.log(1 + maxCountry),
                  ),
              );

    // Biggest first, so smaller bubbles sit on top and stay hoverable.
    const placed = cities
        .filter((city) => city.lat !== null && city.lon !== null)
        .map((city) => {
            const [x, y] = naturalEarth(city.lon!, city.lat!);

            return { ...city, x, y };
        })
        .sort((a, b) => b.count - a.count);
    const maxCity = Math.max(1, ...placed.map((city) => city.count));
    const radius = (count: number) =>
        MIN_RADIUS +
        (maxRadius(width) - MIN_RADIUS) * Math.sqrt(count / maxCity);

    const height = width * (MAP_HEIGHT / MAP_WIDTH);
    const scale = width / MAP_WIDTH;
    const show = (x: number, y: number, next: Omit<Hover, 'x' | 'y'>) =>
        setHover({ x, y, ...next });
    const fromPointer = (
        event: React.PointerEvent<SVGElement>,
        next: Omit<Hover, 'x' | 'y'>,
    ) => {
        const box =
            event.currentTarget.ownerSVGElement!.getBoundingClientRect();
        show(event.clientX - box.left, event.clientY - box.top, next);
    };

    const cityDetail = (city: MapCity) =>
        [city.region, countryName(city.country)]
            .filter((part) => part && part !== city.city)
            .join(', ');

    return (
        <figure className="min-w-0" data-test={testId}>
            <figcaption className="mb-2 flex flex-wrap items-center justify-between gap-2 text-sm font-medium">
                {title}
                <Legend max={maxCountry} hasCities={placed.length > 0} />
            </figcaption>
            <div
                ref={ref}
                className="relative"
                style={{ aspectRatio: `${MAP_WIDTH} / ${MAP_HEIGHT}` }}
            >
                {!countryShapes || width === 0 ? (
                    <div
                        className={
                            failed
                                ? 'flex h-full items-center justify-center text-xs text-muted-foreground'
                                : 'h-full animate-pulse rounded-lg bg-muted/60'
                        }
                    >
                        {failed && "The map couldn't load."}
                    </div>
                ) : (
                    <svg
                        width={width}
                        height={height}
                        viewBox={`0 0 ${MAP_WIDTH} ${MAP_HEIGHT}`}
                        role="img"
                        aria-label={title}
                        onPointerLeave={() => setHover(null)}
                        className="block"
                    >
                        <defs>
                            <radialGradient id="world-map-glow">
                                <stop
                                    offset="0%"
                                    stopColor="var(--viz-2)"
                                    stopOpacity={0.35}
                                />
                                <stop
                                    offset="100%"
                                    stopColor="var(--viz-2)"
                                    stopOpacity={0}
                                />
                            </radialGradient>
                        </defs>
                        {/* The sea: moving off a country hides its details. */}
                        <rect
                            width={MAP_WIDTH}
                            height={MAP_HEIGHT}
                            fill="transparent"
                            onPointerMove={() => setHover(null)}
                        />
                        <path
                            d={OUTLINE}
                            fill="var(--muted)"
                            fillOpacity={0.35}
                            pointerEvents="none"
                        />
                        <g
                            stroke="var(--background)"
                            strokeWidth={0.75}
                            strokeLinejoin="round"
                        >
                            {countryShapes.map((shape) => {
                                const count = counts.get(shape.code) ?? 0;
                                const dimmed =
                                    hover !== null &&
                                    hover.key.length === 2 &&
                                    hover.key !== shape.code;

                                return (
                                    <path
                                        key={shape.code}
                                        d={shape.d}
                                        fill={
                                            count > 0
                                                ? `var(--viz-seq-${step(count)})`
                                                : 'var(--viz-land)'
                                        }
                                        vectorEffect="non-scaling-stroke"
                                        opacity={dimmed ? 0.7 : 1}
                                        className="transition-opacity duration-150"
                                        onPointerMove={(event) =>
                                            fromPointer(event, {
                                                key: shape.code,
                                                title: countryName(shape.code),
                                                detail: null,
                                                count,
                                            })
                                        }
                                    />
                                );
                            })}
                        </g>
                        <g>
                            {placed.map((city) => {
                                const r = radius(city.count) / scale;
                                const key = `${city.country}|${city.region}|${city.city}`;
                                const next = {
                                    key,
                                    title: city.city,
                                    detail: cityDetail(city) || null,
                                    count: city.count,
                                };

                                return (
                                    <g
                                        key={key}
                                        tabIndex={0}
                                        role="img"
                                        aria-label={`${city.city}${next.detail ? `, ${next.detail}` : ''}: ${city.count.toLocaleString()} ${unit}`}
                                        className="cursor-default outline-none [&:focus-visible>circle:last-child]:stroke-foreground"
                                        onPointerMove={(event) =>
                                            fromPointer(event, next)
                                        }
                                        onFocus={() =>
                                            show(
                                                city.x * scale,
                                                city.y * scale,
                                                next,
                                            )
                                        }
                                        onBlur={() => setHover(null)}
                                    >
                                        <circle
                                            cx={city.x}
                                            cy={city.y}
                                            r={r * 1.9}
                                            fill="url(#world-map-glow)"
                                            pointerEvents="none"
                                        />
                                        <circle
                                            cx={city.x}
                                            cy={city.y}
                                            r={r}
                                            fill="var(--viz-2)"
                                            fillOpacity={0.8}
                                            stroke="var(--background)"
                                            strokeWidth={1.5 / scale}
                                        />
                                    </g>
                                );
                            })}
                        </g>
                    </svg>
                )}
                {hover && (
                    <div
                        className="pointer-events-none absolute z-10 rounded-md border surface-floating px-2.5 py-1.5 text-xs whitespace-nowrap"
                        style={{
                            top: Math.max(4, hover.y - 12),
                            ...(hover.x > width / 2
                                ? { right: width - hover.x + 14 }
                                : { left: hover.x + 14 }),
                        }}
                        role="status"
                    >
                        <div className="font-medium">{hover.title}</div>
                        {hover.detail && (
                            <div className="text-muted-foreground">
                                {hover.detail}
                            </div>
                        )}
                        <div className="tabular-nums">
                            {hover.count.toLocaleString()} {unit}
                        </div>
                    </div>
                )}
            </div>
            <DataTable
                caption={`${title}: countries`}
                summary="Show countries table"
                columns={['Country', 'Visitors']}
                rows={countries.map((row) => [
                    countryName(row.label),
                    row.count.toLocaleString(),
                ])}
            />
            {cities.length > 0 && (
                <DataTable
                    caption={`${title}: cities`}
                    summary="Show cities table"
                    columns={['City', 'Region', 'Country', 'Visitors']}
                    rows={cities.map((city) => [
                        city.city,
                        city.region ?? '—',
                        countryName(city.country),
                        city.count.toLocaleString(),
                    ])}
                />
            )}
        </figure>
    );
}

function Legend({ max, hasCities }: { max: number; hasCities: boolean }) {
    return (
        <span className="flex items-center gap-3 text-xs font-normal text-muted-foreground">
            <span className="flex items-center gap-1.5">
                <span className="tabular-nums">1</span>
                <span className="flex gap-0.5" aria-hidden>
                    {Array.from({ length: STEPS }, (_, i) => (
                        <span
                            key={i}
                            className="h-2 w-4 first:rounded-l-sm last:rounded-r-sm"
                            style={{ background: `var(--viz-seq-${i + 1})` }}
                        />
                    ))}
                </span>
                <span className="tabular-nums">{max.toLocaleString()}</span>
                <span className="sr-only">visitors per country</span>
            </span>
            {hasCities && (
                <span className="flex items-center gap-1.5">
                    <span
                        className="size-2.5 rounded-full"
                        style={{ background: 'var(--viz-2)' }}
                        aria-hidden
                    />
                    City
                </span>
            )}
        </span>
    );
}
