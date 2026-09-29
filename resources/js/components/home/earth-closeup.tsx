/**
 * An easter egg in the Sol system: hover over the Earth and it swoops up to
 * fill the view, turning under drifting clouds while the ISS, a 1980s Space
 * Shuttle, Hubble, a few satellites, a Starlink train, and Starman's Roadster
 * circle it (none of it to scale). Then it shrinks back into its orbit.
 *
 * The globe is drawn pixel by pixel from flat maps of the land, the clouds,
 * and city lights, which are painted once, the first time it's shown.
 */

/** Size of the close-up's canvas, and the Earth's radius in it (CSS pixels). */
export const EARTH_CLOSEUP_SIZE = 720;
export const EARTH_CLOSEUP_RADIUS = 140;

/** How long the Earth takes to grow, how long it stays big, and how long it takes to shrink back (seconds). */
export const EARTH_SHOW = { grow: 1.6, hold: 11, shrink: 1.4 };

const MAP_WIDTH = 1024;
const MAP_HEIGHT = 512;

/** Longitude facing us when the show starts, and how fast the Earth turns (degrees, degrees per second). */
const START_LONGITUDE = 25;
const TURN_SPEED = 9;

/** Clouds drift a little faster than the ground turns (degrees per second). */
const CLOUD_DRIFT = 2.5;

/** How far the north pole leans right, and how far it tips toward us (radians). */
const AXIAL_TILT = 0.41;
const VIEW_LATITUDE = 0.3;

/** Where the sunlight comes from: upper left, in front. */
const SUN = normalize(-0.55, 0.35, 0.76);

type LatLon = [lat: number, lon: number];

const CONTINENTS: LatLon[][] = [
    // North America
    [
        [70, -165],
        [72, -140],
        [69, -110],
        [72, -95],
        [75, -85],
        [68, -80],
        [62, -75],
        [60, -65],
        [52, -56],
        [47, -53],
        [45, -65],
        [41, -70],
        [35, -76],
        [30, -81],
        [25, -80],
        [30, -84],
        [30, -90],
        [28, -97],
        [22, -97],
        [19, -95],
        [21, -87],
        [16, -88],
        [15, -84],
        [9, -79],
        [8, -77],
        [8, -82],
        [13, -88],
        [16, -95],
        [20, -105],
        [23, -110],
        [31, -113],
        [30, -116],
        [35, -121],
        [40, -124],
        [48, -125],
        [55, -131],
        [59, -139],
        [60, -147],
        [57, -155],
        [54, -165],
        [60, -164],
        [65, -168],
    ],
    // South America
    [
        [12, -72],
        [11, -64],
        [7, -58],
        [4, -51],
        [-2, -44],
        [-5, -35],
        [-10, -36],
        [-15, -39],
        [-23, -42],
        [-27, -48],
        [-34, -53],
        [-38, -57],
        [-41, -63],
        [-47, -66],
        [-52, -69],
        [-55, -68],
        [-54, -73],
        [-46, -75],
        [-37, -73],
        [-30, -71],
        [-18, -70],
        [-14, -76],
        [-5, -81],
        [1, -80],
        [7, -78],
    ],
    // Europe and Asia
    [
        [36, -9],
        [43, -9],
        [44, -1],
        [48, -4],
        [51, 2],
        [54, 8],
        [57, 8],
        [59, 5],
        [63, 5],
        [68, 14],
        [71, 25],
        [70, 32],
        [67, 41],
        [69, 55],
        [73, 70],
        [76, 90],
        [77, 105],
        [73, 125],
        [72, 140],
        [70, 160],
        [66, 179],
        [62, 175],
        [59, 163],
        [53, 158],
        [57, 155],
        [59, 142],
        [54, 136],
        [48, 140],
        [43, 132],
        [39, 128],
        [35, 129],
        [35, 126],
        [38, 125],
        [40, 121],
        [37, 122],
        [31, 122],
        [25, 119],
        [22, 114],
        [21, 108],
        [16, 108],
        [10, 106],
        [8, 104],
        [13, 100],
        [7, 100],
        [1, 104],
        [4, 101],
        [10, 98],
        [16, 97],
        [20, 93],
        [22, 89],
        [19, 85],
        [15, 80],
        [8, 77],
        [13, 74],
        [20, 73],
        [23, 68],
        [25, 62],
        [25, 57],
        [22, 60],
        [17, 56],
        [13, 44],
        [16, 42],
        [22, 39],
        [28, 34],
        [31, 32],
        [34, 35],
        [36, 36],
        [37, 30],
        [40, 27],
        [40, 23],
        [38, 22],
        [40, 19],
        [42, 19],
        [45, 13],
        [41, 16],
        [38, 16],
        [40, 14],
        [44, 9],
        [43, 4],
        [40, 0],
        [37, -2],
    ],
    // Africa
    [
        [37, 10],
        [33, 11],
        [32, 20],
        [31, 30],
        [27, 34],
        [22, 37],
        [15, 39],
        [12, 43],
        [11, 51],
        [4, 48],
        [-2, 41],
        [-10, 40],
        [-17, 38],
        [-25, 35],
        [-30, 31],
        [-34, 26],
        [-35, 20],
        [-29, 16],
        [-22, 14],
        [-12, 13],
        [-6, 12],
        [-1, 9],
        [4, 9],
        [5, 3],
        [5, -4],
        [5, -8],
        [8, -13],
        [12, -17],
        [16, -17],
        [21, -17],
        [26, -15],
        [30, -10],
        [35, -6],
    ],
    // Madagascar
    [
        [-12, 49],
        [-16, 50],
        [-25, 47],
        [-24, 44],
        [-17, 44],
    ],
    // Great Britain
    [
        [58, -5],
        [58, -2],
        [53, 1],
        [51, 1],
        [50, -5],
        [54, -3],
    ],
    // Japan
    [
        [45, 142],
        [41, 141],
        [36, 141],
        [34, 136],
        [33, 130],
        [35, 133],
        [38, 139],
        [41, 140],
    ],
    // Indonesia and New Guinea
    [
        [5, 95],
        [-6, 106],
        [-8, 114],
        [-8, 125],
        [-3, 135],
        [-9, 147],
        [-6, 150],
        [-1, 134],
        [1, 125],
        [-4, 119],
        [1, 110],
        [2, 101],
    ],
    // Australia
    [
        [-11, 131],
        [-12, 136],
        [-15, 136],
        [-11, 142],
        [-18, 146],
        [-24, 152],
        [-28, 153],
        [-37, 150],
        [-39, 146],
        [-38, 140],
        [-35, 136],
        [-32, 134],
        [-32, 127],
        [-35, 117],
        [-31, 115],
        [-22, 114],
        [-19, 121],
        [-14, 127],
    ],
    // New Zealand
    [
        [-35, 173],
        [-41, 176],
        [-46, 170],
        [-44, 168],
        [-40, 173],
    ],
];

