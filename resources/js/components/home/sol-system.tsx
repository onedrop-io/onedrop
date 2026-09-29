/**
 * Our solar system and the Voyager and Pioneer probes, drawn into the galaxy around
 * the black hole. Nothing is to scale: the planets are big and close together
 * so you can make them out, and a year on Earth takes about 15 seconds.
 *
 * Positions are in the galaxy's plane, in pixels at full size, relative to the
 * Sun. `project` turns a plane offset into a screen offset (with depth) using
 * the galaxy's current tilt, so the system tilts along with everything else.
 */

type Project = (
    x: number,
    z: number,
) => { x: number; y: number; depth: number };

type Ring = {
    /** Ring radius as a multiple of the planet's radius, and its brightness. */
    radius: number;
    width: number;
    color: string;
    alpha: number;
};

type Planet = {
    name: string;
    orbit: number;
    size: number;
    period: number;
    phase: number;
    colors: [light: string, base: string, dark: string];
    /** How the rings sit on screen: extra turn from the galaxy's tilt, and how open they look. */
    ringTilt?: number;
    ringOpen?: number;
    rings?: Ring[];
    bands?: { at: number; height: number; color: string; alpha: number }[];
};

export const SOL_PLANETS: Planet[] = [
    {
        name: 'Mercury',
        orbit: 11,
        size: 1.3,
        period: 7,
        phase: 1.2,
        colors: ['#EFE7E1', '#9C918A', '#2E2926'],
    },
    {
        name: 'Venus',
        orbit: 16,
        size: 2.1,
        period: 11,
        phase: 4.1,
        colors: ['#FFF3D6', '#E6C27E', '#5A3E1C'],
    },
    {
        name: 'Earth',
        orbit: 21,
        size: 3.2,
        period: 15,
        phase: 2.4,
        colors: ['#A8DCFF', '#2E6FD6', '#081A42'],
    },
    {
        name: 'Mars',
        orbit: 27,
        size: 1.8,
        period: 22,
        phase: 5.3,
        colors: ['#FFB690', '#C8553A', '#3E150C'],
    },
    {
        name: 'Jupiter',
        orbit: 37,
        size: 5.6,
        period: 40,
        phase: 0.6,
        colors: ['#F7E6C8', '#D9B27F', '#4E321D'],
        bands: [
            { at: -0.55, height: 0.16, color: '#B9784A', alpha: 0.6 },
            { at: -0.18, height: 0.2, color: '#A8663C', alpha: 0.55 },
            { at: 0.22, height: 0.18, color: '#B9784A', alpha: 0.55 },
            { at: 0.58, height: 0.14, color: '#C79A6C', alpha: 0.5 },
        ],
        ringTilt: 0.05,
        ringOpen: 0.22,
        rings: [{ radius: 1.55, width: 0.12, color: '#E8D2B0', alpha: 0.3 }],
    },
    {
        name: 'Saturn',
        orbit: 49,
        size: 4.8,
        period: 58,
        phase: 3.5,
        colors: ['#FFF1CF', '#E2C58C', '#5E4A28'],
        bands: [
            { at: -0.35, height: 0.16, color: '#C9A866', alpha: 0.4 },
            { at: 0.25, height: 0.14, color: '#CBAE74', alpha: 0.35 },
        ],
        ringTilt: 0.28,
        ringOpen: 0.38,
        rings: [
            { radius: 1.42, width: 0.22, color: '#B89F76', alpha: 0.55 },
            { radius: 1.78, width: 0.36, color: '#EBD9AE', alpha: 0.9 },
            { radius: 2.2, width: 0.2, color: '#CDB68A', alpha: 0.6 },
        ],
    },
    {
        name: 'Uranus',
        orbit: 59,
        size: 3.3,
        period: 80,
        phase: 5.9,
        colors: ['#E4FDFF', '#8FDCE3', '#1F4A55'],
        // Uranus is tipped on its side, so its rings stand almost upright.
        ringTilt: 1.45,
        ringOpen: 0.3,
        rings: [
            { radius: 1.75, width: 0.1, color: '#C8F4F7', alpha: 0.6 },
            { radius: 2.05, width: 0.08, color: '#C8F4F7', alpha: 0.35 },
        ],
    },
    {
        name: 'Neptune',
        orbit: 67,
        size: 3.2,
        period: 100,
        phase: 2.0,
        colors: ['#C3D3FF', '#3F63D8', '#0B1640'],
        bands: [{ at: -0.2, height: 0.16, color: '#2C4BB8', alpha: 0.45 }],
        ringTilt: 0.35,
        ringOpen: 0.3,
        rings: [{ radius: 1.9, width: 0.1, color: '#B7C6F0', alpha: 0.35 }],
    },
];

