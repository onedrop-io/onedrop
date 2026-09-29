import { useEffect, useRef } from 'react';
import { universe } from '@/components/home/particle-universe';

/**
 * Pixel size the shader renders at; CSS scales it to fit (the disk glows, so
 * the upscale is invisible and keeps the ray march cheap).
 */
const RENDER_SIZE = 880;

/** Camera elevation above the disk at rest, and how far the mouse can orbit it (radians). */
const BASE_PITCH = 0.1;
const MAX_PITCH_SWING = 0.14;
const MAX_YAW = 0.35;

/** How far the view drifts on its own: yaw, pitch and roll swing (radians). */
const DRIFT_YAW = 0.3;
const DRIFT_PITCH = 0.09;
const DRIFT_ROLL = 0.12;

/** Radius of the black hole's shadow, in CSS pixels, when the canvas is at scale 1. */
const SHADOW_RADIUS_PX = 140;

const VERTEX_SHADER = `#version 300 es
void main() {
    vec2 corner = vec2(float((gl_VertexID << 1) & 2), float(gl_VertexID & 2));
    gl_Position = vec4(corner * 2.0 - 1.0, 0.0, 1.0);
}`;

/**
 * Ray marches light through a Schwarzschild black hole's curved spacetime:
 * rays bend toward the hole (a = rs / r²), crossings of the disk plane are
 * shaded with blackbody color, Keplerian turbulence and Doppler beaming, and
 * escaped rays sample a lensed star field and nebula.
 *
 * Based on https://threejsroadmap.com/blog/raytracing-a-black-hole-with-webgpu
 */