const GREENLAND: LatLon[] = [
    [83, -35],
    [81, -15],
    [75, -18],
    [70, -22],
    [65, -38],
    [60, -43],
    [64, -51],
    [70, -54],
    [76, -68],
    [79, -72],
    [82, -60],
];

/** Patches painted over the land: forests, deserts, and tundra (center, radius in degrees, color). */
const LAND_PATCHES: [
    lat: number,
    lon: number,
    latRadius: number,
    lonRadius: number,
    color: string,
][] = [
    [-5, -62, 10, 13, '#2A6B2C'],
    [0, 22, 7, 10, '#2A6B2C'],
    [60, 95, 7, 45, '#3B6A38'],
    [55, -100, 6, 25, '#3B6A38'],
    [0, 110, 6, 16, '#2E6E30'],
    [22, 12, 9, 26, '#D8BA7C'],
    [24, 46, 8, 9, '#D5B476'],
    [42, 100, 5, 16, '#C8AC78'],
    [-25, 131, 7, 14, '#CF9E62'],
    [-23, 21, 5, 6, '#C9AB70'],
    [34, -111, 5, 7, '#C7A26A'],
    [-24, -69, 6, 2, '#C4A06A'],
    [69, 60, 5, 60, '#8E9B7A'],
    [68, -115, 5, 35, '#8E9B7A'],
];

type Maps = {
    land: Uint8ClampedArray;
    clouds: Uint8ClampedArray;
    lights: Uint8ClampedArray;
};

type Globe = {
    canvas: HTMLCanvasElement;
    context: CanvasRenderingContext2D;
    image: ImageData;
    /** For each pixel inside the disk: where it is in `image`, and what it sees. */
    pixel: Int32Array;
    longitude: Float32Array;
    row: Int32Array;
    sunlight: Float32Array;
    glint: Float32Array;
    rim: Float32Array;
    edge: Float32Array;
};

type Orbiter = {
    name?: string;
    draw: (context: CanvasRenderingContext2D, size: number) => void;
    /** Orbit radius (in Earth radii), how open it looks, and how it's turned on screen. */
    radius: number;
    open: number;
    tilt: number;
    period: number;
    phase: number;
    size: number;
    /** Tumbles on its own instead of pointing where it's going (radians per second). */
    tumble?: number;
    showsOrbit?: boolean;
};

let maps: Maps | null = null;
let globe: Globe | null = null;
let backdropStars: { x: number; y: number; size: number; alpha: number }[] = [];

function normalize(x: number, y: number, z: number) {
    const length = Math.hypot(x, y, z);

    return { x: x / length, y: y / length, z: z / length };
}

function clamp(value: number, min = 0, max = 1): number {
    return Math.min(max, Math.max(min, value));
}

function smoothstep(from: number, to: number, value: number): number {
    const t = clamp((value - from) / (to - from));

    return t * t * (3 - 2 * t);
}

function mapPoint([lat, lon]: LatLon): [number, number] {
    return [((lon + 180) / 360) * MAP_WIDTH, ((90 - lat) / 180) * MAP_HEIGHT];
}

/** An outline on the flat map, with its corners rounded off. */
function outlinePath(path: Path2D, outline: LatLon[]) {
    const points = outline.map(mapPoint);
    const middle = (a: [number, number], b: [number, number]) => [
        (a[0] + b[0]) / 2,
        (a[1] + b[1]) / 2,
    ];
    const start = middle(points[points.length - 1], points[0]);

    path.moveTo(start[0], start[1]);

    points.forEach((point, index) => {
        const next = middle(point, points[(index + 1) % points.length]);
        path.quadraticCurveTo(point[0], point[1], next[0], next[1]);
    });

    path.closePath();
}

