/**
 * An easter egg in the Sol system: hover over Mars and it swoops up into a
 * realistic globe, turns to bring Gale Crater round, then dives through to
 * the surface, where you ride along with the Curiosity rover, looking out of
 * its mast camera as it drives across the crater floor toward Mount Sharp.
 * Then it pulls back out and shrinks back into its orbit.
 *
 * The globe is Solar System Scope's Mars map (solarsystemscope.com/textures,
 * CC BY 4.0, resized); the home page's footer credits it. The surface is
 * drawn from scratch: a ray-marched height field of rolling ground, scattered
 * rocks, sand ripples, the layered mound of Mount Sharp and the crater rim,
 * under a hazy butterscotch sky, with the rover's own shadow cast ahead of it.
 */

import {
    createShaderCanvas,
    drawSpaceBackdrop,
    loadImage,
    normalize,
    renderShaderCanvas,
    smoothstep,
} from '@/components/home/closeup-gl';
import type { ShaderCanvas } from '@/components/home/closeup-gl';

/** Size of the close-up's canvas, and Mars's radius in it (CSS pixels). */
export const MARS_CLOSEUP_SIZE = 780;
export const MARS_CLOSEUP_RADIUS = 140;

/**
 * How long each part of the show takes (seconds): growing into a globe,
 * turning to Gale Crater, diving to the surface, driving, pulling back out to
 * the globe, and shrinking back into the galaxy.
 */
export const MARS_SHOW = {
    grow: 1.6,
    turn: 2.4,
    dive: 1.3,
    drive: 10,
    rise: 1.2,
    shrink: 1.4,
};

const MAPS = ['/images/mars/color.jpg'];

/** Gale Crater, where Curiosity landed (radians). */
const GALE = { lat: -0.094, lon: 2.405 };

/** How far Mars's north pole leans right (radians), and where the Sun lights the globe from. */
const AXIAL_TILT = 0.44;
const GLOBE_SUN = normalize(-0.8, 0.3, 0.52);

/** The globe's canvas reaches a little past Mars, for its thin, dusty atmosphere (in Mars radii). */
const GLOBE_EXTENT = 1.06;

/**
 * The mast camera's view (CSS pixels), and how many pixels it's rendered at
 * per CSS pixel: ray marching is expensive, so it's drawn smaller and scaled
 * up, which also softens it like a real camera.
 */
const VIEW = { width: 660, height: 340, corner: 16, raise: 14 };
const VIEW_PIXEL_SCALE = 0.8;

/** Curiosity landed at 05:17 UTC on August 6, 2012 (Sol 0); a sol lasts 88,775 seconds. */
const LANDED_AT = Date.UTC(2012, 7, 6, 5, 17);
const SOL_MS = 88_775_244;

const GLOBE_SHADER = `#version 300 es
precision highp float;

in vec2 screen;
out vec4 color;

uniform sampler2D colorMap;
uniform vec3 sun;
uniform mat3 toMars;
uniform float turn;
uniform float pixel;

const float PI = 3.14159265;
const float ATMOSPHERE = 1.035;
const vec3 HAZE = vec3(0.93, 0.62, 0.45);

vec3 toScreen(vec3 value) {
    return pow(clamp(value, 0.0, 1.0), vec3(1.0 / 2.2));
}

void main() {
    float distance = length(screen);
    vec4 globe = vec4(0.0);

    if (distance < 1.0 + pixel) {
        vec3 normal = distance < 1.0
            ? vec3(screen, sqrt(1.0 - distance * distance))
            : vec3(screen / distance, 0.0);
        vec3 mars = toMars * normal;
        vec2 place = vec2(
            (atan(mars.x, mars.z) + turn) / (2.0 * PI) + 0.5,
            0.5 - asin(clamp(mars.y, -1.0, 1.0)) / PI
        );

        vec3 albedo = pow(texture(colorMap, place).rgb, vec3(2.2));
        float facing = dot(normal, sun);
        float lit = max(facing, 0.0);

        // Dust softens the terminator, and the thin air hazes over the edge.
        float day = smoothstep(-0.08, 0.35, facing);
        vec3 surface = albedo * (lit * 1.25 + 0.015) * mix(0.35, 1.0, day);
        float haze = pow(1.0 - normal.z, 3.0) * smoothstep(-0.2, 0.6, facing);
        surface = mix(surface, pow(HAZE, vec3(2.2)) * 0.8, clamp(haze * 0.7, 0.0, 1.0));

        float coverage = clamp((1.0 - distance) / pixel + 0.5, 0.0, 1.0);
        globe = vec4(toScreen(surface), 1.0) * coverage;
    }

    vec4 halo = vec4(0.0);

    if (distance > 1.0 - pixel && distance < ATMOSPHERE) {
        vec3 edge = vec3(screen / distance, 0.0);
        float height = (distance - 1.0) / (ATMOSPHERE - 1.0);
        float alpha = pow(1.0 - clamp(height, 0.0, 1.0), 2.0)
            * smoothstep(-0.3, 0.8, dot(edge, sun)) * 0.55;

        halo = vec4(toScreen(pow(HAZE, vec3(2.2))), 1.0) * alpha;
    }

    color = globe + halo * (1.0 - globe.a);
}`;

