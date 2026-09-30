/**
 * The Solar System level, in 3D: real maps of every planet (Solar System
 * Scope, CC BY 4.0, resized; Earth and Mars share the close-ups' maps),
 * lit by the Sun, which is a shader of its own: boiling granules, a
 * darker limb, a flickering corona, flames licking off its edge, and flare
 * loops that erupt, arc out, and fall back. Nothing is to scale. Drawn with
 * three.js, loaded when the level is first asked for.
 */

import type * as Three from 'three';
import { loadImage } from '@/components/home/closeup-gl';

type World = {
    name: string;
    /** Orbit radius and planet radius (scene units), orbit period (seconds), starting angle, and axial tilt (radians). */
    orbit: number;
    radius: number;
    period: number;
    phase: number;
    tilt: number;
    map?: string;
};

const WORLDS: World[] = [
    {
        name: 'Mercury',
        orbit: 21,
        radius: 1.6,
        period: 9,
        phase: 1.2,
        tilt: 0.03,
        map: '/images/planets/mercury.jpg',
    },
    {
        name: 'Venus',
        orbit: 27,
        radius: 2.5,
        period: 14,
        phase: 4.1,
        tilt: 3.1,
        map: '/images/planets/venus.jpg',
    },
    {
        name: 'Earth',
        orbit: 34,
        radius: 2.7,
        period: 20,
        phase: 2.4,
        tilt: 0.41,
    },
    {
        name: 'Mars',
        orbit: 42,
        radius: 1.9,
        period: 28,
        phase: 5.3,
        tilt: 0.44,
        map: '/images/mars/color.jpg',
    },
    {
        name: 'Jupiter',
        orbit: 60,
        radius: 6.2,
        period: 55,
        phase: 0.6,
        tilt: 0.05,
        map: '/images/planets/jupiter.jpg',
    },
    {
        name: 'Saturn',
        orbit: 75,
        radius: 5,
        period: 80,
        phase: 3.5,
        tilt: 0.47,
        map: '/images/planets/saturn.jpg',
    },
    {
        name: 'Uranus',
        orbit: 88,
        radius: 3.5,
        period: 110,
        phase: 5.9,
        tilt: 1.71,
        map: '/images/planets/uranus.jpg',
    },
    {
        name: 'Neptune',
        orbit: 99,
        radius: 3.4,
        period: 140,
        phase: 2,
        tilt: 0.49,
        map: '/images/planets/neptune.jpg',
    },
];

const EARTH_MAPS = [
    '/images/earth/day.jpg',
    '/images/earth/night.jpg',
    '/images/earth/bump-roughness-clouds.jpg',
];
const SATURN_RING = '/images/planets/saturn-ring.png';

const SUN_RADIUS = 14;

/** The camera: its field of view, how far out it sits, and how high above the planets' plane it looks down from (radians). */
const FIELD_OF_VIEW = 34;
const CAMERA_DISTANCE = 345;
const CAMERA_ELEVATION = 0.55;

const NOISE = `
vec3 mod289(vec3 x) { return x - floor(x * (1.0 / 289.0)) * 289.0; }
vec4 mod289(vec4 x) { return x - floor(x * (1.0 / 289.0)) * 289.0; }
vec4 permute(vec4 x) { return mod289(((x * 34.0) + 1.0) * x); }
vec4 taylorInvSqrt(vec4 r) { return 1.79284291400159 - 0.85373472095314 * r; }

// 3D simplex noise (Stefan Gustavson and Ian McEwan, MIT).
float snoise(vec3 v) {
    const vec2 C = vec2(1.0 / 6.0, 1.0 / 3.0);
    const vec4 D = vec4(0.0, 0.5, 1.0, 2.0);
    vec3 i = floor(v + dot(v, C.yyy));
    vec3 x0 = v - i + dot(i, C.xxx);
    vec3 g = step(x0.yzx, x0.xyz);
    vec3 l = 1.0 - g;
    vec3 i1 = min(g.xyz, l.zxy);
    vec3 i2 = max(g.xyz, l.zxy);
    vec3 x1 = x0 - i1 + C.xxx;
    vec3 x2 = x0 - i2 + C.yyy;
    vec3 x3 = x0 - D.yyy;
    i = mod289(i);
    vec4 p = permute(permute(permute(
        i.z + vec4(0.0, i1.z, i2.z, 1.0))
        + i.y + vec4(0.0, i1.y, i2.y, 1.0))
        + i.x + vec4(0.0, i1.x, i2.x, 1.0));
    float n_ = 0.142857142857;
    vec3 ns = n_ * D.wyz - D.xzx;
    vec4 j = p - 49.0 * floor(p * ns.z * ns.z);
    vec4 x_ = floor(j * ns.z);
    vec4 y_ = floor(j - 7.0 * x_);
    vec4 x = x_ * ns.x + ns.yyyy;
    vec4 y = y_ * ns.x + ns.yyyy;
    vec4 h = 1.0 - abs(x) - abs(y);
    vec4 b0 = vec4(x.xy, y.xy);
    vec4 b1 = vec4(x.zw, y.zw);
    vec4 s0 = floor(b0) * 2.0 + 1.0;
    vec4 s1 = floor(b1) * 2.0 + 1.0;
    vec4 sh = -step(h, vec4(0.0));
    vec4 a0 = b0.xzyw + s0.xzyw * sh.xxyy;
    vec4 a1 = b1.xzyw + s1.xzyw * sh.zzww;
    vec3 p0 = vec3(a0.xy, h.x);
    vec3 p1 = vec3(a0.zw, h.y);
    vec3 p2 = vec3(a1.xy, h.z);
    vec3 p3 = vec3(a1.zw, h.w);
    vec4 norm = taylorInvSqrt(vec4(dot(p0, p0), dot(p1, p1), dot(p2, p2), dot(p3, p3)));
    p0 *= norm.x;
    p1 *= norm.y;
    p2 *= norm.z;
    p3 *= norm.w;
    vec4 m = max(0.6 - vec4(dot(x0, x0), dot(x1, x1), dot(x2, x2), dot(x3, x3)), 0.0);
    m = m * m;

    return 42.0 * dot(m * m, vec4(dot(p0, x0), dot(p1, x1), dot(p2, x2), dot(p3, x3)));
}

float fbm(vec3 p) {
    float value = 0.0;
    float amplitude = 0.5;

    for (int octave = 0; octave < 5; octave++) {
        value += amplitude * snoise(p);
        p *= 2.03;
        amplitude *= 0.5;
    }

    return value;
}
`;