function mapCanvas() {
    const canvas = document.createElement('canvas');
    canvas.width = MAP_WIDTH;
    canvas.height = MAP_HEIGHT;

    return canvas.getContext('2d', { willReadFrequently: true })!;
}

function random(min: number, max: number): number {
    return min + Math.random() * (max - min);
}

function paintLand(land: Path2D): Uint8ClampedArray {
    const context = mapCanvas();
    const ocean = context.createLinearGradient(0, 0, 0, MAP_HEIGHT);
    ocean.addColorStop(0, '#0A2448');
    ocean.addColorStop(0.5, '#1156A3');
    ocean.addColorStop(1, '#0A2448');
    context.fillStyle = ocean;
    context.fillRect(0, 0, MAP_WIDTH, MAP_HEIGHT);

    // Shallow water along the coasts.
    context.strokeStyle = 'rgba(60, 150, 205, 0.55)';
    context.lineWidth = 7;
    context.stroke(land);

    context.fillStyle = '#4E8A3E';
    context.fill(land);

    context.save();
    context.clip(land);

    for (const [lat, lon, latRadius, lonRadius, color] of LAND_PATCHES) {
        const [x, y] = mapPoint([lat, lon]);
        const gradient = context.createRadialGradient(0, 0, 0, 0, 0, 1);
        gradient.addColorStop(0, color);
        gradient.addColorStop(0.6, color);
        gradient.addColorStop(1, `${color}00`);

        context.save();
        context.translate(x, y);
        context.scale(
            (lonRadius / 360) * MAP_WIDTH,
            (latRadius / 180) * MAP_HEIGHT,
        );
        context.fillStyle = gradient;
        context.beginPath();
        context.arc(0, 0, 1, 0, Math.PI * 2);
        context.fill();
        context.restore();
    }

    // Speckle the land so it doesn't look flat.
    for (let index = 0; index < 9000; index++) {
        context.fillStyle =
            Math.random() < 0.5
                ? 'rgba(20, 40, 10, 0.14)'
                : 'rgba(255, 240, 200, 0.08)';
        context.fillRect(
            random(0, MAP_WIDTH),
            random(0, MAP_HEIGHT),
            random(2, 7),
            random(1, 3),
        );
    }

    context.restore();

    // Ice: Greenland, the Arctic, and Antarctica.
    context.fillStyle = '#EEF4F8';
    const greenland = new Path2D();
    outlinePath(greenland, GREENLAND);
    context.fill(greenland);

    for (const [edge, pole] of [
        [-68, MAP_HEIGHT],
        [81, 0],
    ]) {
        context.beginPath();
        context.moveTo(0, pole);

        for (let x = 0; x <= MAP_WIDTH; x += 16) {
            const wobble =
                Math.sin(x * 0.021) * 2.5 + Math.sin(x * 0.057 + 1) * 1.5;
            context.lineTo(x, mapPoint([edge + wobble, 0])[1]);
        }

        context.lineTo(MAP_WIDTH, pole);
        context.fill();
    }

    return context.getImageData(0, 0, MAP_WIDTH, MAP_HEIGHT).data;
}

/** Cities glowing on the night side, clustered where most people live. */
function paintLights(
    land: Path2D,
    landContext: CanvasRenderingContext2D,
): Uint8ClampedArray {
    const context = mapCanvas();
    context.fillStyle = '#000000';
    context.fillRect(0, 0, MAP_WIDTH, MAP_HEIGHT);

    const busy: [
        latFrom: number,
        latTo: number,
        lonFrom: number,
        lonTo: number,
    ][] = [
        [42, 58, -5, 35],
        [28, 46, -98, -70],
        [9, 30, 70, 90],
        [22, 40, 104, 122],
        [32, 42, 130, 141],
        [-35, -15, -58, -38],
        [26, 32, 29, 33],
    ];

    for (let index = 0; index < 1100; index++) {
        const area = index % 3 === 0 ? null : busy[index % busy.length];
        const lat = area ? random(area[0], area[1]) : random(-45, 62);
        const lon = area ? random(area[2], area[3]) : random(-180, 180);
        const [x, y] = mapPoint([lat, lon]);

        if (!landContext.isPointInPath(land, x, y)) {
            continue;
        }

        for (let dot = 0; dot < 1 + Math.random() * 6; dot++) {
            context.globalAlpha = random(0.35, 1);
            context.fillStyle = '#FFFFFF';
            context.beginPath();
            context.arc(
                x + random(-4, 4),
                y + random(-2.5, 2.5),
                random(0.5, 1.5),
                0,
                Math.PI * 2,
            );
            context.fill();
        }
    }

    return context.getImageData(0, 0, MAP_WIDTH, MAP_HEIGHT).data;
}