const SURFACE_SHADER = `#version 300 es
precision highp float;

in vec2 screen;
out vec4 fragment;

uniform vec2 resolution;
uniform float time;

// The afternoon Sun, low behind and to the right: raking light shows off every
// rock and ridge, and the rover's shadow falls ahead and to the left.
const vec3 SUN = normalize(vec3(0.32, 0.42, -0.85));
const vec3 SUN_LIGHT = vec3(1.0, 0.93, 0.84);

const float SPEED = 1.4;
const float MAST_HEIGHT = 2.1;

float hash(vec2 p) {
    p = fract(p * vec2(123.34, 456.21));
    p += dot(p, p + 45.32);

    return fract(p.x * p.y);
}

float noise(vec2 p) {
    vec2 cell = floor(p);
    vec2 f = fract(p);
    vec2 u = f * f * (3.0 - 2.0 * f);

    return mix(
        mix(hash(cell), hash(cell + vec2(1.0, 0.0)), u.x),
        mix(hash(cell + vec2(0.0, 1.0)), hash(cell + vec2(1.0, 1.0)), u.x),
        u.y
    );
}

float fbm(vec2 p, int octaves) {
    const mat2 TURN = mat2(1.6, 1.2, -1.2, 1.6);
    float value = 0.0;
    float amplitude = 0.5;

    for (int octave = 0; octave < 8; octave++) {
        if (octave >= octaves) {
            break;
        }

        value += amplitude * noise(p);
        p = TURN * p;
        amplitude *= 0.5;
    }

    return value;
}

/* Sharp-crested noise, for channels eroded into the mountain. */
float ridged(vec2 p, int octaves) {
    const mat2 TURN = mat2(1.6, 1.2, -1.2, 1.6);
    float value = 0.0;
    float amplitude = 0.5;

    for (int octave = 0; octave < 6; octave++) {
        if (octave >= octaves) {
            break;
        }

        value += amplitude * (1.0 - abs(noise(p) * 2.0 - 1.0));
        p = TURN * p;
        amplitude *= 0.5;
    }

    return value;
}

/*
 * Angular rocks scattered over the ground, from stones to boulders: flat
 * tops, steep broken sides, each tipped a little. Returns how high the rock
 * stands here.
 */
float rockField(vec2 p, out float id) {
    float height = 0.0;
    id = 0.0;

    for (int layer = 0; layer < 2; layer++) {
        float size = layer == 0 ? 1.3 : 7.0;
        float chance = layer == 0 ? 0.5 : 0.3;
        vec2 cell = floor(p / size);

        for (int y = -1; y <= 1; y++) {
            for (int x = -1; x <= 1; x++) {
                vec2 neighbor = cell + vec2(x, y) + float(layer) * 91.0;
                float seed = hash(neighbor);

                if (seed > chance) {
                    continue;
                }

                vec2 center = (cell + vec2(x, y) + vec2(hash(neighbor + 3.1), hash(neighbor + 7.7))) * size;
                float radius = layer == 0
                    ? 0.04 + 0.26 * pow(hash(neighbor + 1.9), 3.0)
                    : 0.35 + 0.65 * hash(neighbor + 1.9);
                vec2 offset = p - center;
                float angle = atan(offset.y, offset.x);
                float outline = radius * (0.82
                    + 0.14 * sin(angle * 3.0 + seed * 40.0)
                    + 0.07 * sin(angle * 5.0 + seed * 17.0));
                float distance = length(offset) / outline;

                if (distance < 1.0) {
                    vec2 tip = vec2(hash(neighbor + 4.2), hash(neighbor + 8.4)) - 0.5;
                    float top = radius * (0.4 + 0.25 * hash(neighbor + 2.6)) * (1.0 + dot(offset / radius, tip) * 0.6);
                    float dome = min(1.0, sqrt(1.0 - distance * distance) * 1.5);
                    float chips = 0.92 + 0.16 * noise(offset * 5.0 / radius + seed * 30.0);
                    float rock = top * dome * chips;

                    if (rock > height) {
                        height = rock;
                        id = hash(neighbor + 6.6);
                    }
                }
            }
        }
    }

    return height;
}

float rocks(vec2 p) {
    float id;

    return rockField(p, id);
}

/* The dark Bagnold Dunes, a band of basaltic sand between the rover and Mount Sharp. */
float dunes(vec2 p) {
    if (p.y < 850.0 || p.y > 1650.0) {
        return 0.0;
    }

    return smoothstep(850.0, 1000.0, p.y) * smoothstep(1650.0, 1450.0, p.y)
        * smoothstep(0.3, 0.55, fbm(p * 0.004 + 11.0, 3));
}

/* Mount Sharp, far ahead: a great mound of layered rock, carved by old channels. */
float mountSharp(vec2 p) {
    vec2 toPeak = (p - vec2(300.0, 3300.0)) * vec2(1.0, 1.2);

    if (length(toPeak) > 3400.0) {
        return 0.0;
    }

    float rise = smoothstep(1.0, 0.0, length(toPeak) / 3400.0);
    float height = 1000.0 * pow(rise, 1.3) * (0.7 + 0.6 * fbm(p * 0.002, 4));
    height -= 160.0 * ridged(p * 0.003, 5) * smoothstep(0.05, 0.5, rise);

    // Its sedimentary layers show as uneven terraces, dipping gently to one side.
    float thickness = 18.0 + 30.0 * noise(p * 0.0015);
    float dip = p.x * 0.03 + fbm(p * 0.002, 2) * 40.0;
    float layer = (height + dip) / thickness;
    float terraced = (floor(layer) + smoothstep(0.15, 1.0, fract(layer))) * thickness - dip;

    return max(mix(height, terraced, 0.12), 0.0);
}

/* How high the ground is, with less detail the farther away it is. */
float terrain(vec2 p, float distance) {
    float height = (fbm(p * 0.01, 4) - 0.5) * 6.0;
    height += mountSharp(p);

    // Dune crests across the dark sand, and flat-topped buttes at the mountain's foot.
    float sand = dunes(p);

    if (sand > 0.0) {
        float crest = 0.5 + 0.5 * sin(p.y * 0.035 + p.x * 0.012 + noise(p * 0.01) * 3.0);
        height += 7.0 * crest * crest * crest * sand;
    }

    if (p.y > 1850.0 && p.y < 2900.0) {
        float buttes = smoothstep(1850.0, 2100.0, p.y) * smoothstep(2900.0, 2600.0, p.y);
        height += 70.0 * smoothstep(0.58, 0.63, fbm(p * 0.005 + 3.0, 3)) * buttes;
    }

    // The crater's far rim along the left horizon.
    if (p.x < -1500.0) {
        height += 450.0 * smoothstep(-1500.0, -4000.0, p.x) * (0.6 + 0.6 * ridged(p * 0.0015, 4));
    }

    if (distance < 400.0) {
        height += (fbm(p * 0.12, 4) - 0.5) * 1.3 * smoothstep(400.0, 150.0, distance);
    }

    if (distance < 70.0) {
        float fade = smoothstep(70.0, 30.0, distance);
        height += (fbm(p * 1.6, 3) - 0.5) * 0.08 * fade;
        // Wind-blown sand ripples in patches.
        float ripples = sin(dot(p, vec2(0.8, 0.6)) * 12.0 + noise(p * 0.7) * 6.0);
        height += ripples * 0.015 * smoothstep(0.6, 0.8, noise(p * 0.06)) * fade;
    }

    if (distance < 32.0) {
        height += rocks(p) * smoothstep(32.0, 20.0, distance);
    }

    return height;
}

/* The rover's winding path across the crater floor. */
vec2 path(float z) {
    return vec2(sin(z * 0.017) * 7.0 + sin(z * 0.041) * 2.0, z);
}

float box(vec3 p, vec3 size) {
    vec3 q = abs(p) - size;

    return length(max(q, 0.0)) + min(max(q.x, max(q.y, q.z)), 0.0);
}

/* The rover itself, only ever seen as its shadow: body, mast and head, arm, and six wheels. */
float rover(vec3 p, vec3 base, vec3 forward) {
    vec3 right = normalize(vec3(forward.z, 0.0, -forward.x));
    vec3 local = p - base;
    local = vec3(dot(local, right), local.y, dot(local, forward));

    float shape = box(local - vec3(0.0, 1.0, -0.2), vec3(0.55, 0.22, 0.8));
    shape = min(shape, box(local - vec3(0.42, 1.6, 0.45), vec3(0.05, 0.45, 0.05)));
    shape = min(shape, box(local - vec3(0.42, 2.1, 0.45), vec3(0.2, 0.1, 0.12)));
    shape = min(shape, box(local - vec3(0.0, 0.9, 1.0), vec3(0.06, 0.06, 0.45)));
    shape = min(shape, box(local - vec3(-0.35, 1.35, -0.85), vec3(0.3, 0.15, 0.25)));

    for (int wheel = 0; wheel < 3; wheel++) {
        float along = float(wheel - 1) * 0.85;

        for (int side = -1; side <= 1; side += 2) {
            vec3 hub = local - vec3(float(side) * 0.72, 0.26, along);
            shape = min(shape, max(length(hub.yz) - 0.26, abs(hub.x) - 0.2));
        }

        shape = min(shape, box(local - vec3(0.0, 0.62, along), vec3(0.7, 0.04, 0.05)));
    }

    return shape;
}

vec3 sky(vec3 direction) {
    float up = max(direction.y, 0.0);
    vec3 horizon = vec3(0.8, 0.66, 0.53);
    vec3 zenith = vec3(0.58, 0.44, 0.34);
    vec3 color = mix(horizon, zenith, pow(up, 0.6));
    float toSun = max(dot(direction, SUN), 0.0);

    // Martian dust scatters a little blue around the Sun.
    color += vec3(0.4, 0.5, 0.62) * pow(toSun, 10.0) * 0.3;
    color += SUN_LIGHT * pow(toSun, 1500.0) * 8.0;

    return color;
}

void main() {
    float aspect = resolution.x / resolution.y;
    vec2 view = screen * vec2(aspect, 1.0);

    // Where the rover is, and which way it's heading and leaning over the ground.
    float along = 30.0 + time * SPEED;
    vec2 here = path(along);
    vec2 ahead = path(along + 1.5);
    vec2 heading = normalize(ahead - here);
    vec2 side = vec2(heading.y, -heading.x);
    float front = terrain(here + heading * 1.1, 0.0);
    float back = terrain(here - heading * 1.1, 0.0);
    float leftWheels = terrain(here - side * 0.7, 0.0);
    float rightWheels = terrain(here + side * 0.7, 0.0);
    float pitch = atan(front - back, 2.2);
    float lean = atan(rightWheels - leftWheels, 1.4);

    vec3 base = vec3(here.x, (front + back) * 0.5, here.y);
    vec3 forward = normalize(vec3(heading.x, sin(pitch), heading.y));
    vec3 origin = base + vec3(0.0, MAST_HEIGHT, 0.0) + forward * 0.45;

    // The mast camera looks a little down, rocking as the wheels climb over rocks.
    float bob = sin(time * 5.3) * 0.003 + sin(time * 8.9) * 0.002;
    vec3 look = normalize(forward + vec3(0.0, -0.17 + bob, 0.0));
    vec3 right = normalize(cross(look, vec3(sin(lean) * 0.3, 1.0, 0.0)));
    vec3 up = cross(right, look);
    vec3 ray = normalize(look * 1.8 + right * view.x + up * view.y);

    float distance = 0.3;
    float gap = 1.0;
    bool isHit = false;

    for (int iteration = 0; iteration < 220; iteration++) {
        vec3 point = origin + ray * distance;
        gap = point.y - terrain(point.xz, distance);

        if (gap < 0.002 * distance) {
            isHit = true;

            break;
        }

        // Longer strides farther out, where rays skim the ground for kilometers.
        distance += distance < 40.0
            ? max(gap * 0.4, 0.005 + distance * 0.0015)
            : max(gap * 0.45, distance * 0.02);

        if (distance > 9000.0) {
            break;
        }
    }

    // A ray that ran out of steps just above the ground has as good as reached it.
    if (!isHit && distance < 9000.0 && gap < distance * 0.02) {
        isHit = true;
    }

    vec3 color;

    if (isHit) {
        // Close in on the surface.
        float near = distance * 0.97;
        float far = distance;

        for (int iteration = 0; iteration < 6; iteration++) {
            float middle = (near + far) * 0.5;
            vec3 point = origin + ray * middle;

            if (point.y < terrain(point.xz, middle)) {
                far = middle;
            } else {
                near = middle;
            }
        }

        distance = far;
        vec3 point = origin + ray * distance;
        float delta = 0.003 + distance * 0.002;
        vec3 normal = normalize(vec3(
            terrain(point.xz - vec2(delta, 0.0), distance) - terrain(point.xz + vec2(delta, 0.0), distance),
            2.0 * delta,
            terrain(point.xz - vec2(0.0, delta), distance) - terrain(point.xz + vec2(0.0, delta), distance)
        ));

        // Fine sand grains catch the light right in front of the camera.
        if (distance < 10.0) {
            const float GRAIN = 0.02;
            vec2 grain = vec2(
                noise((point.xz + vec2(GRAIN, 0.0)) * 40.0) - noise((point.xz - vec2(GRAIN, 0.0)) * 40.0),
                noise((point.xz + vec2(0.0, GRAIN)) * 40.0) - noise((point.xz - vec2(0.0, GRAIN)) * 40.0)
            ) * 0.004 / (2.0 * GRAIN);
            normal = normalize(normal - vec3(grain.x, 0.0, grain.y) * smoothstep(10.0, 3.0, distance));
        }

        // Shadows from the ground, the rocks, and the rover.
        float shadow = 1.0;

        if (distance < 600.0) {
            float travel = 0.02;

            for (int iteration = 0; iteration < 28; iteration++) {
                vec3 probe = point + normal * 0.01 + SUN * travel;
                // Farther along the shadow ray, coarser ground will do.
                float gap = min(
                    probe.y - terrain(probe.xz, distance + travel * 3.0),
                    rover(probe, base, forward)
                );
                shadow = min(shadow, 24.0 * gap / travel);

                if (shadow < 0.01) {
                    break;
                }

                travel += clamp(gap, 0.02, 3.0 + travel * 0.1);

                if (travel > 150.0) {
                    break;
                }
            }

            shadow = clamp(shadow, 0.0, 1.0);
        }

        // Tan dust and soil speckled with gravel, pale bedrock slabs, dark
        // basalt rocks with dust on their tops, grayer layers on the mountain.
        float rockId = 0.0;
        float rockHeight = distance < 32.0 ? rockField(point.xz, rockId) : 0.0;

        // Ground tucked in beside a rock gets less light (ambient occlusion).
        float occlusion = 1.0;

        if (distance < 25.0) {
            const float REACH = 0.14;
            float around = max(rocks(point.xz + vec2(REACH, 0.0)), rocks(point.xz - vec2(0.0, REACH)));
            occlusion = clamp(1.0 - max(around - rockHeight, 0.0) * 3.0, 0.45, 1.0);
        }

        // Fine detail fades out with distance, where it would only shimmer.
        float detail = smoothstep(120.0, 25.0, distance);
        float patches = mix(0.5, fbm(point.xz * 0.25, 3), smoothstep(400.0, 60.0, distance));
        vec3 albedo = mix(vec3(0.5, 0.37, 0.27), vec3(0.62, 0.48, 0.36), smoothstep(0.3, 0.75, patches));
        float slabs = smoothstep(0.62, 0.66, fbm(point.xz * 0.09 + 7.0, 3));
        albedo = mix(albedo, vec3(0.6, 0.5, 0.4), slabs * 0.55);
        vec2 grit = mat2(0.8, 0.6, -0.6, 0.8) * point.xz * 14.0;
        float gravel = smoothstep(0.66, 0.9, noise(grit) * 0.7 + noise(grit * 2.3) * 0.3) * smoothstep(12.0, 3.0, distance);
        albedo = mix(albedo, vec3(0.3, 0.25, 0.22), gravel * 0.5);

        float isRock = smoothstep(0.004, 0.02, rockHeight);
        // Dark basalt, gray, or rusty brown, with dust settled on top.
        vec3 rock = rockId < 0.45
            ? vec3(0.23, 0.21, 0.2)
            : rockId < 0.8 ? vec3(0.33, 0.3, 0.28) : vec3(0.42, 0.3, 0.23);
        rock *= 0.8 + 0.4 * noise(point.xz * 9.0 + rockId * 50.0);
        rock = mix(rock, vec3(0.55, 0.43, 0.33), pow(max(normal.y, 0.0), 8.0) * 0.4);
        albedo = mix(albedo, rock, isRock);

        albedo = mix(albedo, vec3(0.24, 0.21, 0.2), dunes(point.xz) * 0.85);

        float mountain = smoothstep(30.0, 150.0, point.y);
        float bands = 0.5 + 0.5 * sin((point.y + point.x * 0.03) * 0.2 + fbm(point.xz * 0.004, 2) * 5.0);
        albedo = mix(albedo, mix(vec3(0.33, 0.27, 0.23), vec3(0.44, 0.35, 0.28), bands), mountain);

        albedo *= 1.0 + (noise(point.xz * 5.0) - 0.5) * 0.2 * detail;
        albedo = pow(albedo, vec3(2.2));

        float sunlight = max(dot(normal, SUN), 0.0) * shadow;
        vec3 skylight = pow(vec3(0.74, 0.6, 0.48), vec3(2.2)) * (0.55 + 0.45 * normal.y);
        vec3 bounce = pow(vec3(0.58, 0.44, 0.32), vec3(2.2)) * (0.5 - 0.5 * normal.y);
        vec3 lit = albedo * (SUN_LIGHT * sunlight * 2.3 + (skylight * 0.7 + bounce * 0.25) * occlusion);

        // The dusty air hazes out the distance.
        float haze = 1.0 - exp(-distance * 0.00026);
        vec3 air = pow(sky(normalize(vec3(ray.x, 0.03, ray.z))), vec3(2.2));
        color = mix(lit, air, haze);
    } else {
        color = pow(sky(ray), vec3(2.2));
    }

    // Filmic tone curve and a slightly muted palette, like the camera's own processing.
    color *= 1.05;
    color = (color * (2.51 * color + 0.03)) / (color * (2.43 * color + 0.59) + 0.14);
    color = pow(clamp(color, 0.0, 1.0), vec3(1.0 / 2.2));
    color = mix(vec3(dot(color, vec3(0.3, 0.59, 0.11))), color, 0.85);

    // A touch of sensor grain and lens falloff.
    color += (hash(gl_FragCoord.xy + fract(time) * 100.0) - 0.5) * 0.03;
    color *= 1.0 - 0.1 * dot(screen, screen);

    fragment = vec4(color, 1.0);
}`;

