import { useEffect, useRef } from 'react';
import type { ReactNode } from 'react';
import { drawSpaceBackdrop } from '@/components/home/closeup-gl';
import { createCosmicZoom } from '@/components/home/cosmic-zoom';
import {
    drawSolarSystem,
    hasSolarSystem,
    isSolarSystemSettled,
    loadSolarSystem,
    solarPlanetOnScreen,
} from '@/components/home/solar-closeup';
import {
    drawEarthCloseup,
    EARTH_CLOSEUP_RADIUS,
    EARTH_CLOSEUP_SIZE,
    earthShowProgress,
    endMoonwalk,
    isEarthCloseupReady,
    isMoonwalkPlaying,
    loadEarthCloseup,
    moonInCloseup,
    startMoonwalk,
} from '@/components/home/earth-closeup';
import {
    drawMarsCloseup,
    isMarsCloseupReady,
    loadMarsCloseup,
    MARS_CLOSEUP_RADIUS,
    marsShowProgress,
} from '@/components/home/mars-closeup';
import {
    drawProbeCloseup,
    isProbeCloseupReady,
    loadProbeCloseup,
    PROBE_CLOSEUP_RADIUS,
    PROBE_CLOSEUPS,
    probeShowProgress,
} from '@/components/home/probe-closeup';
import {
    drawLaniakea,
    isLaniakeaReady,
    loadLaniakea,
} from '@/components/home/laniakea';
import { universe } from '@/components/home/particle-universe';
import {
    drawSolSystem,
    planetOffset,
    planetSize,
    SOL_FIELD_SIZE,
    SOL_SCREEN_OFFSET,
    probeTrails,
} from '@/components/home/sol-system';
import {
    drawEndurance,
    drawEnterprise,
    SHIP_FIELD_SIZE,
} from '@/components/home/starships';

/** Canvas size in CSS pixels, centered on the black hole. */
const FIELD_SIZE = 1700;

/** Galaxy radii in pixels at full size: where the arms start and end. */
const ARM_START = 150;
const ARM_END = 640;

/** How open the spiral arms are (smaller winds them tighter). */
const ARM_PITCH = 0.32;

/** How far the galaxy is tilted toward the viewer, on top of the camera's pitch (radians). */
const INCLINATION = 0.8;

/** How fast the arm pattern turns (radians per second). */
const PATTERN_SPEED = 0.03;

/** The Endurance orbits the black hole just outside its disk (radius, radians per second). */
const ENDURANCE_ORBIT = { radius: 215, speed: 0.11 };

/** The Enterprise cruises the outer galaxy, jumping ahead at warp every so often (seconds, radians). */
const ENTERPRISE_ORBIT = {
    /** Where it first appears on screen, relative to the black hole (pixels at full size): up and to the left. */
    start: { x: -130, y: -140 },
    speed: 0.02,
    firstWarpAt: 25,
    warpEvery: 50,
    warpJump: 1.1,
    leaveSeconds: 0.45,
    arriveSeconds: 0.6,
};

/** Where a close-up settles, relative to the black hole (pixels), and how much the galaxy dims behind it. */
const CLOSEUP_AT = { x: -80, y: 30 };
const CLOSEUP_DIM = 0.7;

/** After a close-up shrinks back, how long before hovering again plays another (milliseconds). */
const CLOSEUP_COOLDOWN_MS = 2000;

/** Size of the close-ups' canvas (CSS pixels); every close-up draws into the same one. */
const CLOSEUP_SIZE = EARTH_CLOSEUP_SIZE;

type Closeup = {
    /** What to hover over: a planet in Sol, or one of the probes leaving it. */
    name: string;
    kind: 'planet' | 'probe';
    /** How big it is once it's zoomed in (CSS pixels). */
    radius: number;
    load: () => void;
    /** Heavy, so only loaded once the pointer comes near, not when the galaxy appears. */
    loadsNearby?: boolean;
    isReady: () => boolean;
    /** How zoomed in it is, from 0 to 1, `seconds` into its show; null once it's over. */
    progress: (seconds: number) => number | null;
    draw: (
        context: CanvasRenderingContext2D,
        pixelScale: number,
        seconds: number,
        reveal: number,
    ) => void;
};

/** The planets and probes you can hover over to zoom in on. */
const CLOSEUPS: Closeup[] = [
    {
        name: 'Earth',
        kind: 'planet',
        radius: EARTH_CLOSEUP_RADIUS,
        load: loadEarthCloseup,
        isReady: isEarthCloseupReady,
        progress: earthShowProgress,
        draw: drawEarthCloseup,
    },
    {
        name: 'Mars',
        kind: 'planet',
        radius: MARS_CLOSEUP_RADIUS,
        load: loadMarsCloseup,
        isReady: isMarsCloseupReady,
        progress: marsShowProgress,
        draw: drawMarsCloseup,
    },
    ...Object.keys(PROBE_CLOSEUPS).map((name): Closeup => ({
        name,
        kind: 'probe',
        radius: PROBE_CLOSEUP_RADIUS,
        load: () => loadProbeCloseup(name),
        loadsNearby: true,
        isReady: () => isProbeCloseupReady(name),
        progress: probeShowProgress,
        draw: (context, pixelScale, seconds, reveal) =>
            drawProbeCloseup(name, context, pixelScale, seconds, reveal),
    })),
];

/** How far the pointer can be from a heavy close-up's spot and still start loading it (CSS pixels). */
const LOAD_NEARBY_DISTANCE = 140;

/** A probe's dot, at full size (pixels). */
const PROBE_DOT_SIZE = 1.3;

/** How far from the black hole the wheel zooms instead of scrolling the page (CSS pixels). */
const ZOOM_AREA_RADIUS = 380;

/** How big the Solar System is drawn when zoomed in to it (pixels per unit of the galaxy's own drawing), and Neptune's orbit there. */
const SOLAR_ZOOM = 4.8;

/** Laniakea's canvas (CSS pixels), centered on the black hole, and how far it's magnified as it first appears. */
const LANIAKEA_SIZE = 1000;
const LANIAKEA_ARRIVAL_ZOOM = 5;

/** Once it's arrived, Laniakea settles a little left of the black hole, so it's all on screen (CSS pixels). */
const LANIAKEA_SETTLES_AT = { x: -230, y: 10 };

/** Seconds for the galaxy to fade in once the black hole is full size. */
const FADE_IN_SECONDS = 2.5;

/** The galaxy is hidden left of this fraction of its canvas, then fades in until EDGE_FADE_END. */
const EDGE_FADE_START = 0.12;
const EDGE_FADE_END = 0.42;

