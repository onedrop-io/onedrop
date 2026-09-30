/**
 * An easter egg in the Earth close-up: soon after it's zoomed in, SpaceX's
 * Starship lifts off from Florida on a great plume of fire, leaving a white
 * trail as it climbs away, drops its Super Heavy booster (which flies back to
 * the pad), and heads out to the Moon, where it settles into orbit.
 */

import { smoothstep } from '@/components/home/closeup-gl';

type Vector = { x: number; y: number; z: number };

/** Where the scene is this frame, from the Earth close-up (CSS pixels, and the screen's x right, y up, z toward us). */
export type StarshipScene = {
    /** Seconds on the close-up's clock; it jumping back or ahead starts a new mission. */
    seconds: number;
    center: number;
    earthRadius: number;
    /** The launch pad on the Earth's surface (a unit vector). */
    pad: Vector;
    moon: { x: number; y: number; radius: number; depth: number };
    /** Whether the Earth is fully zoomed in, so it can launch. */
    canLaunch: boolean;
};

/**
 * The mission (seconds after liftoff): climbing on the full stack, the
 * booster flying back to the pad, and the ship's trip out to the Moon.
 */
const MISSION = { ascent: 3, boostBack: 2.4, transfer: 4.6 };

/** How far it climbs and heads downrange by staging (Earth radii, radians). */
const STAGING = { height: 0.32, downrange: 0.5 };

/** How long it waits on the pad once it can launch and the pad is in view, and how long the trail lasts (seconds). */
const LAUNCH_DELAY = 1.5;
const TRAIL_SECONDS = 6;

/** It launches while the pad's this far round toward us (0 is the Earth's edge, 1 its middle). */
const PAD_VIEW = { from: 0.03, to: 0.55 };

/** Its orbit round the Moon: size (in Moon radii), how open it looks, its turn on screen, and seconds per orbit. */
const MOON_ORBIT = { radius: 1.9, open: 0.4, tilt: -0.35, period: 6 };

type Mission = {
    /** When it lifted off, on the close-up's clock; null while waiting on the pad. */
    launchedAt: number | null;
    readyAt: number | null;
    /** Where the ship was at staging (CSS pixels), which way it was going, and where it joins the Moon's orbit. */
    staging: { x: number; y: number; dx: number; dy: number } | null;
    entryAngle: number;
    trail: { x: number; y: number; at: number }[];
    lastSeconds: number | null;
};

let mission: Mission = newMission();

function newMission(): Mission {
    return {
        launchedAt: null,
        readyAt: null,
        staging: null,
        entryAngle: 0,
        trail: [],
        lastSeconds: null,
    };
}

function along(from: Vector, to: Vector, angle: number, height: number) {
    const cos = Math.cos(angle);
    const sin = Math.sin(angle);
    const scale = 1 + height;

    return {
        x: (from.x * cos + to.x * sin) * scale,
        y: (from.y * cos + to.y * sin) * scale,
        z: (from.z * cos + to.z * sin) * scale,
    };
}

function onScreen(scene: StarshipScene, point: Vector) {
    return {
        x: scene.center + point.x * scene.earthRadius,
        y: scene.center - point.y * scene.earthRadius,
        depth: point.z,
    };
}

/**
 * Which way it pitches over: along the Earth's edge and up over the top,
 * rather than truly east, which from here would look like diving back
 * across the globe.
 */
function downrange(pad: Vector): Vector {
    const length = Math.hypot(pad.x, pad.y) || 1;
    const sign = pad.x <= 0 ? -1 : 1;

    return { x: (-pad.y / length) * sign, y: (pad.x / length) * sign, z: 0 };
}

/** The climb on the full stack, `t` seconds after liftoff: straight up, then pitching over. */
function ascentAt(scene: StarshipScene, t: number) {
    const progress = Math.min(t / MISSION.ascent, 1);

    return onScreen(
        scene,
        along(
            scene.pad,
            downrange(scene.pad),
            STAGING.downrange * progress ** 2.2,
            STAGING.height * (1 - (1 - progress) ** 2),
        ),
    );
}

function moonOrbitAt(scene: StarshipScene, angle: number) {
    const radius = MOON_ORBIT.radius * scene.moon.radius;
    const flatX = Math.cos(angle) * radius;
    const flatY = Math.sin(angle) * radius * MOON_ORBIT.open;
    const cos = Math.cos(MOON_ORBIT.tilt);
    const sin = Math.sin(MOON_ORBIT.tilt);

    return {
        x: scene.moon.x + flatX * cos - flatY * sin,
        y: scene.moon.y + flatX * sin + flatY * cos,
        /** In front of the Moon (positive) or behind it. */
        depth: Math.sin(angle),
    };
}

