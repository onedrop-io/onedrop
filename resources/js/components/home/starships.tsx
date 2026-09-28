/**
 * Two easter eggs hanging around the black hole, drawn by hand on canvas
 * (no models): the Endurance from Interstellar, spinning its ring of modules
 * as it orbits, and the Enterprise-D, which cruises the outer galaxy and now
 * and then jumps to warp.
 *
 * Both draw centered at (x, y) in CSS pixels, scaled by `size`.
 */

/** Canvas size each ship gets (CSS pixels), with room for the warp streak. */
export const SHIP_FIELD_SIZE = 240;

const ENDURANCE_MODULES = 12;

/**
 * The Endurance: a ring of twelve modules around a docking hub, turning to
 * make gravity. `spin` is the ring's angle; (lightX, lightY) points toward
 * the black hole, whose glow warms the side facing it.
 */
export function drawEndurance(
    context: CanvasRenderingContext2D,
    x: number,
    y: number,
    size: number,
    spin: number,
    lightX: number,
    lightY: number,
) {
    const radius = 15 * size;
    const squash = 0.42;
    const tilt = -0.35;
    const cos = Math.cos(tilt);
    const sin = Math.sin(tilt);
    const place = (localX: number, localY: number) => ({
        x: x + localX * cos - localY * squash * sin,
        y: y + localX * sin + localY * squash * cos,
    });

    const ring = (from: number, to: number) => {
        context.beginPath();

        for (let step = 0; step <= 24; step++) {
            const angle = from + ((to - from) * step) / 24;
            const point = place(
                Math.cos(angle) * radius,
                Math.sin(angle) * radius,
            );

            if (step === 0) {
                context.moveTo(point.x, point.y);
            } else {
                context.lineTo(point.x, point.y);
            }
        }

        context.stroke();
    };

    const modules = Array.from({ length: ENDURANCE_MODULES }, (_, index) => {
        const angle = spin + (index / ENDURANCE_MODULES) * Math.PI * 2;

        return { angle, depth: Math.sin(angle) };
    }).sort((a, b) => a.depth - b.depth);

    const drawModule = (angle: number, depth: number) => {
        const halfLength = (Math.PI / ENDURANCE_MODULES) * radius * 0.72;
        const halfWidth = 1.6 * size;
        const alongX = -Math.sin(angle);
        const alongY = Math.cos(angle);
        const outX = Math.cos(angle);
        const outY = Math.sin(angle);
        const centerX = outX * radius;
        const centerY = outY * radius;
        const corners = [
            [halfLength, halfWidth],
            [halfLength, -halfWidth],
            [-halfLength, -halfWidth],
            [-halfLength, halfWidth],
        ].map(([along, out]) =>
            place(
                centerX + alongX * along + outX * out,
                centerY + alongY * along + outY * out,
            ),
        );
        const center = place(centerX, centerY);
        const facing = clamp01(
            0.5 +
                ((center.x - x) * lightX + (center.y - y) * lightY) /
                    (radius * 2),
        );
        const brightness = 0.5 + 0.5 * ((depth + 1) / 2);

        context.globalAlpha = 1;
        context.fillStyle = shade('#ECE8E0', brightness);
        context.beginPath();
        corners.forEach((corner, index) =>
            index === 0
                ? context.moveTo(corner.x, corner.y)
                : context.lineTo(corner.x, corner.y),
        );
        context.closePath();
        context.fill();

        // Warm light from the black hole on the side that faces it.
        context.globalAlpha = 0.7 * facing;
        context.strokeStyle = '#FFC08A';
        context.lineWidth = 0.6;
        context.stroke();
        context.globalAlpha = 1;
    };

    context.save();
    context.lineWidth = 0.9 * size;
    context.strokeStyle = 'rgba(200, 196, 188, 0.55)';
    ring(Math.PI, Math.PI * 2);

    for (const module of modules) {
        if (module.depth < 0) {
            drawModule(module.angle, module.depth);
        }
    }

    // The docking hub in the middle, with a Ranger docked.
    context.fillStyle = '#D8D3CA';
    context.beginPath();
    context.ellipse(x, y, 2.6 * size, 1.4 * size, tilt, 0, Math.PI * 2);
    context.fill();
    context.fillStyle = '#6E6A64';
    context.beginPath();
    context.ellipse(x, y, 1.1 * size, 0.6 * size, tilt, 0, Math.PI * 2);
    context.fill();
    context.fillStyle = '#F2EEE6';
    context.beginPath();
    context.moveTo(x + 2.2 * size, y - 1.6 * size);
    context.lineTo(x + 5.4 * size, y - 2.6 * size);
    context.lineTo(x + 4.6 * size, y - 0.9 * size);
    context.closePath();
    context.fill();

    context.strokeStyle = 'rgba(220, 216, 208, 0.75)';
    ring(0, Math.PI);

    for (const module of modules) {
        if (module.depth >= 0) {
            drawModule(module.angle, module.depth);
        }
    }

    context.restore();
}

/**
 * The Enterprise-D, seen side-on and heading along `heading` (radians).
 * `warp` runs 0 → 1 as it jumps to warp (stretching ahead, then gone in a
 * flash) and −1 → 0 as it drops out of warp.
 */
