import {
    CalendarDays,
    ChartColumn,
    Database,
    Globe,
    Image as ImageIcon,
    Lock,
    Mail,
    MousePointerClick,
    SquareCheck,
    Table2,
    ToggleRight,
    Users,
} from 'lucide-react';
import type { RefObject } from 'react';
import { useEffect, useRef } from 'react';

/** When the hero prompt is sent and its drop (animated in CSS) starts to fall. */
export const DROP_START_MS = 2600;

/** When that drop hits the surface. */
export const IMPACT_DELAY_MS = DROP_START_MS + 880;

const TAU = Math.PI * 2;
const MAX_PARTICLES = 520;
const GRID_SPACING = 28;
const SHOCKWAVE_SPEED = 650;

const CODE_TOKENS = [
    '</>',
    '{ }',
    '=>',
    'fn()',
    '<div>',
    'SELECT *',
    '200 OK',
    '&&',
    '[ ]',
    'git push',
    '===',
    'npm run',
];
const ICONS = [
    Database,
    ChartColumn,
    ToggleRight,
    Lock,
    Globe,
    Table2,
    CalendarDays,
    SquareCheck,
    Mail,
    Users,
    MousePointerClick,
    ImageIcon,
];
type Particle = {
    kind: 'code' | 'icon' | 'spark';
    glyph: number;
    x: number;
    y: number;
    vx: number;
    vy: number;
    rotation: number;
    spin: number;
    bornAt: number;
    restAlpha: number;
    phase: number;
};

type Impact = { x: number; y: number; at: number };

/**
 * Shared between the particle canvas and the hero's dot grid, in page
 * (document) coordinates and `performance.now()` milliseconds.
 */
export const universe = {
    hole: { x: 0, y: 0, size: 0, radius: 0 },
    /** Set while the WebGL black hole is drawing it, so the 2D fallback stays off. */
    rendersHoleInWebgl: false,
    /** Set while a planet's close-up covers the black hole, so it can stop drawing (nobody can see it). */
    isHoleCovered: false,
    /** The WebGL black hole's camera (radians), so the galaxy around it can follow along. */
    view: { yaw: 0, pitch: 0.1, roll: 0.3 },
    impacts: [] as Impact[],
    firstImpactAt: null as number | null,
    /** Smaller bursts other parts of the page ask for, like the header logo popping. */
    pendingBursts: [] as { x: number; y: number }[],
};

function clamp(value: number, min = 0, max = 1): number {
    return Math.min(max, Math.max(min, value));
}

function smoothstep(from: number, to: number, value: number): number {
    const t = clamp((value - from) / (to - from));

    return t * t * (3 - 2 * t);
}

function diskColor(radius: number): string {
    if (radius < 1.8) {
        return '#FFF3D6';
    }

    if (radius < 2.5) {
        return '#FFC857';
    }

    if (radius < 3.5) {
        return '#FF6A2B';
    }

    return '#6F9BFF';
}

/** How long the burst's code bits and icons last before they've faded away (seconds). */
const GLYPH_LIFETIME = 2.8;

/**
 * The drop's burst of code bits, app icons and sparks. The code and icons
 * fade away soon after; the sparks keep drifting faintly across the page. The
 * swirling black hole boots up where the drop landed.
 */