/** Cloud bands where the weather actually is, plus a few spiraling storms. */
function paintClouds(): Uint8ClampedArray {
    const context = mapCanvas();
    const puff = (
        x: number,
        y: number,
        width: number,
        height: number,
        alpha: number,
    ) => {
        for (const wrap of [-MAP_WIDTH, 0, MAP_WIDTH]) {
            const gradient = context.createRadialGradient(0, 0, 0, 0, 0, 1);
            gradient.addColorStop(0, `rgba(255, 255, 255, ${alpha})`);
            gradient.addColorStop(0.5, `rgba(255, 255, 255, ${alpha * 0.6})`);
            gradient.addColorStop(1, 'rgba(255, 255, 255, 0)');

            context.save();
            context.translate(x + wrap, y);
            context.scale(width, height);
            context.fillStyle = gradient;
            context.beginPath();
            context.arc(0, 0, 1, 0, Math.PI * 2);
            context.fill();
            context.restore();
        }
    };

    const bands = [
        { lat: 6, spread: 7, weight: 3 },
        { lat: 50, spread: 11, weight: 4 },
        { lat: -50, spread: 11, weight: 4 },
        { lat: 25, spread: 14, weight: 1 },
        { lat: -25, spread: 14, weight: 1 },
        { lat: 68, spread: 8, weight: 1.5 },
        { lat: -65, spread: 6, weight: 1.5 },
    ];
    const totalWeight = bands.reduce((sum, band) => sum + band.weight, 0);

    for (let index = 0; index < 900; index++) {
        let roll = Math.random() * totalWeight;
        const band =
            bands.find((candidate) => (roll -= candidate.weight) <= 0) ??
            bands[0];
        const lat = band.lat + (Math.random() * 2 - 1) * band.spread;
        const [x, y] = mapPoint([lat, random(-180, 180)]);

        puff(x, y, random(8, 34), random(3, 11), random(0.2, 0.6));
    }

    for (let storm = 0; storm < 6; storm++) {
        const north = storm % 2 === 0;
        const [x, y] = mapPoint([
            (north ? 1 : -1) * random(30, 55),
            random(-180, 180),
        ]);
        const spin = north ? -1 : 1;

        for (let arm = 0; arm < 2; arm++) {
            for (let step = 0; step < 26; step++) {
                const angle = arm * Math.PI + spin * step * 0.28;
                const distance = 1.5 + step * 1.1;

                puff(
                    x + Math.cos(angle) * distance * 1.6,
                    y + Math.sin(angle) * distance,
                    5 - step * 0.12,
                    3 - step * 0.07,
                    0.55,
                );
            }
        }
    }

    return context.getImageData(0, 0, MAP_WIDTH, MAP_HEIGHT).data;
}

function paintMaps(): Maps {
    const land = new Path2D();

    for (const outline of CONTINENTS) {
        outlinePath(land, outline);
    }

    return {
        land: paintLand(land),
        clouds: paintClouds(),
        lights: paintLights(land, mapCanvas()),
    };
}

/**
 * Works out, once, which spot on the Earth each pixel of the globe shows and
 * how much sunlight falls on it. Each frame then only has to look it up.
 */
function buildGlobe(radius: number): Globe {
    const size = Math.ceil(radius * 2) + 2;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const context = canvas.getContext('2d')!;
    const image = context.createImageData(size, size);
    const pixel: number[] = [];
    const longitude: number[] = [];
    const row: number[] = [];
    const sunlight: number[] = [];
    const glint: number[] = [];
    const rim: number[] = [];
    const edge: number[] = [];
    const halfway = normalize(SUN.x, SUN.y, SUN.z + 1);
    const tiltCos = Math.cos(AXIAL_TILT);
    const tiltSin = Math.sin(AXIAL_TILT);
    const viewCos = Math.cos(VIEW_LATITUDE);
    const viewSin = Math.sin(VIEW_LATITUDE);

    for (let y = 0; y < size; y++) {
        for (let x = 0; x < size; x++) {
            const offsetX = x + 0.5 - size / 2;
            const offsetY = y + 0.5 - size / 2;
            const distance = Math.hypot(offsetX, offsetY);

            if (distance > radius + 0.5) {
                continue;
            }

            // A point on the sphere facing us, with y pointing up.
            let normalX = offsetX / radius;
            let normalY = -offsetY / radius;
            const flat = normalX * normalX + normalY * normalY;

            if (flat > 1) {
                normalX /= Math.sqrt(flat);
                normalY /= Math.sqrt(flat);
            }

            const normalZ = Math.sqrt(
                Math.max(0, 1 - normalX * normalX - normalY * normalY),
            );

            // Turn it into the Earth's own frame: undo the lean, then the tip toward us.
            const east = normalX * tiltCos - normalY * tiltSin;
            const up = normalX * tiltSin + normalY * tiltCos;
            const north = up * viewCos + normalZ * viewSin;
            const toward = -up * viewSin + normalZ * viewCos;

            const lat = Math.asin(clamp(north, -1, 1));
            const lon = Math.atan2(east, toward);

            pixel.push((y * size + x) * 4);
            longitude.push((lon / (Math.PI * 2)) * MAP_WIDTH);
            row.push(
                Math.min(
                    MAP_HEIGHT - 1,
                    Math.floor((0.5 - lat / Math.PI) * MAP_HEIGHT),
                ) * MAP_WIDTH,
            );
            sunlight.push(normalX * SUN.x + normalY * SUN.y + normalZ * SUN.z);
            glint.push(
                Math.max(
                    0,
                    normalX * halfway.x +
                        normalY * halfway.y +
                        normalZ * halfway.z,
                ) ** 60,
            );
            rim.push((1 - normalZ) ** 3);
            edge.push(clamp(radius + 0.5 - distance));
        }
    }

    return {
        canvas,
        context,
        image,
        pixel: Int32Array.from(pixel),
        longitude: Float32Array.from(longitude),
        row: Int32Array.from(row),
        sunlight: Float32Array.from(sunlight),
        glint: Float32Array.from(glint),
        rim: Float32Array.from(rim),
        edge: Float32Array.from(edge),
    };
}