let globe: ShaderCanvas | null = null;
let surface: ShaderCanvas | null = null;
let isLoading = false;

/**
 * Starts loading Mars's map (about 0.6 MB) in the background. The close-up
 * can only play once it's in: see `isMarsCloseupReady`.
 */
export function loadMarsCloseup() {
    if (isLoading) {
        return;
    }

    isLoading = true;

    Promise.all(MAPS.map(loadImage))
        .then((images) => {
            const marsGlobe = createShaderCanvas(
                GLOBE_SHADER,
                ['colorMap'],
                images,
            );
            const { gl, uniform } = marsGlobe;
            gl.uniform3f(uniform('sun'), GLOBE_SUN.x, GLOBE_SUN.y, GLOBE_SUN.z);

            // From the screen (x right, y up, z toward us) to Mars's own frame:
            // undo the lean, then tip it so Gale Crater's latitude faces us.
            const leanCos = Math.cos(AXIAL_TILT);
            const leanSin = Math.sin(AXIAL_TILT);
            const tipCos = Math.cos(GALE.lat);
            const tipSin = Math.sin(GALE.lat);
            gl.uniformMatrix3fv(uniform('toMars'), true, [
                leanCos,
                -leanSin,
                0,
                leanSin * tipCos,
                leanCos * tipCos,
                tipSin,
                -leanSin * tipSin,
                -leanCos * tipSin,
                tipCos,
            ]);

            surface = createShaderCanvas(SURFACE_SHADER, [], []);
            globe = marsGlobe;
        })
        .catch((error: unknown) => {
            // No WebGL2 or the map didn't load: Mars just stays a dot.
            globe = null;
            console.warn('The Mars close-up is unavailable.', error);
        });
}