const SURFACE_VERTEX = `
varying vec3 vNormal;
varying vec3 vObjectNormal;
varying vec3 vToCamera;
varying vec2 vUv;
varying vec3 vWorld;

void main() {
    vec4 world = modelMatrix * vec4(position, 1.0);
    vWorld = world.xyz;
    vNormal = normalize(mat3(modelMatrix) * normal);
    vObjectNormal = normal;
    vToCamera = normalize(cameraPosition - world.xyz);
    vUv = uv;
    gl_Position = projectionMatrix * viewMatrix * world;
}`;

/** The Sun's face: churning granules, sunspots, and a darker limb. */
const SUN_FRAGMENT = `
uniform float time;
varying vec3 vNormal;
varying vec3 vObjectNormal;
varying vec3 vToCamera;
${NOISE}

void main() {
    vec3 p = vObjectNormal * 2.2;
    float swirl = fbm(p + vec3(0.0, time * 0.04, time * 0.02));
    float granules = fbm(p * 5.0 + swirl * 1.5 - vec3(time * 0.12));
    float heat = clamp(0.55 + 0.35 * swirl + 0.3 * granules, 0.0, 1.2);
    float spots = smoothstep(0.55, 0.7, fbm(p * 1.3 + 17.0 + time * 0.01));
    float facing = max(dot(normalize(vNormal), normalize(vToCamera)), 0.0);
    float limb = pow(facing, 0.5);

    vec3 color = mix(vec3(0.85, 0.22, 0.02), vec3(1.0, 0.78, 0.35), heat);
    color += vec3(1.0, 0.95, 0.8) * pow(heat, 5.0) * 0.8;
    color *= mix(1.0, 0.35, spots);
    color *= (0.55 + 0.75 * limb);

    gl_FragColor = vec4(color * 1.5, 1.0);
}`;

const CORONA_VERTEX = `
varying vec2 vUv;

void main() {
    vUv = uv;
    gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
}`;

/** Around the Sun: its glow, flames licking off the edge, and flare loops erupting and falling back. */
const CORONA_FRAGMENT = `
uniform float time;
uniform float extent;
varying vec2 vUv;
${NOISE}

float hash(float n) {
    return fract(sin(n) * 43758.5453);
}

void main() {
    vec2 p = (vUv - 0.5) * 2.0 * extent;
    float r = length(p);
    float angle = atan(p.y, p.x);
    vec2 around = vec2(cos(angle), sin(angle));

    if (r < 0.97) {
        discard;
    }

    float above = r - 1.0;

    // The corona: a soft glow, streaked into rays.
    float rays = 0.6 + 0.4 * snoise(vec3(around * 3.0, time * 0.05));
    vec3 color = vec3(1.0, 0.55, 0.18) * exp(-above * 2.6) * 0.55 * rays;
    color += vec3(1.0, 0.8, 0.5) * exp(-above * 14.0) * 0.9;

    // Flames licking off the edge.
    float flame = fbm(vec3(around * 4.0, above * 3.0 - time * 0.5));
    float tongues = smoothstep(0.05, 0.6, flame) * exp(-above * 7.0);
    color += vec3(1.0, 0.42, 0.08) * tongues * 1.6;

    // Flare loops: each rises from the limb as an arc, glows, and falls back.
    for (int index = 0; index < 4; index++) {
        float seed = float(index) * 7.31;
        float cycle = 6.0 + hash(seed) * 5.0;
        float phase = fract(time / cycle + hash(seed + 1.0));
        float eruption = floor(time / cycle + hash(seed + 1.0));
        float at = hash(seed + eruption * 3.7) * 6.2832;
        float height = sin(phase * 3.1416) * (0.25 + 0.45 * hash(seed + eruption));
        vec2 foot = vec2(cos(at), sin(at));
        vec2 side = vec2(-foot.y, foot.x);
        // In the loop's own frame: out from the Sun, and along its edge.
        vec2 local = vec2(dot(p, foot) - 1.0, dot(p, side));
        float width = 0.18 + 0.1 * hash(seed + 2.0);
        vec2 arc = vec2(local.x / max(height, 0.01), local.y / width);
        float loop = abs(length(arc) - 1.0) * min(height, width);
        float strand = exp(-loop * loop * 900.0) * step(0.0, local.x);
        float flicker = 0.7 + 0.3 * snoise(vec3(local * 12.0, time * 2.0));
        color += vec3(1.0, 0.5, 0.15) * strand * flicker * 2.2 * smoothstep(0.0, 0.15, phase) * (1.0 - smoothstep(0.8, 1.0, phase));
    }

    // Additive light: dark stays see-through.
    gl_FragColor = vec4(color, clamp(max(color.r, max(color.g, color.b)), 0.0, 1.0));
}`;

