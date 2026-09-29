/**
 * An easter egg in the Sol system: hover over the Earth and it swoops up to
 * fill the view, turning under drifting clouds while the ISS, a 1980s Space
 * Shuttle, Hubble, a few satellites, a Starlink train, and Starman's Roadster
 * circle it (none of it to scale). Then it shrinks back into its orbit.
 *
 * The globe is a small WebGL shader over real maps of the Earth by day, by
 * night, and its clouds, lit like the three.js Earth example
 * (threejs.org/examples/webgpu_tsl_earth.html). The maps are by Solar System
 * Scope (solarsystemscope.com/textures), CC BY 4.0, resized for the web. The
 * Moon is lit the same way over NASA's Lunar Reconnaissance Orbiter color and
 * elevation maps (NASA's Scientific Visualization Studio, CGI Moon Kit), and
 * the hurricane is NASA's MODIS photo of Hurricane Isabel (Jeff Schmaltz,
 * MODIS Land Rapid Response Team, NASA GSFC). The home page's footer credits
 * them all.
 */

import {
    clamp,
    createShaderCanvas,
    drawSpaceBackdrop,
    loadImage,
    normalize,
    smoothstep,
} from '@/components/home/closeup-gl';
import type { ShaderCanvas } from '@/components/home/closeup-gl';

/** Size of the close-up's canvas, and the Earth's radius in it (CSS pixels). */
export const EARTH_CLOSEUP_SIZE = 780;
export const EARTH_CLOSEUP_RADIUS = 140;

/** How long the Earth takes to grow, how long it stays big, and how long it takes to shrink back (seconds). */
export const EARTH_SHOW = { grow: 1.6, hold: 11, shrink: 1.4 };

/**
 * The day and night maps, one with elevation, roughness, and clouds in its
 * red, green, and blue, and how cloudy a real hurricane is (NASA's photo of
 * Hurricane Isabel, turned into a cloud map).
 */
const MAPS = [
    '/images/earth/day.jpg',
    '/images/earth/night.jpg',
    '/images/earth/bump-roughness-clouds.jpg',
    '/images/hurricane/isabel.jpg',
];

/** The Moon's color map and its elevation map, for the shadows its craters and mountains cast. */
const MOON_MAPS = ['/images/moon/color.jpg', '/images/moon/elevation.jpg'];

/** The Moon's radius at full size (CSS pixels), and how much its relief is exaggerated so it shows at that size. */
const MOON_RADIUS = 28;
const MOON_RELIEF = 0.05;

/** Longitude facing us when the show starts, and how fast the Earth turns (degrees, degrees per second). */
const START_LONGITUDE = 25;
const TURN_SPEED = 6;

/** The clouds drift across the ground much faster than real ones do (degrees per second). */
const CLOUD_DRIFT = 4;

/** How far the north pole leans right, and how far it tips toward us (radians). */
const AXIAL_TILT = 0.41;
const VIEW_LATITUDE = 0.3;

/** Where the sunlight comes from: the left, a little above and in front (x right, y up, z toward us). */
const SUN = normalize(-0.88, 0.25, 0.42);

/** The globe's canvas reaches a little past the Earth, for the atmosphere (in Earth radii). */
const GLOBE_EXTENT = 1.1;