const FRAGMENT_SHADER = `#version 300 es
precision highp float;

uniform vec2 uResolution;
uniform float uTime;
uniform float uRoll;
uniform float uGrowth;
// Camera orbit: x = yaw around the hole, y = elevation above the disk (radians).
uniform vec2 uView;

out vec4 outColor;

const float RS = 2.0;
const float INNER_RADIUS = 3.2;

float hash21(vec2 p) {
    return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453);
}

vec2 hash22(vec2 p) {
    return vec2(hash21(p), fract(sin(dot(p, vec2(269.5, 183.3))) * 43758.5453));
}

float hash31(vec3 p) {
    return fract(sin(dot(p, vec3(127.1, 311.7, 74.7))) * 43758.5453);
}

float noise3(vec3 p) {
    vec3 i = floor(p);
    vec3 f = fract(p);
    vec3 u = f * f * (3.0 - 2.0 * f);

    return mix(
        mix(
            mix(hash31(i), hash31(i + vec3(1, 0, 0)), u.x),
            mix(hash31(i + vec3(0, 1, 0)), hash31(i + vec3(1, 1, 0)), u.x),
            u.y
        ),
        mix(
            mix(hash31(i + vec3(0, 0, 1)), hash31(i + vec3(1, 0, 1)), u.x),
            mix(hash31(i + vec3(0, 1, 1)), hash31(i + vec3(1, 1, 1)), u.x),
            u.y
        ),
        u.z
    );
}

float fbm(vec3 p) {
    float value = 0.0;
    float amplitude = 0.5;

    for (int i = 0; i < 4; i++) {
        value += noise3(p) * amplitude;
        p *= 2.0;
        amplitude *= 0.5;
    }

    return value;
}

// Mitchell Charity blackbody colors (CIE 1931 2-deg, sRGB), 1000K to 10000K.
vec3 blackbody(float kelvin) {
    vec3 table[10] = vec3[10](
        vec3(1.0, 0.0337, 0.0),
        vec3(1.0, 0.2647, 0.0033),
        vec3(1.0, 0.487, 0.1411),
        vec3(1.0, 0.6636, 0.3583),
        vec3(1.0, 0.7992, 0.6045),
        vec3(1.0, 0.9019, 0.8473),
        vec3(0.9337, 0.915, 1.0),
        vec3(0.7874, 0.8187, 1.0),
        vec3(0.6991, 0.7556, 1.0),
        vec3(0.6268, 0.7039, 1.0)
    );
    float x = clamp((kelvin - 1000.0) / 1000.0, 0.0, 8.999);
    int index = int(floor(x));

    return mix(table[index], table[index + 1], fract(x));
}

vec4 accretionDisk(vec3 hit, vec3 rayDir, float outerRadius) {
    float radius = length(hit.xz);
    float normalized = clamp((radius - INNER_RADIUS) / (outerRadius - INNER_RADIUS), 0.0, 1.0);
    float edge = smoothstep(0.0, 0.08, normalized) * smoothstep(1.0, 0.55, normalized);
    float angle = atan(hit.z, hit.x);

    // Keplerian rotation, with a cyclic crossfade so the arcs never wind up too tight.
    float cycle = 8.0;
    float cycleTime = mod(uTime, cycle);
    float orbit = 6.0 / pow(radius, 1.5);
    float phaseA = angle + cycleTime * orbit;
    float phaseB = angle + (cycleTime + cycle) * orbit;
    float stretch = 0.35;
    float turbulenceA = fbm(vec3(radius * 1.6, cos(phaseA) / stretch, sin(phaseA) / stretch));
    float turbulenceB = fbm(vec3(radius * 1.6, cos(phaseB) / stretch, sin(phaseB) / stretch));
    float turbulence = mix(turbulenceB, turbulenceA, cycleTime / cycle);
    float rings = pow(clamp(turbulence * 1.35, 0.0, 1.0), 2.2);

    // Thin-disk temperature profile: hottest at the inner edge.
    vec3 color = blackbody(7000.0 * pow(INNER_RADIUS / radius, 0.85));

    // Doppler beaming: material moving toward the camera is brighter (D³).
    vec3 velocity = vec3(-sin(angle), 0.0, cos(angle));
    float beta = 0.45 / sqrt(radius / INNER_RADIUS);
    float doppler = 1.0 / (1.0 + beta * dot(velocity, rayDir));
    float beaming = clamp(pow(doppler, 3.0), 0.15, 6.0);

    float intensity = (0.35 + rings * 1.8) * edge * beaming * (1.2 + 0.8 * (1.0 - normalized));
    float opacity = clamp((0.25 + rings) * edge, 0.0, 1.0);

    return vec4(color * intensity, opacity);
}

vec3 background(vec3 rayDir) {
    float theta = atan(rayDir.z, rayDir.x);
    float phi = asin(clamp(rayDir.y, -1.0, 1.0));
    vec2 scaled = vec2(theta, phi) * 40.0;
    vec2 cell = floor(scaled);
    vec2 inCell = fract(scaled);
    float hasStar = step(0.82, hash21(cell));
    float distanceToStar = length(inCell - (hash22(cell + 42.0) * 0.8 + 0.1));
    float size = hash21(cell + 100.0) * 0.05 + 0.02;
    float star = (smoothstep(size, 0.0, distanceToStar) + smoothstep(size * 3.0, 0.0, distanceToStar) * 0.3) * hasStar;
    vec3 starColor = mix(vec3(1.0, 0.78, 0.6), vec3(1.0, 0.95, 0.88), hash21(cell + 200.0));

    float cloud = clamp(fbm(rayDir * 3.0) * 2.0 - 1.0 + 0.15, 0.0, 1.0);
    float wisps = clamp(fbm(rayDir * 7.0 + 11.0) * 2.0 - 1.0 + 0.05, 0.0, 1.0);
    vec3 nebula = vec3(0.55, 0.09, 0.03) * cloud * 0.5 + vec3(0.9, 0.35, 0.1) * wisps * 0.18;

    return starColor * star * 0.9 + nebula;
}

void main() {
    vec2 uv = (gl_FragCoord.xy / uResolution) * 2.0 - 1.0;
    uv = mat2(cos(uRoll), -sin(uRoll), sin(uRoll), cos(uRoll)) * uv;

    vec3 cameraPosition = 26.0 * vec3(
        sin(uView.x) * cos(uView.y),
        sin(uView.y),
        cos(uView.x) * cos(uView.y)
    );
    vec3 forward = normalize(-cameraPosition);
    vec3 right = normalize(cross(forward, vec3(0.0, 1.0, 0.0)));
    vec3 up = cross(right, forward);
    vec3 rayDir = normalize(forward + right * uv.x + up * uv.y);

    vec3 position = cameraPosition;
    vec3 previous = position;
    vec3 color = vec3(0.0);
    float alpha = 0.0;
    bool captured = false;
    float outerRadius = mix(7.0, 15.0, uGrowth);

    for (int i = 0; i < 220; i++) {
        float radius = length(position);

        if (radius < RS * 1.01) {
            captured = true;
            break;
        }

        if (radius > 60.0) {
            break;
        }

        float stepSize = clamp((radius - RS) * 0.12, 0.03, 1.2);
        vec3 toCenter = -position / radius;
        rayDir = normalize(rayDir + toCenter * (RS / (radius * radius)) * stepSize * 1.5);
        previous = position;
        position += rayDir * stepSize;

        if (previous.y * position.y < 0.0) {
            vec3 hit = mix(previous, position, previous.y / (previous.y - position.y));
            float hitRadius = length(hit.xz);

            if (hitRadius > INNER_RADIUS && hitRadius < outerRadius) {
                vec4 disk = accretionDisk(hit, rayDir, outerRadius);
                float remaining = 1.0 - alpha;
                color += disk.rgb * disk.a * remaining;
                alpha += disk.a * remaining;

                if (alpha > 0.99) {
                    break;
                }
            }
        }
    }

    if (captured) {
        alpha = 1.0;
    } else {
        float fade = 1.0 - smoothstep(0.3, 0.95, length(uv));
        vec3 sky = background(rayDir) * fade;
        float remaining = 1.0 - alpha;
        color += sky * remaining;
        alpha += clamp(max(sky.r, max(sky.g, sky.b)), 0.0, 1.0) * remaining;
    }

    color = 1.0 - exp(-color * 1.4 * mix(0.6, 1.3, uGrowth));
    outColor = vec4(color, alpha) * smoothstep(0.0, 0.12, uGrowth);
}`;