type Dust = {
    radius: number;
    angle: number;
    size: number;
    alpha: number;
    sprite: HTMLCanvasElement;
};

type Star = {
    radius: number;
    angle: number;
    speed: number;
    size: number;
    color: string;
    twinkle: number;
    phase: number;
    planets: Planet[];
};

type Planet = {
    orbit: number;
    speed: number;
    phase: number;
    size: number;
    color: string;
};

/** Star colors by spectral class, from hot blue giants to cool red dwarfs, and how common each is. */
const STAR_CLASSES = [
    { color: '#8FB0FF', weight: 1, size: [2.2, 3.4] },
    { color: '#AFC5FF', weight: 4, size: [1.5, 2.7] },
    { color: '#D6E0FF', weight: 7, size: [1.1, 2.2] },
    { color: '#F6F4FF', weight: 10, size: [0.9, 1.8] },
    { color: '#FFF0D2', weight: 18, size: [0.8, 1.6] },
    { color: '#FFC58E', weight: 25, size: [0.7, 1.4] },
    { color: '#FF8E66', weight: 33, size: [0.6, 1.2] },
    { color: '#FF5A3C', weight: 2, size: [2.4, 3.4] },
];

const PLANET_COLORS = [
    '#6FA8DC',
    '#C9A66B',
    '#8FBF7F',
    '#D96C4F',
    '#B7A3E0',
    '#E8D6A8',
];

const DUST_COLORS = {
    inner: ['#FFB070', '#FF8A3D', '#FFC89A'],
    middle: ['#FF6A2B', '#E8431C', '#FF7F50'],
    outer: ['#B8321E', '#8E2A3A', '#6B2C5A'],
};

function random(min: number, max: number): number {
    return min + Math.random() * (max - min);
}

function pick<T>(items: T[]): T {
    return items[Math.floor(Math.random() * items.length)];
}

function gaussian(): number {
    return (
        Math.sqrt(-2 * Math.log(Math.random() || 1e-6)) *
        Math.cos(2 * Math.PI * Math.random())
    );
}

function clamp(value: number, min = 0, max = 1): number {
    return Math.min(max, Math.max(min, value));
}

function smoothstep(from: number, to: number, value: number): number {
    const t = clamp((value - from) / (to - from));

    return t * t * (3 - 2 * t);
}

/** Angle of a spiral arm at a given radius (a logarithmic spiral). */
function armAngle(radius: number, arm: number): number {
    return arm * Math.PI + Math.log(radius / ARM_START) / ARM_PITCH;
}

const sprites = new Map<string, HTMLCanvasElement>();

/** A soft round glow in one color, drawn once and reused. */
function glowSprite(color: string): HTMLCanvasElement {
    const cached = sprites.get(color);

    if (cached) {
        return cached;
    }

    const sprite = document.createElement('canvas');
    sprite.width = 64;
    sprite.height = 64;
    const context = sprite.getContext('2d')!;
    const gradient = context.createRadialGradient(32, 32, 0, 32, 32, 32);
    gradient.addColorStop(0, color);
    gradient.addColorStop(0.35, `${color}66`);
    gradient.addColorStop(1, `${color}00`);
    context.fillStyle = gradient;
    context.fillRect(0, 0, 64, 64);
    sprites.set(color, sprite);

    return sprite;
}

function makeDust(): Dust[] {
    const dust: Dust[] = [];

    for (let index = 0; index < 1800; index++) {
        const radius =
            ARM_START + (ARM_END - ARM_START) * Math.random() ** 0.85;
        const along = (radius - ARM_START) / (ARM_END - ARM_START);
        const inArm = Math.random() > 0.12;
        const spread = inArm ? 0.11 + along * 0.1 : Math.PI;
        const palette =
            along < 0.3
                ? DUST_COLORS.inner
                : along < 0.65
                  ? DUST_COLORS.middle
                  : DUST_COLORS.outer;

        dust.push({
            radius: radius + gaussian() * 18,
            angle:
                armAngle(radius, index % 2) +
                (inArm ? gaussian() * spread : random(0, Math.PI * 2)),
            size: random(30, 80) * (0.6 + along * 0.6),
            alpha: random(0.04, 0.085) * (inArm ? 1 : 0.5),
            sprite: glowSprite(pick(palette)),
        });
    }

    // Bright knots where new stars are forming along the arms.
    for (let index = 0; index < 90; index++) {
        const radius = random(ARM_START + 60, ARM_END - 80);

        dust.push({
            radius,
            angle: armAngle(radius, index % 2) + gaussian() * 0.1,
            size: random(6, 13),
            alpha: random(0.25, 0.5),
            sprite: glowSprite(pick(['#FFD2B0', '#AFC5FF', '#FFB3C7'])),
        });
    }

    return dust;
}

function starClass() {
    const total = STAR_CLASSES.reduce((sum, type) => sum + type.weight, 0);
    let roll = Math.random() * total;

    for (const type of STAR_CLASSES) {
        roll -= type.weight;

        if (roll <= 0) {
            return type;
        }
    }

    return STAR_CLASSES[STAR_CLASSES.length - 1];
}

/** Inner stars orbit faster than outer ones, but slowly overall. */
function orbitSpeed(radius: number): number {
    return 0.07 * (250 / radius);
}

function makeStars(): Star[] {
    const stars: Star[] = [];

    for (let index = 0; index < 340; index++) {
        const type = starClass();
        const followsArm = Math.random() < 0.55;
        const radius = followsArm
            ? random(ARM_START, ARM_END)
            : 100 + -Math.log(1 - Math.random() * 0.98) * 220;

        stars.push({
            radius,
            angle: followsArm
                ? armAngle(radius, index % 2) + gaussian() * 0.22
                : random(0, Math.PI * 2),
            speed: orbitSpeed(radius),
            size: random(type.size[0], type.size[1]),
            color: type.color,
            twinkle: random(0.6, 2.4),
            phase: random(0, Math.PI * 2),
            planets: [],
        });
    }

    // Fine star dust that traces the arms.
    for (let index = 0; index < 900; index++) {
        const radius = ARM_START + (ARM_END - ARM_START) * Math.random() ** 0.9;

        stars.push({
            radius,
            angle: armAngle(radius, index % 2) + gaussian() * 0.13,
            speed: PATTERN_SPEED,
            size: random(0.4, 1),
            color: pick(['#FFE3C8', '#FFC58E', '#FFF6EC', '#FFB3A0']),
            twinkle: random(0.4, 1.6),
            phase: random(0, Math.PI * 2),
            planets: [],
        });
    }

    // A handful of sun-like stars with planets circling them.
    for (let index = 0; index < 8; index++) {
        const radius = random(230, 560);
        const planets = Array.from(
            { length: 1 + Math.floor(Math.random() * 3) },
            (_, order) => ({
                orbit: 12 + order * 9 + random(0, 4),
                speed: (Math.PI * 2) / random(5 + order * 3, 9 + order * 4),
                phase: random(0, Math.PI * 2),
                size: random(1.3, 2.6),
                color: pick(PLANET_COLORS),
            }),
        );

        stars.push({
            radius,
            angle: armAngle(radius, index % 2) + gaussian() * 0.3,
            speed: orbitSpeed(radius),
            size: random(2, 2.8),
            color: pick(['#FFF0D2', '#FFC58E', '#F6F4FF']),
            twinkle: 0.8,
            phase: random(0, Math.PI * 2),
            planets,
        });
    }

    return stars;
}

