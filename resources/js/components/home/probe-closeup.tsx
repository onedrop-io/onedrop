/**
 * Easter eggs on the probes leaving the solar system. Hover over a Voyager
 * and NASA's 3D model of it swoops up, turns in the sunlight, then flies in
 * to the Golden Record bolted to its side, and tours the engravings on its
 * cover: how to play it, how to decode its pictures, where the Sun is, and
 * the hydrogen clock. Hover over a Pioneer for the same with its plaque.
 *
 * The models are NASA's (science.nasa.gov/3d-resources, free and without
 * copyright), drawn with three.js, which is only loaded when a probe is
 * approached. The Golden Record cover is NASA/JPL's photo (public domain),
 * and the plaque is a public-domain tracing of NASA's photo of it by Oona
 * Räisänen. The home page's footer credits them.
 */

import type * as Three from 'three';
import {
    clamp,
    drawSpaceBackdrop,
    loadImage,
    smoothstep,
} from '@/components/home/closeup-gl';

/** Size of the close-up's canvas, and the spacecraft's size in it once zoomed in (CSS pixels). */
export const PROBE_CLOSEUP_SIZE = 780;
export const PROBE_CLOSEUP_RADIUS = 150;

/**
 * How long each part of the show takes (seconds): growing out of its dot,
 * turning in the sunlight, flying in to the record or plaque, fading over to
 * its engraving, each stop on the tour, pulling back out, and shrinking back.
 */
export const PROBE_SHOW = {
    grow: 1.6,
    turn: 4.2,
    approach: 2.4,
    reveal: 0.9,
    stop: 3.2,
    back: 1.6,
    shrink: 1.4,
};

type Kind = 'voyager' | 'pioneer';

type Stop = {
    /** Where on the engraving to look (0 to 1 across and down), and how far to zoom in. */
    x: number;
    y: number;
    zoom: number;
    caption: string;
};

type Design = {
    model: string;
    /** The model's materials for the dish (to frame the spacecraft by) and for the record or plaque. */
    frameMaterial: string;
    targetMaterial: string;
    /** Where the record or plaque is painted in that material's texture, if it shares one with other parts (u and v from, u and v to). */
    targetTexture?: [number, number, number, number];
    engraving: string;
    shape: 'record' | 'plaque';
    title: string;
    stops: Stop[];
};

const DESIGNS: Record<Kind, Design> = {
    voyager: {
        model: '/images/voyager/voyager.glb',
        frameMaterial: 'tex_02_AO',
        targetMaterial: 'tex_01',
        targetTexture: [0.005, 0.16, 0.235, 0.39],
        engraving: '/images/voyager/golden-record.jpg',
        shape: 'record',
        title: 'The Golden Record',
        stops: [
            {
                x: 0.27,
                y: 0.33,
                zoom: 2.3,
                caption:
                    'How to play it: start the stylus at the edge. The ticks give one turn: 3.6 seconds.',
            },
            {
                x: 0.66,
                y: 0.43,
                zoom: 1.9,
                caption:
                    'How to turn its signals into pictures, line by line. The first one is a circle.',
            },
            {
                x: 0.34,
                y: 0.73,
                zoom: 2.1,
                caption:
                    'Where we are: the Sun, placed by its distance to 14 pulsars.',
            },
            {
                x: 0.68,
                y: 0.85,
                zoom: 3.2,
                caption:
                    'Hydrogen flipping between its two states: the clock for every time on the cover.',
            },
        ],
    },
    pioneer: {
        model: '/images/pioneer/pioneer.glb',
        frameMaterial: 'dish_AO',
        targetMaterial: 'norm_trans',
        engraving: '/images/pioneer/plaque.svg',
        shape: 'plaque',
        title: 'The Pioneer plaque',
        stops: [
            {
                x: 0.23,
                y: 0.1,
                zoom: 3,
                caption:
                    'Hydrogen flipping its spin: the plaque’s unit of length (21 cm) and of time.',
            },
            {
                x: 0.24,
                y: 0.42,
                zoom: 2,
                caption:
                    'Where the Sun is: its direction and distance to 14 pulsars, their periods in binary.',
            },
            {
                x: 0.68,
                y: 0.45,
                zoom: 1.9,
                caption:
                    'Us, drawn to scale in front of the spacecraft. She is 8 hydrogen units tall: 168 cm.',
            },
            {
                x: 0.5,
                y: 0.9,
                zoom: 2.1,
                caption:
                    'The Solar System, and Pioneer’s path out from the third planet past Jupiter.',
            },
        ],
    },
};

