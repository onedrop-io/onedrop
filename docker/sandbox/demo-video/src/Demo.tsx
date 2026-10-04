import {
    AbsoluteFill,
    Easing,
    Img,
    Sequence,
    interpolate,
    spring,
    useCurrentFrame,
    useVideoConfig,
} from 'remotion';

/**
 * The demo video (DEMO-002): a title card, then each scene (a test's recording, see demo.mjs) in a browser window
 * with its caption, a cursor that glides to each click with a ripple, and a zoom towards it, then an end card.
 * Times in the props are ms from the start of their scene.
 */
export const FPS = 30;
const INTRO = 90;
const OUTRO = 90;
const FADE = 10;
const WIDTH = 1920;
// The browser window: its page area keeps the frames' 16:9.
const PAGE_WIDTH = 1472;
const PAGE_HEIGHT = 828;
const BAR = 48;
const WINDOW_TOP = 160;
const ZOOM = 0.35;
const GLIDE_MS = 450;
const RIPPLE_MS = 600;
const FONT =
    'Inter, "Liberation Sans", "Noto Sans", "Helvetica Neue", Arial, sans-serif';

type Frame = { src: string; t: number };
/** A mouse move or press, or a field focused (typing into it doesn't move the mouse); x and y are fractions of the page. */
type Mouse = {
    t: number;
    type: 'move' | 'down' | 'focus';
    x: number;
    y: number;
};
type Address = { t: number; address: string };

export type Scene = {
    caption: string;
    durationMs: number;
    frames: Frame[];
    mouse: Mouse[];
    urls: Address[];
};

export type DemoProps = {
    title: string;
    tagline: string;
    accent: string;
    /** The published address, for the end card. */
    address: string | null;
    /** The app's icon, under `base`. */
    icon: string | null;
    /** Where demo.mjs serves the frames and icon. */
    base: string;
    scenes: Scene[];
};

const sceneFrames = (scene: Scene) =>
    Math.max(FADE * 2, Math.ceil((scene.durationMs / 1000) * FPS));

export function totalFrames(props: DemoProps) {
    return (
        INTRO +
        props.scenes.reduce((sum, scene) => sum + sceneFrames(scene), 0) +
        OUTRO
    );
}

export function Demo(props: DemoProps) {
    let from = INTRO;

    return (
        <AbsoluteFill style={{ fontFamily: FONT, color: 'white' }}>
            <Background accent={props.accent} />
            <Sequence durationInFrames={INTRO}>
                <TitleCard {...props} />
            </Sequence>
            {props.scenes.map((scene, index) => {
                const start = from;
                from += sceneFrames(scene);

                return (
                    <Sequence
                        key={index}
                        from={start}
                        durationInFrames={sceneFrames(scene)}
                    >
                        <SceneView scene={scene} {...props} />
                    </Sequence>
                );
            })}
            <Sequence from={from} durationInFrames={OUTRO}>
                <EndCard {...props} />
            </Sequence>
        </AbsoluteFill>
    );
}

function Background({ accent }: { accent: string }) {
    return (
        <AbsoluteFill
            style={{
                background: `radial-gradient(ellipse 80% 60% at 50% 0%, ${accent}55, transparent 70%), radial-gradient(ellipse 60% 50% at 100% 100%, ${accent}33, transparent 70%), #0b0b10`,
            }}
        />
    );
}