/**
 * Browsers without a GPU (and headless test browsers) run WebGL on the CPU,
 * where this shader would stall the page.
 */
function usesSoftwareRenderer(gl: WebGL2RenderingContext): boolean {
    const info = gl.getExtension('WEBGL_debug_renderer_info');
    const renderer = String(
        gl.getParameter(info ? info.UNMASKED_RENDERER_WEBGL : gl.RENDERER),
    );

    return /swiftshader|llvmpipe|software|basic render/i.test(renderer);
}

function compile(
    gl: WebGL2RenderingContext,
    type: number,
    source: string,
): WebGLShader | null {
    const shader = gl.createShader(type);

    if (!shader) {
        return null;
    }

    gl.shaderSource(shader, source);
    gl.compileShader(shader);

    if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
        console.warn(gl.getShaderInfoLog(shader));
        gl.deleteShader(shader);

        return null;
    }

    return shader;
}

/**
 * A ray-traced black hole that grows as the particle universe feeds it. Sits
 * behind the hero content, centered on where the first drop landed. Falls back
 * to the 2D canvas black hole when WebGL2 isn't available. Moving the mouse
 * gently orbits the camera around it.
 */
export function BlackHole() {
    const canvasRef = useRef<HTMLCanvasElement>(null);

    useEffect(() => {
        const canvas = canvasRef.current;

        if (
            !canvas ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ) {
            return;
        }

        const gl = canvas.getContext('webgl2', {
            alpha: true,
            premultipliedAlpha: true,
            antialias: false,
        });

        if (!gl || usesSoftwareRenderer(gl)) {
            return;
        }

        const vertexShader = compile(gl, gl.VERTEX_SHADER, VERTEX_SHADER);
        const fragmentShader = compile(gl, gl.FRAGMENT_SHADER, FRAGMENT_SHADER);
        const program = gl.createProgram();

        if (!vertexShader || !fragmentShader || !program) {
            return;
        }

        gl.attachShader(program, vertexShader);
        gl.attachShader(program, fragmentShader);
        gl.linkProgram(program);

        if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
            console.warn(gl.getProgramInfoLog(program));

            return;
        }

        const resolution = gl.getUniformLocation(program, 'uResolution');
        const time = gl.getUniformLocation(program, 'uTime');
        const growth = gl.getUniformLocation(program, 'uGrowth');
        const view = gl.getUniformLocation(program, 'uView');
        const roll = gl.getUniformLocation(program, 'uRoll');
        const startedAt = performance.now();
        let isVisible = true;
        let frame = 0;
        let lastTickAt = performance.now();
        const camera = {
            yaw: 0,
            pitch: BASE_PITCH,
            targetYaw: 0,
            targetPitch: BASE_PITCH,
        };

        const onPointerMove = (event: PointerEvent) => {
            if (event.pointerType !== 'mouse') {
                return;
            }

            const x = (event.clientX / window.innerWidth) * 2 - 1;
            const y = (event.clientY / window.innerHeight) * 2 - 1;
            camera.targetYaw = x * MAX_YAW;
            camera.targetPitch = BASE_PITCH - y * MAX_PITCH_SWING;
        };

        window.addEventListener('pointermove', onPointerMove);
        let renderSize = RENDER_SIZE;
        let lastFrameAt = 0;
        let slowFrames = 0;
        let drawnFrames = 0;

        const setRenderSize = (size: number) => {
            renderSize = size;
            canvas.width = size;
            canvas.height = size;
            gl.viewport(0, 0, size, size);
            gl.uniform2f(resolution, size, size);
        };

        gl.useProgram(program);
        setRenderSize(RENDER_SIZE);
        universe.rendersHoleInWebgl = true;

        /**
         * Drop to half resolution, then to the 2D fallback, if the GPU can't
         * keep up (roughly under 30fps).
         */
        const keepsUp = (now: number): boolean => {
            const frameTime = now - lastFrameAt;
            lastFrameAt = now;

            if (drawnFrames++ < 5 || frameTime > 1000) {
                return true;
            }

            slowFrames =
                frameTime > 34 ? slowFrames + 1 : Math.max(0, slowFrames - 1);

            if (slowFrames < 20) {
                return true;
            }

            slowFrames = 0;

            if (renderSize > RENDER_SIZE / 2) {
                setRenderSize(RENDER_SIZE / 2);

                return true;
            }

            return false;
        };

        const visibilityObserver = new IntersectionObserver(([entry]) => {
            isVisible = entry.isIntersecting;
        });
        visibilityObserver.observe(canvas);

        const tick = (now: number) => {
            const easing =
                1 - Math.exp(-Math.min(0.1, (now - lastTickAt) / 1000) * 2.5);
            lastTickAt = now;
            camera.yaw += (camera.targetYaw - camera.yaw) * easing;
            camera.pitch += (camera.targetPitch - camera.pitch) * easing;
            const { size, radius } = universe.hole;

            if (universe.isHoleCovered) {
                // Hidden behind a close-up: skip drawing, and don't count
                // these frames when checking whether the GPU keeps up.
                lastFrameAt = now;
                slowFrames = 0;
            } else if (size > 0 && isVisible) {
                if (!keepsUp(now)) {
                    canvas.style.opacity = '0';
                    universe.rendersHoleInWebgl = false;

                    return;
                }

                canvas.style.opacity = '1';
                canvas.style.transform = `scale(${(radius / SHADOW_RADIUS_PX).toFixed(4)})`;
                const seconds = (now - startedAt) / 1000;
                universe.view.yaw =
                    camera.yaw + Math.sin(seconds * 0.21) * DRIFT_YAW;
                universe.view.pitch =
                    camera.pitch + Math.sin(seconds * 0.13 + 1) * DRIFT_PITCH;
                universe.view.roll =
                    0.3 + Math.sin(seconds * 0.17 + 2) * DRIFT_ROLL;
                gl.uniform1f(time, seconds);
                gl.uniform1f(growth, size);
                gl.uniform2f(view, universe.view.yaw, universe.view.pitch);
                gl.uniform1f(roll, universe.view.roll);
                gl.drawArrays(gl.TRIANGLES, 0, 3);
            }

            frame = requestAnimationFrame(tick);
        };

        frame = requestAnimationFrame(tick);

        return () => {
            cancelAnimationFrame(frame);
            visibilityObserver.disconnect();
            window.removeEventListener('pointermove', onPointerMove);
            // Leave the 2D fallback off: this only runs when the page (or a hot
            // reload in dev) swaps the component out, not when WebGL fails.
            gl.deleteProgram(program);
            gl.deleteShader(vertexShader);
            gl.deleteShader(fragmentShader);
        };
    }, []);

    return (
        <canvas
            ref={canvasRef}
            aria-hidden="true"
            className="absolute inset-0 size-full origin-center opacity-0 motion-reduce:hidden"
        />
    );
}