/** Earth, lit like the Earth close-up: day and night, clouds drifting over, and its blue edge. */
const EARTH_FRAGMENT = `
uniform sampler2D dayMap;
uniform sampler2D nightMap;
uniform sampler2D surfaceMap;
uniform vec3 sunPosition;
uniform float cloudTurn;
varying vec3 vNormal;
varying vec3 vToCamera;
varying vec2 vUv;
varying vec3 vWorld;

void main() {
    vec3 normal = normalize(vNormal);
    vec3 toSun = normalize(sunPosition - vWorld);
    float facing = dot(normal, toSun);
    vec3 day = pow(texture2D(dayMap, vUv).rgb, vec3(2.2));
    vec3 night = pow(texture2D(nightMap, vUv).rgb, vec3(2.2));
    float clouds = smoothstep(0.2, 1.0, texture2D(surfaceMap, vUv + vec2(cloudTurn, 0.0)).b);
    vec3 albedo = mix(day, vec3(1.0), clamp(clouds * 2.0, 0.0, 1.0));
    vec3 lit = albedo * max(facing, 0.0) * 1.3;
    vec3 lights = night * (1.0 - clouds * 0.75) * 1.5;
    vec3 color = mix(lights, lit, smoothstep(-0.25, 0.5, facing));

    float fresnel = 1.0 - abs(dot(normal, normalize(vToCamera)));
    vec3 sky = mix(vec3(0.737, 0.286, 0.043), vec3(0.302, 0.698, 1.0), smoothstep(-0.25, 0.75, facing));
    color = mix(color, sky, clamp(smoothstep(-0.5, 1.0, facing) * fresnel * fresnel, 0.0, 1.0));

    gl_FragColor = vec4(pow(clamp(color, 0.0, 1.0), vec3(1.0 / 2.2)), 1.0);
}`;

const RIBBON_VERTEX = `
attribute float along;
attribute float across;
varying float vAlong;
varying float vAcross;

void main() {
    vAlong = along;
    vAcross = across;
    gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
}`;

/** A plume of solar wind: wispy streaks bursting out, spreading, and dissipating fast. */
const RIBBON_FRAGMENT = `
uniform float time;
uniform float front;
uniform float fade;
uniform float seed;
varying float vAlong;
varying float vAcross;
${NOISE}

void main() {
    // A diffuse, streaky plume, fading fast as it spreads.
    float wisps = 0.45 + 0.55 * snoise(vec3(vAlong * 5.0 - time * 0.8 + seed, vAcross * 2.2, seed));
    float strands = 0.5 + 0.5 * snoise(vec3(vAlong * 2.0, vAcross * 4.0 + seed, time * 0.3));
    float body = exp(-vAcross * vAcross * 2.2);
    float ahead = 1.0 - smoothstep(front - 0.35, front, vAlong);
    float glow = body * wisps * strands * ahead
        * pow(1.0 - vAlong, 1.3) * smoothstep(0.05, 0.35, vAlong) * fade;
    vec3 color = mix(vec3(1.0, 0.72, 0.36), vec3(1.0, 0.4, 0.12), smoothstep(0.0, 0.5, vAlong)) * glow * 6.0;

    gl_FragColor = vec4(color, clamp(glow, 0.0, 1.0));
}`;

/** A comet's tail: brightest by its head, streaked and flowing away from it. */
const TAIL_FRAGMENT = `
uniform float time;
uniform float seed;
uniform float strength;
uniform float streakiness;
uniform vec3 nearColor;
uniform vec3 farColor;
varying float vAlong;
varying float vAcross;
${NOISE}

void main() {
    float flow = 0.5 + 0.5 * snoise(vec3(vAlong * 6.0 - time * 0.6 + seed, vAcross * streakiness, seed));
    float strands = 0.6 + 0.4 * snoise(vec3(vAlong * 1.5, vAcross * streakiness * 2.5 + seed, time * 0.2));
    float body = exp(-vAcross * vAcross * 4.0);
    float glow = body * mix(1.0, flow * strands * 1.6, 0.6)
        * pow(1.0 - vAlong, 1.5) * smoothstep(0.0, 0.03, vAlong) * strength;
    vec3 color = mix(nearColor, farColor, vAlong) * glow;

    gl_FragColor = vec4(color, clamp(glow, 0.0, 1.0));
}`;

