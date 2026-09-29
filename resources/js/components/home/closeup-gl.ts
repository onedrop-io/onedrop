/**
 * Small WebGL2 helpers shared by the planet close-ups (`earth-closeup`,
 * `mars-closeup`): each draws one full-canvas fragment shader over a few maps
 * on its own canvas, which the close-up then paints into its 2D canvas.
 */

/**
 * Covers the canvas with one quad. `screen` runs from -extent to extent
 * across it, so a sphere shader can treat it as the sphere's own coordinates.
 */
const VERTEX_SHADER = `#version 300 es
in vec2 position;
out vec2 screen;
uniform float extent;

void main() {
    screen = position * extent;
    gl_Position = vec4(position, 0.0, 1.0);
}`;

export type ShaderCanvas = {
    canvas: HTMLCanvasElement;
    gl: WebGL2RenderingContext;
    uniform: (name: string) => WebGLUniformLocation | null;
};

export function normalize(x: number, y: number, z: number) {
    const length = Math.hypot(x, y, z);

    return { x: x / length, y: y / length, z: z / length };
}

export function clamp(value: number, min = 0, max = 1): number {
    return Math.min(max, Math.max(min, value));
}

export function smoothstep(from: number, to: number, value: number): number {
    const t = clamp((value - from) / (to - from));

    return t * t * (3 - 2 * t);
}

export async function loadImage(source: string): Promise<HTMLImageElement> {
    const image = new Image();
    image.src = source;
    await image.decode();

    return image;
}

function compile(
    gl: WebGL2RenderingContext,
    type: number,
    source: string,
): WebGLShader {
    const shader = gl.createShader(type)!;
    gl.shaderSource(shader, source);
    gl.compileShader(shader);

    if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
        throw new Error(gl.getShaderInfoLog(shader) ?? 'Shader failed');
    }

    return shader;
}

/**
 * A WebGL canvas that draws `fragmentShader`, with the maps in `images` given
 * to its samplers in order (mipmapped, wrapping around east to west).
 */
export function createShaderCanvas(
    fragmentShader: string,
    samplers: string[],
    images: HTMLImageElement[],
): ShaderCanvas {
    const canvas = document.createElement('canvas');
    const gl = canvas.getContext('webgl2', { antialias: false });

    if (!gl) {
        throw new Error('WebGL2 is not available');
    }

    const program = gl.createProgram();
    gl.attachShader(program, compile(gl, gl.VERTEX_SHADER, VERTEX_SHADER));
    gl.attachShader(program, compile(gl, gl.FRAGMENT_SHADER, fragmentShader));
    gl.linkProgram(program);

    if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
        throw new Error(gl.getProgramInfoLog(program) ?? 'Program failed');
    }

    gl.useProgram(program);

    gl.bindBuffer(gl.ARRAY_BUFFER, gl.createBuffer());
    gl.bufferData(
        gl.ARRAY_BUFFER,
        new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1]),
        gl.STATIC_DRAW,
    );
    const position = gl.getAttribLocation(program, 'position');
    gl.enableVertexAttribArray(position);
    gl.vertexAttribPointer(position, 2, gl.FLOAT, false, 0, 0);

    const anisotropy = gl.getExtension('EXT_texture_filter_anisotropic');

    samplers.forEach((name, index) => {
        gl.activeTexture(gl.TEXTURE0 + index);
        gl.bindTexture(gl.TEXTURE_2D, gl.createTexture());
        gl.texImage2D(
            gl.TEXTURE_2D,
            0,
            gl.RGB,
            gl.RGB,
            gl.UNSIGNED_BYTE,
            images[index],
        );
        gl.generateMipmap(gl.TEXTURE_2D);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.REPEAT);
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
        gl.texParameteri(
            gl.TEXTURE_2D,
            gl.TEXTURE_MIN_FILTER,
            gl.LINEAR_MIPMAP_LINEAR,
        );
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);

        if (anisotropy) {
            gl.texParameterf(
                gl.TEXTURE_2D,
                anisotropy.TEXTURE_MAX_ANISOTROPY_EXT,
                8,
            );
        }

        gl.uniform1i(gl.getUniformLocation(program, name), index);
    });

    return {
        canvas,
        gl,
        uniform: (name: string) => gl.getUniformLocation(program, name),
    };
}

/** Sizes the canvas to `width` by `height` device pixels (if it isn't already) and draws the shader once. */
export function renderShaderCanvas(
    { canvas, gl }: ShaderCanvas,
    width: number,
    height: number,
) {
    if (canvas.width !== width || canvas.height !== height) {
        canvas.width = width;
        canvas.height = height;
        gl.viewport(0, 0, width, height);
    }

    gl.clearColor(0, 0, 0, 0);
    gl.clear(gl.COLOR_BUFFER_BIT);
    gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
}

let backdropStars: { x: number; y: number; size: number; alpha: number }[] = [];

/**
 * Deep space behind a close-up, filling its `size` canvas and fading out at
 * the edges, so it hides the galaxy and black hole behind it.
 */
export function drawSpaceBackdrop(
    context: CanvasRenderingContext2D,
    size: number,
    alpha: number,
) {
    const center = size / 2;

    if (backdropStars.length === 0) {
        backdropStars = Array.from({ length: 140 }, () => {
            const angle = Math.random() * Math.PI * 2;
            const distance = Math.sqrt(Math.random()) * size * 0.45;

            return {
                x: Math.cos(angle) * distance,
                y: Math.sin(angle) * distance,
                size: 0.4 + Math.random() * 0.9,
                alpha: 0.3 + Math.random() * 0.6,
            };
        });
    }

    const backdrop = context.createRadialGradient(
        center,
        center,
        0,
        center,
        center,
        center,
    );
    backdrop.addColorStop(0, 'rgba(3, 4, 10, 0.98)');
    backdrop.addColorStop(0.72, 'rgba(3, 4, 10, 0.95)');
    backdrop.addColorStop(1, 'rgba(3, 4, 10, 0)');
    context.globalAlpha = alpha;
    context.fillStyle = backdrop;
    context.fillRect(0, 0, size, size);

    context.fillStyle = '#FFFFFF';

    for (const star of backdropStars) {
        context.globalAlpha =
            star.alpha * alpha * (1 - Math.hypot(star.x, star.y) / center);
        context.fillRect(
            center + star.x,
            center + star.y,
            star.size,
            star.size,
        );
    }

    context.globalAlpha = 1;
}