/** Where the Sun first appears on screen, relative to the black hole, at full size (pixels); from there it orbits with the galaxy. */
export const SOL_SCREEN_OFFSET = { x: -190, y: 152 };

/** Size of the Sun's own canvas (CSS pixels), big enough for Neptune's orbit and its label. */
export const SOL_FIELD_SIZE = 220;

function planetAngle(planet: Planet, seconds: number): number {
    return planet.phase + (seconds / planet.period) * Math.PI * 2;
}

const EARTH = SOL_PLANETS.find((planet) => planet.name === 'Earth')!;

/** A planet's radius at full size (pixels). */
export function planetSize(name: string): number {
    return SOL_PLANETS.find((planet) => planet.name === name)!.size;
}

/** Where a planet is right now, relative to the Sun, on screen. */
export function planetOffset(name: string, project: Project, seconds: number) {
    const planet = SOL_PLANETS.find((candidate) => candidate.name === name)!;
    const angle = planetAngle(planet, seconds);

    return project(
        Math.cos(angle) * planet.orbit,
        Math.sin(angle) * planet.orbit,
    );
}

/**
 * Lights a sphere from the Sun's side: bright where it faces the Sun, fading
 * to its night side.
 */
function shadedBall(
    context: CanvasRenderingContext2D,
    x: number,
    y: number,
    radius: number,
    [light, base, dark]: [string, string, string],
    sunX: number,
    sunY: number,
) {
    const gradient = context.createRadialGradient(
        x + sunX * radius * 0.5,
        y + sunY * radius * 0.5,
        radius * 0.1,
        x,
        y,
        radius * 1.05,
    );
    gradient.addColorStop(0, light);
    gradient.addColorStop(0.5, base);
    gradient.addColorStop(1, dark);
    context.fillStyle = gradient;
    context.beginPath();
    context.arc(x, y, radius, 0, Math.PI * 2);
    context.fill();
}

/** Darkens the half of a planet facing away from the Sun. */
function nightSide(
    context: CanvasRenderingContext2D,
    x: number,
    y: number,
    radius: number,
    sunX: number,
    sunY: number,
) {
    const shade = context.createLinearGradient(
        x + sunX * radius,
        y + sunY * radius,
        x - sunX * radius,
        y - sunY * radius,
    );
    shade.addColorStop(0, 'rgba(0, 0, 8, 0)');
    shade.addColorStop(0.45, 'rgba(0, 0, 8, 0.1)');
    shade.addColorStop(1, 'rgba(0, 0, 8, 0.78)');
    context.fillStyle = shade;
    context.fillRect(x - radius, y - radius, radius * 2, radius * 2);
}

function drawRings(
    context: CanvasRenderingContext2D,
    planet: Planet,
    x: number,
    y: number,
    radius: number,
    rotation: number,
    half: 'back' | 'front',
) {
    // The top half of each ring (on screen) is behind the planet, the bottom half in front.
    const [from, to] = half === 'back' ? [Math.PI, Math.PI * 2] : [0, Math.PI];

    for (const ring of planet.rings ?? []) {
        context.globalAlpha = ring.alpha;
        context.strokeStyle = ring.color;
        context.lineWidth = ring.width * radius;
        context.beginPath();
        context.ellipse(
            x,
            y,
            ring.radius * radius,
            ring.radius * radius * (planet.ringOpen ?? 0.3),
            rotation + (planet.ringTilt ?? 0),
            from,
            to,
        );
        context.stroke();
    }

    context.globalAlpha = 1;
}