/**
 * The comet's orbit: a long, thin ellipse (semi-major axis, eccentricity)
 * that dives in from far beyond Neptune to just outside Mercury, a trip round
 * (seconds) the same way as the planets, tipped out of their plane and turned
 * (radians), and where along it it starts (0 to 1).
 */
const COMET = {
    axis: 140,
    eccentricity: 0.83,
    period: 70,
    tilt: 0.38,
    turn: 2.3,
    phase: 0.83,
};

/**
 * Where the comet wakes up on the way in (and dies down on the way out): no
 * coma or tails beyond `far` from the Sun, fully lit inside `near`. Out there
 * it's also slow enough for the circling camera to overtake it.
 */
const COMET_ACTIVE = { near: 120, far: 145 };

const TAIL_SEGMENTS = 48;

type Tail = {
    mesh: Three.Mesh;
    uniforms: Record<'time' | 'strength', Three.IUniform<number>>;
};

type Comet = {
    nucleus: Three.Mesh;
    coma: Three.Sprite;
    dust: Tail;
    ion: Tail;
    position: Three.Vector3;
    strength: number;
};

/** How many plumes can be out at once, how many segments each has, and how long each lasts (seconds). */
const RIBBONS = 2;
const RIBBON_SEGMENTS = 64;
const RIBBON_LIFE = [2, 3.5];

/** How long each rests before erupting again (seconds), so eruptions are occasional. */
const RIBBON_REST = [4, 11];

type Ribbon = {
    mesh: Three.Mesh;
    uniforms: Record<
        'time' | 'front' | 'fade' | 'seed',
        Three.IUniform<number>
    >;
    /** When it erupted and how long it lasts; which way it heads (radians), how far, and how it curves. */
    bornAt: number;
    life: number;
    heading: number;
    rise: number;
    length: number;
    curl: number;
};

type Planet = { world: World; body: Three.Object3D; spin: Three.Object3D };

type Scene = {
    three: typeof Three;
    renderer: Three.WebGLRenderer;
    scene: Three.Scene;
    camera: Three.PerspectiveCamera;
    planets: Planet[];
    sunTime: Three.IUniform<number>[];
    corona: Three.Mesh;
    cloudTurn: Three.IUniform<number>;
    belt: Three.Points;
    ribbons: Ribbon[];
    comet: Comet;
};

let scene: Scene | null = null;
let status: 'idle' | 'loading' | 'ready' | 'failed' = 'idle';

/** Where each planet was last drawn on the canvas (CSS pixels), and how big. */
const onScreen: Record<string, { x: number; y: number; radius: number }> = {};

/** A flat strip of `segments` quads, with how far along (0 to 1) and across (-1 to 1) each corner is. */
function stripGeometry(three: typeof Three, segments: number) {
    const geometry = new three.BufferGeometry();
    const points = (segments + 1) * 2;
    geometry.setAttribute(
        'position',
        new three.BufferAttribute(new Float32Array(points * 3), 3),
    );
    geometry.setAttribute(
        'along',
        new three.BufferAttribute(
            Float32Array.from(
                { length: points },
                (_, point) => Math.floor(point / 2) / segments,
            ),
            1,
        ),
    );
    geometry.setAttribute(
        'across',
        new three.BufferAttribute(
            Float32Array.from({ length: points }, (_, point) =>
                point % 2 === 0 ? -1 : 1,
            ),
            1,
        ),
    );
    geometry.setIndex(
        Array.from({ length: segments }, (_, segment) => {
            const at = segment * 2;

            return [at, at + 1, at + 2, at + 1, at + 3, at + 2];
        }).flat(),
    );

    return geometry;
}

/**
 * Lays a strip along a path, `width` wide either side, turned to face the
 * camera so it never goes edge-on.
 */
function layStrip(
    three: typeof Three,
    camera: Three.Camera,
    mesh: Three.Mesh,
    segments: number,
    at: (along: number, target: Three.Vector3) => Three.Vector3,
    width: (along: number) => number,
) {
    const point = new three.Vector3();
    const ahead = new three.Vector3();
    const tangent = new three.Vector3();
    const side = new three.Vector3();
    const toCamera = new three.Vector3();
    const positions = mesh.geometry.getAttribute('position');

    for (let segment = 0; segment <= segments; segment++) {
        const along = segment / segments;
        at(along, point);
        at(Math.min(1, along + 0.01), ahead);
        tangent.subVectors(ahead, point).normalize();
        toCamera.subVectors(camera.position, point).normalize();
        side.crossVectors(tangent, toCamera).normalize();
        const half = width(along);

        positions.setXYZ(
            segment * 2,
            point.x - side.x * half,
            point.y - side.y * half,
            point.z - side.z * half,
        );
        positions.setXYZ(
            segment * 2 + 1,
            point.x + side.x * half,
            point.y + side.y * half,
            point.z + side.z * half,
        );
    }

    positions.needsUpdate = true;
}

/** A soft round glow, for the comet's coma. */
function glowTexture(three: typeof Three): Three.Texture {
    const canvas = document.createElement('canvas');
    canvas.width = 128;
    canvas.height = 128;
    const context = canvas.getContext('2d')!;
    const glow = context.createRadialGradient(64, 64, 0, 64, 64, 64);
    glow.addColorStop(0, 'rgba(235, 248, 255, 1)');
    glow.addColorStop(0.15, 'rgba(190, 225, 255, 0.6)');
    glow.addColorStop(0.5, 'rgba(140, 190, 255, 0.12)');
    glow.addColorStop(1, 'rgba(140, 190, 255, 0)');
    context.fillStyle = glow;
    context.fillRect(0, 0, 128, 128);

    return new three.CanvasTexture(canvas);
}