const FRAGMENT_SHADER = `#version 300 es
precision highp float;

in vec2 screen;
out vec4 color;

uniform sampler2D dayMap;
uniform sampler2D nightMap;
uniform sampler2D surfaceMap;
uniform sampler2D stormMap;
uniform float landTurn;
uniform float cloudTurn;
uniform vec3 sun;
uniform mat3 toEarth;
uniform float pixel;
uniform float time;

const float PI = 3.14159265;
const float ATMOSPHERE = 1.05;
const vec3 ATMOSPHERE_DAY = vec3(0.302, 0.698, 1.0);
const vec3 ATMOSPHERE_TWILIGHT = vec3(0.737, 0.286, 0.043);

/* A spot on the Earth from its latitude and longitude (radians), in the Earth's frame. */
vec3 spot(float lat, float lon) {
    return vec3(cos(lat) * sin(lon), sin(lat), cos(lat) * cos(lon));
}

float hash(float value) {
    return fract(sin(value) * 43758.5453);
}

/*
 * A massive hurricane in the Atlantic: a real satellite photo of one, turning
 * slowly counterclockwise as it heads west. Returns how cloudy it is here, and
 * how far inside its footprint this spot is (to clear other clouds away).
 */
vec2 hurricane(float lat, float lon) {
    const float LAT = 0.31;
    const float LON = -0.66;
    const float SIZE = 0.3;

    vec2 offset = vec2(mod(lon - LON + time * 0.012 + PI, 2.0 * PI) - PI, lat - LAT);
    offset.x *= cos(lat);
    offset /= SIZE;

    float turn = -time * 0.05;
    vec2 turned = vec2(
        cos(turn) * offset.x - sin(turn) * offset.y,
        sin(turn) * offset.x + cos(turn) * offset.y
    );
    float inside = step(length(offset), 1.0);
    float storm = texture(stormMap, clamp(vec2(0.5 + turned.x * 0.5, 0.5 - turned.y * 0.5), 0.0, 1.0)).r;

    return vec2(storm * inside, smoothstep(1.0, 0.55, length(offset)));
}

/* Lightning flickering inside a few storms; strongest at night. */
float lightning(vec3 earth) {
    const vec3 STORMS[5] = vec3[5](
        vec3(-0.03, 0.4, 1.0),
        vec3(0.2, 1.57, 2.0),
        vec3(0.02, 1.98, 3.0),
        vec3(-0.26, 0.55, 4.0),
        vec3(0.3, -0.74, 5.0)
    );
    float flash = 0.0;

    for (int index = 0; index < 5; index++) {
        vec3 storm = STORMS[index];
        float slot = floor(time * 7.0 + storm.z * 13.0);

        if (hash(slot + storm.z * 37.0) < 0.78) {
            continue;
        }

        vec3 center = spot(
            storm.x + (hash(slot * 1.3 + storm.z) - 0.5) * 0.08,
            storm.y + (hash(slot * 1.7 + storm.z) - 0.5) * 0.08
        );
        float angle = acos(clamp(dot(earth, center), -1.0, 1.0));
        float flicker = 0.55 + 0.45 * sin(time * 97.0 + storm.z);

        flash += flicker * (exp(-pow(angle / 0.02, 2.0)) + 0.35 * exp(-pow(angle / 0.06, 2.0)));
    }

    return flash;
}

/* The northern lights: shimmering curtains around the pole, green below and violet above. */
vec3 aurora(float lat, float lon, float height) {
    const float BAND = 1.16;
    float wave = sin(lon * 3.0 + time * 0.4) * 0.03 + sin(lon * 7.0 - time * 0.7) * 0.015;
    float along = lat - BAND - wave - height * 0.12;

    if (abs(along) > 0.12) {
        return vec3(0.0);
    }

    float curtain = exp(-pow(along / 0.045, 2.0));
    float rays = 0.75 + 0.25 * sin(lon * 38.0 + sin(lon * 9.0 + time * 1.3) * 3.0 + time * 1.5);
    float pulse = 0.75 + 0.25 * sin(time * 1.7 + lon * 4.0);
    vec3 glow = mix(vec3(0.15, 1.0, 0.45), vec3(0.6, 0.3, 1.0), clamp(along / 0.08 + 0.5, 0.0, 1.0));

    return glow * curtain * rays * pulse;
}

vec3 toLinear(vec3 value) {
    return pow(value, vec3(2.2));
}

vec3 toScreen(vec3 value) {
    return pow(clamp(value, 0.0, 1.0), vec3(1.0 / 2.2));
}

/* Samples a map turned by 'turn', without a seam where the longitude wraps. */
vec4 sampleMap(sampler2D map, vec2 place, float turn) {
    vec2 at = vec2(place.x + turn, place.y);
    vec2 across = vec2(fract(at.x + 0.5), at.y);
    vec2 stepX = dFdx(at);
    vec2 stepY = dFdy(at);
    vec2 wrappedX = dFdx(across);
    vec2 wrappedY = dFdy(across);

    if (abs(wrappedX.x) < abs(stepX.x)) {
        stepX = wrappedX;
    }

    if (abs(wrappedY.x) < abs(stepY.x)) {
        stepY = wrappedY;
    }

    return textureGrad(map, vec2(fract(at.x), at.y), stepX, stepY);
}

void main() {
    float distance = length(screen);
    vec4 globe = vec4(0.0);

    if (distance < 1.0 + pixel) {
        vec3 normal = distance < 1.0
            ? vec3(screen, sqrt(1.0 - distance * distance))
            : vec3(screen / distance, 0.0);
        vec3 earth = toEarth * normal;
        vec2 place = vec2(
            atan(earth.x, earth.z) / (2.0 * PI) + 0.5,
            0.5 - asin(clamp(earth.y, -1.0, 1.0)) / PI
        );

        vec3 day = toLinear(sampleMap(dayMap, place, landTurn).rgb);
        vec3 night = toLinear(sampleMap(nightMap, place, landTurn).rgb);
        vec3 surface = sampleMap(surfaceMap, place, landTurn).rgb;
        float weather = smoothstep(0.2, 1.0, sampleMap(surfaceMap, place, cloudTurn).b);
        float lat = (0.5 - place.y) * PI;
        float cloudLon = (fract(place.x + cloudTurn) - 0.5) * 2.0 * PI;
        float landLon = (fract(place.x + landTurn) - 0.5) * 2.0 * PI;
        vec2 storm = hurricane(lat, landLon);
        weather *= 1.0 - 0.8 * storm.y;
        float clouds = max(weather, storm.x);
        float whiteness = clamp(max(weather * 2.0, storm.x * 0.9), 0.0, 1.0);

        // Mountains catch the light.
        vec3 bumped = normalize(normal - 0.004 * vec3(dFdx(surface.r), dFdy(surface.r), 0.0) / pixel);

        float facing = dot(normal, sun);
        float lit = max(dot(bumped, sun), 0.0);
        vec3 albedo = mix(day, vec3(1.0), whiteness);

        // Oceans are glossy; land and clouds aren't.
        float roughness = max(surface.g, smoothstep(0.0, 0.35, clouds));
        vec3 halfway = normalize(sun + vec3(0.0, 0.0, 1.0));
        float shine = pow(max(dot(bumped, halfway), 0.0), mix(90.0, 6.0, roughness))
            * mix(0.7, 0.03, roughness) * (1.0 - clouds) * step(0.0, facing);

        vec3 daylight = albedo * lit * 1.15 + vec3(1.0, 0.96, 0.9) * shine;
        vec3 lights = night * (1.0 - clouds * 0.75) * 1.4;
        float dayStrength = smoothstep(-0.25, 0.5, facing);
        vec3 surfaceColor = mix(lights, daylight, dayStrength);

        float darkness = 1.0 - dayStrength * 0.8;
        surfaceColor += vec3(0.75, 0.85, 1.0) * lightning(spot(lat, landLon)) * (0.4 + clouds) * darkness * 1.6;
        surfaceColor += aurora(lat, landLon, 0.0) * darkness * 0.9;

        // Blue sky at the edge, going orange along the sunset line.
        float fresnel = 1.0 - normal.z;
        vec3 sky = mix(ATMOSPHERE_TWILIGHT, ATMOSPHERE_DAY, smoothstep(-0.25, 0.75, facing));
        float skyMix = clamp(smoothstep(-0.5, 1.0, facing) * fresnel * fresnel, 0.0, 1.0);
        surfaceColor = mix(surfaceColor, sky, skyMix);

        float coverage = clamp((1.0 - distance) / pixel + 0.5, 0.0, 1.0);
        globe = vec4(toScreen(surfaceColor), 1.0) * coverage;
    }

    // The thin shell of atmosphere glowing just past the edge.
    vec4 halo = vec4(0.0);

    if (distance > 1.0 - pixel && distance < ATMOSPHERE) {
        float depth = sqrt(max(0.0, 1.0 - pow(distance / ATMOSPHERE, 2.0)));
        vec3 shell = vec3(screen / ATMOSPHERE, -depth);
        float facing = dot(shell, sun);
        vec3 sky = mix(ATMOSPHERE_TWILIGHT, ATMOSPHERE_DAY, smoothstep(-0.25, 0.75, facing));
        float alpha = pow(clamp(1.0 - (1.0 - depth - 0.73) / 0.27, 0.0, 1.0), 3.0)
            * smoothstep(-0.5, 1.0, facing);

        halo = vec4(toScreen(sky), 1.0) * alpha;
    }

    // The aurora's curtains rise above the edge of the Earth.
    if (distance > 1.0 && distance < 1.1) {
        vec3 edge = toEarth * vec3(screen / distance, 0.0);
        float lat = asin(clamp(edge.y, -1.0, 1.0));
        float lon = atan(edge.x, edge.z) + landTurn * 2.0 * PI;
        float height = (distance - 1.0) / 0.1;
        vec3 curtains = aurora(lat, lon, height) * (1.0 - height) * (1.0 - smoothstep(-0.2, 0.6, dot(vec3(screen / distance, 0.0), sun)));

        halo += vec4(toScreen(curtains), 0.0) * (1.0 - globe.a);
        halo.a = max(halo.a, max(curtains.g, curtains.b) * 0.8);
    }

    color = globe + halo * (1.0 - globe.a);
}`;

