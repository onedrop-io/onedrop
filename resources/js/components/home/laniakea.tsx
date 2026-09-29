/**
 * Laniakea, the supercluster our Milky Way belongs to: the real positions of
 * about 41,000 galaxies around us from the 2MASS Redshift Survey (Huchra et
 * al. 2012, via VizieR), in supergalactic coordinates with distances from
 * their redshifts, drawn as a slowly turning 3D cloud with three.js. The
 * galaxies inside Laniakea glow warm, and streams of motes flow through them
 * toward the Great Attractor. The streams and the outline are an artist's
 * approximation of Tully et al. (2014), not a measured flow field; the
 * galaxies are real. The home page's footer credits the survey.
 */

import type * as Three from 'three';
import { smoothstep } from '@/components/home/closeup-gl';

const GALAXIES = '/images/laniakea/galaxies.bin';

/** Where Laniakea's flows converge (the Great Attractor, by Norma and Centaurus), and roughly its middle and size (Mpc, supergalactic). */
const ATTRACTOR = { x: -52, y: 6, z: -2 };
const CENTER = { x: -38, y: 8, z: -3 };
const RADII = { x: 78, y: 64, z: 52 };

/** The clusters worth naming (supergalactic Mpc). */
const LANDMARKS = [
    { name: 'You are here', x: 0, y: 0, z: 0, isHome: true },
    { name: 'Virgo Cluster', x: -3.5, y: 15.3, z: -0.6 },
    { name: 'Great Attractor', x: -52, y: 6, z: -2 },
    { name: 'Hydra', x: -32.5, y: 27.9, z: -32.9 },
    { name: 'Perseus–Pisces', x: 72.6, y: -15.6, z: -18.8 },
    { name: 'Coma', x: 0.7, y: 97.9, z: 14.3 },
];

/** Pulls toward the Great Attractor, and toward the nearer clusters on the way (supergalactic Mpc, relative strength). */
const PULLS = [
    { ...ATTRACTOR, strength: 6 },
    { x: -3.5, y: 15.3, z: -0.6, strength: 2.2 },
    { x: -32.5, y: 27.9, z: -32.9, strength: 2.4 },
    { x: -45.2, y: -33.7, z: 16.1, strength: 2 },
    { x: -43.6, y: 19, z: -9.6, strength: 2.4 },
];

const STREAMS = 420;
const STREAM_STEPS = 48;
const MOTES_PER_STREAM = 3;

type Scene = {
    three: typeof Three;
    renderer: Three.WebGLRenderer;
    scene: Three.Scene;
    camera: Three.PerspectiveCamera;
    group: Three.Group;
    streams: Float32Array[];
    motes: Three.BufferAttribute;
    motePhases: Float32Array;
};

let scene: Scene | null = null;
let isLoading = false;

/** How far outside Laniakea's (lumpy) outline a point is: below 1 inside, above 1 outside. */
function outside(x: number, y: number, z: number): number {
    const dx = (x - CENTER.x) / RADII.x;
    const dy = (y - CENTER.y) / RADII.y;
    const dz = (z - CENTER.z) / RADII.z;
    const lumps =
        1 +
        0.12 * Math.sin(x * 0.07 + y * 0.05) * Math.cos(z * 0.06 - x * 0.03);

    return Math.sqrt(dx * dx + dy * dy + dz * dz) / lumps;
}

/** Which way matter flows at a point: toward the Great Attractor, bending past the clusters on the way. */
function flow(x: number, y: number, z: number): [number, number, number] {
    let fx = 0;
    let fy = 0;
    let fz = 0;

    for (const pull of PULLS) {
        const dx = pull.x - x;
        const dy = pull.y - y;
        const dz = pull.z - z;
        const distance = Math.hypot(dx, dy, dz) + 6;
        const strength = pull.strength / distance ** 1.4;

        fx += dx * strength;
        fy += dy * strength;
        fz += dz * strength;
    }

    const length = Math.hypot(fx, fy, fz) || 1;

    // A slow swirl, so the streams bend along the way instead of running straight in.
    const swirl = 0.45;
    const bend: [number, number, number] = [
        Math.sin(y * 0.045 + z * 0.03),
        Math.sin(z * 0.05 + x * 0.035),
        Math.sin(x * 0.04 + y * 0.05),
    ];
    const bx = fx / length + bend[0] * swirl;
    const by = fy / length + bend[1] * swirl;
    const bz = fz / length + bend[2] * swirl;
    const bent = Math.hypot(bx, by, bz) || 1;

    return [bx / bent, by / bent, bz / bent];
}