async function build(): Promise<Scene> {
    const three = await import('three');
    const [earthMaps, maps, ring] = await Promise.all([
        Promise.all(EARTH_MAPS.map(loadImage)),
        Promise.all(
            WORLDS.map((world) =>
                world.map ? loadImage(world.map) : Promise.resolve(null),
            ),
        ),
        loadImage(SATURN_RING),
    ]);

    const renderer = new three.WebGLRenderer({ alpha: true, antialias: true });
    renderer.setClearColor(0x000000, 0);
    renderer.toneMapping = three.ACESFilmicToneMapping;

    const threeScene = new three.Scene();
    const camera = new three.PerspectiveCamera(FIELD_OF_VIEW, 1, 0.5, 2000);
    threeScene.add(new three.AmbientLight(0x8a93a3, 0.35));
    threeScene.add(new three.PointLight(0xfff1dd, 4.2, 0, 0));

    const texture = (image: HTMLImageElement, isColor = true) => {
        const map = new three.Texture(image);
        map.colorSpace = isColor ? three.SRGBColorSpace : three.NoColorSpace;
        map.anisotropy = 4;
        map.wrapS = three.RepeatWrapping;
        map.needsUpdate = true;

        return map;
    };

    // The Sun and its corona.
    const sunTime: Three.IUniform<number>[] = [];
    const sunClock = { value: 0 };
    sunTime.push(sunClock);
    const sun = new three.Mesh(
        new three.SphereGeometry(SUN_RADIUS, 64, 32),
        new three.ShaderMaterial({
            uniforms: { time: sunClock },
            vertexShader: SURFACE_VERTEX,
            fragmentShader: SUN_FRAGMENT,
        }),
    );
    threeScene.add(sun);

    const coronaExtent = 2.6;
    const coronaClock = { value: 0 };
    sunTime.push(coronaClock);
    const corona = new three.Mesh(
        new three.PlaneGeometry(
            SUN_RADIUS * coronaExtent * 2,
            SUN_RADIUS * coronaExtent * 2,
        ),
        new three.ShaderMaterial({
            uniforms: { time: coronaClock, extent: { value: coronaExtent } },
            vertexShader: CORONA_VERTEX,
            fragmentShader: CORONA_FRAGMENT,
            transparent: true,
            depthWrite: false,
            blending: three.AdditiveBlending,
        }),
    );
    threeScene.add(corona);

    // The planets, each spinning on its tilted axis.
    const cloudTurn = { value: 0 };
    const planets = WORLDS.map((world, index): Planet => {
        const geometry = new three.SphereGeometry(world.radius, 48, 24);
        const image = maps[index];
        const material =
            world.name === 'Earth'
                ? new three.ShaderMaterial({
                      uniforms: {
                          dayMap: { value: texture(earthMaps[0], false) },
                          nightMap: { value: texture(earthMaps[1], false) },
                          surfaceMap: { value: texture(earthMaps[2], false) },
                          sunPosition: { value: new three.Vector3() },
                          cloudTurn,
                      },
                      vertexShader: SURFACE_VERTEX,
                      fragmentShader: EARTH_FRAGMENT,
                  })
                : new three.MeshStandardMaterial({
                      map: image ? texture(image) : null,
                      roughness: 1,
                      metalness: 0,
                  });
        const spin = new three.Mesh(geometry, material);
        const body = new three.Group();
        body.rotation.z = world.tilt;
        body.add(spin);

        if (world.name === 'Saturn') {
            // Its rings, mapped from the inner edge out.
            const inner = world.radius * 1.25;
            const outer = world.radius * 2.25;
            const ringGeometry = new three.RingGeometry(inner, outer, 128, 1);
            const position = ringGeometry.getAttribute('position');
            const uv = ringGeometry.getAttribute('uv');

            for (let vertex = 0; vertex < position.count; vertex++) {
                const distance = Math.hypot(
                    position.getX(vertex),
                    position.getY(vertex),
                );
                uv.setXY(vertex, (distance - inner) / (outer - inner), 0.5);
            }

            const rings = new three.Mesh(
                ringGeometry,
                // Lit on their own: with the Sun in their plane they'd be dark edge-on.
                new three.MeshBasicMaterial({
                    map: texture(ring),
                    color: 0xd9ccb4,
                    transparent: true,
                    side: three.DoubleSide,
                    depthWrite: false,
                }),
            );
            rings.rotation.x = Math.PI / 2;
            body.add(rings);
        }

        threeScene.add(body);

        return { world, body, spin };
    });

    // The asteroid belt between Mars and Jupiter.
    const asteroids = new Float32Array(1800 * 3);

    for (let index = 0; index < 1800; index++) {
        const angle = Math.random() * Math.PI * 2;
        const distance = 47 + Math.random() * 7 + (Math.random() - 0.5) * 2;
        asteroids.set(
            [
                Math.cos(angle) * distance,
                (Math.random() - 0.5) * 1.2,
                Math.sin(angle) * distance,
            ],
            index * 3,
        );
    }

    const beltGeometry = new three.BufferGeometry();
    beltGeometry.setAttribute(
        'position',
        new three.BufferAttribute(asteroids, 3),
    );
    const belt = new three.Points(
        beltGeometry,
        new three.PointsMaterial({
            color: 0x8c7f70,
            size: 0.35,
            transparent: true,
            opacity: 0.8,
        }),
    );
    threeScene.add(belt);

    // Faint orbits.
    for (const world of WORLDS) {
        const points = Array.from({ length: 129 }, (_, step) => {
            const angle = (step / 128) * Math.PI * 2;

            return new three.Vector3(
                Math.cos(angle) * world.orbit,
                0,
                Math.sin(angle) * world.orbit,
            );
        });
        threeScene.add(
            new three.Line(
                new three.BufferGeometry().setFromPoints(points),
                new three.LineBasicMaterial({
                    color: 0xffd7bd,
                    transparent: true,
                    opacity: 0.12,
                }),
            ),
        );
    }

    // Ribbons of solar wind streaming out through the planets.
    const ribbons = Array.from({ length: RIBBONS }, (_, index): Ribbon => {
        const geometry = stripGeometry(three, RIBBON_SEGMENTS);
        const uniforms = {
            time: { value: 0 },
            front: { value: 0 },
            fade: { value: 0 },
            seed: { value: Math.random() * 100 },
        };
        const mesh = new three.Mesh(
            geometry,
            new three.ShaderMaterial({
                uniforms,
                vertexShader: RIBBON_VERTEX,
                fragmentShader: RIBBON_FRAGMENT,
                transparent: true,
                depthWrite: false,
                side: three.DoubleSide,
                blending: three.AdditiveBlending,
            }),
        );
        mesh.frustumCulled = false;
        threeScene.add(mesh);

        return {
            mesh,
            uniforms,
            // Staggered, so they don't all erupt together.
            bornAt: 3 + index * 6,
            life: RIBBON_LIFE[0],
            heading: Math.random() * Math.PI * 2,
            rise: (Math.random() - 0.5) * 0.5,
            length: 40 + Math.random() * 18,
            curl: 0.5 + Math.random() * 0.6,
        };
    });

    // A comet: a dark, lumpy nucleus of ice and dust in its glowing coma,
    // with a curving dust tail and a straight blue ion tail.
    const nucleusShape = new three.IcosahedronGeometry(0.55, 3);
    const corners = nucleusShape.getAttribute('position');

    for (let corner = 0; corner < corners.count; corner++) {
        const x = corners.getX(corner);
        const y = corners.getY(corner);
        const z = corners.getZ(corner);
        const lumps =
            1 +
            0.22 * Math.sin(x * 7 + y * 3) * Math.cos(z * 5 - x * 2) +
            0.1 * Math.sin(y * 13 + z * 11);
        corners.setXYZ(corner, x * lumps * 1.3, y * lumps, z * lumps * 0.9);
    }

    nucleusShape.computeVertexNormals();
    const nucleus = new three.Mesh(
        nucleusShape,
        new three.MeshStandardMaterial({ color: 0x4d4843, roughness: 1 }),
    );
    const coma = new three.Sprite(
        new three.SpriteMaterial({
            map: glowTexture(three),
            transparent: true,
            depthWrite: false,
            blending: three.AdditiveBlending,
        }),
    );
    const tail = (near: string, far: string, streakiness: number): Tail => {
        const uniforms = {
            time: { value: 0 },
            strength: { value: 0 },
            seed: { value: Math.random() * 100 },
            streakiness: { value: streakiness },
            nearColor: { value: new three.Color(near) },
            farColor: { value: new three.Color(far) },
        };
        const mesh = new three.Mesh(
            stripGeometry(three, TAIL_SEGMENTS),
            new three.ShaderMaterial({
                uniforms,
                vertexShader: RIBBON_VERTEX,
                fragmentShader: TAIL_FRAGMENT,
                transparent: true,
                depthWrite: false,
                side: three.DoubleSide,
                blending: three.AdditiveBlending,
            }),
        );
        mesh.frustumCulled = false;
        threeScene.add(mesh);

        return { mesh, uniforms };
    };
    const dust = tail('#FFF1D2', '#C9A774', 2.5);
    const ion = tail('#D8F0FF', '#4F8DFF', 7);
    threeScene.add(nucleus, coma);

    return {
        three,
        renderer,
        scene: threeScene,
        camera,
        planets,
        sunTime,
        corona,
        cloudTurn,
        belt,
        ribbons,
        comet: {
            nucleus,
            coma,
            dust,
            ion,
            position: new three.Vector3(),
            strength: 0,
        },
    };
}