/** The trip out to the Moon, `u` from 0 (staging) to 1 (in orbit round it). */
function transferAt(scene: StarshipScene, u: number) {
    const staging = mission.staging!;
    const end = moonOrbitAt(scene, mission.entryAngle);
    const reach = Math.hypot(end.x - staging.x, end.y - staging.y) * 0.35;
    const bend = {
        x: staging.x + staging.dx * reach,
        y: staging.y + staging.dy * reach,
    };
    const eased = 1 - (1 - u) ** 2;
    const rest = 1 - eased;

    return {
        x:
            rest * rest * staging.x +
            2 * rest * eased * bend.x +
            eased * eased * end.x,
        y:
            rest * rest * staging.y +
            2 * rest * eased * bend.y +
            eased * eased * end.y,
    };
}

export type StarshipPlacement = {
    ship: {
        x: number;
        y: number;
        heading: number;
        /** The full stack (before staging), or just the ship. */
        isStacked: boolean;
        /** How big its engines' fire is, 0 (coasting) to 1 (liftoff). */
        fire: number;
        /** Round the Moon: in front of it or behind it (otherwise null). */
        moonDepth: number | null;
        /** Behind the Earth, so hidden. */
        isHidden: boolean;
    } | null;
    booster: {
        x: number;
        y: number;
        heading: number;
        fire: number;
        alpha: number;
    } | null;
    /** The billowing cloud at the pad at liftoff. */
    smoke: { x: number; y: number; size: number; alpha: number } | null;
    trail: { x: number; y: number; alpha: number }[];
};

/**
 * Moves the mission on to `scene.seconds` (launching once the pad has been
 * in view a moment) and works out where everything is.
 */
export function placeStarship(scene: StarshipScene): StarshipPlacement {
    const { seconds } = scene;
    const step =
        mission.lastSeconds === null ? null : seconds - mission.lastSeconds;

    if (step !== null && (step < 0 || step > 0.5)) {
        mission = newMission();
    }

    mission.lastSeconds = seconds;
    const empty: StarshipPlacement = {
        ship: null,
        booster: null,
        smoke: null,
        trail: [],
    };

    if (mission.launchedAt === null) {
        // Just come round the Earth's sunlit edge, so the climb shows side on.
        const isPadInView =
            scene.pad.z > PAD_VIEW.from && scene.pad.z < PAD_VIEW.to;

        if (!scene.canLaunch || !isPadInView) {
            mission.readyAt = null;

            return empty;
        }

        mission.readyAt ??= seconds;

        if (seconds - mission.readyAt < LAUNCH_DELAY) {
            return empty;
        }

        mission.launchedAt = seconds;
    }

    const t = seconds - mission.launchedAt;
    const { ascent, boostBack, transfer } = MISSION;
    const padPoint = onScreen(scene, scene.pad);
    let ship: StarshipPlacement['ship'];

    if (t < ascent) {
        const at = ascentAt(scene, t);
        const ahead = ascentAt(scene, t + 0.05);

        ship = {
            x: at.x,
            y: at.y,
            heading: Math.atan2(ahead.y - at.y, ahead.x - at.x),
            isStacked: true,
            fire: 1,
            moonDepth: null,
            isHidden:
                at.depth < 0 &&
                Math.hypot(at.x - scene.center, at.y - scene.center) <
                    scene.earthRadius,
        };
    } else {
        if (!mission.staging) {
            const at = ascentAt(scene, ascent);
            const before = ascentAt(scene, ascent - 0.05);
            const length = Math.hypot(at.x - before.x, at.y - before.y) || 1;

            mission.staging = {
                x: at.x,
                y: at.y,
                dx: (at.x - before.x) / length,
                dy: (at.y - before.y) / length,
            };
            mission.entryAngle = Math.atan2(
                at.y - scene.moon.y,
                at.x - scene.moon.x,
            );
        }

        if (t < ascent + transfer) {
            const u = (t - ascent) / transfer;
            const at = transferAt(scene, u);
            const ahead = transferAt(scene, Math.min(u + 0.01, 1));
            const behind = transferAt(scene, Math.max(u - 0.01, 0));

            ship = {
                x: at.x,
                y: at.y,
                heading: Math.atan2(ahead.y - behind.y, ahead.x - behind.x),
                isStacked: false,
                // A burn away from Earth, and another to slow into orbit round the Moon.
                fire: Math.max(
                    0.55 * (1 - smoothstep(0.1, 0.3, u)),
                    0.35 * smoothstep(0.8, 0.9, u),
                ),
                moonDepth: null,
                isHidden: false,
            };
        } else {
            const angle =
                mission.entryAngle +
                ((t - ascent - transfer) / MOON_ORBIT.period) * Math.PI * 2;
            const at = moonOrbitAt(scene, angle);
            const ahead = moonOrbitAt(scene, angle + 0.05);

            ship = {
                x: at.x,
                y: at.y,
                heading: Math.atan2(ahead.y - at.y, ahead.x - at.x),
                isStacked: false,
                fire: 0,
                moonDepth: at.depth,
                isHidden: false,
            };
        }
    }

    // The booster flips round and flies back to be caught at the pad.
    let booster: StarshipPlacement['booster'] = null;

    if (t >= ascent && t < ascent + boostBack) {
        const u = (t - ascent) / boostBack;
        const staging = mission.staging!;
        const home = onScreen(scene, along(scene.pad, scene.pad, 0, 0.03));
        const eased = smoothstep(0, 1, u);
        const lift = Math.sin(u * Math.PI) * 28;

        booster = {
            x: staging.x + (home.x - staging.x) * eased,
            y: staging.y + (home.y - staging.y) * eased - lift,
            heading:
                Math.atan2(staging.dy, staging.dx) +
                Math.PI * smoothstep(0, 0.35, u),
            fire: u < 0.2 || u > 0.85 ? 0.35 : 0,
            alpha: 1 - smoothstep(0.9, 1, u),
        };
    }

    // The white trail it leaves on the way up and out.
    if (t < ascent + transfer) {
        mission.trail.push({ x: ship.x, y: ship.y, at: seconds });
    }

    mission.trail = mission.trail.filter(
        ({ at }) => seconds - at < TRAIL_SECONDS,
    );

    return {
        ship,
        booster,
        smoke:
            t < 4 && padPoint.depth > 0
                ? {
                      x: padPoint.x,
                      y: padPoint.y,
                      size: 6 + 16 * smoothstep(0, 3, t),
                      alpha: 0.7 * (1 - smoothstep(1.5, 4, t)),
                  }
                : null,
        trail: mission.trail.map(({ x, y, at }) => ({
            x,
            y,
            alpha: 1 - (seconds - at) / TRAIL_SECONDS,
        })),
    };
}