/** Each probe you can hover over, and when it left. */
export const PROBE_CLOSEUPS: Record<string, { kind: Kind; launched: string }> =
    {
        'Voyager 1': {
            kind: 'voyager',
            launched: 'Launched September 5, 1977',
        },
        'Voyager 2': { kind: 'voyager', launched: 'Launched August 20, 1977' },
        'Pioneer 10': { kind: 'pioneer', launched: 'Launched March 2, 1972' },
        'Pioneer 11': { kind: 'pioneer', launched: 'Launched April 5, 1973' },
    };

type Scene = {
    scene: Three.Scene;
    camera: Three.PerspectiveCamera;
    /** Where the record or plaque is, and which way it faces (with the spacecraft framed to a radius of 1). */
    target: Three.Vector3;
    facing: Three.Vector3;
    /** How far in front of the record or plaque the camera stops to fill the view with it. */
    closeDistance: number;
    engraving: HTMLImageElement;
};

type Renderer = {
    three: typeof Three;
    renderer: Three.WebGLRenderer;
    environment: Three.Texture;
};

let rendering: Promise<Renderer> | null = null;
let renderer: Renderer | null = null;
const scenes: Partial<Record<Kind, Scene>> = {};
const loading: Partial<Record<Kind, Promise<void>>> = {};

/** Distance from the camera to the middle of the spacecraft while it turns, and the camera's field of view (degrees). */
const ORBIT_DISTANCE = 6.2;
const FIELD_OF_VIEW = 32;

/** How big the record or plaque is drawn once its engraving takes over (CSS pixels). */
const ENGRAVING = { radius: 170, width: 400, height: 317, lift: 22 };

async function createRenderer(): Promise<Renderer> {
    const three = await import('three');
    const { RoomEnvironment } =
        await import('three/addons/environments/RoomEnvironment.js');
    const canvas = document.createElement('canvas');
    const webgl = new three.WebGLRenderer({
        canvas,
        alpha: true,
        antialias: true,
    });
    webgl.setClearColor(0x000000, 0);
    webgl.toneMapping = three.ACESFilmicToneMapping;
    webgl.toneMappingExposure = 1.05;

    // A soft studio environment, so the foil and brass have something to reflect.
    const environment = new three.PMREMGenerator(webgl).fromScene(
        new RoomEnvironment(),
        0.04,
    ).texture;

    return { three, renderer: webgl, environment };
}