/**
 * A slowly turning spiral galaxy around the black hole: glowing cloud arms,
 * stars of every color and size, and a few stars with planets circling them.
 * It's drawn on two canvases, one behind the black hole and one in front, so
 * the near side of the galaxy passes in front of it. Only runs alongside the
 * WebGL black hole, and follows its camera. Hovering over the Earth, Mars,
 * or one of the Voyager and Pioneer probes zooms in on it for a little while
 * (see `earth-closeup`, `mars-closeup`, `probe-closeup`).
 */
export function Galaxy({ children }: { children: ReactNode }) {
    const backRef = useRef<HTMLCanvasElement>(null);
    const frontRef = useRef<HTMLCanvasElement>(null);
    const solRef = useRef<HTMLCanvasElement>(null);
    const enduranceRef = useRef<HTMLCanvasElement>(null);
    const enterpriseRef = useRef<HTMLCanvasElement>(null);
    const closeupRef = useRef<HTMLCanvasElement>(null);
    const galaxyRef = useRef<HTMLDivElement>(null);
    const laniakeaRef = useRef<HTMLCanvasElement>(null);
    const spotRefs = useRef<Record<string, HTMLDivElement | null>>({});

    useEffect(() => {
        const back = backRef.current?.getContext('2d');
        const front = frontRef.current?.getContext('2d');
        const sol = solRef.current?.getContext('2d');
        const endurance = enduranceRef.current?.getContext('2d');
        const enterprise = enterpriseRef.current?.getContext('2d');
        const closeupCanvas = closeupRef.current?.getContext('2d');
        const laniakea = laniakeaRef.current?.getContext('2d');
        const galaxyLayer = galaxyRef.current;
        const spots = CLOSEUPS.map((closeup) => spotRefs.current[closeup.name]);

        if (
            !back ||
            !front ||
            !sol ||
            !endurance ||
            !enterprise ||
            !closeupCanvas ||
            !laniakea ||
            !galaxyLayer ||
            spots.some((spot) => !spot) ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ) {
            return;
        }

        const scale = Math.min(window.devicePixelRatio || 1, 1.25);

        for (const context of [back, front]) {
            context.canvas.width = FIELD_SIZE * scale;
            context.canvas.height = FIELD_SIZE * scale;
        }

        // The solar system gets its own sharper canvas, so the planets look crisp.
        const solScale = Math.min(window.devicePixelRatio || 1, 2);
        sol.canvas.width = SOL_FIELD_SIZE * solScale;
        sol.canvas.height = SOL_FIELD_SIZE * solScale;

        for (const ship of [endurance, enterprise]) {
            ship.canvas.width = SHIP_FIELD_SIZE * solScale;
            ship.canvas.height = SHIP_FIELD_SIZE * solScale;
        }

        closeupCanvas.canvas.width = CLOSEUP_SIZE * solScale;
        closeupCanvas.canvas.height = CLOSEUP_SIZE * solScale;
        const spotFor = (closeup: Closeup) => spotRefs.current[closeup.name]!;

        const laniakeaScale = Math.min(window.devicePixelRatio || 1, 1.5);
        laniakea.canvas.width = LANIAKEA_SIZE * laniakeaScale;
        laniakea.canvas.height = LANIAKEA_SIZE * laniakeaScale;
        let home = { x: LANIAKEA_SIZE / 2, y: LANIAKEA_SIZE / 2 };

        // Levels drawn half-faded (Earth, the Solar System) go through this
        // canvas first, since their drawing sets its own transparency.
        const level = document.createElement('canvas').getContext('2d')!;
        level.canvas.width = CLOSEUP_SIZE * solScale;
        level.canvas.height = CLOSEUP_SIZE * solScale;
        const drawFaded = (
            draw: (context: CanvasRenderingContext2D) => void,
            alpha: number,
        ) => {
            level.setTransform(solScale, 0, 0, solScale, 0, 0);
            level.clearRect(0, 0, CLOSEUP_SIZE, CLOSEUP_SIZE);
            draw(level);
            closeupCanvas.globalAlpha = alpha;
            closeupCanvas.drawImage(
                level.canvas,
                0,
                0,
                CLOSEUP_SIZE,
                CLOSEUP_SIZE,
            );
            closeupCanvas.globalAlpha = 1;
        };

        const layers = [back, front, sol, endurance, enterprise];

        const edgeFade = back.createLinearGradient(
            0,
            0,
            FIELD_SIZE * EDGE_FADE_END,
            0,
        );
        edgeFade.addColorStop(
            EDGE_FADE_START / EDGE_FADE_END,
            'rgba(0, 0, 0, 1)',
        );
        edgeFade.addColorStop(1, 'rgba(0, 0, 0, 0)');

        const dust = makeDust();
        const stars = makeStars();
        const startedAt = performance.now();
        let appearedAt: number | null = null;
        let solOrbit: { radius: number; angle: number } | null = null;
        let enterpriseOrbit: { radius: number; angle: number } | null = null;
        let isVisible = true;
        let frame = 0;
        let show: {
            closeup: Closeup;
            startedAt: number;
            /** Where it was last seen, in case a probe fades out mid-show. */
            at: { x: number; y: number };
            /** Started from a planet in the Solar System view, rather than in the galaxy. */
            isInSolarView: boolean;
            /** How long it's been held up by the Moon's moonwalk (milliseconds). */
            heldMs: number;
        } | null = null;
        let cooldownUntil = 0;
        let hovered: Closeup | null = null;
        let pointer: { x: number; y: number } | null = null;
        /** The moonwalk plays again only once the pointer has left the Moon. */
        let isOffMoon = true;
        let lastTickAt: number | null = null;

        // The headline's layer sits on top of the galaxy, so check where the
        // pointer is rather than waiting for a spot to be hovered.
        const trackPointer = (event: PointerEvent) => {
            pointer = { x: event.clientX, y: event.clientY };
            hovered = null;
            let nearest = Infinity;

            // Planets and probes can pass close by each other, so pick the nearest one.
            for (const closeup of CLOSEUPS) {
                const spot = spotFor(closeup).getBoundingClientRect();

                if (spot.width === 0) {
                    continue;
                }

                const distance = Math.hypot(
                    event.clientX - (spot.left + spot.width / 2),
                    event.clientY - (spot.top + spot.height / 2),
                );

                if (closeup.loadsNearby && distance < LOAD_NEARBY_DISTANCE) {
                    closeup.load();
                }

                if (distance < spot.width / 2 && distance < nearest) {
                    hovered = closeup;
                    nearest = distance;
                }
            }
        };
        window.addEventListener('pointermove', trackPointer, {
            passive: true,
        });

        /** While the Earth fills the view, hovering over its Moon starts the Apollo 11 moonwalk. */
        const watchForMoonwalk = (isEarthUp: boolean) => {
            const moon = isEarthUp ? moonInCloseup() : null;

            if (!moon || !pointer) {
                isOffMoon ||= !isMoonwalkPlaying();

                return;
            }

            const drawn = closeupCanvas.canvas.getBoundingClientRect();
            const across = drawn.width / CLOSEUP_SIZE;
            const isOnMoon =
                Math.hypot(
                    pointer.x - (drawn.left + moon.x * across),
                    pointer.y - (drawn.top + moon.y * across),
                ) <
                moon.radius * across;

            if (isOnMoon && isOffMoon) {
                startMoonwalk();
            }

            isOffMoon = !isOnMoon;
        };

        const endShow = (now: number) => {
            endMoonwalk();

            if (show) {
                spotFor(show.closeup).dataset.state = 'idle';
            }

            show = null;
            universe.isHoleCovered = false;
            cooldownUntil = now + CLOSEUP_COOLDOWN_MS;
            hovered = null;
            closeupCanvas.canvas.style.opacity = '0';
        };

        const zoomLevels = createCosmicZoom({
            isInZoomArea: (x, y) => {
                const field = back.canvas.getBoundingClientRect();

                return (
                    Math.hypot(
                        x - (field.left + field.width / 2),
                        y - (field.top + field.height / 2),
                    ) < ZOOM_AREA_RADIUS
                );
            },
            canChange: () => !show && !isMoonwalkPlaying(),
            interrupt: () => {
                if (show) {
                    endShow(performance.now());
                    cooldownUntil = 0;
                }
            },
            prepare: (next) => {
                if (next === 'laniakea') {
                    loadLaniakea();
                }

                if (next === 'solar' || next === 'earth') {
                    loadSolarSystem();
                }
            },
            isReady: (next) =>
                next === 'laniakea'
                    ? isLaniakeaReady()
                    : next === 'earth'
                      ? isEarthCloseupReady()
                      : next === 'solar'
                        ? isSolarSystemSettled()
                        : true,
        });

        const visibilityObserver = new IntersectionObserver(([entry]) => {
            isVisible = entry.isIntersecting;
        });
        visibilityObserver.observe(back.canvas);

        const tick = (now: number) => {
            frame = requestAnimationFrame(tick);
            const sinceLastTick = Math.min(now - (lastTickAt ?? now), 100);
            lastTickAt = now;

            if (!isVisible) {
                return;
            }

            if (!universe.rendersHoleInWebgl || universe.hole.size < 0.9) {
                for (const layer of layers) {
                    layer.canvas.style.opacity = '0';
                }

                appearedAt = null;
                zoomLevels.tick(now, false);

                if (show) {
                    endShow(now);
                }

                return;
            }

            appearedAt ??= now;

            for (const closeup of CLOSEUPS) {
                if (!closeup.loadsNearby) {
                    closeup.load();
                }
            }

            const fade = smoothstep(
                0,
                FADE_IN_SECONDS * 1000,
                now - appearedAt,
            );
            if (show && isMoonwalkPlaying()) {
                show.heldMs += sinceLastTick;
            }

            let zoomedIn = show
                ? show.closeup.progress(
                      (now - show.startedAt - show.heldMs) / 1000,
                  )
                : null;

            if (show && zoomedIn === null) {
                endShow(now);
            }

            // Zooming in to the Solar System or Earth dims the galaxy like a
            // close-up; zooming out to Laniakea shrinks it away to a point.
            zoomLevels.tick(now, fade === 1);
            const weights = zoomLevels.weights(now);
            const inward = weights.solar + weights.earth;

            for (const layer of layers) {
                layer.canvas.style.opacity = String(
                    fade * (1 - CLOSEUP_DIM * Math.max(zoomedIn ?? 0, inward)),
                );
            }

            galaxyLayer.style.transform = `scale(${1 - 0.97 * weights.laniakea})`;
            galaxyLayer.style.opacity = String(1 - weights.laniakea);

            const seconds = (now - startedAt) / 1000;
            const { yaw, pitch, roll } = universe.view;
            const zoom = clamp(universe.hole.radius / 72, 0.55, 1.15);
            const tilt = clamp(INCLINATION + pitch * 1.5, 0.3, 1.3);
            const tiltSin = Math.sin(tilt);
            const yawCos = Math.cos(yaw);
            const yawSin = Math.sin(yaw);
            const rollCos = Math.cos(roll);
            const rollSin = Math.sin(roll);
            const center = FIELD_SIZE / 2;
            const holeRadius = universe.hole.radius;

            /** Galaxy-plane offset to a screen offset, plus how near it is to the viewer. */
            const projectOffset = (x: number, z: number) => {
                const turnedX = x * yawCos - z * yawSin;
                const turnedZ = x * yawSin + z * yawCos;
                const flatY = turnedZ * tiltSin;

                return {
                    x: (turnedX * rollCos + flatY * rollSin) * zoom,
                    y: (-turnedX * rollSin + flatY * rollCos) * zoom,
                    depth: turnedZ,
                };
            };

            /** Galaxy-plane point to a position on the galaxy canvases. */
            const project = (x: number, z: number) => {
                const offset = projectOffset(x, z);

                return {
                    x: center + offset.x,
                    y: center + offset.y,
                    depth: offset.depth,
                };
            };

            /** The galaxy-plane point that lands at a given screen offset (the reverse of `projectOffset`). */
            const unproject = (x: number, y: number) => {
                const turnedX = (x * rollCos - y * rollSin) / zoom;
                const turnedZ = (x * rollSin + y * rollCos) / zoom / tiltSin;

                return {
                    x: turnedX * yawCos + turnedZ * yawSin,
                    z: -turnedX * yawSin + turnedZ * yawCos,
                };
            };

            // While a close-up (or another level) covers everything, there's no galaxy to see.
            const isCovered =
                zoomedIn === 1 || inward === 1 || weights.laniakea === 1;
            universe.isHoleCovered = isCovered;

            const drawGalaxy = () => {
                for (const context of [back, front]) {
                    context.setTransform(scale, 0, 0, scale, 0, 0);
                    context.clearRect(0, 0, FIELD_SIZE, FIELD_SIZE);
                    context.globalCompositeOperation = 'lighter';
                }

                // The galaxy's warm core, flattened by the tilt.
                back.save();
                back.translate(center, center);
                back.rotate(-roll);
                back.scale(1, 0.35 + tiltSin * 0.65);
                const core = back.createRadialGradient(
                    0,
                    0,
                    0,
                    0,
                    0,
                    330 * zoom,
                );
                core.addColorStop(0, 'rgba(255, 150, 80, 0.22)');
                core.addColorStop(0.5, 'rgba(255, 90, 40, 0.07)');
                core.addColorStop(1, 'rgba(255, 60, 30, 0)');
                back.fillStyle = core;
                back.fillRect(-330 * zoom, -330 * zoom, 660 * zoom, 660 * zoom);
                back.restore();

                const patternAngle = seconds * PATTERN_SPEED;

                for (const cloud of dust) {
                    const angle = cloud.angle + patternAngle;
                    const point = project(
                        Math.cos(angle) * cloud.radius,
                        Math.sin(angle) * cloud.radius,
                    );
                    const isNear = point.depth > 0;
                    const context = isNear ? front : back;
                    const distance = Math.hypot(
                        point.x - center,
                        point.y - center,
                    );
                    // Keep near-side clouds from fogging over the black hole itself.
                    const clearing = isNear
                        ? smoothstep(
                              holeRadius * 1.3,
                              holeRadius * 3.2,
                              distance,
                          )
                        : 1;
                    const size = cloud.size * zoom;

                    context.globalAlpha = cloud.alpha * clearing;
                    context.drawImage(
                        cloud.sprite,
                        point.x - size / 2,
                        point.y - size / 2,
                        size,
                        size,
                    );
                }

                for (const star of stars) {
                    const angle = star.angle + seconds * star.speed;
                    const starX = Math.cos(angle) * star.radius;
                    const starZ = Math.sin(angle) * star.radius;
                    const point = project(starX, starZ);
                    const context = point.depth > 0 ? front : back;
                    const brightness =
                        0.75 +
                        0.25 * Math.sin(seconds * star.twinkle + star.phase);
                    const size = star.size * zoom;

                    const drawPlanets = (inFront: boolean) => {
                        for (const planet of star.planets) {
                            const orbitAngle =
                                planet.phase + seconds * planet.speed;
                            const localZ = Math.sin(orbitAngle) * planet.orbit;

                            if (localZ > 0 !== inFront) {
                                continue;
                            }

                            const spot = project(
                                starX + Math.cos(orbitAngle) * planet.orbit,
                                starZ + localZ,
                            );
                            context.globalAlpha = 1;
                            context.fillStyle = planet.color;
                            context.beginPath();
                            context.arc(
                                spot.x,
                                spot.y,
                                planet.size * zoom,
                                0,
                                Math.PI * 2,
                            );
                            context.fill();
                        }
                    };

                    if (star.planets.length > 0) {
                        context.globalAlpha = 0.18;
                        context.strokeStyle = '#FFD7BD';
                        context.lineWidth = 0.6;

                        for (const planet of star.planets) {
                            context.beginPath();

                            for (let step = 0; step <= 32; step++) {
                                const orbitAngle = (step / 32) * Math.PI * 2;
                                const spot = project(
                                    starX + Math.cos(orbitAngle) * planet.orbit,
                                    starZ + Math.sin(orbitAngle) * planet.orbit,
                                );

                                if (step === 0) {
                                    context.moveTo(spot.x, spot.y);
                                } else {
                                    context.lineTo(spot.x, spot.y);
                                }
                            }

                            context.stroke();
                        }

                        drawPlanets(false);
                    }

                    if (size > 1.2) {
                        const glow = size * 7;
                        context.globalAlpha = 0.35 * brightness;
                        context.drawImage(
                            glowSprite(star.color),
                            point.x - glow / 2,
                            point.y - glow / 2,
                            glow,
                            glow,
                        );
                    }

                    context.globalAlpha = brightness;
                    context.fillStyle = star.color;
                    context.beginPath();
                    context.arc(point.x, point.y, size / 2, 0, Math.PI * 2);
                    context.fill();

                    if (star.planets.length > 0) {
                        drawPlanets(true);
                    }
                }

                // Fade the galaxy out toward the headline. Drawn here rather than
                // with a CSS mask, which Safari recomposites on every frame.
                for (const context of [back, front]) {
                    context.globalAlpha = 1;
                    context.globalCompositeOperation = 'destination-out';
                    context.fillStyle = edgeFade;
                    context.fillRect(
                        0,
                        0,
                        FIELD_SIZE * EDGE_FADE_END,
                        FIELD_SIZE,
                    );
                    context.globalCompositeOperation = 'source-over';
                }
            };

            if (!isCovered) {
                drawGalaxy();
            }

            // Our solar system orbits the galaxy like every other star,
            // starting just below and left of the black hole.
            if (!solOrbit) {
                const start = unproject(
                    SOL_SCREEN_OFFSET.x * zoom,
                    SOL_SCREEN_OFFSET.y * zoom,
                );
                const radius = Math.hypot(start.x, start.z);

                solOrbit = {
                    radius,
                    angle:
                        Math.atan2(start.z, start.x) -
                        seconds * orbitSpeed(radius),
                };
            }

            const solAngle =
                solOrbit.angle + seconds * orbitSpeed(solOrbit.radius);
            const sun = {
                x: Math.cos(solAngle) * solOrbit.radius,
                z: Math.sin(solAngle) * solOrbit.radius,
            };
            const solOffset = projectOffset(sun.x, sun.z);

            sol.setTransform(solScale, 0, 0, solScale, 0, 0);
            sol.clearRect(0, 0, SOL_FIELD_SIZE, SOL_FIELD_SIZE);
            sol.canvas.style.transform = `translate(${solOffset.x - SOL_FIELD_SIZE / 2}px, ${solOffset.y - SOL_FIELD_SIZE / 2}px)`;
            // On the far side of the galaxy it passes behind the black hole.
            sol.canvas.style.zIndex = solOffset.depth > 0 ? '' : '-1';
            drawSolSystem(sol, projectOffset, zoom, roll, seconds);

            // The probes' recent paths, and where each one is now (while it's visible).
            const probes = probeTrails(seconds).map((probe) => ({
                ...probe,
                points: probe.trail.map((point) =>
                    project(sun.x + point.x, sun.z + point.z),
                ),
            }));

            // The Solar System close up: turning slowly, its planets named.
            const turn = seconds * 0.015;
            const projectSolar = (x: number, z: number) => {
                const turnedX = x * Math.cos(turn) - z * Math.sin(turn);
                const turnedZ = x * Math.sin(turn) + z * Math.cos(turn);

                return {
                    x: turnedX * SOLAR_ZOOM,
                    y: turnedZ * Math.sin(0.95) * SOLAR_ZOOM,
                    depth: turnedZ,
                };
            };

            /** Draws the Solar System view, flying in toward Earth by `towardEarth` (0 to 1). */
            const drawSolarView = (towardEarth: number, alpha: number) => {
                if (hasSolarSystem()) {
                    drawFaded(
                        (context) =>
                            drawSolarSystem(
                                context,
                                CLOSEUP_SIZE,
                                solScale,
                                seconds,
                                towardEarth,
                            ),
                        alpha,
                    );

                    return;
                }

                // The flat one, if the 3D one couldn't load.
                const rush = 1 + 5 * towardEarth;
                const earthOnScreen = planetOffset(
                    'Earth',
                    projectSolar,
                    seconds,
                );
                const focusX = CLOSEUP_SIZE / 2 + earthOnScreen.x;
                const focusY = CLOSEUP_SIZE / 2 + earthOnScreen.y;

                drawFaded((context) => {
                    context.translate(focusX, focusY);
                    context.scale(rush, rush);
                    context.translate(-focusX, -focusY);
                    drawSolSystem(
                        context,
                        projectSolar,
                        SOLAR_ZOOM,
                        0,
                        seconds,
                        {
                            size: CLOSEUP_SIZE,
                            hasNames: true,
                        },
                    );
                }, alpha);
            };

            /** Where a planet is in the Solar System view, from its middle, and how big it looks there. */
            const solarOffset = (name: string) => {
                const drawn = hasSolarSystem()
                    ? solarPlanetOnScreen(name)
                    : null;

                if (drawn) {
                    return {
                        x: drawn.x - CLOSEUP_SIZE / 2,
                        y: drawn.y - CLOSEUP_SIZE / 2,
                        radius: drawn.radius,
                    };
                }

                const flat = planetOffset(name, projectSolar, seconds);

                return {
                    x: flat.x,
                    y: flat.y,
                    radius: planetSize(name) * SOLAR_ZOOM,
                };
            };

            // Hovering over the Earth, Mars, or a probe zooms in on it for a
            // little while: in the galaxy, or (planets only) in the Solar System view.
            const isAtSolar = zoomLevels.isAtSolar(now);
            const locate = (closeup: Closeup) => {
                if (isAtSolar) {
                    if (closeup.kind !== 'planet') {
                        return null;
                    }

                    const offset = solarOffset(closeup.name);

                    return {
                        x: CLOSEUP_AT.x + offset.x,
                        y: CLOSEUP_AT.y + offset.y,
                    };
                }

                if (!zoomLevels.isAtGalaxy(now) && !show) {
                    return null;
                }

                if (closeup.kind === 'planet') {
                    const offset = planetOffset(
                        closeup.name,
                        projectOffset,
                        seconds,
                    );

                    return {
                        x: solOffset.x + offset.x,
                        y: solOffset.y + offset.y,
                    };
                }

                const probe = probes.find(
                    (candidate) => candidate.name === closeup.name,
                );

                if (!probe || probe.visibility < 0.6) {
                    return null;
                }

                const head = probe.points[probe.points.length - 1];

                return { x: head.x - center, y: head.y - center };
            };

            for (const closeup of CLOSEUPS) {
                const at = locate(closeup);
                const spot = spotFor(closeup);
                spot.style.display = at ? '' : 'none';

                if (at) {
                    spot.style.transform = `translate(${at.x}px, ${at.y}px) translate(-50%, -50%)`;
                }
            }

            const hoveredAt = hovered ? locate(hovered) : null;

            if (
                hovered &&
                hoveredAt &&
                !show &&
                (zoomLevels.isAtGalaxy(now) || isAtSolar) &&
                fade === 1 &&
                now > cooldownUntil &&
                hovered.isReady()
            ) {
                show = {
                    closeup: hovered,
                    startedAt: now,
                    at: hoveredAt,
                    isInSolarView: isAtSolar,
                    heldMs: 0,
                };
                zoomedIn = 0;
                spotFor(hovered).dataset.state = 'playing';
            }

            if (show && zoomedIn !== null && show.isInSolarView) {
                // Growing out of the planet in the Solar System view, which stays behind it.
                const { closeup, startedAt } = show;
                const offset = solarOffset(closeup.name);
                const smallest = offset.radius / closeup.radius;
                const size = smallest * (1 / smallest) ** zoomedIn;
                const x = CLOSEUP_SIZE / 2 + offset.x * (1 - zoomedIn);
                const y = CLOSEUP_SIZE / 2 + offset.y * (1 - zoomedIn);

                closeupCanvas.canvas.style.opacity = '1';
                closeupCanvas.canvas.style.transform = `translate(${CLOSEUP_AT.x - CLOSEUP_SIZE / 2}px, ${CLOSEUP_AT.y - CLOSEUP_SIZE / 2}px)`;
                closeupCanvas.setTransform(solScale, 0, 0, solScale, 0, 0);
                closeupCanvas.clearRect(0, 0, CLOSEUP_SIZE, CLOSEUP_SIZE);
                drawSpaceBackdrop(closeupCanvas, CLOSEUP_SIZE, 1);
                drawSolarView(0, 1);

                level.setTransform(solScale, 0, 0, solScale, 0, 0);
                level.clearRect(0, 0, CLOSEUP_SIZE, CLOSEUP_SIZE);
                closeup.draw(
                    level,
                    solScale,
                    (now - startedAt) / 1000,
                    zoomedIn,
                );
                closeupCanvas.drawImage(
                    level.canvas,
                    x - (CLOSEUP_SIZE / 2) * size,
                    y - (CLOSEUP_SIZE / 2) * size,
                    CLOSEUP_SIZE * size,
                    CLOSEUP_SIZE * size,
                );
            } else if (show && zoomedIn !== null) {
                const { closeup, startedAt } = show;
                show.at = locate(closeup) ?? show.at;
                const { at } = show;
                const dotSize =
                    closeup.kind === 'planet'
                        ? planetSize(closeup.name)
                        : PROBE_DOT_SIZE;
                const smallest = (dotSize * zoom) / closeup.radius;
                const closeupX = at.x + (CLOSEUP_AT.x - at.x) * zoomedIn;
                const closeupY = at.y + (CLOSEUP_AT.y - at.y) * zoomedIn;

                closeupCanvas.canvas.style.opacity = '1';
                closeupCanvas.canvas.style.transform = `translate(${closeupX - CLOSEUP_SIZE / 2}px, ${closeupY - CLOSEUP_SIZE / 2}px) scale(${smallest * (1 / smallest) ** zoomedIn})`;
                closeupCanvas.setTransform(solScale, 0, 0, solScale, 0, 0);
                closeupCanvas.clearRect(0, 0, CLOSEUP_SIZE, CLOSEUP_SIZE);
                closeup.draw(
                    closeupCanvas,
                    solScale,
                    (now - startedAt) / 1000,
                    zoomedIn,
                );
            }

            // The Solar System and Earth levels, growing out of Sol (or Earth).
            if (!show && inward > 0) {
                const isFromEarth =
                    weights.solar === 0 &&
                    (zoomLevels.level === 'earth' ||
                        zoomLevels.from === 'earth');
                const earthAt = planetOffset('Earth', projectOffset, seconds);
                const anchor = isFromEarth
                    ? { x: solOffset.x + earthAt.x, y: solOffset.y + earthAt.y }
                    : solOffset;
                const smallest = isFromEarth
                    ? (planetSize('Earth') * zoom) / EARTH_CLOSEUP_RADIUS
                    : zoom / SOLAR_ZOOM;
                const closeupX = anchor.x + (CLOSEUP_AT.x - anchor.x) * inward;
                const closeupY = anchor.y + (CLOSEUP_AT.y - anchor.y) * inward;

                closeupCanvas.canvas.style.opacity = '1';
                closeupCanvas.canvas.style.transform = `translate(${closeupX - CLOSEUP_SIZE / 2}px, ${closeupY - CLOSEUP_SIZE / 2}px) scale(${smallest * (1 / smallest) ** inward})`;
                closeupCanvas.setTransform(solScale, 0, 0, solScale, 0, 0);
                closeupCanvas.clearRect(0, 0, CLOSEUP_SIZE, CLOSEUP_SIZE);
                drawSpaceBackdrop(
                    closeupCanvas,
                    CLOSEUP_SIZE,
                    smoothstep(0, 0.6, inward),
                );

                if (weights.solar > 0) {
                    // Rushing in toward Earth on the way down.
                    drawSolarView(
                        weights.earth,
                        weights.solar / Math.max(inward, 0.001),
                    );
                }

                if (weights.earth > 0) {
                    drawFaded(
                        (context) =>
                            drawEarthCloseup(context, solScale, seconds, 1),
                        weights.earth / Math.max(inward, 0.001),
                    );
                }
            } else if (!show) {
                closeupCanvas.canvas.style.opacity = '0';
            }

            watchForMoonwalk(
                show
                    ? show.closeup.name === 'Earth' && zoomedIn === 1
                    : weights.earth === 1,
            );

            // Laniakea, zooming out from the Milky Way's own spot in it.
            if (weights.laniakea > 0) {
                laniakea.setTransform(laniakeaScale, 0, 0, laniakeaScale, 0, 0);
                laniakea.clearRect(0, 0, LANIAKEA_SIZE, LANIAKEA_SIZE);

                // Deep space behind it, hiding the hero's ripples.
                const depths = laniakea.createRadialGradient(
                    LANIAKEA_SIZE / 2,
                    LANIAKEA_SIZE / 2,
                    0,
                    LANIAKEA_SIZE / 2,
                    LANIAKEA_SIZE / 2,
                    LANIAKEA_SIZE / 2,
                );
                depths.addColorStop(0, 'rgba(3, 4, 10, 0.96)');
                depths.addColorStop(0.75, 'rgba(3, 4, 10, 0.9)');
                depths.addColorStop(1, 'rgba(3, 4, 10, 0)');
                laniakea.globalAlpha = weights.laniakea;
                laniakea.fillStyle = depths;
                laniakea.fillRect(0, 0, LANIAKEA_SIZE, LANIAKEA_SIZE);
                laniakea.globalAlpha = 1;

                home =
                    drawLaniakea(
                        laniakea,
                        LANIAKEA_SIZE,
                        laniakeaScale,
                        seconds,
                        smoothstep(0.1, 0.8, weights.laniakea),
                    ) ?? home;

                // Fade it out toward the headline, like the galaxy.
                laniakea.globalCompositeOperation = 'destination-out';
                const toHeadline = laniakea.createLinearGradient(
                    0,
                    0,
                    LANIAKEA_SIZE * 0.5,
                    0,
                );
                toHeadline.addColorStop(0, 'rgba(0, 0, 0, 1)');
                toHeadline.addColorStop(1, 'rgba(0, 0, 0, 0)');
                laniakea.fillStyle = toHeadline;
                laniakea.fillRect(0, 0, LANIAKEA_SIZE * 0.5, LANIAKEA_SIZE);
                laniakea.globalCompositeOperation = 'source-over';

                const arriving = 1 - weights.laniakea;
                laniakea.canvas.style.opacity = '1';
                laniakea.canvas.style.transformOrigin = `${home.x}px ${home.y}px`;
                // (Its centering comes from its class, as a separate `translate`.)
                const shiftX =
                    (LANIAKEA_SIZE / 2 - home.x) * arriving +
                    LANIAKEA_SETTLES_AT.x * (1 - arriving);
                const shiftY =
                    (LANIAKEA_SIZE / 2 - home.y) * arriving +
                    LANIAKEA_SETTLES_AT.y * (1 - arriving);
                laniakea.canvas.style.transform = `translate(${shiftX}px, ${shiftY}px) scale(${1 + (LANIAKEA_ARRIVAL_ZOOM - 1) * arriving})`;
            } else {
                laniakea.canvas.style.opacity = '0';
            }

            // The Voyagers and Pioneers leave Earth and head out across the galaxy.
            for (const voyager of probes) {
                if (voyager.visibility <= 0 || isCovered) {
                    continue;
                }

                const { points } = voyager;
                const head = points[points.length - 1];
                const context = head.depth > 0 ? front : back;

                context.lineWidth = 0.9;
                context.strokeStyle = '#CFE6FF';

                for (let index = 1; index < points.length; index++) {
                    context.globalAlpha =
                        (index / points.length) ** 1.6 *
                        0.55 *
                        voyager.visibility;
                    context.beginPath();
                    context.moveTo(points[index - 1].x, points[index - 1].y);
                    context.lineTo(points[index].x, points[index].y);
                    context.stroke();
                }

                context.globalAlpha = 0.8 * voyager.visibility;
                context.drawImage(
                    glowSprite('#BFE0FF'),
                    head.x - 6,
                    head.y - 6,
                    12,
                    12,
                );
                context.globalAlpha = voyager.visibility;
                context.fillStyle = '#FFFFFF';
                context.beginPath();
                context.arc(head.x, head.y, 1.3, 0, Math.PI * 2);
                context.fill();

                context.globalAlpha = 0.7 * voyager.visibility;
                context.font = '500 9px "Instrument Sans", sans-serif';
                context.fillStyle = '#DCEBFF';
                context.fillText(voyager.name, head.x + 6, head.y - 5);
            }

            /** Moves a ship's canvas to a galaxy-plane point, behind the black hole on the far side. */
            const placeShip = (
                ship: CanvasRenderingContext2D,
                x: number,
                z: number,
            ) => {
                const offset = projectOffset(x, z);

                ship.setTransform(solScale, 0, 0, solScale, 0, 0);
                ship.clearRect(0, 0, SHIP_FIELD_SIZE, SHIP_FIELD_SIZE);
                ship.canvas.style.transform = `translate(${offset.x - SHIP_FIELD_SIZE / 2}px, ${offset.y - SHIP_FIELD_SIZE / 2}px)`;
                ship.canvas.style.zIndex = offset.depth > 0 ? '' : '-1';

                return offset;
            };

            // The Endurance, spinning its ring as it orbits the black hole.
            const enduranceAngle = 2.2 + seconds * ENDURANCE_ORBIT.speed;
            const enduranceAt = placeShip(
                endurance,
                Math.cos(enduranceAngle) * ENDURANCE_ORBIT.radius,
                Math.sin(enduranceAngle) * ENDURANCE_ORBIT.radius,
            );
            const toHole = Math.hypot(enduranceAt.x, enduranceAt.y) || 1;
            drawEndurance(
                endurance,
                SHIP_FIELD_SIZE / 2,
                SHIP_FIELD_SIZE / 2,
                zoom * 1.25,
                seconds * 0.6,
                -enduranceAt.x / toHole,
                -enduranceAt.y / toHole,
            );

            // The Enterprise, cruising and jumping ahead at warp now and then.
            const trip = ENTERPRISE_ORBIT;
            const warpsDone = Math.max(
                0,
                Math.floor((seconds - trip.firstWarpAt) / trip.warpEvery) + 1,
            );
            const nextWarpAt = trip.firstWarpAt + warpsDone * trip.warpEvery;
            const lastWarpAt = nextWarpAt - trip.warpEvery;
            const warp =
                nextWarpAt - seconds < trip.leaveSeconds
                    ? 1 - (nextWarpAt - seconds) / trip.leaveSeconds
                    : warpsDone > 0 && seconds - lastWarpAt < trip.arriveSeconds
                      ? -(1 - (seconds - lastWarpAt) / trip.arriveSeconds)
                      : 0;
            if (!enterpriseOrbit) {
                const start = unproject(
                    trip.start.x * zoom,
                    trip.start.y * zoom,
                );

                enterpriseOrbit = {
                    radius: Math.hypot(start.x, start.z),
                    angle: Math.atan2(start.z, start.x) - seconds * trip.speed,
                };
            }

            const enterpriseAngle =
                enterpriseOrbit.angle +
                seconds * trip.speed +
                warpsDone * trip.warpJump;
            const enterpriseAt = placeShip(
                enterprise,
                Math.cos(enterpriseAngle) * enterpriseOrbit.radius,
                Math.sin(enterpriseAngle) * enterpriseOrbit.radius,
            );
            const ahead = projectOffset(
                Math.cos(enterpriseAngle + 0.01) * enterpriseOrbit.radius,
                Math.sin(enterpriseAngle + 0.01) * enterpriseOrbit.radius,
            );
            drawEnterprise(
                enterprise,
                SHIP_FIELD_SIZE / 2,
                SHIP_FIELD_SIZE / 2,
                zoom * 1.1,
                Math.atan2(ahead.y - enterpriseAt.y, ahead.x - enterpriseAt.x),
                warp,
            );
        };

        frame = requestAnimationFrame(tick);

        return () => {
            cancelAnimationFrame(frame);
            visibilityObserver.disconnect();
            window.removeEventListener('pointermove', trackPointer);
            zoomLevels.destroy();
            endMoonwalk();
        };
    }, []);

    const canvasClassName =
        'absolute top-1/2 left-1/2 size-[1700px] -translate-1/2 opacity-0 motion-reduce:hidden';

    const shipClassName =
        'absolute top-1/2 left-1/2 size-[240px] opacity-0 motion-reduce:hidden';

    return (
        <>
            {/* Everything in the galaxy, so zooming out to Laniakea can shrink it away. */}
            <div ref={galaxyRef} className="absolute inset-0">
                <canvas ref={backRef} className={canvasClassName} />
                {children}
                <canvas ref={frontRef} className={canvasClassName} />
                <canvas
                    ref={solRef}
                    className="absolute top-1/2 left-1/2 size-[220px] opacity-0 motion-reduce:hidden"
                />
                <canvas ref={enduranceRef} className={shipClassName} />
                <canvas ref={enterpriseRef} className={shipClassName} />
            </div>
            <canvas
                ref={laniakeaRef}
                className="pointer-events-none absolute top-1/2 left-1/2 size-[1000px] -translate-1/2 opacity-0 motion-reduce:hidden"
            />
            <canvas
                ref={closeupRef}
                className="pointer-events-none absolute top-1/2 left-1/2 size-[780px] opacity-0 motion-reduce:hidden"
            />
            {CLOSEUPS.map((closeup) => (
                <div
                    key={closeup.name}
                    ref={(spot) => {
                        spotRefs.current[closeup.name] = spot;
                    }}
                    data-test={closeup.name.toLowerCase().replace(' ', '-')}
                    data-state="idle"
                    aria-hidden="true"
                    className="pointer-events-none absolute top-1/2 left-1/2 size-8 rounded-full motion-reduce:hidden"
                />
            ))}
        </>
    );
}