/**
 * Moves each plume along: now and then it bursts off the Sun, curling
 * outward (like the solar wind) and spreading wide as it goes, and is gone
 * within a few seconds; then it rests before bursting out somewhere else.
 * Each one turns to face the camera so it never goes edge-on.
 */
function streamRibbons({ three, camera, ribbons }: Scene, seconds: number) {
    const point = new three.Vector3();
    const ahead = new three.Vector3();
    const tangent = new three.Vector3();
    const side = new three.Vector3();
    const toCamera = new three.Vector3();

    for (const ribbon of ribbons) {
        if (seconds - ribbon.bornAt > ribbon.life) {
            // Rest a while, then erupt again somewhere else.
            ribbon.bornAt =
                seconds +
                RIBBON_REST[0] +
                Math.random() * (RIBBON_REST[1] - RIBBON_REST[0]);
            ribbon.life =
                RIBBON_LIFE[0] +
                Math.random() * (RIBBON_LIFE[1] - RIBBON_LIFE[0]);
            ribbon.heading = Math.random() * Math.PI * 2;
            ribbon.rise = (Math.random() - 0.5) * 0.5;
            ribbon.length = 40 + Math.random() * 18;
            ribbon.curl = 0.5 + Math.random() * 0.6;
            ribbon.uniforms.seed.value = Math.random() * 100;
        }

        const age = Math.max(0, (seconds - ribbon.bornAt) / ribbon.life);
        ribbon.uniforms.time.value = seconds;
        // It bursts out quickly and is gone almost as fast.
        ribbon.uniforms.front.value = Math.min(1, age * 3.5) * 1.05;
        ribbon.uniforms.fade.value =
            Math.min(1, age * 12) *
            (1 - Math.min(1, Math.max(0, (age - 0.3) / 0.7))) ** 1.4;

        const at = (along: number, target: Three.Vector3) => {
            const distance = SUN_RADIUS * 0.95 + along * ribbon.length;
            const angle = ribbon.heading + along * ribbon.curl;
            const wave =
                Math.sin(along * 6 + ribbon.uniforms.seed.value) * along * 3;

            return target.set(
                Math.cos(angle) * distance,
                ribbon.rise * distance * 0.4 + wave,
                Math.sin(angle) * distance,
            );
        };

        const positions = ribbon.mesh.geometry.getAttribute('position');

        for (let segment = 0; segment <= RIBBON_SEGMENTS; segment++) {
            const along = segment / RIBBON_SEGMENTS;
            at(along, point);
            at(Math.min(1, along + 0.01), ahead);
            tangent.subVectors(ahead, point).normalize();
            toCamera.subVectors(camera.position, point).normalize();
            side.crossVectors(tangent, toCamera).normalize();
            // Narrow at the Sun, spreading out as it goes.
            // Spreading out wide as it goes.
            const width = 3 + along * 20;

            positions.setXYZ(
                segment * 2,
                point.x - side.x * width,
                point.y - side.y * width,
                point.z - side.z * width,
            );
            positions.setXYZ(
                segment * 2 + 1,
                point.x + side.x * width,
                point.y + side.y * width,
                point.z + side.z * width,
            );
        }

        positions.needsUpdate = true;
    }
}