/** Paints the globe as it looks `seconds` into the show. */
function paintGlobe(
    globe: Globe,
    { land, clouds, lights }: Maps,
    seconds: number,
) {
    const data = globe.image.data;
    const facing = START_LONGITUDE - TURN_SPEED * seconds;
    const landShift = (facing / 360 + 0.5) * MAP_WIDTH;
    const cloudShift =
        ((facing - CLOUD_DRIFT * seconds) / 360 + 0.5) * MAP_WIDTH;

    for (let index = 0; index < globe.pixel.length; index++) {
        let landColumn =
            Math.floor(globe.longitude[index] + landShift) % MAP_WIDTH;
        let cloudColumn =
            Math.floor(globe.longitude[index] + cloudShift) % MAP_WIDTH;

        if (landColumn < 0) {
            landColumn += MAP_WIDTH;
        }

        if (cloudColumn < 0) {
            cloudColumn += MAP_WIDTH;
        }

        const at = (globe.row[index] + landColumn) * 4;
        const cloud = clouds[(globe.row[index] + cloudColumn) * 4 + 3] / 255;
        const sun = globe.sunlight[index];
        const lit = sun > 0 ? sun : 0;
        const day = smoothstep(-0.12, 0.22, sun);
        const shade = 0.05 + 1.05 * lit;
        const red = land[at];
        const green = land[at + 1];
        const blue = land[at + 2];
        const isWater = blue > red + 30 && blue > green;
        const glint = isWater ? globe.glint[index] * 220 * (1 - cloud) : 0;
        const cloudShade = 255 * (0.04 + lit);
        const city = (1 - day) * (1 - cloud * 0.8) * (lights[at] / 255);
        const air =
            globe.rim[index] * (0.2 + 0.8 * smoothstep(-0.25, 0.35, sun));
        const out = globe.pixel[index];

        data[out] =
            (red * shade + glint) * (1 - cloud) +
            cloudShade * cloud +
            city * 255 +
            air * 90;
        data[out + 1] =
            (green * shade + glint) * (1 - cloud) +
            cloudShade * cloud +
            city * 190 +
            air * 170;
        data[out + 2] =
            (blue * shade + glint) * (1 - cloud) +
            cloudShade * cloud +
            city * 110 +
            air * 255;
        data[out + 3] = globe.edge[index] * 255;
    }

    globe.context.putImageData(globe.image, 0, 0);
}

function drawSatellite(context: CanvasRenderingContext2D, size: number) {
    context.fillStyle = '#2C4F8F';
    context.fillRect(-2 * size, -11 * size, 4 * size, 8 * size);
    context.fillRect(-2 * size, 3 * size, 4 * size, 8 * size);
    context.fillStyle = '#8A8F99';
    context.fillRect(-0.4 * size, -3 * size, 0.8 * size, 6 * size);
    context.fillStyle = '#D9A441';
    context.fillRect(-2.2 * size, -2.2 * size, 4.4 * size, 4.4 * size);
    context.fillStyle = '#E8E8E8';
    context.beginPath();
    context.arc(2.8 * size, 0, 1.3 * size, 0, Math.PI * 2);
    context.fill();
}

function drawHubble(context: CanvasRenderingContext2D, size: number) {
    context.fillStyle = '#3B5FA8';
    context.fillRect(-1.2 * size, -10 * size, 2.4 * size, 7 * size);
    context.fillRect(-1.2 * size, 3 * size, 2.4 * size, 7 * size);
    context.fillStyle = '#9AA0A8';
    context.fillRect(-0.3 * size, -3 * size, 0.6 * size, 6 * size);
    context.fillStyle = '#D3D8DF';
    context.beginPath();
    context.roundRect(-7 * size, -2.3 * size, 14 * size, 4.6 * size, size);
    context.fill();
    context.fillStyle = '#C89B3C';
    context.fillRect(-4 * size, -2.3 * size, 1.4 * size, 4.6 * size);
    context.fillStyle = '#3A3F48';
    context.fillRect(6 * size, -2 * size, 1.2 * size, 4 * size);
}