export function drawEnterprise(
    context: CanvasRenderingContext2D,
    x: number,
    y: number,
    size: number,
    heading: number,
    warp: number,
) {
    const leaving = Math.max(0, warp);
    const arriving = Math.max(0, -warp);
    const stretch = 1 + leaving ** 2 * 9 + arriving ** 2 * 6;
    const opacity = warp > 0 ? 1 - leaving ** 3 : 1 - arriving ** 3;

    context.save();
    context.translate(x, y);
    context.rotate(heading);

    // Keep it upright when it's flying right to left.
    if (Math.cos(heading) < 0) {
        context.scale(1, -1);
    }

    // Warp streak: ahead of the ship as it leaves, behind it as it arrives.
    if (leaving > 0 || arriving > 0) {
        const length = (leaving > 0 ? leaving : arriving) * 110 * size;
        const streak = context.createLinearGradient(
            leaving > 0 ? 0 : -length,
            0,
            leaving > 0 ? length : 0,
            0,
        );
        streak.addColorStop(0, 'rgba(160, 220, 255, 0)');
        streak.addColorStop(1, 'rgba(210, 240, 255, 0.85)');
        context.globalCompositeOperation = 'lighter';
        context.strokeStyle = streak;
        context.lineWidth = 1.4 * size;
        context.beginPath();
        context.moveTo(leaving > 0 ? 0 : -length, 0);
        context.lineTo(leaving > 0 ? length : 0, 0);
        context.stroke();
        context.globalCompositeOperation = 'source-over';
    }

    const flash =
        leaving > 0.8
            ? (leaving - 0.8) / 0.2
            : arriving > 0.6
              ? 1 - (arriving - 0.6) / 0.4
              : 0;

    context.globalAlpha = opacity;
    context.scale(size, size);
    // Stretch from the tail forward, like jumping to warp in the show.
    context.translate(-16, 0);
    context.scale(stretch, 1);
    context.translate(16, 0);

    // Nacelle pylons.
    context.strokeStyle = '#A9B2BC';
    context.lineWidth = 1.2;
    context.beginPath();
    context.moveTo(-6, 3);
    context.lineTo(-9.5, -0.6);
    context.stroke();

    // Nacelles: blue warp field grilles, red Bussard collectors up front.
    context.fillStyle = '#C3CBD4';
    roundedBar(context, -16, -2.4, 13, 1.9);
    context.save();
    context.shadowColor = '#5EC8FF';
    context.shadowBlur = 4;
    context.fillStyle = '#7FD6FF';
    roundedBar(context, -15, -1.95, 10.5, 0.8);
    context.restore();
    context.save();
    context.shadowColor = '#FF4A3A';
    context.shadowBlur = 4;
    context.fillStyle = '#FF5A44';
    context.beginPath();
    context.arc(-3.3, -1.45, 0.9, 0, Math.PI * 2);
    context.fill();
    context.restore();

    // Engineering hull, with the amber deflector dish.
    context.fillStyle = '#C9D0D8';
    context.beginPath();
    context.ellipse(-4, 3.4, 7.6, 2.3, 0, 0, Math.PI * 2);
    context.fill();
    context.save();
    context.shadowColor = '#FFB05A';
    context.shadowBlur = 3;
    context.fillStyle = '#FFC06A';
    context.beginPath();
    context.arc(3.1, 3.6, 1.1, 0, Math.PI * 2);
    context.fill();
    context.restore();

    // The neck.
    context.fillStyle = '#B8C1CA';
    context.beginPath();
    context.moveTo(1.2, -1);
    context.lineTo(4.2, -1);
    context.lineTo(1, 2.6);
    context.lineTo(-2.2, 2.6);
    context.closePath();
    context.fill();

    // The saucer, with the bridge on top.
    const saucer = context.createLinearGradient(0, -4.6, 0, 0.2);
    saucer.addColorStop(0, '#EEF2F6');
    saucer.addColorStop(1, '#A7B0BA');
    context.fillStyle = saucer;
    context.beginPath();
    context.ellipse(6.5, -2.2, 9.6, 2.4, 0, 0, Math.PI * 2);
    context.fill();
    context.fillStyle = '#E8EDF2';
    context.beginPath();
    context.ellipse(6.2, -4.2, 1.6, 0.8, 0, Math.PI, Math.PI * 2);
    context.fill();
    context.fillStyle = 'rgba(255, 236, 190, 0.8)';

    for (let window = 0; window < 6; window++) {
        context.fillRect(-0.5 + window * 2.6, -2.1, 0.7, 0.35);
    }

    context.restore();

    if (flash > 0) {
        context.save();
        context.globalCompositeOperation = 'lighter';
        const glow = context.createRadialGradient(x, y, 0, x, y, 26 * size);
        glow.addColorStop(0, `rgba(230, 245, 255, ${0.9 * flash})`);
        glow.addColorStop(0.3, `rgba(140, 210, 255, ${0.35 * flash})`);
        glow.addColorStop(1, 'rgba(120, 200, 255, 0)');
        context.fillStyle = glow;
        context.fillRect(x - 26 * size, y - 26 * size, 52 * size, 52 * size);
        context.restore();
    }
}

function roundedBar(
    context: CanvasRenderingContext2D,
    x: number,
    y: number,
    width: number,
    height: number,
) {
    context.beginPath();
    context.roundRect(x, y, width, height, height / 2);
    context.fill();
}

function clamp01(value: number): number {
    return Math.min(1, Math.max(0, value));
}

/** Darkens a #RRGGBB color by `brightness` (0–1). */
function shade(color: string, brightness: number): string {
    const value = parseInt(color.slice(1), 16);
    const channel = (shift: number) =>
        Math.round(((value >> shift) & 255) * brightness);

    return `rgb(${channel(16)}, ${channel(8)}, ${channel(0)})`;
}