/** Fades a sequence in at its start and out at its end. */
function useFade(duration: number) {
    const frame = useCurrentFrame();

    return interpolate(
        frame,
        [0, FADE, duration - FADE, duration],
        [0, 1, 1, 0],
        { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' },
    );
}

function Icon({
    icon,
    base,
    title,
    accent,
    size,
}: DemoProps & { size: number }) {
    return icon ? (
        <Img
            src={base + icon}
            style={{
                width: size,
                height: size,
                objectFit: 'contain',
                borderRadius: size * 0.22,
            }}
        />
    ) : (
        <div
            style={{
                width: size,
                height: size,
                borderRadius: size * 0.22,
                background: accent,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                fontSize: size * 0.5,
                fontWeight: 700,
            }}
        >
            {(title.trim()[0] ?? '?').toUpperCase()}
        </div>
    );
}

function TitleCard(props: DemoProps) {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const enter = spring({ frame, fps, config: { damping: 200 } });
    const later = spring({ frame: frame - 8, fps, config: { damping: 200 } });

    return (
        <AbsoluteFill
            style={{
                alignItems: 'center',
                justifyContent: 'center',
                gap: 36,
                opacity: useFade(INTRO),
            }}
        >
            <div
                style={{
                    transform: `scale(${0.8 + 0.2 * enter})`,
                    opacity: enter,
                }}
            >
                <Icon {...props} size={168} />
            </div>
            <div
                style={{
                    fontSize: 104,
                    fontWeight: 700,
                    letterSpacing: '-0.02em',
                    opacity: enter,
                    transform: `translateY(${(1 - enter) * 24}px)`,
                }}
            >
                {props.title}
            </div>
            {props.tagline && (
                <div
                    style={{
                        fontSize: 44,
                        opacity: 0.72 * later,
                        maxWidth: 1400,
                        textAlign: 'center',
                        transform: `translateY(${(1 - later) * 24}px)`,
                    }}
                >
                    {props.tagline}
                </div>
            )}
        </AbsoluteFill>
    );
}

function EndCard(props: DemoProps) {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const enter = spring({ frame, fps, config: { damping: 200 } });

    return (
        <AbsoluteFill
            style={{
                alignItems: 'center',
                justifyContent: 'center',
                gap: 40,
                opacity: useFade(OUTRO),
            }}
        >
            <div
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 32,
                    opacity: enter,
                }}
            >
                <Icon {...props} size={112} />
                <div
                    style={{
                        fontSize: 88,
                        fontWeight: 700,
                        letterSpacing: '-0.02em',
                    }}
                >
                    {props.title}
                </div>
            </div>
            {props.address && (
                <div
                    style={{
                        fontSize: 40,
                        padding: '18px 40px',
                        borderRadius: 999,
                        background: `${props.accent}33`,
                        border: `2px solid ${props.accent}`,
                        opacity: enter,
                        transform: `translateY(${(1 - enter) * 24}px)`,
                    }}
                >
                    {props.address}
                </div>
            )}
        </AbsoluteFill>
    );
}

/** The last item at or before `t` in a list sorted by time (else the first). */
function latest<T extends { t: number }>(items: T[], t: number): T | undefined {
    let low = 0;
    let high = items.length - 1;
    let found = -1;

    while (low <= high) {
        const mid = (low + high) >> 1;

        if (items[mid].t <= t) {
            found = mid;
            low = mid + 1;
        } else {
            high = mid - 1;
        }
    }

    return items[found] ?? items[0];
}

const ease = Easing.inOut(Easing.cubic);
const clamp01 = (n: number) => Math.min(1, Math.max(0, n));

/** Where the cursor is at `t`: it glides to each position so it arrives as the page got it. */
function cursorAt(mouse: Mouse[], t: number) {
    if (mouse.length === 0) {
        return null;
    }

    const next = mouse.findIndex((m) => m.t > t);

    if (next === -1) {
        return { x: mouse.at(-1)!.x, y: mouse.at(-1)!.y, opacity: 1 };
    }

    const to = mouse[next];
    const from = mouse[next - 1] ?? { t: to.t - GLIDE_MS, x: 0.5, y: 0.62 };
    const start = Math.max(from.t, to.t - GLIDE_MS);
    const p = ease(clamp01((t - start) / Math.max(1, to.t - start)));

    return {
        x: from.x + (to.x - from.x) * p,
        y: from.y + (to.y - from.y) * p,
        opacity: next === 0 ? clamp01((t - start) / 200) : 1,
    };
}

/** How far to zoom at `t`, and towards where: in before each click or field typed into, held, then out. */
function zoomAt(mouse: Mouse[], t: number) {
    let strongest = 0;
    let weight = 0;
    let x = 0;
    let y = 0;

    for (const m of mouse) {
        if (m.type === 'move') {
            continue;
        }

        const envelope = ease(
            Math.min(
                clamp01((t - (m.t - 700)) / 450),
                clamp01((m.t + 1400 - t) / 500),
            ),
        );

        if (envelope > 0) {
            strongest = Math.max(strongest, envelope);
            weight += envelope;
            x += m.x * envelope;
            y += m.y * envelope;
        }
    }

    return weight > 0
        ? { scale: 1 + ZOOM * strongest, x: x / weight, y: y / weight }
        : { scale: 1, x: 0.5, y: 0.5 };
}