const MOON_SHADER = `#version 300 es
precision highp float;

in vec2 screen;
out vec4 color;

uniform sampler2D colorMap;
uniform sampler2D elevationMap;
uniform vec3 sun;
uniform float pixel;
uniform float libration;
uniform float relief;

const float PI = 3.14159265;

void main() {
    float distance = length(screen);

    if (distance > 1.0 + pixel) {
        color = vec4(0.0);

        return;
    }

    vec3 normal = distance < 1.0
        ? vec3(screen, sqrt(1.0 - distance * distance))
        : vec3(screen / distance, 0.0);
    float lat = asin(clamp(normal.y, -1.0, 1.0));
    float facingLon = atan(normal.x, normal.z);
    vec2 place = vec2((facingLon + libration) / (2.0 * PI) + 0.5, 0.5 - lat / PI);

    // Tilt the surface by the slope of the ground, so craters and mountains cast shadows.
    vec2 spacing = 1.5 / vec2(textureSize(elevationMap, 0));
    float east = texture(elevationMap, place + vec2(spacing.x, 0.0)).r
        - texture(elevationMap, place - vec2(spacing.x, 0.0)).r;
    float north = texture(elevationMap, place - vec2(0.0, spacing.y)).r
        - texture(elevationMap, place + vec2(0.0, spacing.y)).r;
    vec3 eastward = vec3(cos(facingLon), 0.0, -sin(facingLon));
    vec3 northward = vec3(-sin(lat) * sin(facingLon), cos(lat), -sin(lat) * cos(facingLon));
    vec3 bumped = normalize(normal - relief * (
        east / (2.0 * spacing.x * 2.0 * PI * max(cos(lat), 0.05)) * eastward
        + north / (2.0 * spacing.y * PI) * northward
    ));

    // Moon dust scatters light back toward the Sun, so the Moon stays bright
    // right out to its edge instead of dimming like a matte ball.
    float toSun = dot(bumped, sun);
    float toUs = max(normal.z, 0.05);
    float lit = toSun > 0.0 ? 0.3 * toSun + 0.7 * toSun / (toSun + toUs) : 0.0;

    vec3 albedo = pow(texture(colorMap, place).rgb, vec3(2.2));
    vec3 earthshine = albedo * 0.012;
    vec3 surface = pow(clamp(albedo * lit * 1.9 + earthshine, 0.0, 1.0), vec3(1.0 / 2.2));
    float coverage = clamp((1.0 - distance) / pixel + 0.5, 0.0, 1.0);

    color = vec4(surface, 1.0) * coverage;
}`;