/** Fire out of the engines, pointing back along -x (length in CSS pixels). */
function drawFire(
    context: CanvasRenderingContext2D,
    from: number,
    length: number,
    width: number,
) {
    const flicker = 0.85 + Math.random() * 0.3;
    const reach = length * flicker;

    context.save();
    context.globalCompositeOperation = 'lighter';

    const glow = context.createRadialGradient(
        from - reach * 0.3,
        0,
        0,
        from - reach * 0.3,
        0,
        reach * 0.9,
    );
    glow.addColorStop(0, 'rgba(255, 170, 70, 0.55)');
    glow.addColorStop(1, 'rgba(255, 90, 20, 0)');
    context.fillStyle = glow;
    context.beginPath();
    context.arc(from - reach * 0.3, 0, reach * 0.9, 0, Math.PI * 2);
    context.fill();

    const plume = context.createLinearGradient(from, 0, from - reach, 0);
    plume.addColorStop(0, 'rgba(255, 250, 230, 1)');
    plume.addColorStop(0.25, 'rgba(255, 200, 90, 0.95)');
    plume.addColorStop(0.7, 'rgba(255, 110, 30, 0.6)');
    plume.addColorStop(1, 'rgba(255, 70, 20, 0)');
    context.fillStyle = plume;
    context.beginPath();
    context.moveTo(from, -width / 2);
    context.quadraticCurveTo(
        from - reach * 0.35,
        -width * 1.3,
        from - reach,
        0,
    );
    context.quadraticCurveTo(from - reach * 0.35, width * 1.3, from, width / 2);
    context.closePath();
    context.fill();
    context.restore();
}