function pointSprite(three: typeof Three): Three.Texture {
    const canvas = document.createElement('canvas');
    canvas.width = 64;
    canvas.height = 64;
    const context = canvas.getContext('2d')!;
    const glow = context.createRadialGradient(32, 32, 0, 32, 32, 32);
    glow.addColorStop(0, 'rgba(255, 255, 255, 1)');
    glow.addColorStop(0.25, 'rgba(255, 255, 255, 0.55)');
    glow.addColorStop(1, 'rgba(255, 255, 255, 0)');
    context.fillStyle = glow;
    context.fillRect(0, 0, 64, 64);

    return new three.CanvasTexture(canvas);
}

async function build(): Promise<Scene> {
    const three = await import('three');
    const data = await fetch(GALAXIES).then((response) => {
        if (!response.ok) {
            throw new Error(`Couldn't load ${GALAXIES}`);
        }

        return response.arrayBuffer();
    });

    const count = new DataView(data).getUint32(0, true);
    const positions = new Int16Array(data, 4, count * 3);
    const brightness = new Uint8Array(data, 4 + count * 6, count);

    const renderer = new three.WebGLRenderer({
        alpha: true,
        antialias: true,
    });
    renderer.setClearColor(0x000000, 0);

    const threeScene = new three.Scene();
    const camera = new three.PerspectiveCamera(38, 1, 1, 2000);
    const group = new three.Group();
    threeScene.add(group);
    const sprite = pointSprite(three);

    // The galaxies: warm inside Laniakea, cool and dim beyond it.
    const galaxyPositions = new Float32Array(count * 3);
    const galaxyColors = new Float32Array(count * 3);
    const insideColor = new three.Color('#FFD7A1');
    const outsideColor = new three.Color('#7F9BD6');

    for (let index = 0; index < count; index++) {
        const x = positions[index * 3] / 100;
        const y = positions[index * 3 + 1] / 100;
        const z = positions[index * 3 + 2] / 100;
        const edge = outside(x, y, z);
        const glow = 0.35 + (brightness[index] / 255) * 0.9;
        const color = insideColor
            .clone()
            .lerp(outsideColor, smoothstep(0.9, 1.15, edge))
            .multiplyScalar(glow * (edge < 1.1 ? 1 : 0.55));

        galaxyPositions.set([x, y, z], index * 3);
        galaxyColors.set([color.r, color.g, color.b], index * 3);
    }

    const galaxyGeometry = new three.BufferGeometry();
    galaxyGeometry.setAttribute(
        'position',
        new three.BufferAttribute(galaxyPositions, 3),
    );
    galaxyGeometry.setAttribute(
        'color',
        new three.BufferAttribute(galaxyColors, 3),
    );
    group.add(
        new three.Points(
            galaxyGeometry,
            new three.PointsMaterial({
                size: 2.2,
                map: sprite,
                vertexColors: true,
                transparent: true,
                depthWrite: false,
                blending: three.AdditiveBlending,
            }),
        ),
    );

    // Streams through Laniakea toward the Great Attractor, started from real
    // galaxies inside it.
    const streams: Float32Array[] = [];
    const streamPositions: number[] = [];
    const streamColors: number[] = [];
    const flowColor = new three.Color('#FF9A4D');

    for (
        let index = 0;
        streams.length < STREAMS && index < count * 4;
        index++
    ) {
        const galaxy = Math.floor(Math.random() * count);
        let x = positions[galaxy * 3] / 100;
        let y = positions[galaxy * 3 + 1] / 100;
        let z = positions[galaxy * 3 + 2] / 100;

        if (outside(x, y, z) > 0.95) {
            continue;
        }

        const stream = new Float32Array(STREAM_STEPS * 3);

        for (let step = 0; step < STREAM_STEPS; step++) {
            stream.set([x, y, z], step * 3);

            const toCore = Math.hypot(
                ATTRACTOR.x - x,
                ATTRACTOR.y - y,
                ATTRACTOR.z - z,
            );

            // Streams end before they all pile up at the Great Attractor.
            if (toCore < 9) {
                break;
            }

            if (step > 0) {
                const along = step / STREAM_STEPS;
                const color = flowColor
                    .clone()
                    .multiplyScalar(
                        (0.05 + 0.25 * Math.sin(Math.PI * along)) *
                            smoothstep(9, 25, toCore),
                    );
                streamPositions.push(
                    stream[(step - 1) * 3],
                    stream[(step - 1) * 3 + 1],
                    stream[(step - 1) * 3 + 2],
                    x,
                    y,
                    z,
                );
                streamColors.push(
                    color.r,
                    color.g,
                    color.b,
                    color.r,
                    color.g,
                    color.b,
                );
            }

            const [fx, fy, fz] = flow(x, y, z);
            const toAttractor = Math.hypot(
                ATTRACTOR.x - x,
                ATTRACTOR.y - y,
                ATTRACTOR.z - z,
            );
            const stride = Math.min(2.6, toAttractor * 0.12);
            x += fx * stride;
            y += fy * stride;
            z += fz * stride;
        }

        // Fill the rest of a stream that ended early with its last point.
        const last = stream.findLastIndex((value) => value !== 0);
        const lastPoint = Math.floor(last / 3) * 3;

        for (let rest = lastPoint + 3; rest < stream.length; rest += 3) {
            stream.set(stream.subarray(lastPoint, lastPoint + 3), rest);
        }

        streams.push(stream);
    }

    const streamGeometry = new three.BufferGeometry();
    streamGeometry.setAttribute(
        'position',
        new three.Float32BufferAttribute(streamPositions, 3),
    );
    streamGeometry.setAttribute(
        'color',
        new three.Float32BufferAttribute(streamColors, 3),
    );
    group.add(
        new three.LineSegments(
            streamGeometry,
            new three.LineBasicMaterial({
                vertexColors: true,
                transparent: true,
                depthWrite: false,
                blending: three.AdditiveBlending,
            }),
        ),
    );

    // Motes drifting along the streams, so the flow visibly moves.
    const motes = new three.BufferAttribute(
        new Float32Array(streams.length * MOTES_PER_STREAM * 3),
        3,
    );
    const moteGeometry = new three.BufferGeometry();
    moteGeometry.setAttribute('position', motes);
    group.add(
        new three.Points(
            moteGeometry,
            new three.PointsMaterial({
                size: 3.2,
                map: sprite,
                color: '#FFB36B',
                transparent: true,
                depthWrite: false,
                blending: three.AdditiveBlending,
            }),
        ),
    );

    // Center the view on Laniakea and turn it so its long axis runs across.
    group.position.set(-CENTER.x, -CENTER.y, -CENTER.z);
    const holder = new three.Group();
    holder.add(group);
    holder.rotation.set(-0.35, 0, 0.2);
    threeScene.remove(group);
    threeScene.add(holder);

    return {
        three,
        renderer,
        scene: threeScene,
        camera,
        group,
        streams,
        motes,
        motePhases: Float32Array.from(
            { length: streams.length * MOTES_PER_STREAM },
            () => Math.random(),
        ),
    };
}