type Globe = {
    canvas: HTMLCanvasElement;
    gl: WebGL2RenderingContext;
    uniforms: Record<
        | 'landTurn'
        | 'cloudTurn'
        | 'sun'
        | 'toEarth'
        | 'pixel'
        | 'extent'
        | 'time',
        WebGLUniformLocation | null
    >;
};

type Orbiter = {
    name?: string;
    draw: (context: CanvasRenderingContext2D, size: number) => void;
    /** Orbit radius (in Earth radii), how open it looks, and how it's turned on screen. */
    radius: number;
    open: number;
    tilt: number;
    period: number;
    phase: number;
    size: number;
    /** Tumbles on its own instead of pointing where it's going (radians per second), or stays upright. */
    tumble?: number;
    isUpright?: boolean;
    showsOrbit?: boolean;
};

let globe: Globe | null = null;
let moon: ShaderCanvas | null = null;
/** How the Moon is being drawn this frame: `drawMoon` is called as an orbiter, so it reads these. */
let moonFrame = { pixelScale: 1, seconds: 0 };
let isLoading = false;

function createGlobe(images: HTMLImageElement[]): Globe {
    const { canvas, gl, uniform } = createShaderCanvas(
        FRAGMENT_SHADER,
        ['dayMap', 'nightMap', 'surfaceMap', 'stormMap'],
        images,
    );
    gl.uniform3f(uniform('sun'), SUN.x, SUN.y, SUN.z);

    // From the screen (x right, y up, z toward us) to the Earth's own frame
    // (x east, y north, z facing us at longitude 0): undo the lean, then the tip.
    const leanCos = Math.cos(AXIAL_TILT);
    const leanSin = Math.sin(AXIAL_TILT);
    const tipCos = Math.cos(VIEW_LATITUDE);
    const tipSin = Math.sin(VIEW_LATITUDE);
    gl.uniformMatrix3fv(uniform('toEarth'), true, [
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
    return {
        canvas,
        gl,
        uniforms: {
            landTurn: uniform('landTurn'),
            cloudTurn: uniform('cloudTurn'),
            sun: uniform('sun'),
            toEarth: uniform('toEarth'),
            pixel: uniform('pixel'),
            extent: uniform('extent'),
            time: uniform('time'),
        },
    };
}

/**
 * Starts loading the Earth's and Moon's maps (about 1.4 MB) in the background. The
 * close-up can only play once they're in: see `isEarthCloseupReady`.
 */
export function loadEarthCloseup() {
    if (isLoading) {
        return;
    }

    isLoading = true;

    Promise.all([...MAPS, ...MOON_MAPS].map(loadImage))
        .then((images) => {
            const moonSphere = createShaderCanvas(
                MOON_SHADER,
                ['colorMap', 'elevationMap'],
                images.slice(MAPS.length),
            );
            moonSphere.gl.uniform3f(
                moonSphere.uniform('sun'),
                SUN.x,
                SUN.y,
                SUN.z,
            );
            moonSphere.gl.uniform1f(moonSphere.uniform('relief'), MOON_RELIEF);
            moon = moonSphere;
            globe = createGlobe(images.slice(0, MAPS.length));
        })
        .catch((error: unknown) => {
            // No WebGL2 or the maps didn't load: the Earth just stays a dot.
            globe = null;
            console.warn('The Earth close-up is unavailable.', error);
        });
}

export function isEarthCloseupReady(): boolean {
    return globe !== null;
}

/** Renders the globe as it looks `seconds` into the show, `size` device pixels across. */
function renderGlobe(globe: Globe, size: number, seconds: number) {
    const { canvas, gl, uniforms } = globe;

    if (canvas.width !== size) {
        canvas.width = size;
        canvas.height = size;
        gl.viewport(0, 0, size, size);
    }

    const facing = START_LONGITUDE - TURN_SPEED * seconds;

    gl.uniform1f(uniforms.time, seconds);
    gl.uniform1f(uniforms.extent, GLOBE_EXTENT);
    gl.uniform1f(uniforms.pixel, (2 * GLOBE_EXTENT) / size);
    gl.uniform1f(uniforms.landTurn, facing / 360);
    gl.uniform1f(uniforms.cloudTurn, (facing - CLOUD_DRIFT * seconds) / 360);
    gl.clearColor(0, 0, 0, 0);
    gl.clear(gl.COLOR_BUFFER_BIT);
    gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
}

/**
 * How much sunlight reaches a point near the Earth (0 in its shadow, 1 in
 * full sun), with the point in Earth radii (x right, y up, z toward us).
 */
function sunlightAt(x: number, y: number, z: number): number {
    const along = x * SUN.x + y * SUN.y + z * SUN.z;

    if (along > 0) {
        return 1;
    }

    const across = Math.hypot(
        x - along * SUN.x,
        y - along * SUN.y,
        z - along * SUN.z,
    );

    return smoothstep(0.92, 1.08, across);
}

function drawSatellite(context: CanvasRenderingContext2D, size: number) {
    context.fillStyle = '#2C4F8F';
    context.fillRect(-2 * size, -11 * size, 4 * size, 8 * size);
    context.fillRect(-2 * size, 3 * size, 4 * size, 8 * size);
    context.fillStyle = '#8A8F99';
    context.fillRect(-0.4 * size, -3 * size, 0.8 * size, 6 * size);
    context.fillStyle = '#D9A441';
    context.fillRect(-2.2 * size, -2.2 * size, 4.4 * size, 4.4 * size);
    context.fillStyle = '#E8E8E8';
    context.beginPath();
    context.arc(2.8 * size, 0, 1.3 * size, 0, Math.PI * 2);
    context.fill();
}

function drawHubble(context: CanvasRenderingContext2D, size: number) {
    context.fillStyle = '#3B5FA8';
    context.fillRect(-1.2 * size, -10 * size, 2.4 * size, 7 * size);
    context.fillRect(-1.2 * size, 3 * size, 2.4 * size, 7 * size);
    context.fillStyle = '#9AA0A8';
    context.fillRect(-0.3 * size, -3 * size, 0.6 * size, 6 * size);
    context.fillStyle = '#D3D8DF';
    context.beginPath();
    context.roundRect(-7 * size, -2.3 * size, 14 * size, 4.6 * size, size);
    context.fill();
    context.fillStyle = '#C89B3C';
    context.fillRect(-4 * size, -2.3 * size, 1.4 * size, 4.6 * size);
    context.fillStyle = '#3A3F48';
    context.fillRect(6 * size, -2 * size, 1.2 * size, 4 * size);
}

/** The ISS: its long truss runs across its path, with four pairs of golden solar wings. */
function drawIss(context: CanvasRenderingContext2D, size: number) {
    context.fillStyle = '#9AA0A8';
    context.fillRect(-0.8 * size, -22 * size, 1.6 * size, 44 * size);

    [-20, -15.5, 15.5, 20].forEach((y, index) => {
        for (const side of [-1, 1]) {
            context.fillStyle = index % 2 === 0 ? '#B38A36' : '#94702A';
            context.fillRect(
                side > 0 ? 1.2 * size : -11.2 * size,
                (y - 1.6) * size,
                10 * size,
                3.2 * size,
            );
        }
    });

    context.fillStyle = '#E9ECEF';

    for (const y of [-8, 8]) {
        context.fillRect(-5 * size, (y - 0.8) * size, 10 * size, 1.6 * size);
    }

    context.fillStyle = '#DADDE2';
    context.beginPath();
    context.roundRect(
        -12 * size,
        -1.8 * size,
        24 * size,
        3.6 * size,
        1.5 * size,
    );
    context.fill();
    context.fillStyle = '#C6CBD1';
    context.fillRect(-2 * size, -4 * size, 4 * size, 8 * size);
    context.fillStyle = '#5B616A';
    context.fillRect(11.5 * size, -1 * size, 1.4 * size, 2 * size);
}

/** A 1980s Space Shuttle orbiter from above: white delta wings with black leading edges, payload bay, and tail. */
function drawShuttle(context: CanvasRenderingContext2D, size: number) {
    const wing = (side: number) => {
        context.beginPath();
        context.moveTo(7 * size, 2 * side * size);
        context.lineTo(-2 * size, 4.6 * side * size);
        context.lineTo(-11.5 * size, 9.8 * side * size);
        context.lineTo(-13 * size, 9.8 * side * size);
        context.lineTo(-13 * size, 2 * side * size);
        context.closePath();
    };

    for (const side of [-1, 1]) {
        wing(side);
        context.fillStyle = '#F2F2EE';
        context.fill();

        context.strokeStyle = '#1B1B1B';
        context.lineWidth = 0.9 * size;
        context.beginPath();
        context.moveTo(7 * size, 2 * side * size);
        context.lineTo(-2 * size, 4.6 * side * size);
        context.lineTo(-11.5 * size, 9.8 * side * size);
        context.stroke();

        context.strokeStyle = '#B9BCBF';
        context.lineWidth = 0.5 * size;
        context.beginPath();
        context.moveTo(-12.2 * size, 3 * side * size);
        context.lineTo(-12.2 * size, 9.4 * side * size);
        context.stroke();

        // The OMS pods by the tail.
        context.fillStyle = '#E6E6E2';
        context.beginPath();
        context.ellipse(
            -12 * size,
            2.3 * side * size,
            3 * size,
            1.2 * size,
            0,
            0,
            Math.PI * 2,
        );
        context.fill();
    }

    context.fillStyle = '#F5F5F2';
    context.beginPath();
    context.moveTo(15 * size, 0);
    context.quadraticCurveTo(14 * size, 2.1 * size, 10 * size, 2.1 * size);
    context.lineTo(-15 * size, 2.1 * size);
    context.lineTo(-15 * size, -2.1 * size);
    context.lineTo(10 * size, -2.1 * size);
    context.quadraticCurveTo(14 * size, -2.1 * size, 15 * size, 0);
    context.fill();

    context.fillStyle = '#2A2A2A';
    context.beginPath();
    context.ellipse(14.6 * size, 0, 0.8 * size, 0.9 * size, 0, 0, Math.PI * 2);
    context.fill();
    context.fillRect(9.5 * size, -1.3 * size, 1.4 * size, 2.6 * size);

    // Payload bay doors.
    context.strokeStyle = '#C9CCCF';
    context.lineWidth = 0.4 * size;
    context.strokeRect(-9 * size, -1.9 * size, 16 * size, 3.8 * size);
    context.beginPath();
    context.moveTo(-9 * size, 0);
    context.lineTo(7 * size, 0);
    context.stroke();

    context.fillStyle = '#D6D6D2';
    context.fillRect(-15 * size, -0.4 * size, 7 * size, 0.8 * size);
    context.fillStyle = '#1B1B1B';
    context.fillRect(-9 * size, -0.4 * size, 1 * size, 0.8 * size);

    context.fillStyle = '#4A4D52';

    for (const y of [-1.2, 0, 1.2]) {
        context.beginPath();
        context.arc(-15.6 * size, y * size, 0.8 * size, 0, Math.PI * 2);
        context.fill();
    }

    // A tiny flag on the left wing.
    context.fillStyle = '#B22234';
    context.fillRect(-7 * size, -6.2 * size, 2.4 * size, 1.4 * size);
    context.fillStyle = '#3C3B6E';
    context.fillRect(-5.6 * size, -6.2 * size, 1 * size, 0.7 * size);
}

/** Starman's cherry red Roadster, top down, with Starman at the wheel. */
function drawRoadster(context: CanvasRenderingContext2D, size: number) {
    context.fillStyle = '#111111';

    for (const [x, y] of [
        [-7, -5.2],
        [-7, 5.2],
        [6.5, -5.2],
        [6.5, 5.2],
    ]) {
        context.fillRect(
            (x - 2) * size,
            (y - 0.7) * size,
            4 * size,
            1.4 * size,
        );
    }

    const paint = context.createLinearGradient(0, -5 * size, 0, 5 * size);
    paint.addColorStop(0, '#E8354B');
    paint.addColorStop(0.5, '#B80F26');
    paint.addColorStop(1, '#7E0A1A');
    context.fillStyle = paint;
    context.beginPath();
    context.roundRect(-11 * size, -5 * size, 22 * size, 10 * size, 4 * size);
    context.fill();

    context.fillStyle = '#1A1D22';
    context.beginPath();
    context.moveTo(4.5 * size, -4 * size);
    context.lineTo(2.5 * size, -4 * size);
    context.lineTo(2.5 * size, 4 * size);
    context.lineTo(4.5 * size, 4 * size);
    context.quadraticCurveTo(5.5 * size, 0, 4.5 * size, -4 * size);
    context.fill();
    context.fillStyle = '#141414';
    context.beginPath();
    context.roundRect(
        -4.5 * size,
        -3.8 * size,
        7 * size,
        7.6 * size,
        1.5 * size,
    );
    context.fill();

    context.fillStyle = '#FFF4D6';
    context.fillRect(10 * size, -3.8 * size, 0.8 * size, 1.6 * size);
    context.fillRect(10 * size, 2.2 * size, 0.8 * size, 1.6 * size);

    // Starman, one arm resting on the door.
    context.fillStyle = '#F2F2F2';
    context.fillRect(-0.5 * size, -4.6 * size, 3.2 * size, 1 * size);
    context.beginPath();
    context.arc(-1 * size, -1.8 * size, 2 * size, 0, Math.PI * 2);
    context.fill();
    context.fillStyle = '#2A2D33';
    context.beginPath();
    context.arc(-0.2 * size, -1.8 * size, 1 * size, -1.2, 1.2);
    context.fill();
}

/**
 * The Moon, rendered from real maps and lit from the Sun's side, with an
 * astronaut and an American flag standing on its sunlit edge.
 */
function drawMoon(context: CanvasRenderingContext2D, size: number) {
    const radius = MOON_RADIUS * size;

    if (moon) {
        const { canvas, gl, uniform } = moon;
        const pixels = Math.ceil(radius * 2 * moonFrame.pixelScale) + 2;

        if (canvas.width !== pixels) {
            canvas.width = pixels;
            canvas.height = pixels;
            gl.viewport(0, 0, pixels, pixels);
        }

        const extent = pixels / (radius * 2 * moonFrame.pixelScale);
        gl.uniform1f(uniform('extent'), extent);
        gl.uniform1f(uniform('pixel'), (2 * extent) / pixels);
        // It rocks a little from side to side as it goes round (libration).
        gl.uniform1f(
            uniform('libration'),
            Math.sin(moonFrame.seconds * 0.3) * 0.12,
        );
        gl.clearColor(0, 0, 0, 0);
        gl.clear(gl.COLOR_BUFFER_BIT);
        gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
        context.drawImage(
            canvas,
            -radius * extent,
            -radius * extent,
            radius * extent * 2,
            radius * extent * 2,
        );
    }

    // Standing on top, a little toward the Sun, with "up" pointing away from the Moon.
    context.save();
    context.rotate(-0.45);
    context.translate(0, -radius + 0.4 * size);

    // The flag, on a pole with a rod across the top to hold it out.
    context.fillStyle = '#D9D9D9';
    context.fillRect(3.2 * size, -12 * size, 0.6 * size, 12 * size);
    context.fillStyle = '#FFFFFF';
    context.fillRect(3.8 * size, -12 * size, 7 * size, 4.6 * size);
    context.fillStyle = '#B22234';

    for (let stripe = 0; stripe < 4; stripe++) {
        context.fillRect(
            3.8 * size,
            (-12 + stripe * 1.3) * size,
            7 * size,
            0.65 * size,
        );
    }

    context.fillStyle = '#3C3B6E';
    context.fillRect(3.8 * size, -12 * size, 3 * size, 2.5 * size);

    // The astronaut, waving.
    context.fillStyle = '#D8D8D4';
    context.fillRect(-3.4 * size, -7.4 * size, 1.4 * size, 4 * size);
    context.fillStyle = '#F4F4F2';
    context.fillRect(-2.6 * size, -3.4 * size, 1.3 * size, 3.4 * size);
    context.fillRect(-0.9 * size, -3.4 * size, 1.3 * size, 3.4 * size);
    context.beginPath();
    context.roundRect(-2.8 * size, -7.8 * size, 3.4 * size, 4.8 * size, size);
    context.fill();
    context.save();
    context.translate(0.3 * size, -7 * size);
    context.rotate(-0.9);
    context.fillRect(0, -0.5 * size, 3.2 * size, 1 * size);
    context.restore();
    context.beginPath();
    context.arc(-1.1 * size, -9.4 * size, 1.9 * size, 0, Math.PI * 2);
    context.fill();
    context.fillStyle = '#C99A2E';
    context.beginPath();
    context.ellipse(
        -0.5 * size,
        -9.4 * size,
        1.1 * size,
        1.2 * size,
        0,
        0,
        Math.PI * 2,
    );
    context.fill();
    context.restore();
}

function drawStarlinkSatellite(
    context: CanvasRenderingContext2D,
    size: number,
) {
    context.fillStyle = '#FFFFFF';
    context.beginPath();
    context.arc(0, 0, 1.1 * size, 0, Math.PI * 2);
    context.fill();
}

const ORBITERS: Orbiter[] = [
    {
        name: 'ISS',
        draw: drawIss,
        radius: 1.24,
        open: 0.36,
        tilt: -0.22,
        period: 9,
        phase: 1.9,
        size: 0.8,
        showsOrbit: true,
    },
    {
        name: 'Space Shuttle',
        draw: drawShuttle,
        radius: 1.38,
        open: 0.28,
        tilt: 0.18,
        period: 11,
        phase: 0.7,
        size: 0.95,
        showsOrbit: true,
    },
    {
        name: 'Hubble',
        draw: drawHubble,
        radius: 1.52,
        open: 0.5,
        tilt: 0.55,
        period: 13,
        phase: 4,
        size: 0.85,
    },
    {
        draw: drawSatellite,
        radius: 1.64,
        open: 0.6,
        tilt: -0.7,
        period: 16,
        phase: 2.6,
        size: 0.75,
    },
    {
        draw: drawSatellite,
        radius: 1.8,
        open: 0.18,
        tilt: 0.04,
        period: 19,
        phase: 5.1,
        size: 0.7,
    },
    {
        draw: drawSatellite,
        radius: 1.46,
        open: 0.85,
        tilt: 1.25,
        period: 14,
        phase: 0.2,
        size: 0.7,
    },
    {
        name: 'Moon',
        draw: drawMoon,
        radius: 2.3,
        open: 0.4,
        tilt: 0.3,
        period: 36,
        phase: 0.9,
        size: 1,
        isUpright: true,
    },
    {
        name: 'Starman',
        draw: drawRoadster,
        radius: 1.95,
        open: 0.34,
        tilt: -0.12,
        period: 24,
        phase: 1.1,
        size: 0.95,
        tumble: 0.7,
        showsOrbit: true,
    },
    ...Array.from({ length: 14 }, (_, index) => ({
        name: index === 0 ? 'Starlink' : undefined,
        draw: drawStarlinkSatellite,
        radius: 1.13,
        open: 0.46,
        tilt: 0.38,
        period: 8,
        phase: 3.4 - index * 0.07,
        size: 1,
    })),
];

function orbitPoint(orbiter: Orbiter, angle: number) {
    const radius = orbiter.radius * EARTH_CLOSEUP_RADIUS;
    const flatX = Math.cos(angle) * radius;
    const flatY = Math.sin(angle) * radius * orbiter.open;
    const cos = Math.cos(orbiter.tilt);
    const sin = Math.sin(orbiter.tilt);

    return { x: flatX * cos - flatY * sin, y: flatX * sin + flatY * cos };
}

/**
 * Draws the close-up centered in its canvas, `seconds` into the show.
 * `reveal` (0 to 1) fades in the dark backdrop and everything in orbit as the
 * Earth grows. `pixelScale` is how many device pixels the globe gets per CSS
 * pixel.
 */
export function drawEarthCloseup(
    context: CanvasRenderingContext2D,
    pixelScale: number,
    seconds: number,
    reveal: number,
) {
    if (!globe) {
        return;
    }

    moonFrame = { pixelScale, seconds };

    const center = EARTH_CLOSEUP_SIZE / 2;
    const radius = EARTH_CLOSEUP_RADIUS;
    const orbitAlpha = smoothstep(0.55, 1, reveal);

    drawSpaceBackdrop(context, EARTH_CLOSEUP_SIZE, smoothstep(0, 0.6, reveal));

    const placed = ORBITERS.map((orbiter) => {
        const angle = orbiter.phase + (seconds / orbiter.period) * Math.PI * 2;
        const point = orbitPoint(orbiter, angle);
        const ahead = orbitPoint(orbiter, angle + 0.01);
        const depth = Math.sin(angle);
        const nearness =
            depth * orbiter.radius * Math.sqrt(1 - orbiter.open ** 2);

        return {
            orbiter,
            x: center + point.x,
            y: center + point.y,
            depth,
            sunlight: sunlightAt(point.x / radius, -point.y / radius, nearness),
            heading: orbiter.isUpright
                ? 0
                : orbiter.tumble
                  ? seconds * orbiter.tumble
                  : Math.atan2(ahead.y - point.y, ahead.x - point.x),
        };
    });

    const drawOrbitLines = (half: 'back' | 'front') => {
        const [from, to] =
            half === 'back' ? [Math.PI, Math.PI * 2] : [0, Math.PI];

        context.globalAlpha = 0.14 * orbitAlpha;
        context.strokeStyle = '#BFD8FF';
        context.lineWidth = 0.7;

        for (const orbiter of ORBITERS.filter(
            (candidate) => candidate.showsOrbit,
        )) {
            context.beginPath();
            context.ellipse(
                center,
                center,
                orbiter.radius * radius,
                orbiter.radius * radius * orbiter.open,
                orbiter.tilt,
                from,
                to,
            );
            context.stroke();
        }
    };

    const drawOrbiters = (inFront: boolean) => {
        for (const { orbiter, x, y, depth, sunlight, heading } of placed) {
            if (depth > 0 !== inFront) {
                continue;
            }

            // Passing through the Earth's shadow, it goes dark.
            context.save();
            context.globalAlpha = orbitAlpha * (0.12 + 0.88 * sunlight);
            context.translate(x, y);
            context.rotate(heading);
            orbiter.draw(context, orbiter.size * (1 + 0.12 * depth));
            context.restore();

            const isHidden =
                !inFront && Math.hypot(x - center, y - center) < radius * 1.05;

            if (orbiter.name && !isHidden) {
                context.globalAlpha = 0.75 * orbitAlpha;
                context.font = '500 10px "Instrument Sans", sans-serif';
                context.textAlign = 'left';
                context.fillStyle = '#DCEBFF';
                context.fillText(orbiter.name, x + 12, y - 10);
            }
        }
    };

    drawOrbitLines('back');
    drawOrbiters(false);

    context.globalAlpha = 1;
    const extent = radius * GLOBE_EXTENT;
    renderGlobe(globe, Math.ceil(extent * 2 * pixelScale), seconds);
    context.drawImage(
        globe.canvas,
        center - extent,
        center - extent,
        extent * 2,
        extent * 2,
    );

    // A soft glow of the atmosphere around the edge, beyond the globe's own.
    const halo = context.createRadialGradient(
        center,
        center,
        radius,
        center,
        center,
        radius * 1.25,
    );
    halo.addColorStop(0, 'rgba(90, 170, 255, 0.22)');
    halo.addColorStop(0.35, 'rgba(70, 140, 255, 0.07)');
    halo.addColorStop(1, 'rgba(60, 120, 255, 0)');
    context.save();
    context.globalCompositeOperation = 'lighter';
    context.fillStyle = halo;
    context.beginPath();
    context.arc(center, center, radius * 1.25, 0, Math.PI * 2);
    context.arc(center, center, radius, 0, Math.PI * 2, true);
    context.fill();
    context.restore();

    drawOrbitLines('front');
    drawOrbiters(true);
    context.globalAlpha = 1;
}

/** How big the Earth is during the show, from 0 (its usual dot) to 1 (fully zoomed in), `seconds` after it starts. Null once it's over. */
export function earthShowProgress(seconds: number): number | null {
    const { grow, hold, shrink } = EARTH_SHOW;
    const ease = (t: number) => t * t * (3 - 2 * t);

    if (seconds < 0 || seconds > grow + hold + shrink) {
        return null;
    }

    if (seconds < grow) {
        return ease(seconds / grow);
    }

    if (seconds < grow + hold) {
        return 1;
    }

    return ease(1 - (seconds - grow - hold) / shrink);
}