export function ParticleUniverse({
    originRef,
}: {
    originRef: RefObject<HTMLDivElement | null>;
}) {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const iconsRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const canvas = canvasRef.current;
        const context = canvas?.getContext('2d');

        if (
            !canvas ||
            !context ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ) {
            return;
        }

        const iconImages = Array.from(
            iconsRef.current?.querySelectorAll('svg') ?? [],
        ).map((svg) => {
            const image = new Image();
            image.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg.outerHTML)}`;

            return image;
        });
        const hole = universe.hole;
        const startedAt = performance.now();
        const particles: Particle[] = [];
        const disk = Array.from({ length: 260 }, () => ({
            radius: 1.35 + Math.random() ** 1.6 * 2.9,
            angle: Math.random() * TAU,
            length: 0.15 + Math.random() * 0.55,
            width: 0.6 + Math.random() * 1.6,
            alpha: 0.25 + Math.random() * 0.75,
        }));
        let width = 0;
        let height = 0;
        let maxRadius = 0;
        let lastFrameAt = startedAt;
        let hasDropLanded = false;
        let frame = 0;

        hole.size = 0;
        hole.radius = 0;
        universe.impacts = [];
        universe.firstImpactAt = null;

        const measure = () => {
            const rect = originRef.current?.getBoundingClientRect();
            const pixelRatio = Math.min(window.devicePixelRatio, 2);

            if (rect) {
                hole.x = rect.left + rect.width / 2 + window.scrollX;
                hole.y = rect.top + rect.height / 2 + window.scrollY;
            }

            width = window.innerWidth;
            height = window.innerHeight;
            maxRadius = clamp(width * 0.055, 30, 80);
            canvas.width = Math.round(width * pixelRatio);
            canvas.height = Math.round(height * pixelRatio);
            context.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);
        };

        const burst = (
            x: number,
            y: number,
            now: number,
            glyphCount: number,
            sparkCount: number,
            power: number,
        ) => {
            const room = MAX_PARTICLES - particles.length;
            const total = Math.min(room, glyphCount + sparkCount);

            for (let index = 0; index < total; index++) {
                const isGlyph = index < glyphCount;
                const kind: Particle['kind'] = !isGlyph
                    ? 'spark'
                    : index % 2 === 0
                      ? 'code'
                      : 'icon';
                const angle = Math.random() * TAU;
                const speed =
                    power *
                    (0.3 + Math.random() * 0.7) *
                    (kind === 'spark' ? 1.15 : 1);

                particles.push({
                    kind,
                    glyph: Math.floor(
                        Math.random() *
                            (kind === 'code'
                                ? CODE_TOKENS.length
                                : ICONS.length),
                    ),
                    x,
                    y,
                    vx: Math.cos(angle) * speed,
                    vy: Math.sin(angle) * speed * 0.85,
                    rotation: (Math.random() - 0.5) * 1.2,
                    spin: (Math.random() - 0.5) * 0.8,
                    bornAt: now,
                    restAlpha:
                        kind === 'spark'
                            ? 0.3 + Math.random() * 0.25
                            : 0.13 + Math.random() * 0.09,
                    phase: Math.random() * TAU,
                });
            }

            universe.impacts = [
                ...universe.impacts.filter((impact) => now - impact.at < 4000),
                { x, y, at: now },
            ].slice(-8);
            universe.firstImpactAt ??= now;
        };

        const landDrop = (now: number) => {
            if (hasDropLanded || now < startedAt + IMPACT_DELAY_MS) {
                return;
            }

            hasDropLanded = true;
            burst(hole.x, hole.y, now, 24, 44, 1500);
        };

        const simulate = (now: number, seconds: number, dt: number) => {
            const sinceImpact = seconds - IMPACT_DELAY_MS / 1000;

            // The black hole boots up to full size right after the burst.
            hole.size = smoothstep(0.3, 2, sinceImpact);
            hole.radius = hole.size > 0 ? 6 + hole.size * maxRadius : 0;

            for (let index = particles.length - 1; index >= 0; index--) {
                const particle = particles[index];

                if (
                    particle.kind !== 'spark' &&
                    now - particle.bornAt > GLYPH_LIFETIME * 1000
                ) {
                    particles.splice(index, 1);
                    continue;
                }

                const ax = Math.cos(seconds * 0.6 + particle.phase) * 8;
                const ay = Math.sin(seconds * 0.5 + particle.phase * 1.3) * 8;
                const speed = Math.hypot(particle.vx, particle.vy);
                const damping = Math.exp(-(0.55 + speed / 500) * dt);

                particle.vx = (particle.vx + ax * dt) * damping;
                particle.vy = (particle.vy + ay * dt) * damping;
                particle.x += particle.vx * dt;
                particle.y += particle.vy * dt;
                particle.rotation += particle.spin * dt;
            }

            for (const segment of disk) {
                segment.angle += (dt * 3.2) / segment.radius ** 1.5;
            }
        };

        const drawDisk = (centerX: number, centerY: number, front: boolean) => {
            const tilt = -0.32;
            const flatten = 0.3;
            const cosTilt = Math.cos(tilt);
            const sinTilt = Math.sin(tilt);

            context.globalCompositeOperation = 'lighter';
            context.lineCap = 'round';

            for (const segment of disk) {
                const middle = segment.angle + segment.length / 2;

                if (Math.sin(middle) > 0 !== front) {
                    continue;
                }

                const reach = segment.radius * hole.radius;
                context.beginPath();

                for (let step = 0; step <= 8; step++) {
                    const theta = segment.angle + (segment.length * step) / 8;
                    const px = Math.cos(theta) * reach;
                    const py = Math.sin(theta) * reach * flatten;
                    const x = centerX + px * cosTilt - py * sinTilt;
                    const y = centerY + px * sinTilt + py * cosTilt;

                    if (step === 0) {
                        context.moveTo(x, y);
                    } else {
                        context.lineTo(x, y);
                    }
                }

                context.globalAlpha =
                    segment.alpha *
                    hole.size *
                    (0.55 + 0.45 * Math.cos(middle)) *
                    (segment.radius > 3.5 ? 0.5 : 1);
                context.strokeStyle = diskColor(segment.radius);
                context.lineWidth = segment.width * (0.6 + hole.size);
                context.stroke();
            }

            context.globalCompositeOperation = 'source-over';
            context.globalAlpha = 1;
        };

        const drawHole = (
            centerX: number,
            centerY: number,
            seconds: number,
        ) => {
            const radius = hole.radius;
            const glow = context.createRadialGradient(
                centerX,
                centerY,
                radius,
                centerX,
                centerY,
                radius * 7,
            );
            glow.addColorStop(0, `rgba(255, 70, 20, ${0.18 * hole.size})`);
            glow.addColorStop(1, 'rgba(255, 70, 20, 0)');
            context.fillStyle = glow;
            context.fillRect(
                centerX - radius * 7,
                centerY - radius * 7,
                radius * 14,
                radius * 14,
            );

            if (hole.size > 0.5) {
                const jet = (hole.size - 0.5) * 2;
                const angle = -0.32 - Math.PI / 2;
                const length = 360 * hole.size + Math.sin(seconds * 3) * 20;

                context.globalCompositeOperation = 'lighter';

                for (const direction of [1, -0.45]) {
                    const endX = centerX + Math.cos(angle) * length * direction;
                    const endY = centerY + Math.sin(angle) * length * direction;
                    const beam = context.createLinearGradient(
                        centerX,
                        centerY,
                        endX,
                        endY,
                    );
                    beam.addColorStop(0, `rgba(255, 243, 214, ${0.8 * jet})`);
                    beam.addColorStop(0.4, `rgba(255, 154, 92, ${0.45 * jet})`);
                    beam.addColorStop(1, 'rgba(255, 77, 28, 0)');
                    context.strokeStyle = beam;
                    context.lineWidth = 2.5;
                    context.beginPath();
                    context.moveTo(centerX, centerY);
                    context.lineTo(endX, endY);
                    context.stroke();
                }

                context.globalCompositeOperation = 'source-over';
            }

            drawDisk(centerX, centerY, false);

            context.globalCompositeOperation = 'lighter';
            context.lineCap = 'round';

            for (const [scale, widthScale, alpha] of [
                [1.28, 0.2, 0.5],
                [1.12, 0.08, 0.35],
            ]) {
                context.globalAlpha = alpha * hole.size;
                context.strokeStyle = '#FFB347';
                context.lineWidth = radius * widthScale;
                context.beginPath();
                context.arc(
                    centerX,
                    centerY,
                    radius * scale,
                    Math.PI - 0.32 + 0.15,
                    TAU - 0.32 - 0.15,
                );
                context.stroke();
            }

            context.globalAlpha = 1;
            context.globalCompositeOperation = 'source-over';

            context.shadowColor = '#FF9A5C';
            context.shadowBlur = 24;
            context.strokeStyle = `rgba(255, 241, 208, ${0.4 + 0.6 * hole.size})`;
            context.lineWidth = 2;
            context.beginPath();
            context.arc(centerX, centerY, radius * 1.04, 0, TAU);
            context.stroke();
            context.shadowBlur = 0;

            context.fillStyle = '#000';
            context.beginPath();
            context.arc(centerX, centerY, radius, 0, TAU);
            context.fill();

            drawDisk(centerX, centerY, true);
        };

        const drawParticles = (
            now: number,
            scrollX: number,
            scrollY: number,
        ) => {
            context.textAlign = 'center';
            context.textBaseline = 'middle';
            context.font =
                '600 14px ui-monospace, SFMono-Regular, Menlo, monospace';

            for (const particle of particles) {
                const x = particle.x - scrollX;
                const y = particle.y - scrollY;

                if (x < -60 || y < -60 || x > width + 60 || y > height + 60) {
                    continue;
                }

                const age = (now - particle.bornAt) / 1000;
                const settled =
                    (age < 1.4
                        ? 1 - (age / 1.4) * (1 - particle.restAlpha)
                        : particle.restAlpha) *
                    (particle.kind === 'spark'
                        ? 1
                        : clamp((GLYPH_LIFETIME - age) / 1.2));
                const distance = Math.hypot(
                    hole.x - particle.x,
                    hole.y - particle.y,
                );
                const heat =
                    hole.radius > 0
                        ? clamp(
                              1 - (distance - hole.radius) / (hole.radius * 5),
                          )
                        : 0;
                const direction = Math.atan2(particle.vy, particle.vx);

                context.globalAlpha = clamp(settled + heat * 0.8);

                if (particle.kind === 'spark') {
                    const length =
                        2 +
                        Math.hypot(particle.vx, particle.vy) * 0.02 +
                        heat * 12;

                    context.globalCompositeOperation = 'lighter';
                    context.strokeStyle = heat > 0.4 ? '#FFF3C4' : '#FFE1D1';
                    context.lineWidth = 1.4;
                    context.beginPath();
                    context.moveTo(x, y);
                    context.lineTo(
                        x - Math.cos(direction) * length,
                        y - Math.sin(direction) * length,
                    );
                    context.stroke();
                    context.globalCompositeOperation = 'source-over';
                    continue;
                }

                context.save();
                context.translate(x, y);

                if (heat > 0.02) {
                    context.rotate(direction);
                    context.scale(1 + heat * 1.8, 1 - heat * 0.6);
                } else {
                    context.rotate(particle.rotation);
                }

                if (particle.kind === 'code') {
                    context.fillStyle = heat > 0.5 ? '#FFE9B0' : '#FFB27A';
                    context.fillText(CODE_TOKENS[particle.glyph], 0, 0);
                } else {
                    const image = iconImages[particle.glyph];

                    if (image?.complete) {
                        context.drawImage(image, -10, -10, 20, 20);
                    }
                }

                context.restore();
            }

            context.globalAlpha = 1;
        };

        const tick = (now: number) => {
            const dt = Math.min(0.05, (now - lastFrameAt) / 1000);
            const seconds = (now - startedAt) / 1000;
            const scrollX = window.scrollX;
            const scrollY = window.scrollY;
            lastFrameAt = now;

            landDrop(now);

            for (const request of universe.pendingBursts.splice(0)) {
                if (hasDropLanded) {
                    burst(request.x, request.y, now, 12, 26, 750);
                }
            }

            simulate(now, seconds, dt);

            context.clearRect(0, 0, width, height);

            if (hole.radius > 0 && !universe.rendersHoleInWebgl) {
                drawHole(hole.x - scrollX, hole.y - scrollY, seconds);
            }

            drawParticles(now, scrollX, scrollY);

            frame = requestAnimationFrame(tick);
        };

        measure();
        window.addEventListener('resize', measure);
        frame = requestAnimationFrame(tick);

        return () => {
            cancelAnimationFrame(frame);
            window.removeEventListener('resize', measure);
        };
    }, [originRef]);

    return (
        <>
            <canvas
                ref={canvasRef}
                aria-hidden="true"
                className="pointer-events-none fixed inset-0 z-[60] size-full motion-reduce:hidden"
            />
            <div ref={iconsRef} className="hidden">
                {ICONS.map((Icon, index) => (
                    <Icon
                        key={index}
                        color="#FF9A5C"
                        size={48}
                        strokeWidth={1.75}
                    />
                ))}
            </div>
        </>
    );
}

/**
 * The dot grid behind the hero, drawn on a canvas so it can bend: each drop's
 * shockwave pushes the dots out, and the black hole's gravity well pulls them
 * in, deeper as it grows, with gentle waves rolling outward.
 */
export function GravityField({
    centerRef,
}: {
    centerRef: RefObject<HTMLDivElement | null>;
}) {
    const canvasRef = useRef<HTMLCanvasElement>(null);

    useEffect(() => {
        const canvas = canvasRef.current;
        const context = canvas?.getContext('2d');

        if (!canvas || !context) {
            return;
        }

        const reducesMotion = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;
        let width = 0;
        let height = 0;
        let pageLeft = 0;
        let pageTop = 0;
        let centerX = 0;
        let centerY = 0;
        let isVisible = true;
        let frame = 0;

        const draw = (now: number) => {
            const firstImpactAt = universe.firstImpactAt;
            const seconds =
                firstImpactAt === null ? -1 : (now - firstImpactAt) / 1000;
            const impacts = universe.impacts.filter(
                (impact) => now - impact.at < 4000,
            );
            const wellDepth =
                seconds < 0
                    ? 0
                    : Math.min(1, seconds / 0.8) *
                      (1 + universe.hole.size * 1.6);

            context.clearRect(0, 0, width, height);

            for (let x = GRID_SPACING / 2; x < width; x += GRID_SPACING) {
                for (let y = GRID_SPACING / 2; y < height; y += GRID_SPACING) {
                    const offsetX = x - centerX;
                    const offsetY = y - centerY;
                    const distance = Math.hypot(offsetX, offsetY) || 1;
                    const fade = 1 - distance / 1150;

                    if (fade <= 0) {
                        continue;
                    }

                    let pushX = 0;
                    let pushY = 0;
                    let glow = 0;

                    if (seconds >= 0 && !reducesMotion) {
                        const well =
                            -16 * Math.exp(-distance / 170) * wellDepth;
                        const wave =
                            Math.sin((distance / 150 - seconds / 2.6) * TAU) *
                            Math.exp(-distance / 850) *
                            Math.min(1, seconds / 1.5);

                        pushX += (offsetX / distance) * (well + 4 * wave);
                        pushY += (offsetY / distance) * (well + 4 * wave);
                        glow += Math.max(0, wave) * 0.45;

                        for (const impact of impacts) {
                            const impactX = impact.x - pageLeft;
                            const impactY = impact.y - pageTop;
                            const fromX = x - impactX;
                            const fromY = y - impactY;
                            const reach = Math.hypot(fromX, fromY) || 1;
                            const age = (now - impact.at) / 1000;
                            const front = reach - age * SHOCKWAVE_SPEED;
                            const shock =
                                Math.exp(-(front * front) / 6000) *
                                Math.exp(-age / 2.2);

                            pushX += (fromX / reach) * 24 * shock;
                            pushY += (fromY / reach) * 24 * shock;
                            glow += shock;
                        }
                    }

                    const heat = Math.min(1, glow);
                    const size = 1.5 + heat * 1.3;
                    context.fillStyle = `rgba(${Math.round(74 + heat * 181)}, ${Math.round(60 + heat * 94)}, ${Math.round(54 + heat * 38)}, ${(fade * (0.55 + heat * 0.45)).toFixed(3)})`;
                    context.fillRect(
                        x + pushX - size / 2,
                        y + pushY - size / 2,
                        size,
                        size,
                    );
                }
            }
        };

        const resize = () => {
            const rect = canvas.getBoundingClientRect();
            const center = centerRef.current?.getBoundingClientRect();
            const pixelRatio = Math.min(window.devicePixelRatio, 2);

            width = rect.width;
            height = rect.height;
            pageLeft = rect.left + window.scrollX;
            pageTop = rect.top + window.scrollY;
            canvas.width = Math.round(width * pixelRatio);
            canvas.height = Math.round(height * pixelRatio);
            context.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);

            if (center) {
                centerX = center.left + center.width / 2 - rect.left;
                centerY = center.top + center.height / 2 - rect.top;
            }

            draw(performance.now());
        };

        const tick = (now: number) => {
            if (isVisible) {
                draw(now);
            }

            frame = requestAnimationFrame(tick);
        };

        const resizeObserver = new ResizeObserver(resize);
        resizeObserver.observe(canvas);

        const visibilityObserver = new IntersectionObserver(([entry]) => {
            isVisible = entry.isIntersecting;
        });
        visibilityObserver.observe(canvas);

        resize();

        if (!reducesMotion) {
            frame = requestAnimationFrame(tick);
        }

        return () => {
            cancelAnimationFrame(frame);
            resizeObserver.disconnect();
            visibilityObserver.disconnect();
        };
    }, [centerRef]);

    return <canvas ref={canvasRef} className="absolute inset-0 size-full" />;
}