/** The ISS: its long truss runs across its path, with four pairs of golden solar wings. */
function drawIss(context: CanvasRenderingContext2D, size: number) {
    context.fillStyle = '#9AA0A8';
    context.fillRect(-0.8 * size, -22 * size, 1.6 * size, 44 * size);

    [-20, -15.5, 15.5, 20].forEach((y, index) => {
        for (const side of [-1, 1]) {
            context.fillStyle = index % 2 === 0 ? '#B38A36' : '#94702A';
            context.fillRect(
                side > 0 ? 1.2 * size : -11.2 * size,
                (y - 1.6) * size,
                10 * size,
                3.2 * size,
            );
        }
    });

    context.fillStyle = '#E9ECEF';

    for (const y of [-8, 8]) {
        context.fillRect(-5 * size, (y - 0.8) * size, 10 * size, 1.6 * size);
    }

    context.fillStyle = '#DADDE2';
    context.beginPath();
    context.roundRect(
        -12 * size,
        -1.8 * size,
        24 * size,
        3.6 * size,
        1.5 * size,
    );
    context.fill();
    context.fillStyle = '#C6CBD1';
    context.fillRect(-2 * size, -4 * size, 4 * size, 8 * size);
    context.fillStyle = '#5B616A';
    context.fillRect(11.5 * size, -1 * size, 1.4 * size, 2 * size);
}

/** A 1980s Space Shuttle orbiter from above: white delta wings with black leading edges, payload bay, and tail. */
function drawShuttle(context: CanvasRenderingContext2D, size: number) {
    const wing = (side: number) => {
        context.beginPath();
        context.moveTo(7 * size, 2 * side * size);
        context.lineTo(-2 * size, 4.6 * side * size);
        context.lineTo(-11.5 * size, 9.8 * side * size);
        context.lineTo(-13 * size, 9.8 * side * size);
        context.lineTo(-13 * size, 2 * side * size);
        context.closePath();
    };

    for (const side of [-1, 1]) {
        wing(side);
        context.fillStyle = '#F2F2EE';
        context.fill();

        context.strokeStyle = '#1B1B1B';
        context.lineWidth = 0.9 * size;
        context.beginPath();
        context.moveTo(7 * size, 2 * side * size);
        context.lineTo(-2 * size, 4.6 * side * size);
        context.lineTo(-11.5 * size, 9.8 * side * size);
        context.stroke();

        context.strokeStyle = '#B9BCBF';
        context.lineWidth = 0.5 * size;
        context.beginPath();
        context.moveTo(-12.2 * size, 3 * side * size);
        context.lineTo(-12.2 * size, 9.4 * side * size);
        context.stroke();

        // The OMS pods by the tail.
        context.fillStyle = '#E6E6E2';
        context.beginPath();
        context.ellipse(
            -12 * size,
            2.3 * side * size,
            3 * size,
            1.2 * size,
            0,
            0,
            Math.PI * 2,
        );
        context.fill();
    }

    context.fillStyle = '#F5F5F2';
    context.beginPath();
    context.moveTo(15 * size, 0);
    context.quadraticCurveTo(14 * size, 2.1 * size, 10 * size, 2.1 * size);
    context.lineTo(-15 * size, 2.1 * size);
    context.lineTo(-15 * size, -2.1 * size);
    context.lineTo(10 * size, -2.1 * size);
    context.quadraticCurveTo(14 * size, -2.1 * size, 15 * size, 0);
    context.fill();

    context.fillStyle = '#2A2A2A';
    context.beginPath();
    context.ellipse(14.6 * size, 0, 0.8 * size, 0.9 * size, 0, 0, Math.PI * 2);
    context.fill();
    context.fillRect(9.5 * size, -1.3 * size, 1.4 * size, 2.6 * size);

    // Payload bay doors.
    context.strokeStyle = '#C9CCCF';
    context.lineWidth = 0.4 * size;
    context.strokeRect(-9 * size, -1.9 * size, 16 * size, 3.8 * size);
    context.beginPath();
    context.moveTo(-9 * size, 0);
    context.lineTo(7 * size, 0);
    context.stroke();

    context.fillStyle = '#D6D6D2';
    context.fillRect(-15 * size, -0.4 * size, 7 * size, 0.8 * size);
    context.fillStyle = '#1B1B1B';
    context.fillRect(-9 * size, -0.4 * size, 1 * size, 0.8 * size);

    context.fillStyle = '#4A4D52';

    for (const y of [-1.2, 0, 1.2]) {
        context.beginPath();
        context.arc(-15.6 * size, y * size, 0.8 * size, 0, Math.PI * 2);
        context.fill();
    }

    // A tiny flag on the left wing.
    context.fillStyle = '#B22234';
    context.fillRect(-7 * size, -6.2 * size, 2.4 * size, 1.4 * size);
    context.fillStyle = '#3C3B6E';
    context.fillRect(-5.6 * size, -6.2 * size, 1 * size, 0.7 * size);
}