function drawPlanet(
    context: CanvasRenderingContext2D,
    planet: Planet,
    x: number,
    y: number,
    radius: number,
    sunX: number,
    sunY: number,
    roll: number,
    seconds: number,
) {
    const ringRotation = -roll;

    drawRings(context, planet, x, y, radius, ringRotation, 'back');
    shadedBall(context, x, y, radius, planet.colors, sunX, sunY);

    context.save();
    context.beginPath();
    context.arc(x, y, radius, 0, Math.PI * 2);
    context.clip();

    if (planet.bands) {
        context.save();
        context.translate(x, y);
        context.rotate(ringRotation);

        for (const band of planet.bands) {
            context.globalAlpha = band.alpha;
            context.fillStyle = band.color;
            context.fillRect(
                -radius,
                (band.at - band.height / 2) * radius,
                radius * 2,
                band.height * radius,
            );
        }

        if (planet.name === 'Jupiter') {
            // The Great Red Spot.
            context.globalAlpha = 0.85;
            context.fillStyle = '#C0502E';
            context.beginPath();
            context.ellipse(
                radius * 0.3,
                radius * 0.32,
                radius * 0.22,
                radius * 0.13,
                0,
                0,
                Math.PI * 2,
            );
            context.fill();
        }

        context.restore();
    }

    if (planet.name === 'Earth') {
        // Continents and clouds drifting past as the Earth turns.
        const turn = (seconds * 0.35) % 2;
        const cloudTurn = (seconds * 0.22) % 2;
        const land: [number, number, number, number][] = [
            [-0.35, -0.25, 0.42, 0.3],
            [0.35, 0.2, 0.34, 0.42],
            [-0.1, 0.55, 0.3, 0.18],
        ];

        context.globalAlpha = 0.95;
        context.fillStyle = '#3FA34D';

        for (const [landX, landY, width, height] of land) {
            const offset = ((landX + turn + 1) % 2) - 1;

            for (const wrap of [0, -2]) {
                context.beginPath();
                context.ellipse(
                    x + (offset + wrap) * radius,
                    y + landY * radius,
                    width * radius,
                    height * radius,
                    0.4,
                    0,
                    Math.PI * 2,
                );
                context.fill();
            }
        }

        context.globalAlpha = 0.7;
        context.fillStyle = '#FFFFFF';
        const cloudOffset = ((3.1 - cloudTurn) % 2) - 1;

        for (const wrap of [0, 2]) {
            context.beginPath();
            context.ellipse(
                x + (cloudOffset + wrap) * radius,
                y - radius * 0.1,
                radius * 0.5,
                radius * 0.12,
                -0.2,
                0,
                Math.PI * 2,
            );
            context.fill();
        }
        context.globalAlpha = 1;
    }

    nightSide(context, x, y, radius, sunX, sunY);
    context.restore();

    if (planet.name === 'Earth') {
        // A thin blue atmosphere.
        context.save();
        context.strokeStyle = 'rgba(130, 200, 255, 0.75)';
        context.lineWidth = Math.max(0.8, radius * 0.22);
        context.shadowColor = '#6CB8FF';
        context.shadowBlur = radius * 2.2;
        context.beginPath();
        context.arc(x, y, radius * 1.08, 0, Math.PI * 2);
        context.stroke();
        context.restore();
    }

    drawRings(context, planet, x, y, radius, ringRotation, 'front');
}

/**
 * Draws the Sun, the planets' orbits, and the planets, back to front, centered
 * in `context`'s canvas.
 */
export function drawSolSystem(
    context: CanvasRenderingContext2D,
    project: Project,
    zoom: number,
    roll: number,
    seconds: number,
) {
    const center = SOL_FIELD_SIZE / 2;

    context.strokeStyle = 'rgba(255, 215, 189, 0.16)';
    context.lineWidth = 0.7;

    for (const planet of SOL_PLANETS) {
        context.beginPath();

        for (let step = 0; step <= 72; step++) {
            const angle = (step / 72) * Math.PI * 2;
            const point = project(
                Math.cos(angle) * planet.orbit,
                Math.sin(angle) * planet.orbit,
            );

            if (step === 0) {
                context.moveTo(center + point.x, center + point.y);
            } else {
                context.lineTo(center + point.x, center + point.y);
            }
        }

        context.stroke();
    }

    const bodies = SOL_PLANETS.map((planet) => {
        const angle = planetAngle(planet, seconds);
        const point = project(
            Math.cos(angle) * planet.orbit,
            Math.sin(angle) * planet.orbit,
        );

        return { planet, point };
    });

    const drawSun = () => {
        context.save();
        context.globalCompositeOperation = 'lighter';
        const glow = context.createRadialGradient(
            center,
            center,
            0,
            center,
            center,
            24 * zoom,
        );
        glow.addColorStop(0, 'rgba(255, 246, 216, 0.95)');
        glow.addColorStop(0.22, 'rgba(255, 205, 100, 0.45)');
        glow.addColorStop(0.6, 'rgba(255, 140, 40, 0.1)');
        glow.addColorStop(1, 'rgba(255, 120, 30, 0)');
        context.fillStyle = glow;
        context.fillRect(
            center - 24 * zoom,
            center - 24 * zoom,
            48 * zoom,
            48 * zoom,
        );
        context.restore();

        context.save();
        context.fillStyle = '#FFF3C4';
        context.shadowColor = '#FFC857';
        context.shadowBlur = 9 * zoom;
        context.beginPath();
        context.arc(center, center, 4 * zoom, 0, Math.PI * 2);
        context.fill();
        context.restore();
    };

    let isSunDrawn = false;

    for (const { planet, point } of bodies.sort(
        (a, b) => a.point.depth - b.point.depth,
    )) {
        if (!isSunDrawn && point.depth > 0) {
            drawSun();
            isSunDrawn = true;
        }

        const distance = Math.hypot(point.x, point.y) || 1;

        drawPlanet(
            context,
            planet,
            center + point.x,
            center + point.y,
            planet.size * zoom,
            -point.x / distance,
            -point.y / distance,
            roll,
            seconds,
        );
    }

    if (!isSunDrawn) {
        drawSun();
    }

    context.font = `600 ${Math.round(10 * Math.max(zoom, 0.8))}px "Schibsted Grotesk", sans-serif`;
    context.textAlign = 'center';
    context.fillStyle = 'rgba(255, 215, 189, 0.7)';
    context.fillText('Sol', center, center + 12 * zoom);
}