async function createScene(kind: Kind, { three, environment }: Renderer) {
    const design = DESIGNS[kind];
    const { GLTFLoader } = await import('three/addons/loaders/GLTFLoader.js');
    const [gltf, engraving] = await Promise.all([
        new GLTFLoader().loadAsync(design.model),
        loadImage(design.engraving),
    ]);

    const scene = new three.Scene();
    scene.environment = environment;
    scene.environmentIntensity = 0.3;

    // Sunlight from far off to one side, and a faint fill so the shadowed side isn't black.
    const sun = new three.DirectionalLight(0xfff2e0, 3.4);
    sun.position.set(-4, 2.5, 3);
    scene.add(sun, new three.AmbientLight(0x5a6478, 0.35));

    const model = gltf.scene;
    const find = (name: string) => {
        let found: Three.Mesh | null = null;

        model.traverse((object) => {
            const mesh = object as Three.Mesh;
            const materials = mesh.isMesh
                ? ([] as Three.Material[]).concat(mesh.material)
                : [];

            if (
                !found &&
                materials.some((material) => material.name === name)
            ) {
                found = mesh;
            }
        });

        if (!found) {
            throw new Error(`No ${name} in ${design.model}`);
        }

        return found as Three.Mesh;
    };

    // Frame the spacecraft by its dish, so long booms can run out of view.
    const dish = new three.Box3()
        .setFromObject(find(design.frameMaterial))
        .getBoundingSphere(new three.Sphere());
    const holder = new three.Group();
    model.position.sub(dish.center);
    holder.add(model);
    holder.scale.setScalar(1 / dish.radius);
    scene.add(holder);
    holder.updateMatrixWorld(true);

    // The record or plaque: the triangles using its material (or, when it
    // shares a texture with other parts, the ones painted with it).
    const targetMesh = find(design.targetMaterial);
    const geometry = targetMesh.geometry;
    const positions = geometry.getAttribute('position');
    const normals = geometry.getAttribute('normal');
    const uvs = geometry.getAttribute('uv');
    const corners = geometry.index
        ? Array.from(geometry.index.array)
        : Array.from({ length: positions.count }, (_, index) => index);
    const normalMatrix = new three.Matrix3().getNormalMatrix(
        targetMesh.matrixWorld,
    );
    const targetBox = new three.Box3();
    const facing = new three.Vector3();

    for (let corner = 0; corner < corners.length; corner += 3) {
        const triangle = corners.slice(corner, corner + 3);

        if (design.targetTexture) {
            const [uFrom, vFrom, uTo, vTo] = design.targetTexture;
            const u =
                triangle.reduce((sum, index) => sum + uvs.getX(index), 0) / 3;
            const v =
                triangle.reduce((sum, index) => sum + uvs.getY(index), 0) / 3;

            if (u < uFrom || u > uTo || v < vFrom || v > vTo) {
                continue;
            }
        }

        for (const index of triangle) {
            targetBox.expandByPoint(
                new three.Vector3()
                    .fromBufferAttribute(positions, index)
                    .applyMatrix4(targetMesh.matrixWorld),
            );
            facing.add(
                new three.Vector3()
                    .fromBufferAttribute(normals, index)
                    .applyMatrix3(normalMatrix),
            );
        }
    }

    if (targetBox.isEmpty()) {
        throw new Error(`No ${design.title} on ${design.model}`);
    }

    const target = targetBox.getCenter(new three.Vector3());

    // It faces the way its surface does (or straight out, if that's unclear).
    if (facing.lengthSq() === 0) {
        facing.copy(target);
    }

    facing.normalize();

    const targetSize = targetBox.getSize(new three.Vector3());
    const targetRadius = Math.max(targetSize.x, targetSize.y, targetSize.z) / 2;
    const camera = new three.PerspectiveCamera(FIELD_OF_VIEW, 1, 0.01, 100);
    const halfView = Math.tan(((FIELD_OF_VIEW / 2) * Math.PI) / 180);

    scenes[kind] = {
        scene,
        camera,
        target,
        facing,
        // Never so close that the camera ends up inside the spacecraft.
        closeDistance: Math.max(
            0.6,
            targetRadius /
                (halfView * (ENGRAVING.radius / (PROBE_CLOSEUP_SIZE / 2))),
        ),
        engraving,
    };
}

/**
 * Starts loading three.js, the probe's model, and its engraving (about 1 MB)
 * in the background. The close-up can only play once they're in: see
 * `isProbeCloseupReady`.
 */
export function loadProbeCloseup(name: string) {
    const { kind } = PROBE_CLOSEUPS[name];

    loading[kind] ??= (async () => {
        rendering ??= createRenderer();
        renderer = await rendering;
        await createScene(kind, renderer);
    })().catch((error: unknown) => {
        // No WebGL, or the model didn't load: the probe just stays a dot.
        console.warn(`The ${name} close-up is unavailable.`, error);
    });
}

export function isProbeCloseupReady(name: string): boolean {
    return renderer !== null && scenes[PROBE_CLOSEUPS[name].kind] !== undefined;
}

function ease(t: number): number {
    return smoothstep(0, 1, t);
}

/** When each part of the show starts (seconds). */
function timeline() {
    const { grow, turn, approach, reveal, stop, back } = PROBE_SHOW;
    const approachAt = grow + turn;
    // The engraving fades in over the end of the fly-in.
    const revealAt = approachAt + approach * 0.55;
    const tourAt = revealAt + reveal;
    const backAt = tourAt + stop * 4;
    const shrinkAt = backAt + back;

    return { approachAt, revealAt, tourAt, backAt, shrinkAt };
}

/**
 * How big the probe is during the show, from 0 (its usual dot) to 1 (fully
 * zoomed in), `seconds` after it starts. Null once it's over.
 */
export function probeShowProgress(seconds: number): number | null {
    const { shrinkAt } = timeline();

    if (seconds < 0 || seconds > shrinkAt + PROBE_SHOW.shrink) {
        return null;
    }

    if (seconds < PROBE_SHOW.grow) {
        return ease(seconds / PROBE_SHOW.grow);
    }

    if (seconds < shrinkAt) {
        return 1;
    }

    return ease(1 - (seconds - shrinkAt) / PROBE_SHOW.shrink);
}

