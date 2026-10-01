/**
 * The Natural Earth projection (Šavrič et al.), into a 1000-wide box from 84°N to 58°S (no Antarctica).
 * scripts/world-map.mjs draws the country shapes with it, and the Growth map places cities with it.
 */
const NORTH = 84;
const SOUTH = -58;
const HALF_WIDTH = 0.8707 * Math.PI;

export const MAP_WIDTH = 1000;

const SCALE = MAP_WIDTH / (2 * HALF_WIDTH);
const TOP = raw(0, NORTH)[1];

export const MAP_HEIGHT = Number(((TOP - raw(0, SOUTH)[1]) * SCALE).toFixed(1));

function raw(lon: number, lat: number): [number, number] {
    const lambda = (lon * Math.PI) / 180;
    const phi = (lat * Math.PI) / 180;
    const phi2 = phi * phi;
    const phi4 = phi2 * phi2;

    return [
        lambda *
            (0.8707 -
                0.131979 * phi2 +
                phi4 *
                    (-0.013791 + phi4 * (0.003971 * phi2 - 0.001529 * phi4))),
        phi *
            (1.007226 +
                phi2 *
                    (0.015085 +
                        phi4 *
                            (-0.044475 + 0.028874 * phi2 - 0.005916 * phi4))),
    ];
}

/** Map coordinates (x right, y down) for a longitude and latitude. */
export function naturalEarth(lon: number, lat: number): [number, number] {
    const [x, y] = raw(lon, lat);

    return [(x + HALF_WIDTH) * SCALE, (TOP - y) * SCALE];
}

/** The projection's outline (its rounded sides, cut flat at the top and bottom): the sea behind the countries. */
export function mapOutline(): string {
    const east = Array.from({ length: 29 }, (_, i) =>
        naturalEarth(180, NORTH - ((NORTH - SOUTH) * i) / 28),
    );
    const west = east.map(([x, y]) => [MAP_WIDTH - x, y]).reverse();

    return (
        'M' +
        [...east, ...west]
            .map(([x, y]) => `${x.toFixed(1)},${y.toFixed(1)}`)
            .join('L') +
        'Z'
    );
}