/**
 * Moves the comet along its orbit (quickly round the Sun, slowly far out, as
 * Kepler had it), and points its tails away from the Sun, longer and brighter
 * the nearer it gets. The dust tail curves back along the path it's come from.
 * Far out it's dormant: no coma, no tails.
 */
function flyComet({ three, camera, comet }: Scene, seconds: number) {
    const { axis, eccentricity, period, tilt, turn, phase } = COMET;
    const mean = (phase + seconds / period) * Math.PI * 2;
    let eccentric = mean;

    for (let step = 0; step < 6; step++) {
        eccentric -=
            (eccentric - eccentricity * Math.sin(eccentric) - mean) /
            (1 - eccentricity * Math.cos(eccentric));
    }

    const minor = axis * Math.sqrt(1 - eccentricity ** 2);
    const orbit = new three.Euler(tilt, turn, 0);
    const position = new three.Vector3(
        axis * (Math.cos(eccentric) - eccentricity),
        0,
        -minor * Math.sin(eccentric),
    ).applyEuler(orbit);
    const heading = new three.Vector3(
        -axis * Math.sin(eccentric),
        0,
        -minor * Math.cos(eccentric),
    )
        .applyEuler(orbit)
        .normalize();

    const distance = position.length();
    const { near, far } = COMET_ACTIVE;
    const waking = Math.min(1, Math.max(0, (far - distance) / (far - near)));
    const awake = waking * waking * (3 - 2 * waking);
    const strength =
        Math.min(1.4, Math.max(0.3, (60 / distance) ** 1.3)) * awake;
    const away = position.clone().normalize();
    comet.position.copy(position);
    comet.strength = strength;

    comet.nucleus.visible = awake > 0;
    comet.nucleus.position.copy(position);
    comet.nucleus.rotation.set(seconds * 0.2, seconds * 0.13, 0);
    comet.coma.position.copy(position);
    comet.coma.scale.setScalar(5 + strength * 7);
    comet.coma.material.opacity = Math.min(1, 0.5 + strength * 0.6) * awake;

    const dustLength = 24 + strength * 50;
    const ionLength = 34 + strength * 72;

    for (const tail of [comet.dust, comet.ion]) {
        tail.uniforms.time.value = seconds;
        tail.uniforms.strength.value =
            strength * (tail === comet.ion ? 2.2 : 2);
    }

    layStrip(
        three,
        camera,
        comet.dust.mesh,
        TAIL_SEGMENTS,
        (along, target) =>
            target
                .copy(position)
                .addScaledVector(away, along * dustLength)
                .addScaledVector(heading, -along * along * dustLength * 0.45),
        (along) => 1 + along * (7 + strength * 6),
    );
    layStrip(
        three,
        camera,
        comet.ion.mesh,
        TAIL_SEGMENTS,
        (along, target) =>
            target
                .copy(position)
                .addScaledVector(away, along * ionLength)
                .addScaledVector(
                    heading,
                    Math.sin(along * 5 + seconds * 0.7) * along * 1.2,
                ),
        (along) => 0.5 + along * 2.4,
    );
}

