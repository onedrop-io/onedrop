/**
 * An easter egg in the Earth close-up: hover over the Moon and the view flies
 * in to it, then dives through to NASA's black-and-white TV pictures of the
 * Apollo 11 moonwalk (Neil Armstrong's first step, Buzz Aldrin climbing down,
 * and the flag going up), before pulling back out to the Earth.
 *
 * The footage is NASA's (public domain), from its Apollo 11 moonwalk montage
 * on Wikimedia Commons, cut down and shrunk for the web. The home page's
 * footer credits it.
 */

import { smoothstep } from '@/components/home/closeup-gl';

/**
 * How long each part of the moonwalk takes (seconds): flying in to the Moon,
 * diving through to the footage, watching it (the clip's length), rising
 * back out to the Moon, and flying back out to the Earth.
 */
export const MOONWALK_SHOW = {
    approach: 1.6,
    dive: 1.3,
    watch: 26,
    rise: 1.2,
    retreat: 1.4,
};

const FOOTAGE = '/videos/apollo/moonwalk.mp4';

/** The TV picture (CSS pixels), a little above the middle like the Mars rover's camera view. */
const VIEW = { width: 600, height: 338, corner: 14, raise: 20 };

/** What's happening in the clip, from when (seconds into it). */
const CAPTIONS = [
    { from: 0, text: "Neil Armstrong's first step" },
    { from: 10, text: 'Buzz Aldrin climbs down' },
    { from: 15, text: 'Planting the flag' },
];

let footage: HTMLVideoElement | null = null;

/** Starts loading the footage (about 130 KB) in the background: see `isMoonwalkReady`. */
export function loadMoonwalk() {
    if (footage) {
        return;
    }

    footage = document.createElement('video');
    footage.muted = true;
    footage.playsInline = true;
    footage.preload = 'auto';
    footage.src = FOOTAGE;
    footage.load();
}

/** Whether the footage has loaded enough to show its first picture. */
export function isMoonwalkReady(): boolean {
    return footage !== null && footage.readyState >= 2;
}

/** The whole moonwalk's length (seconds). */
export function moonwalkLength(): number {
    const { approach, dive, watch, rise, retreat } = MOONWALK_SHOW;

    return approach + dive + watch + rise + retreat;
}

/**
 * How far into each part of the moonwalk `seconds` is: `approach` flies in to
 * the Moon (0 to 1, and back to 0 on the way out), `down` dives through to the
 * footage (likewise), and `watch` is how far into the footage it is.
 */
export function moonwalkPhases(seconds: number) {
    const { approach, dive, watch, rise, retreat } = MOONWALK_SHOW;
    const diveAt = approach;
    const watchAt = diveAt + dive;
    const riseAt = watchAt + watch;
    const retreatAt = riseAt + rise;
    const ease = (t: number) => smoothstep(0, 1, t);

    return {
        approach:
            ease(seconds / approach) *
            (1 - ease((seconds - retreatAt) / retreat)),
        down:
            ease((seconds - diveAt) / dive) *
            (1 - ease((seconds - riseAt) / rise)),
        watch: seconds - watchAt,
    };
}

/** Stops the footage, ready to play again from the start. */
export function stopMoonwalk() {
    if (footage && !footage.paused) {
        footage.pause();
    }

    if (footage) {
        footage.currentTime = 0;
    }
}

/**
 * Draws the footage like a 1969 TV picture, centered in a `size` canvas,
 * `watchSeconds` into it (it starts playing once that's past zero), at
 * `alpha` and `scale`.
 */
export function drawMoonwalk(
    context: CanvasRenderingContext2D,
    size: number,
    watchSeconds: number,
    alpha: number,
    scale: number,
) {
    if (!footage) {
        return;
    }

    if (watchSeconds >= 0 && footage.paused && !footage.ended) {
        footage.play().catch(() => {});
    }

    if (alpha <= 0) {
        return;
    }

    const width = VIEW.width * scale;
    const height = VIEW.height * scale;
    const left = size / 2 - width / 2;
    const top = size / 2 - height / 2 - VIEW.raise;

    context.save();
    context.globalAlpha = alpha;
    context.shadowColor = 'rgba(190, 210, 255, 0.2)';
    context.shadowBlur = 40;
    context.beginPath();
    context.roundRect(left, top, width, height, VIEW.corner * scale);
    context.fillStyle = '#000000';
    context.fill();
    context.shadowColor = 'transparent';
    context.clip();
    context.drawImage(footage, left, top, width, height);

    // The scan lines and dark, rounded corners of an old TV set.
    context.fillStyle = 'rgba(0, 0, 0, 0.22)';

    for (let line = top; line < top + height; line += 3 * scale) {
        context.fillRect(left, line, width, scale);
    }

    const vignette = context.createRadialGradient(
        size / 2,
        top + height / 2,
        height * 0.35,
        size / 2,
        top + height / 2,
        width * 0.62,
    );
    vignette.addColorStop(0, 'rgba(0, 0, 0, 0)');
    vignette.addColorStop(1, 'rgba(0, 0, 0, 0.7)');
    context.fillStyle = vignette;
    context.fillRect(left, top, width, height);

    // A readout, like the broadcast's captions.
    context.font = '600 10px "Instrument Sans", sans-serif';
    context.fillStyle = 'rgba(236, 242, 255, 0.85)';
    context.textAlign = 'left';
    context.fillText('APOLLO 11 · LIVE FROM THE MOON', left + 16, top + 22);
    context.textAlign = 'right';
    context.fillText('JULY 20, 1969', left + width - 16, top + 22);

    const playedFor = footage.currentTime;
    const caption = CAPTIONS.findLast(({ from }) => playedFor >= from);

    if (caption) {
        context.font = '500 13px "Instrument Sans", sans-serif';
        context.textAlign = 'left';
        context.fillStyle = 'rgba(255, 255, 255, 0.92)';
        context.fillText(caption.text, left + 16, top + height - 18);
    }

    context.restore();

    context.save();
    context.globalAlpha = alpha * 0.5;
    context.strokeStyle = 'rgba(210, 225, 255, 0.35)';
    context.lineWidth = 1;
    context.beginPath();
    context.roundRect(left, top, width, height, VIEW.corner * scale);
    context.stroke();
    context.restore();
}