function SceneView({ scene, base, accent }: DemoProps & { scene: Scene }) {
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();
    const t = (frame / fps) * 1000;
    const shown = latest(scene.frames, t);
    const address = latest(scene.urls, t)?.address ?? '';
    const cursor = cursorAt(scene.mouse, t);
    const zoom = zoomAt(scene.mouse, t);
    const caption = spring({ frame: frame - 4, fps, config: { damping: 200 } });
    const ripples = scene.mouse.filter(
        (m) => m.type === 'down' && t >= m.t && t <= m.t + RIPPLE_MS,
    );

    return (
        <AbsoluteFill style={{ opacity: useFade(sceneFrames(scene)) }}>
            <div
                style={{
                    position: 'absolute',
                    top: 52,
                    width: WIDTH,
                    textAlign: 'center',
                    fontSize: 54,
                    fontWeight: 600,
                    letterSpacing: '-0.01em',
                    opacity: caption,
                    transform: `translateY(${(1 - caption) * 20}px)`,
                }}
            >
                {scene.caption}
            </div>
            <div
                style={{
                    position: 'absolute',
                    left: (WIDTH - PAGE_WIDTH) / 2,
                    top: WINDOW_TOP,
                    width: PAGE_WIDTH,
                    height: BAR + PAGE_HEIGHT,
                    borderRadius: 18,
                    overflow: 'hidden',
                    background: '#1c1c22',
                    boxShadow: `0 40px 120px rgba(0,0,0,0.55), 0 0 0 1px rgba(255,255,255,0.08)`,
                }}
            >
                <div
                    style={{
                        height: BAR,
                        display: 'flex',
                        alignItems: 'center',
                        gap: 10,
                        padding: '0 18px',
                    }}
                >
                    {['#ff5f57', '#febc2e', '#28c840'].map((color) => (
                        <div
                            key={color}
                            style={{
                                width: 14,
                                height: 14,
                                borderRadius: 7,
                                background: color,
                            }}
                        />
                    ))}
                    <div
                        style={{
                            marginLeft: 18,
                            flex: 1,
                            maxWidth: 760,
                            height: 30,
                            borderRadius: 8,
                            background: 'rgba(255,255,255,0.07)',
                            color: 'rgba(255,255,255,0.7)',
                            fontSize: 18,
                            display: 'flex',
                            alignItems: 'center',
                            padding: '0 14px',
                            overflow: 'hidden',
                            whiteSpace: 'nowrap',
                            textOverflow: 'ellipsis',
                        }}
                    >
                        {address}
                    </div>
                </div>
                <div
                    style={{
                        position: 'relative',
                        width: PAGE_WIDTH,
                        height: PAGE_HEIGHT,
                        overflow: 'hidden',
                        background: 'white',
                    }}
                >
                    <div
                        style={{
                            position: 'absolute',
                            inset: 0,
                            transform: `scale(${zoom.scale})`,
                            transformOrigin: `${zoom.x * 100}% ${zoom.y * 100}%`,
                        }}
                    >
                        {shown && (
                            <Img
                                src={base + shown.src}
                                style={{
                                    width: PAGE_WIDTH,
                                    height: PAGE_HEIGHT,
                                    display: 'block',
                                }}
                            />
                        )}
                        {ripples.map((m) => {
                            const p = (t - m.t) / RIPPLE_MS;
                            const size = 20 + 70 * p;

                            return (
                                <div
                                    key={m.t}
                                    style={{
                                        position: 'absolute',
                                        left: m.x * PAGE_WIDTH - size / 2,
                                        top: m.y * PAGE_HEIGHT - size / 2,
                                        width: size,
                                        height: size,
                                        borderRadius: '50%',
                                        border: `4px solid ${accent}`,
                                        background: `${accent}33`,
                                        opacity: 1 - p,
                                    }}
                                />
                            );
                        })}
                        {cursor && (
                            <svg
                                width={30}
                                height={30}
                                viewBox="0 0 24 24"
                                style={{
                                    position: 'absolute',
                                    left: cursor.x * PAGE_WIDTH - 5,
                                    top: cursor.y * PAGE_HEIGHT - 3,
                                    opacity: cursor.opacity,
                                    filter: 'drop-shadow(0 2px 4px rgba(0,0,0,0.35))',
                                }}
                            >
                                <path
                                    d="M5 3l14 8-6.5 1.5L9 19z"
                                    fill="#111"
                                    stroke="white"
                                    strokeWidth={1.6}
                                    strokeLinejoin="round"
                                />
                            </svg>
                        )}
                    </div>
                </div>
            </div>
        </AbsoluteFill>
    );
}