/** Starts loading three.js and the planets' maps (about 0.5 MB) in the background. */
export function loadSolarSystem() {
    if (status !== 'idle') {
        return;
    }

    status = 'loading';

    build()
        .then((built) => {
            scene = built;
            status = 'ready';
        })
        .catch((error: unknown) => {
            status = 'failed';
            console.warn('The 3D Solar System is unavailable.', error);
        });
}

/** Whether it's done loading, one way or the other (if it failed, the flat Solar System is used instead). */
export function isSolarSystemSettled(): boolean {
    return status === 'ready' || status === 'failed';
}

export function hasSolarSystem(): boolean {
    return scene !== null;
}

/** Where a planet was last drawn in the Solar System view (CSS pixels from the canvas's top left), and its radius there. */
export function solarPlanetOnScreen(name: string) {
    return onScreen[name] ?? null;
}

/**
 * Draws the Solar System into `context`, filling its `size` square canvas
 * (CSS pixels), `seconds` in. `towardEarth` (0 to 1) flies the camera in to
 * Earth, for the zoom down to it.
 */
export function drawSolarSystem(
    context: CanvasRenderingContext2D,
    size: number,
    pixelScale: number,
    seconds: number,
    towardEarth: number,
) {
    if (!scene) {
        return;
    }

    const { three, renderer, camera, planets, sunTime, corona, cloudTurn } =
        scene;
    const pixels = Math.round(size * Math.min(pixelScale, 1.5));

    if (renderer.domElement.width !== pixels) {
        renderer.setPixelRatio(1);
        renderer.setSize(pixels, pixels, false);
    }

    for (const clock of sunTime) {
        clock.value = seconds;
    }

    cloudTurn.value = seconds * 0.004;
    scene.belt.rotation.y = seconds * 0.01;

    let earth = new three.Vector3();

    for (const { world, body, spin } of planets) {
        const angle = world.phase + (seconds / world.period) * Math.PI * 2;
        body.position.set(
            Math.cos(angle) * world.orbit,
            0,
            -Math.sin(angle) * world.orbit,
        );
        spin.rotation.y = seconds * (world.name === 'Venus' ? -0.05 : 0.35);

        if (world.name === 'Earth') {
            earth = body.position.clone();
            (
                (spin as Three.Mesh).material as Three.ShaderMaterial
            ).uniforms.sunPosition.value.set(0, 0, 0);
        }
    }

    // Circling slowly above the planets' plane; flying in to Earth on the way down to it.
    const turn = seconds * 0.02;
    const circling = new three.Vector3(
        Math.sin(turn) * Math.cos(CAMERA_ELEVATION),
        Math.sin(CAMERA_ELEVATION),
        Math.cos(turn) * Math.cos(CAMERA_ELEVATION),
    ).multiplyScalar(CAMERA_DISTANCE);
    const nearEarth = earth
        .clone()
        .add(circling.clone().normalize().multiplyScalar(14));
    const ease = towardEarth * towardEarth * (3 - 2 * towardEarth);
    camera.position.copy(circling.lerp(nearEarth, ease));
    camera.lookAt(new three.Vector3().lerp(earth, ease));
    corona.quaternion.copy(camera.quaternion);
    streamRibbons(scene, seconds);
    flyComet(scene, seconds);

    renderer.render(scene.scene, camera);
    context.drawImage(renderer.domElement, 0, 0, size, size);

    // Name each planet under it, and remember where it is (for hovering).
    const halfView = Math.tan(((FIELD_OF_VIEW / 2) * Math.PI) / 180);
    context.save();
    context.globalAlpha = 1 - ease;
    context.font = '500 11px "Instrument Sans", sans-serif';
    context.textAlign = 'center';
    context.fillStyle = 'rgba(255, 236, 222, 0.75)';

    for (const { world, body } of planets) {
        const projected = body.position.clone().project(camera);
        const distance = camera.position.distanceTo(body.position);
        const radius = (world.radius / (distance * halfView)) * (size / 2);
        const x = ((projected.x + 1) / 2) * size;
        const y = ((1 - projected.y) / 2) * size;
        onScreen[world.name] = { x, y, radius };
        context.fillText(
            world.name,
            x,
            y + radius * (world.name === 'Saturn' ? 1.6 : 1) + 14,
        );
    }

    // And the comet, while it's in close enough to be bright.
    const cometOnScreen = scene.comet.position.clone().project(camera);

    if (scene.comet.strength > 0.3 && cometOnScreen.z < 1) {
        context.globalAlpha = (1 - ease) * Math.min(1, scene.comet.strength);
        context.fillText(
            'Comet',
            ((cometOnScreen.x + 1) / 2) * size,
            ((1 - cometOnScreen.y) / 2) * size + 18,
        );
    }

    context.restore();
}