/** Starman's cherry red Roadster, top down, with Starman at the wheel. */
function drawRoadster(context: CanvasRenderingContext2D, size: number) {
    context.fillStyle = '#111111';

    for (const [x, y] of [
        [-7, -5.2],
        [-7, 5.2],
        [6.5, -5.2],
        [6.5, 5.2],
    ]) {
        context.fillRect(
            (x - 2) * size,
            (y - 0.7) * size,
            4 * size,
            1.4 * size,
        );
    }

    const paint = context.createLinearGradient(0, -5 * size, 0, 5 * size);
    paint.addColorStop(0, '#E8354B');
    paint.addColorStop(0.5, '#B80F26');
    paint.addColorStop(1, '#7E0A1A');
    context.fillStyle = paint;
    context.beginPath();
    context.roundRect(-11 * size, -5 * size, 22 * size, 10 * size, 4 * size);
    context.fill();

    context.fillStyle = '#1A1D22';
    context.beginPath();
    context.moveTo(4.5 * size, -4 * size);
    context.lineTo(2.5 * size, -4 * size);
    context.lineTo(2.5 * size, 4 * size);
    context.lineTo(4.5 * size, 4 * size);
    context.quadraticCurveTo(5.5 * size, 0, 4.5 * size, -4 * size);
    context.fill();
    context.fillStyle = '#141414';
    context.beginPath();
    context.roundRect(
        -4.5 * size,
        -3.8 * size,
        7 * size,
        7.6 * size,
        1.5 * size,
    );
    context.fill();

    context.fillStyle = '#FFF4D6';
    context.fillRect(10 * size, -3.8 * size, 0.8 * size, 1.6 * size);
    context.fillRect(10 * size, 2.2 * size, 0.8 * size, 1.6 * size);

    // Starman, one arm resting on the door.
    context.fillStyle = '#F2F2F2';
    context.fillRect(-0.5 * size, -4.6 * size, 3.2 * size, 1 * size);
    context.beginPath();
    context.arc(-1 * size, -1.8 * size, 2 * size, 0, Math.PI * 2);
    context.fill();
    context.fillStyle = '#2A2D33';
    context.beginPath();
    context.arc(-0.2 * size, -1.8 * size, 1 * size, -1.2, 1.2);
    context.fill();
}

function drawStarlinkSatellite(
    context: CanvasRenderingContext2D,
    size: number,
) {
    context.fillStyle = '#FFFFFF';
    context.beginPath();
    context.arc(0, 0, 1.1 * size, 0, Math.PI * 2);
    context.fill();
}

const ORBITERS: Orbiter[] = [
    {
        name: 'ISS',
        draw: drawIss,
        radius: 1.24,
        open: 0.36,
        tilt: -0.22,
        period: 9,
        phase: 1.9,
        size: 0.8,
        showsOrbit: true,
    },
    {
        name: 'Space Shuttle',
        draw: drawShuttle,
        radius: 1.38,
        open: 0.28,
        tilt: 0.18,
        period: 11,
        phase: 0.7,
        size: 0.95,
        showsOrbit: true,
    },
    {
        name: 'Hubble',
        draw: drawHubble,
        radius: 1.52,
        open: 0.5,
        tilt: 0.55,
        period: 13,
        phase: 4,
        size: 0.85,
    },
    {
        draw: drawSatellite,
        radius: 1.64,
        open: 0.6,
        tilt: -0.7,
        period: 16,
        phase: 2.6,
        size: 0.75,
    },
    {
        draw: drawSatellite,
        radius: 1.8,
        open: 0.18,
        tilt: 0.04,
        period: 19,
        phase: 5.1,
        size: 0.7,
    },
    {
        draw: drawSatellite,
        radius: 1.46,
        open: 0.85,
        tilt: 1.25,
        period: 14,
        phase: 0.2,
        size: 0.7,
    },
    {
        name: 'Starman',
        draw: drawRoadster,
        radius: 2.05,
        open: 0.34,
        tilt: -0.12,
        period: 24,
        phase: 1.1,
        size: 0.95,
        tumble: 0.7,
        showsOrbit: true,
    },
    ...Array.from({ length: 14 }, (_, index) => ({
        name: index === 0 ? 'Starlink' : undefined,
        draw: drawStarlinkSatellite,
        radius: 1.13,
        open: 0.46,
        tilt: 0.38,
        period: 8,
        phase: 3.4 - index * 0.07,
        size: 1,
    })),
];

function orbitPoint(orbiter: Orbiter, angle: number) {
    const radius = orbiter.radius * EARTH_CLOSEUP_RADIUS;
    const flatX = Math.cos(angle) * radius;
    const flatY = Math.sin(angle) * radius * orbiter.open;
    const cos = Math.cos(orbiter.tilt);
    const sin = Math.sin(orbiter.tilt);

    return { x: flatX * cos - flatY * sin, y: flatX * sin + flatY * cos };
}

/**
 * Draws the close-up centered in its canvas, `seconds` into the show.
 * `reveal` (0 to 1) fades in the dark backdrop and everything in orbit as the
 * Earth grows. `pixelScale` is how many device pixels the globe gets per CSS
 * pixel.
 */