/** A stainless-steel Starship, nose along +x; the full stack has the Super Heavy booster under it. */
function drawVehicle(
    context: CanvasRenderingContext2D,
    part: 'stack' | 'ship' | 'booster',
) {
    const steel = context.createLinearGradient(0, -1.6, 0, 1.6);
    steel.addColorStop(0, '#F4F6F8');
    steel.addColorStop(0.5, '#B9BFC6');
    steel.addColorStop(1, '#7D848C');
    context.fillStyle = steel;

    const shipFrom = part === 'stack' ? 1 : -4.5;

    if (part !== 'ship') {
        // Super Heavy, with its grid fins near the top.
        const boosterFrom = part === 'stack' ? -10 : -5.5;

        context.fillRect(boosterFrom, -1.5, 11, 3);
        context.fillStyle = '#5B6168';
        context.fillRect(boosterFrom + 9.4, -2.3, 0.8, 0.8);
        context.fillRect(boosterFrom + 9.4, 1.5, 0.8, 0.8);
        context.fillStyle = steel;
    }

    if (part !== 'booster') {
        context.fillRect(shipFrom, -1.5, 6.5, 3);
        context.beginPath();
        context.moveTo(shipFrom + 6.5, -1.5);
        context.quadraticCurveTo(shipFrom + 9, -1.3, shipFrom + 9.5, 0);
        context.quadraticCurveTo(shipFrom + 9, 1.3, shipFrom + 6.5, 1.5);
        context.fill();
        // Its flaps, fore and aft.
        context.fillStyle = '#3E4349';
        context.fillRect(shipFrom + 0.2, -2.2, 1.6, 0.7);
        context.fillRect(shipFrom + 0.2, 1.5, 1.6, 0.7);
        context.fillRect(shipFrom + 6, -1.9, 1, 0.5);
        context.fillRect(shipFrom + 6, 1.4, 1, 0.5);
    }
}

/** Draws the trail, the smoke at the pad, and the booster: everything but the ship itself. */
export function drawStarshipTrail(
    context: CanvasRenderingContext2D,
    placement: StarshipPlacement,
    alpha: number,
) {
    const { trail, smoke, booster } = placement;

    if (alpha <= 0) {
        return;
    }

    context.save();
    context.lineCap = 'round';

    for (const [width, strength] of [
        [5, 0.12],
        [1.6, 0.75],
    ]) {
        context.lineWidth = width;

        for (let index = 1; index < trail.length; index++) {
            context.globalAlpha = alpha * strength * trail[index].alpha ** 1.5;
            context.strokeStyle = '#FFFFFF';
            context.beginPath();
            context.moveTo(trail[index - 1].x, trail[index - 1].y);
            context.lineTo(trail[index].x, trail[index].y);
            context.stroke();
        }
    }

    if (smoke) {
        context.globalAlpha = alpha * smoke.alpha;

        for (const [dx, dy, grow] of [
            [0, 0, 1],
            [-0.6, 0.3, 0.7],
            [0.6, 0.3, 0.7],
            [-1.1, 0.5, 0.5],
            [1.1, 0.5, 0.5],
        ]) {
            const radius = smoke.size * grow;
            const puff = context.createRadialGradient(
                smoke.x + dx * smoke.size,
                smoke.y + dy * smoke.size,
                0,
                smoke.x + dx * smoke.size,
                smoke.y + dy * smoke.size,
                radius,
            );
            puff.addColorStop(0, 'rgba(240, 236, 230, 0.8)');
            puff.addColorStop(1, 'rgba(210, 205, 200, 0)');
            context.fillStyle = puff;
            context.beginPath();
            context.arc(
                smoke.x + dx * smoke.size,
                smoke.y + dy * smoke.size,
                radius,
                0,
                Math.PI * 2,
            );
            context.fill();
        }
    }

    if (booster) {
        context.globalAlpha = alpha * booster.alpha;
        context.translate(booster.x, booster.y);
        context.rotate(booster.heading);

        if (booster.fire > 0) {
            drawFire(context, -5.5, 16 * booster.fire, 2.6);
        }

        drawVehicle(context, 'booster');
    }

    context.restore();
}

/** Draws the ship (or the full stack) with its fire and its name. */
export function drawStarship(
    context: CanvasRenderingContext2D,
    placement: StarshipPlacement,
    alpha: number,
) {
    const { ship } = placement;

    if (!ship || ship.isHidden || alpha <= 0) {
        return;
    }

    context.save();
    context.globalAlpha = alpha;
    context.translate(ship.x, ship.y);
    context.rotate(ship.heading);

    if (ship.fire > 0) {
        drawFire(
            context,
            ship.isStacked ? -10 : -4.5,
            (ship.isStacked ? 46 : 16) * ship.fire,
            ship.isStacked ? 5 : 2.6,
        );
    }

    drawVehicle(context, ship.isStacked ? 'stack' : 'ship');
    context.restore();

    context.save();
    context.globalAlpha = 0.75 * alpha;
    context.font = '500 10px "Instrument Sans", sans-serif';
    context.textAlign = 'left';
    context.fillStyle = '#DCEBFF';
    context.fillText('Starship', ship.x + 12, ship.y - 10);
    context.restore();
}