/**
 * Starts loading three.js and the galaxy survey (about 0.3 MB) in the
 * background. Laniakea can only be shown once they're in: see
 * `isLaniakeaReady`.
 */
export function loadLaniakea() {
    if (isLoading) {
        return;
    }

    isLoading = true;

    build()
        .then((built) => {
            scene = built;
        })
        .catch((error: unknown) => {
            console.warn('Laniakea is unavailable.', error);
        });
}

export function isLaniakeaReady(): boolean {
    return scene !== null;
}

/**
 * Draws Laniakea into `context`, filling its `size` square canvas (CSS
 * pixels), `seconds` in. It turns slowly on its axis. Returns where the Milky
 * Way (you are here) is on the canvas, so the zoom out from the galaxy can
 * start there.
 */
export function drawLaniakea(
    context: CanvasRenderingContext2D,
    size: number,
    pixelScale: number,
    seconds: number,
    alpha: number,
): { x: number; y: number } | null {
    if (!scene || alpha <= 0) {
        return null;
    }

    const { three, renderer, camera, group, streams, motes, motePhases } =
        scene;
    const pixels = Math.round(size * Math.min(pixelScale, 1.5));

    if (renderer.domElement.width !== pixels) {
        renderer.setPixelRatio(1);
        renderer.setSize(pixels, pixels, false);
    }

    // Turning on its axis, with the camera drifting gently around it.
    group.parent!.rotation.y = seconds * 0.06;
    const drift = seconds * 0.05;
    camera.position.set(
        Math.sin(drift) * 40,
        60 + Math.sin(drift * 0.7) * 20,
        230,
    );
    camera.lookAt(0, 0, 0);

    const moteCount = motePhases.length;

    for (let index = 0; index < moteCount; index++) {
        const stream = streams[Math.floor(index / MOTES_PER_STREAM)];
        const along = (motePhases[index] + seconds * 0.05) % 1;
        const step = along * (STREAM_STEPS - 1);
        const from = Math.floor(step);
        const blend = step - from;

        for (let axis = 0; axis < 3; axis++) {
            motes.array[index * 3 + axis] =
                stream[from * 3 + axis] +
                (stream[(from + 1) * 3 + axis] - stream[from * 3 + axis]) *
                    blend;
        }
    }

    motes.needsUpdate = true;
    renderer.render(scene.scene, camera);

    context.save();
    context.globalAlpha = alpha;
    context.drawImage(renderer.domElement, 0, 0, size, size);

    // Names for the clusters, and where we are.
    const onScreen = (point: { x: number; y: number; z: number }) => {
        const projected = new three.Vector3(point.x, point.y, point.z)
            .applyMatrix4(group.matrixWorld)
            .project(camera);

        return {
            x: ((projected.x + 1) / 2) * size,
            y: ((1 - projected.y) / 2) * size,
            isInFront: projected.z < 1,
        };
    };

    let home: { x: number; y: number } | null = null;
    context.textAlign = 'left';

    for (const landmark of LANDMARKS) {
        const spot = onScreen(landmark);

        if (!spot.isInFront) {
            continue;
        }

        if (landmark.isHome) {
            home = spot;
            context.fillStyle = '#FF5A3C';
            context.shadowColor = '#FF5A3C';
            context.shadowBlur = 10;
            context.beginPath();
            context.arc(spot.x, spot.y, 3, 0, Math.PI * 2);
            context.fill();
            context.shadowBlur = 0;
        }

        context.font = landmark.isHome
            ? '600 11px "Instrument Sans", sans-serif'
            : '500 10px "Instrument Sans", sans-serif';
        context.fillStyle = landmark.isHome
            ? '#FFD2C4'
            : 'rgba(255, 236, 214, 0.7)';
        context.fillText(landmark.name, spot.x + 7, spot.y - 6);
    }

    context.font = '600 11px "Instrument Sans", sans-serif';
    context.textAlign = 'center';
    context.fillStyle = 'rgba(255, 214, 170, 0.8)';
    context.fillText(
        'LANIAKEA · 100,000 GALAXIES ACROSS 520 MILLION LIGHT-YEARS',
        size / 2,
        size / 2 - 180,
    );
    context.restore();

    return home;
}