export function drawEarthCloseup(
    context: CanvasRenderingContext2D,
    pixelScale: number,
    seconds: number,
    reveal: number,
) {
    maps ??= paintMaps();

    if (
        !globe ||
        globe.canvas.width !==
            Math.ceil(EARTH_CLOSEUP_RADIUS * pixelScale * 2) + 2
    ) {
        globe = buildGlobe(EARTH_CLOSEUP_RADIUS * pixelScale);
    }

    if (backdropStars.length === 0) {
        backdropStars = Array.from({ length: 140 }, () => {
            const angle = random(0, Math.PI * 2);
            const distance =
                Math.sqrt(Math.random()) * EARTH_CLOSEUP_SIZE * 0.45;

            return {
                x: Math.cos(angle) * distance,
                y: Math.sin(angle) * distance,
                size: random(0.4, 1.3),
                alpha: random(0.3, 0.9),
            };
        });
    }

    const center = EARTH_CLOSEUP_SIZE / 2;
    const radius = EARTH_CLOSEUP_RADIUS;
    const backdropAlpha = smoothstep(0, 0.6, reveal);
    const orbitAlpha = smoothstep(0.55, 1, reveal);

    // Deep space behind the Earth, hiding the galaxy and black hole.
    const backdrop = context.createRadialGradient(
        center,
        center,
        0,
        center,
        center,
        center,
    );
    backdrop.addColorStop(0, 'rgba(3, 4, 10, 0.97)');
    backdrop.addColorStop(0.6, 'rgba(3, 4, 10, 0.9)');
    backdrop.addColorStop(1, 'rgba(3, 4, 10, 0)');
    context.globalAlpha = backdropAlpha;
    context.fillStyle = backdrop;
    context.fillRect(0, 0, EARTH_CLOSEUP_SIZE, EARTH_CLOSEUP_SIZE);

    context.fillStyle = '#FFFFFF';

    for (const star of backdropStars) {
        context.globalAlpha =
            star.alpha *
            backdropAlpha *
            (1 - Math.hypot(star.x, star.y) / center);
        context.fillRect(
            center + star.x,
            center + star.y,
            star.size,
            star.size,
        );
    }

    const placed = ORBITERS.map((orbiter) => {
        const angle = orbiter.phase + (seconds / orbiter.period) * Math.PI * 2;
        const point = orbitPoint(orbiter, angle);
        const ahead = orbitPoint(orbiter, angle + 0.01);
        const depth = Math.sin(angle);

        return {
            orbiter,
            x: center + point.x,
            y: center + point.y,
            depth,
            heading: orbiter.tumble
                ? seconds * orbiter.tumble
                : Math.atan2(ahead.y - point.y, ahead.x - point.x),
        };
    });

    const drawOrbitLines = (half: 'back' | 'front') => {
        const [from, to] =
            half === 'back' ? [Math.PI, Math.PI * 2] : [0, Math.PI];

        context.globalAlpha = 0.14 * orbitAlpha;
        context.strokeStyle = '#BFD8FF';
        context.lineWidth = 0.7;

        for (const orbiter of ORBITERS.filter(
            (candidate) => candidate.showsOrbit,
        )) {
            context.beginPath();
            context.ellipse(
                center,
                center,
                orbiter.radius * radius,
                orbiter.radius * radius * orbiter.open,
                orbiter.tilt,
                from,
                to,
            );
            context.stroke();
        }
    };

    const drawOrbiters = (inFront: boolean) => {
        for (const { orbiter, x, y, depth, heading } of placed) {
            if (depth > 0 !== inFront) {
                continue;
            }

            context.save();
            context.globalAlpha = orbitAlpha;
            context.translate(x, y);
            context.rotate(heading);
            orbiter.draw(context, orbiter.size * (1 + 0.12 * depth));
            context.restore();

            const isHidden =
                !inFront && Math.hypot(x - center, y - center) < radius * 1.05;

            if (orbiter.name && !isHidden) {
                context.globalAlpha = 0.75 * orbitAlpha;
                context.font = '500 10px "Instrument Sans", sans-serif';
                context.textAlign = 'left';
                context.fillStyle = '#DCEBFF';
                context.fillText(orbiter.name, x + 12, y - 10);
            }
        }
    };

    drawOrbitLines('back');
    drawOrbiters(false);

    context.globalAlpha = 1;
    paintGlobe(globe, maps, seconds);
    context.drawImage(
        globe.canvas,
        center - globe.canvas.width / pixelScale / 2,
        center - globe.canvas.height / pixelScale / 2,
        globe.canvas.width / pixelScale,
        globe.canvas.height / pixelScale,
    );

    // The glow of the atmosphere around the edge.
    const halo = context.createRadialGradient(
        center,
        center,
        radius,
        center,
        center,
        radius * 1.3,
    );
    halo.addColorStop(0, 'rgba(110, 180, 255, 0.5)');
    halo.addColorStop(0.3, 'rgba(70, 140, 255, 0.16)');
    halo.addColorStop(1, 'rgba(60, 120, 255, 0)');
    context.save();
    context.globalCompositeOperation = 'lighter';
    context.fillStyle = halo;
    context.beginPath();
    context.arc(center, center, radius * 1.3, 0, Math.PI * 2);
    context.arc(center, center, radius, 0, Math.PI * 2, true);
    context.fill();
    context.restore();

    drawOrbitLines('front');
    drawOrbiters(true);
    context.globalAlpha = 1;
}

/** How big the Earth is during the show, from 0 (its usual dot) to 1 (fully zoomed in), `seconds` after it starts. Null once it's over. */
export function earthShowProgress(seconds: number): number | null {
    const { grow, hold, shrink } = EARTH_SHOW;
    const ease = (t: number) => t * t * (3 - 2 * t);

    if (seconds < 0 || seconds > grow + hold + shrink) {
        return null;
    }

    if (seconds < grow) {
        return ease(seconds / grow);
    }

    if (seconds < grow + hold) {
        return 1;
    }

    return ease(1 - (seconds - grow - hold) / shrink);
}