export function isMarsCloseupReady(): boolean {
    return globe !== null && surface !== null;
}

function ease(t: number): number {
    return smoothstep(0, 1, t);
}

/** How far into each part of the show `seconds` is. */
function phases(seconds: number) {
    const { grow, turn, dive, drive, rise } = MARS_SHOW;
    const diveAt = grow + turn;
    const driveAt = diveAt + dive;
    const riseAt = driveAt + drive;

    return {
        turn: ease((seconds - grow * 0.5) / (turn + grow * 0.5)),
        dive: ease((seconds - diveAt) / dive),
        drive: seconds - diveAt,
        rise: ease((seconds - riseAt) / rise),
    };
}

/**
 * How big Mars is during the show, from 0 (its usual dot) to 1 (fully zoomed
 * in), `seconds` after it starts. Null once it's over.
 */
export function marsShowProgress(seconds: number): number | null {
    const { grow, turn, dive, drive, rise, shrink } = MARS_SHOW;
    const shrinkAt = grow + turn + dive + drive + rise;

    if (seconds < 0 || seconds > shrinkAt + shrink) {
        return null;
    }

    if (seconds < grow) {
        return ease(seconds / grow);
    }

    if (seconds < shrinkAt) {
        return 1;
    }

    return ease(1 - (seconds - shrinkAt) / shrink);
}

