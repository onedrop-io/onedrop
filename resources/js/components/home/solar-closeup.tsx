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
        orbit: 13,
        radius: 1.6,
        period: 9,
        phase: 1.2,
        tilt: 0.03,
        map: '/images/planets/mercury.jpg',
    },
    {
        name: 'Venus',
        orbit: 18.5,
        radius: 2.5,
        period: 14,
        phase: 4.1,
        tilt: 3.1,
        map: '/images/planets/venus.jpg',
    },
    {
        name: 'Earth',
        orbit: 25,
        radius: 2.7,
        period: 20,
        phase: 2.4,
        tilt: 0.41,
    },
    {
        name: 'Mars',
        orbit: 32,
        radius: 1.9,
        period: 28,
        phase: 5.3,
        tilt: 0.44,
        map: '/images/mars/color.jpg',
    },
    {
        name: 'Jupiter',
        orbit: 48,
        radius: 6.2,
        period: 55,
        phase: 0.6,
        tilt: 0.05,
        map: '/images/planets/jupiter.jpg',
    },
    {
        name: 'Saturn',
        orbit: 62,
        radius: 5,
        period: 80,
        phase: 3.5,
        tilt: 0.47,
        map: '/images/planets/saturn.jpg',
    },
    {
        name: 'Uranus',
        orbit: 74,
        radius: 3.5,
        period: 110,
        phase: 5.9,
        tilt: 1.71,
        map: '/images/planets/uranus.jpg',
    },
    {
        name: 'Neptune',
        orbit: 84,
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

const SUN_RADIUS = 8.5;

/** The camera: its field of view, how far out it sits, and how high above the planets' plane it looks down from (radians). */
const FIELD_OF_VIEW = 34;
const CAMERA_DISTANCE = 300;
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
};

let scene: Scene | null = null;
let status: 'idle' | 'loading' | 'ready' | 'failed' = 'idle';

/** Where each planet was last drawn on the canvas (CSS pixels), and how big. */
const onScreen: Record<string, { x: number; y: number; radius: number }> = {};

async function build(): Promise<Scene> {
    const three = await import('three');
    const [earthMaps, maps, ring] = await Promise.all([
        Promise.all(EARTH_MAPS.map(loadImage)),
        Promise.all(
            WORLDS.map((world) => (world.map ? loadImage(world.map) : null)),
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
        const distance = 37 + Math.random() * 6 + (Math.random() - 0.5) * 2;
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
    };
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

    context.restore();
}