type Probe = {
    name: string;
    /** When it launches in each trip, and how long each trip lasts (seconds). */
    offset: number;
    cycle: number;
    /** How long it spends spiraling out through the planets, how far out it gets, and how much it turns. */
    spiralSeconds: number;
    exitRadius: number;
    turns: number;
    /** Speed once it's out among the stars (pixels per second), and how much its path bends. */
    speed: number;
    bend: number;
};

const PROBES: Probe[] = [
    {
        name: 'Voyager 2',
        offset: 0,
        cycle: 52,
        spiralSeconds: 14,
        exitRadius: 72,
        turns: 0.85,
        speed: 22,
        bend: 0.006,
    },
    {
        name: 'Voyager 1',
        offset: 7,
        cycle: 52,
        spiralSeconds: 10,
        exitRadius: 54,
        turns: 0.6,
        speed: 28,
        bend: -0.008,
    },
    // The Pioneers left first (1972 and 1973) and are slower, heading off
    // in nearly opposite directions.
    {
        name: 'Pioneer 10',
        offset: 20,
        cycle: 52,
        spiralSeconds: 11,
        exitRadius: 48,
        turns: 0.35,
        speed: 17,
        bend: 0.004,
    },
    {
        name: 'Pioneer 11',
        offset: 34,
        cycle: 52,
        spiralSeconds: 16,
        exitRadius: 62,
        turns: 1.05,
        speed: 15,
        bend: -0.005,
    },
];

/** Where a probe is, relative to the Sun, `elapsed` seconds after leaving Earth. */
function probePosition(
    probe: Probe,
    launchedAt: number,
    elapsed: number,
): { x: number; z: number } {
    const startAngle = planetAngle(EARTH, launchedAt);
    const spiral = (time: number) => {
        const progress = Math.min(1, time / probe.spiralSeconds);
        const radius =
            EARTH.orbit + (probe.exitRadius - EARTH.orbit) * progress ** 1.5;
        const angle =
            startAngle + probe.turns * Math.PI * 2 * (1 - (1 - progress) ** 2);

        return { x: Math.cos(angle) * radius, z: Math.sin(angle) * radius };
    };

    if (elapsed <= probe.spiralSeconds) {
        return spiral(elapsed);
    }

    const exit = spiral(probe.spiralSeconds);
    const before = spiral(probe.spiralSeconds - 0.1);
    const heading = Math.atan2(exit.z - before.z, exit.x - before.x);
    const cruise = elapsed - probe.spiralSeconds;
    const angle = heading + probe.bend * cruise;

    return {
        x: exit.x + Math.cos(angle) * probe.speed * cruise,
        z: exit.z + Math.sin(angle) * probe.speed * cruise,
    };
}

/**
 * Each probe's recent path (its trail) and how visible it is right now, in
 * the Sun's plane coordinates. They leave Earth, spiral out past the planets,
 * then head off across the galaxy before the next trip starts.
 */
export function probeTrails(seconds: number) {
    return PROBES.map((probe) => {
        const sinceStart = seconds - probe.offset;
        const trip = Math.floor(sinceStart / probe.cycle);
        const launchedAt = probe.offset + trip * probe.cycle;
        const elapsed = sinceStart - trip * probe.cycle;
        const visibility =
            Math.min(1, elapsed / 0.6) *
            Math.min(1, (probe.cycle - elapsed) / 5);
        const trail = Array.from({ length: 36 }, (_, step) => {
            const time = Math.max(0, elapsed - 10 + (step / 35) * 10);

            return probePosition(probe, launchedAt, time);
        });

        return {
            name: probe.name,
            trail,
            visibility: sinceStart < 0 ? 0 : visibility,
        };
    });
}
