import { useEffect, useRef } from 'react';
import type { ReactNode } from 'react';
import {
    drawEarthCloseup,
    EARTH_CLOSEUP_RADIUS,
    EARTH_CLOSEUP_SIZE,
    earthShowProgress,
} from '@/components/home/earth-closeup';
import { universe } from '@/components/home/particle-universe';
import {
    drawSolSystem,
    EARTH_SIZE,
    earthOffset,
    SOL_FIELD_SIZE,
    SOL_SCREEN_OFFSET,
    voyagerTrails,
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

/** Where the Earth's close-up settles, relative to the black hole (pixels), and how much the galaxy dims behind it. */
const EARTH_CLOSEUP_AT = { x: -30, y: 30 };
const EARTH_CLOSEUP_DIM = 0.7;

/** After the Earth shrinks back, how long before hovering it again replays the close-up (milliseconds). */
const EARTH_CLOSEUP_COOLDOWN_MS = 2000;

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
 * WebGL black hole, and follows its camera. Hovering over the Earth zooms
 * in on it for a little while (see `earth-closeup`).
 */
export function Galaxy({ children }: { children: ReactNode }) {
    const backRef = useRef<HTMLCanvasElement>(null);
    const frontRef = useRef<HTMLCanvasElement>(null);
    const solRef = useRef<HTMLCanvasElement>(null);
    const enduranceRef = useRef<HTMLCanvasElement>(null);
    const enterpriseRef = useRef<HTMLCanvasElement>(null);
    const earthRef = useRef<HTMLCanvasElement>(null);
    const earthSpotRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const back = backRef.current?.getContext('2d');
        const front = frontRef.current?.getContext('2d');
        const sol = solRef.current?.getContext('2d');
        const endurance = enduranceRef.current?.getContext('2d');
        const enterprise = enterpriseRef.current?.getContext('2d');
        const earth = earthRef.current?.getContext('2d');
        const earthSpot = earthSpotRef.current;

        if (
            !back ||
            !front ||
            !sol ||
            !endurance ||
            !enterprise ||
            !earth ||
            !earthSpot ||
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

        earth.canvas.width = EARTH_CLOSEUP_SIZE * solScale;
        earth.canvas.height = EARTH_CLOSEUP_SIZE * solScale;

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
        let earthShowStartedAt: number | null = null;
        let earthCooldownUntil = 0;
        let isHoveringEarth = false;

        // The headline's layer sits on top of the galaxy, so check where the
        // pointer is rather than waiting for the Earth's spot to be hovered.
        const trackPointer = (event: PointerEvent) => {
            const spot = earthSpot.getBoundingClientRect();

            isHoveringEarth =
                Math.hypot(
                    event.clientX - (spot.left + spot.width / 2),
                    event.clientY - (spot.top + spot.height / 2),
                ) <
                spot.width / 2;
        };
        window.addEventListener('pointermove', trackPointer, {
            passive: true,
        });

        const endEarthShow = (now: number) => {
            earthShowStartedAt = null;
            earthCooldownUntil = now + EARTH_CLOSEUP_COOLDOWN_MS;
            isHoveringEarth = false;
            earth.canvas.style.opacity = '0';
            earthSpot.dataset.state = 'idle';
        };

        const visibilityObserver = new IntersectionObserver(([entry]) => {
            isVisible = entry.isIntersecting;
        });
        visibilityObserver.observe(back.canvas);

        const tick = (now: number) => {
            frame = requestAnimationFrame(tick);

            if (!isVisible) {
                return;
            }

            if (!universe.rendersHoleInWebgl || universe.hole.size < 0.9) {
                for (const layer of layers) {
                    layer.canvas.style.opacity = '0';
                }

                appearedAt = null;
                if (earthShowStartedAt !== null) {
                    endEarthShow(now);
                }

                return;
            }

            appearedAt ??= now;
            const fade = smoothstep(
                0,
                FADE_IN_SECONDS * 1000,
                now - appearedAt,
            );
            let closeup =
                earthShowStartedAt === null
                    ? null
                    : earthShowProgress((now - earthShowStartedAt) / 1000);

            if (earthShowStartedAt !== null && closeup === null) {
                endEarthShow(now);
            }

            for (const layer of layers) {
                layer.canvas.style.opacity = String(
                    fade * (1 - EARTH_CLOSEUP_DIM * (closeup ?? 0)),
                );
            }

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
            const core = back.createRadialGradient(0, 0, 0, 0, 0, 330 * zoom);
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
                const distance = Math.hypot(point.x - center, point.y - center);
                // Keep near-side clouds from fogging over the black hole itself.
                const clearing = isNear
                    ? smoothstep(holeRadius * 1.3, holeRadius * 3.2, distance)
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
                    0.75 + 0.25 * Math.sin(seconds * star.twinkle + star.phase);
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
                context.fillRect(0, 0, FIELD_SIZE * EDGE_FADE_END, FIELD_SIZE);
                context.globalCompositeOperation = 'source-over';
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

            // Hovering over the Earth zooms in on it for a little while.
            const earthAt = earthOffset(projectOffset, seconds);
            const earthX = solOffset.x + earthAt.x;
            const earthY = solOffset.y + earthAt.y;
            earthSpot.style.transform = `translate(${earthX}px, ${earthY}px) translate(-50%, -50%)`;

            if (
                isHoveringEarth &&
                earthShowStartedAt === null &&
                fade === 1 &&
                now > earthCooldownUntil
            ) {
                earthShowStartedAt = now;
                closeup = 0;
                earthSpot.dataset.state = 'playing';
            }

            if (earthShowStartedAt !== null && closeup !== null) {
                const smallest = (EARTH_SIZE * zoom) / EARTH_CLOSEUP_RADIUS;
                const closeupX =
                    earthX + (EARTH_CLOSEUP_AT.x - earthX) * closeup;
                const closeupY =
                    earthY + (EARTH_CLOSEUP_AT.y - earthY) * closeup;

                earth.canvas.style.opacity = '1';
                earth.canvas.style.transform = `translate(${closeupX - EARTH_CLOSEUP_SIZE / 2}px, ${closeupY - EARTH_CLOSEUP_SIZE / 2}px) scale(${smallest * (1 / smallest) ** closeup})`;
                earth.setTransform(solScale, 0, 0, solScale, 0, 0);
                earth.clearRect(0, 0, EARTH_CLOSEUP_SIZE, EARTH_CLOSEUP_SIZE);
                drawEarthCloseup(
                    earth,
                    Math.min(solScale, 1.5),
                    (now - earthShowStartedAt) / 1000,
                    closeup,
                );
            }

            // The Voyagers leave Earth and head out across the galaxy.
            for (const voyager of voyagerTrails(seconds)) {
                if (voyager.visibility <= 0) {
                    continue;
                }

                const points = voyager.trail.map((point) =>
                    project(sun.x + point.x, sun.z + point.z),
                );
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
        };
    }, []);

    const canvasClassName =
        'absolute top-1/2 left-1/2 size-[1700px] -translate-1/2 opacity-0 motion-reduce:hidden';

    const shipClassName =
        'absolute top-1/2 left-1/2 size-[240px] opacity-0 motion-reduce:hidden';

    return (
        <>
            <canvas ref={backRef} className={canvasClassName} />
            {children}
            <canvas ref={frontRef} className={canvasClassName} />
            <canvas
                ref={solRef}
                className="absolute top-1/2 left-1/2 size-[220px] opacity-0 motion-reduce:hidden"
            />
            <canvas ref={enduranceRef} className={shipClassName} />
            <canvas ref={enterpriseRef} className={shipClassName} />
            <canvas
                ref={earthRef}
                className="pointer-events-none absolute top-1/2 left-1/2 size-[720px] opacity-0 motion-reduce:hidden"
            />
            <div
                ref={earthSpotRef}
                data-test="earth"
                data-state="idle"
                aria-hidden="true"
                className="pointer-events-none absolute top-1/2 left-1/2 size-8 rounded-full motion-reduce:hidden"
            />
        </>
    );
}