/** Moves the camera: circling the spacecraft, then flying in to the record or plaque by `closeness` (0 to 1). */
function placeCamera(
    { three }: Renderer,
    { camera, target, facing, closeDistance }: Scene,
    seconds: number,
    closeness: number,
) {
    const angle = 0.5 + seconds * 0.28;
    const circling = new three.Vector3(
        Math.sin(angle) * Math.cos(0.32),
        Math.sin(0.32),
        Math.cos(angle) * Math.cos(0.32),
    );
    const close = target.clone().addScaledVector(facing, closeDistance);
    const direction = circling
        .clone()
        .lerp(close.clone().normalize(), closeness)
        .normalize();
    const distance =
        ORBIT_DISTANCE + (close.length() - ORBIT_DISTANCE) * closeness;

    camera.position.copy(direction.multiplyScalar(distance));
    camera.lookAt(new three.Vector3().lerp(target, closeness));
}

function drawEngraving(
    context: CanvasRenderingContext2D,
    design: Design,
    engraving: HTMLImageElement,
    view: { x: number; y: number; zoom: number },
    alpha: number,
) {
    const center = PROBE_CLOSEUP_SIZE / 2;
    const middleY = center - ENGRAVING.lift;
    const isRecord = design.shape === 'record';
    // The record fills about 94% of its photo; the plaque drawing fills its frame.
    const width = isRecord ? (ENGRAVING.radius * 2) / 0.94 : ENGRAVING.width;
    const height = isRecord
        ? width
        : (width * engraving.naturalHeight) / engraving.naturalWidth ||
          ENGRAVING.height;
    const outline = () => {
        context.beginPath();

        if (isRecord) {
            context.arc(center, middleY, ENGRAVING.radius, 0, Math.PI * 2);
        } else {
            context.roundRect(
                center - ENGRAVING.width / 2,
                middleY - height / 2,
                ENGRAVING.width,
                height,
                6,
            );
        }
    };

    context.save();
    context.globalAlpha = alpha;
    context.shadowColor = 'rgba(255, 200, 110, 0.35)';
    context.shadowBlur = 40;
    outline();
    context.fillStyle = '#1A1206';
    context.fill();
    context.shadowColor = 'transparent';
    context.clip();

    context.translate(center, middleY);
    context.scale(view.zoom, view.zoom);
    context.translate(-view.x * width, -view.y * height);

    if (isRecord) {
        context.drawImage(engraving, 0, 0, width, height);
    } else {
        // The plaque is gold-anodized aluminum, its lines etched in.
        const gold = context.createLinearGradient(0, 0, width, height);
        gold.addColorStop(0, '#E8C77A');
        gold.addColorStop(0.45, '#C39445');
        gold.addColorStop(0.7, '#DDB564');
        gold.addColorStop(1, '#A87A33');
        context.fillStyle = gold;
        context.fillRect(0, 0, width, height);
        context.globalCompositeOperation = 'multiply';
        context.globalAlpha = alpha * 0.85;
        context.drawImage(engraving, 0, 0, width, height);
        context.globalCompositeOperation = 'source-over';
        context.globalAlpha = alpha;
    }

    context.restore();

    // A sheen across the metal.
    context.save();
    context.globalAlpha = alpha * 0.25;
    outline();
    context.clip();
    const sheen = context.createLinearGradient(
        center - 260,
        middleY - 260,
        center + 260,
        middleY + 260,
    );
    sheen.addColorStop(0.3, 'rgba(255, 255, 255, 0)');
    sheen.addColorStop(0.5, 'rgba(255, 246, 220, 0.9)');
    sheen.addColorStop(0.7, 'rgba(255, 255, 255, 0)');
    context.fillStyle = sheen;
    context.globalCompositeOperation = 'screen';
    context.fillRect(0, 0, PROBE_CLOSEUP_SIZE, PROBE_CLOSEUP_SIZE);
    context.restore();
}

function drawText(
    context: CanvasRenderingContext2D,
    lines: { text: string; y: number; font: string; color: string }[],
    alpha: number,
) {
    if (alpha <= 0) {
        return;
    }

    context.save();
    context.globalAlpha = alpha;
    context.textAlign = 'center';

    for (const line of lines) {
        context.font = line.font;
        context.fillStyle = line.color;
        context.fillText(line.text, PROBE_CLOSEUP_SIZE / 2, line.y);
    }

    context.restore();
}

/**
 * Draws the close-up of the probe called `name`, centered in its canvas,
 * `seconds` into the show. `reveal` (0 to 1) fades in the backdrop as it
 * grows. `pixelScale` is how many device pixels it gets per CSS pixel.
 */