function drawGlobe(
    context: CanvasRenderingContext2D,
    pixelScale: number,
    turnProgress: number,
    scale: number,
    alpha: number,
) {
    if (!globe || alpha <= 0) {
        return;
    }

    const center = MARS_CLOSEUP_SIZE / 2;
    const extent = MARS_CLOSEUP_RADIUS * GLOBE_EXTENT;
    const pixels = Math.ceil(extent * 2 * pixelScale);
    // Turn from the Tharsis side round to Gale Crater.
    const facing = GALE.lon - (1 - turnProgress) * 1.6;

    globe.gl.uniform1f(globe.uniform('extent'), GLOBE_EXTENT);
    globe.gl.uniform1f(globe.uniform('pixel'), (2 * GLOBE_EXTENT) / pixels);
    globe.gl.uniform1f(globe.uniform('turn'), facing);
    renderShaderCanvas(globe, pixels, pixels);

    context.globalAlpha = alpha;
    context.drawImage(
        globe.canvas,
        center - extent * scale,
        center - extent * scale,
        extent * 2 * scale,
        extent * 2 * scale,
    );
    context.globalAlpha = 1;
}

function drawSurface(
    context: CanvasRenderingContext2D,
    pixelScale: number,
    driveSeconds: number,
    alpha: number,
    scale: number,
) {
    if (!surface || alpha <= 0) {
        return;
    }

    const renderScale = Math.min(pixelScale, 1) * VIEW_PIXEL_SCALE;
    const width = Math.round(VIEW.width * renderScale);
    const height = Math.round(VIEW.height * renderScale);
    surface.gl.uniform1f(surface.uniform('extent'), 1);
    surface.gl.uniform2f(surface.uniform('resolution'), width, height);
    surface.gl.uniform1f(surface.uniform('time'), driveSeconds);
    renderShaderCanvas(surface, width, height);

    const center = MARS_CLOSEUP_SIZE / 2;
    const viewWidth = VIEW.width * scale;
    const viewHeight = VIEW.height * scale;
    const left = center - viewWidth / 2;
    const top = center - viewHeight / 2 - VIEW.raise;

    context.save();
    context.globalAlpha = alpha;
    context.shadowColor = 'rgba(255, 140, 80, 0.25)';
    context.shadowBlur = 40;
    context.beginPath();
    context.roundRect(left, top, viewWidth, viewHeight, VIEW.corner * scale);
    context.fillStyle = '#000000';
    context.fill();
    context.shadowColor = 'transparent';
    context.clip();
    context.drawImage(surface.canvas, left, top, viewWidth, viewHeight);

    // The camera's readout, like the rover's raw images.
    const sol = Math.floor((Date.now() - LANDED_AT) / SOL_MS);
    context.font = '600 10px "Instrument Sans", sans-serif';
    context.fillStyle = 'rgba(255, 244, 232, 0.8)';
    context.textAlign = 'left';
    context.fillText('CURIOSITY · MASTCAM', left + 16, top + 22);
    context.textAlign = 'right';
    context.fillText(
        `GALE CRATER · SOL ${sol.toLocaleString('en-US')}`,
        left + viewWidth - 16,
        top + 22,
    );
    context.restore();

    context.save();
    context.globalAlpha = alpha * 0.5;
    context.strokeStyle = 'rgba(255, 220, 190, 0.35)';
    context.lineWidth = 1;
    context.beginPath();
    context.roundRect(left, top, viewWidth, viewHeight, VIEW.corner * scale);
    context.stroke();
    context.restore();
}

/**
 * Draws the close-up centered in its canvas, `seconds` into the show.
 * `reveal` (0 to 1) fades in the backdrop as Mars grows. `pixelScale` is
 * how many device pixels the drawing gets per CSS pixel.
 */
export function drawMarsCloseup(
    context: CanvasRenderingContext2D,
    pixelScale: number,
    seconds: number,
    reveal: number,
) {
    if (!globe || !surface) {
        return;
    }

    const { turn, dive, drive, rise } = phases(seconds);
    const down = dive * (1 - rise);

    drawSpaceBackdrop(context, MARS_CLOSEUP_SIZE, smoothstep(0, 0.6, reveal));

    // Diving in, the globe rushes up and fades as the surface appears; pulling out, the reverse.
    drawGlobe(
        context,
        pixelScale,
        turn,
        1 + 7 * down ** 2,
        1 - smoothstep(0.3, 0.9, down),
    );
    drawSurface(
        context,
        pixelScale,
        Math.max(0, drive),
        smoothstep(0.35, 1, down),
        0.7 + 0.3 * down,
    );
}