export function drawProbeCloseup(
    name: string,
    context: CanvasRenderingContext2D,
    pixelScale: number,
    seconds: number,
    reveal: number,
) {
    const { kind, launched } = PROBE_CLOSEUPS[name];
    const scene = scenes[kind];

    if (!renderer || !scene) {
        return;
    }

    const design = DESIGNS[kind];
    const { approachAt, revealAt, tourAt, backAt } = timeline();
    const closeness =
        ease((seconds - approachAt) / PROBE_SHOW.approach) *
        (1 - ease((seconds - backAt) / PROBE_SHOW.back));
    const engravingAlpha =
        ease((seconds - revealAt) / PROBE_SHOW.reveal) *
        (1 - ease((seconds - backAt) / (PROBE_SHOW.back * 0.6)));

    drawSpaceBackdrop(context, PROBE_CLOSEUP_SIZE, smoothstep(0, 0.6, reveal));

    // The spacecraft itself.
    const pixels = Math.round(PROBE_CLOSEUP_SIZE * Math.min(pixelScale, 1.5));
    renderer.renderer.setPixelRatio(1);
    renderer.renderer.setSize(pixels, pixels, false);
    placeCamera(renderer, scene, seconds, closeness);
    renderer.renderer.render(scene.scene, scene.camera);
    context.globalAlpha = 1 - engravingAlpha * 0.85;
    context.drawImage(
        renderer.renderer.domElement,
        0,
        0,
        PROBE_CLOSEUP_SIZE,
        PROBE_CLOSEUP_SIZE,
    );
    context.globalAlpha = 1;

    const bottom = PROBE_CLOSEUP_SIZE / 2 + PROBE_CLOSEUP_RADIUS + 70;
    drawText(
        context,
        [
            {
                text: name,
                y: bottom,
                font: '700 18px "Schibsted Grotesk", sans-serif',
                color: '#F5EFEA',
            },
            {
                text: launched,
                y: bottom + 20,
                font: '500 12px "Instrument Sans", sans-serif',
                color: 'rgba(245, 239, 234, 0.65)',
            },
        ],
        smoothstep(0.5, 1, reveal) * (1 - smoothstep(0, 0.4, closeness)),
    );

    if (engravingAlpha <= 0) {
        return;
    }

    // Touring the engraving: glide from stop to stop, starting from the whole thing.
    const whole = { x: 0.5, y: 0.5, zoom: 1 };
    const stops = [whole, ...design.stops, whole];
    const along = clamp(
        (seconds - tourAt) / PROBE_SHOW.stop,
        0,
        stops.length - 1,
    );
    const stopIndex = Math.min(Math.floor(along), stops.length - 2);
    const glide = ease(clamp((along - stopIndex) / 0.35));
    const from = stops[stopIndex];
    const to = stops[stopIndex + 1];
    const zoom = from.zoom * (to.zoom / from.zoom) ** glide;
    const view = {
        x: from.x + (to.x - from.x) * glide,
        y: from.y + (to.y - from.y) * glide,
        zoom,
    };

    drawEngraving(context, design, scene.engraving, view, engravingAlpha);

    const current = seconds >= tourAt ? design.stops[stopIndex] : undefined;
    const captionAlpha =
        engravingAlpha *
        smoothstep(0.3, 0.5, along - stopIndex) *
        (1 - smoothstep(0.9, 1, along - stopIndex));
    const engravingBottom =
        PROBE_CLOSEUP_SIZE / 2 -
        ENGRAVING.lift +
        (design.shape === 'record' ? ENGRAVING.radius : ENGRAVING.height / 2);

    drawText(
        context,
        [
            {
                text: `${design.title.toUpperCase()} · ${name.toUpperCase()}`,
                y:
                    PROBE_CLOSEUP_SIZE / 2 -
                    ENGRAVING.lift -
                    (design.shape === 'record'
                        ? ENGRAVING.radius
                        : ENGRAVING.height / 2) -
                    16,
                font: '600 11px "Instrument Sans", sans-serif',
                color: 'rgba(255, 226, 170, 0.85)',
            },
        ],
        engravingAlpha,
    );

    if (current) {
        drawText(
            context,
            [
                {
                    text: current.caption,
                    y: engravingBottom + 30,
                    font: '500 14px "Instrument Sans", sans-serif',
                    color: '#FFF1DA',
                },
            ],
            captionAlpha,
        );
    }
}
