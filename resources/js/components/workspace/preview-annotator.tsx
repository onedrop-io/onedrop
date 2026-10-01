import {
    MoveUpRight,
    Pencil,
    Square,
    Trash2,
    Type,
    Undo2,
    X,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import type { PointerEvent as ReactPointerEvent } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/** A picture of the preview as it was shown (AGT-013): the page's viewport, `width`×`height` CSS pixels. */
export type PreviewCapture = {
    image: string;
    width: number;
    height: number;
    page: string | null;
};

/** Where a mark is on the page, in the preview's CSS pixels; a point has no width or height. */
export type MarkRegion = {
    x: number;
    y: number;
    width: number;
    height: number;
};

type Tool = 'pen' | 'box' | 'arrow' | 'text';
type Point = { x: number; y: number };
type Mark =
    | { tool: 'pen'; color: string; points: Point[] }
    | { tool: 'box'; color: string; from: Point; to: Point }
    | { tool: 'arrow'; color: string; from: Point; to: Point }
    | { tool: 'text'; color: string; at: Point; text: string };

const COLORS = [
    { name: 'Red', value: '#ef4444' },
    { name: 'Yellow', value: '#eab308' },
    { name: 'Blue', value: '#3b82f6' },
];

const TOOLS: { tool: Tool; label: string; icon: React.ReactNode }[] = [
    { tool: 'pen', label: 'Pen', icon: <Pencil className="size-4" /> },
    { tool: 'box', label: 'Box', icon: <Square className="size-4" /> },
    { tool: 'arrow', label: 'Arrow', icon: <MoveUpRight className="size-4" /> },
    { tool: 'text', label: 'Text', icon: <Type className="size-4" /> },
];

const KIND: Record<Tool, string> = {
    pen: 'Drawing',
    box: 'Box',
    arrow: 'Arrow',
    text: 'Note',
};

/** How the chat says where each kind of mark is. */
const PLACES: Record<string, string> = {
    Drawing: 'over',
    Box: 'around',
    Arrow: 'pointing at',
    Note: 'on',
};

const CAPTURE_TIMEOUT_MS = 15_000;
const INSPECT_TIMEOUT_MS = 2_000;
const MAX_IMAGE_BYTES = 9_500_000;

/** Ask the preview's page (docker/sandbox/host-proxy.mjs) for something, and wait for its answer. */
function askPreview<T>(
    frame: HTMLIFrameElement,
    message: Record<string, unknown>,
    answer: string,
    timeout: number,
): Promise<T> {
    return new Promise((resolve, reject) => {
        const id = `${Date.now()}-${Math.random()}`;
        const timer = window.setTimeout(() => {
            window.removeEventListener('message', onMessage);
            reject(new Error("The preview didn't answer."));
        }, timeout);
        const onMessage = (event: MessageEvent) => {
            const data = event.data as { onedrop?: string; id?: string };

            if (
                event.source !== frame.contentWindow ||
                data?.onedrop !== answer ||
                data.id !== id
            ) {
                return;
            }

            window.clearTimeout(timer);
            window.removeEventListener('message', onMessage);
            resolve(event.data as T);
        };

        window.addEventListener('message', onMessage);
        frame.contentWindow?.postMessage({ ...message, id }, '*');
    });
}

/** The preview's page takes a picture of itself, as it's shown right now. */
export async function capturePreview(
    frame: HTMLIFrameElement,
): Promise<PreviewCapture> {
    const shot = await askPreview<Partial<PreviewCapture> & { error?: string }>(
        frame,
        { onedrop: 'capture' },
        'captured',
        CAPTURE_TIMEOUT_MS,
    );

    if (
        typeof shot.image !== 'string' ||
        !shot.image.startsWith('data:image/') ||
        !shot.width ||
        !shot.height
    ) {
        throw new Error(shot.error || "The preview didn't send a picture.");
    }

    return {
        image: shot.image,
        width: shot.width,
        height: shot.height,
        page: typeof shot.page === 'string' ? shot.page : null,
    };
}

/** A picture of the preview from the browser's own tab sharing, for pages that can't take their own. */
export async function captureTab(
    frame: HTMLIFrameElement,
): Promise<PreviewCapture> {
    const stream = await navigator.mediaDevices.getDisplayMedia({
        video: { displaySurface: 'browser' },
        audio: false,
        preferCurrentTab: true,
    } as DisplayMediaStreamOptions);

    try {
        const video = document.createElement('video');
        video.srcObject = stream;
        video.muted = true;
        await video.play();
        // Let the sharing prompt's own bar go before the frame is grabbed.
        await new Promise((resolve) => window.setTimeout(resolve, 300));

        const box = frame.getBoundingClientRect();
        const scale = video.videoWidth / window.innerWidth;
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(box.width * scale);
        canvas.height = Math.round(box.height * scale);
        canvas
            .getContext('2d')
            ?.drawImage(
                video,
                box.left * scale,
                box.top * scale,
                canvas.width,
                canvas.height,
                0,
                0,
                canvas.width,
                canvas.height,
            );

        return {
            image: canvas.toDataURL('image/png'),
            width: Math.round(box.width),
            height: Math.round(box.height),
            page: null,
        };
    } finally {
        stream.getTracks().forEach((track) => track.stop());
    }
}

/** The element under each mark, as the preview's page describes it (null where there's none, or no answer). */
export async function inspectPreview(
    frame: HTMLIFrameElement,
    marks: MarkRegion[],
): Promise<(string | null)[]> {
    try {
        const answer = await askPreview<{ elements?: unknown }>(
            frame,
            { onedrop: 'inspect', marks },
            'inspected',
            INSPECT_TIMEOUT_MS,
        );

        return marks.map((_, index) => {
            const element = Array.isArray(answer.elements)
                ? answer.elements[index]
                : null;

            return typeof element === 'string' ? element : null;
        });
    } catch {
        return marks.map(() => null);
    }
}

/**
 * What the agent is told about a marked-up picture (not shown in the chat): the page, then each numbered mark and
 * the element it points at, written like a CSS selector, e.g. `1. Box (red) around button#save.btn ("Save changes")`.
 */
export function annotationText(
    capture: PreviewCapture,
    marks: { kind: string; color: string; note?: string }[],
    elements: (string | null)[],
    fileName: string,
): string {
    const where = capture.page ? ` at ${capture.page}` : '';
    const lines = marks.map((mark, index) => {
        const what =
            mark.kind === 'Note' && mark.note
                ? `Note "${mark.note}" on`
                : `${mark.kind} (${mark.color.toLowerCase()}) ${PLACES[mark.kind] ?? 'on'}`;
        const element = elements[index];

        return `${index + 1}. ${element ? `${what} ${element}` : `${what} an empty spot`}`;
    });

    return [
        `${fileName} is the user's marked-up screenshot of the app's preview${where} (${capture.width}×${capture.height}px), showing what they want changed.`,
        ...(lines.length > 0
            ? [
                  'Its numbered marks, and the element under each (found in the page, so search the code for them):',
                  ...lines,
              ]
            : []),
    ].join('\n');
}

/**
 * Draw on a picture of the preview (AGT-013): pen, boxes, arrows and text notes, numbered so the chat can say
 * what each points at. "Add to chat" hands back the picture with the marks and where they are on the page.
 */
export default function PreviewAnnotator({
    capture,
    onCancel,
    onDone,
}: {
    capture: PreviewCapture;
    onCancel: () => void;
    onDone: (
        image: File,
        marks: {
            kind: string;
            color: string;
            note?: string;
            region: MarkRegion;
        }[],
    ) => void;
}) {
    const canvas = useRef<HTMLCanvasElement>(null);
    const [picture, setPicture] = useState<HTMLImageElement | null>(null);
    const [tool, setTool] = useState<Tool>('text');
    const [color, setColor] = useState(COLORS[0].value);
    const [marks, setMarksState] = useState<Mark[]>([]);
    // The marks are also kept in a ref for pointer events, and each change keeps the marks before it, for undo.
    const marksRef = useRef<Mark[]>([]);
    const history = useRef<Mark[][]>([]);
    const setMarks = (next: Mark[], remember = true) => {
        if (remember) {
            history.current.push(marksRef.current);
        }

        marksRef.current = next;
        setMarksState(next);
    };
    // The mark being dragged to a new place, and where the pointer last was.
    const moving = useRef<{
        index: number;
        from: Point;
        moved: boolean;
    } | null>(null);
    const [overMark, setOverMark] = useState(false);
    // The mark being drawn; also kept in a ref, since pointer events can come faster than renders.
    const [drawing, setDrawingState] = useState<Mark | null>(null);
    const drawingRef = useRef<Mark | null>(null);
    const setDrawing = (mark: Mark | null) => {
        drawingRef.current = mark;
        setDrawingState(mark);
    };
    const [typing, setTyping] = useState<{ at: Point; css: Point } | null>(
        null,
    );
    const [note, setNote] = useState('');
    const [saving, setSaving] = useState(false);
    // Shown at the page's own size, or smaller to fit the pane.
    const stage = useRef<HTMLDivElement>(null);
    const [fit, setFit] = useState(1);

    useLayoutEffect(() => {
        const element = stage.current;

        if (!element) {
            return;
        }

        const measure = () =>
            setFit(
                Math.min(
                    1,
                    element.clientWidth / capture.width,
                    element.clientHeight / capture.height,
                ),
            );
        const observer = new ResizeObserver(measure);

        measure();
        observer.observe(element);

        return () => observer.disconnect();
    }, [capture.width, capture.height]);

    useEffect(() => {
        const image = new Image();
        image.onload = () => setPicture(image);
        image.src = capture.image;
    }, [capture.image]);

    // The picture's pixels per CSS pixel of the page (its device pixel ratio when it was taken).
    const ratio = picture ? picture.naturalWidth / capture.width : 1;

    const render = useCallback(
        (target: HTMLCanvasElement, all: Mark[]) => {
            const context = target.getContext('2d');

            if (!context || !picture) {
                return;
            }

            context.clearRect(0, 0, target.width, target.height);
            context.drawImage(picture, 0, 0);
            all.forEach((mark) => drawMark(context, mark, ratio));
            all.forEach((mark, index) =>
                drawBadge(context, mark, index + 1, ratio),
            );
        },
        [picture, ratio],
    );

    useEffect(() => {
        if (canvas.current) {
            render(canvas.current, drawing ? [...marks, drawing] : marks);
        }
    }, [render, marks, drawing]);

    const pointAt = (event: ReactPointerEvent<HTMLCanvasElement>): Point => {
        const box = event.currentTarget.getBoundingClientRect();

        return {
            x:
                ((event.clientX - box.left) / box.width) *
                event.currentTarget.width,
            y:
                ((event.clientY - box.top) / box.height) *
                event.currentTarget.height,
        };
    };

    const commitNote = () => {
        if (typing && note.trim()) {
            setMarks([
                ...marksRef.current,
                { tool: 'text', color, at: typing.at, text: note.trim() },
            ]);
        }

        setTyping(null);
        setNote('');
    };

    const onPointerDown = (event: ReactPointerEvent<HTMLCanvasElement>) => {
        if (event.button !== 0 || !picture) {
            return;
        }

        const point = pointAt(event);
        const hit = markAt(event.currentTarget, marksRef.current, point, ratio);

        if (hit !== -1) {
            // Pressing a mark picks it up, whatever the tool, so it can be dragged into place.
            event.preventDefault();
            commitNote();
            capturePointer(event);
            moving.current = { index: hit, from: point, moved: false };

            return;
        }

        if (tool === 'text') {
            // Otherwise the click moves focus off the note box it opens, which closes it again.
            event.preventDefault();
            commitNote();
            const box = event.currentTarget.getBoundingClientRect();
            setTyping({
                at: point,
                css: {
                    x: event.clientX - box.left,
                    y: event.clientY - box.top,
                },
            });

            return;
        }

        capturePointer(event);
        setDrawing(
            tool === 'pen'
                ? { tool, color, points: [point] }
                : { tool, color, from: point, to: point },
        );
    };

    const onPointerMove = (event: ReactPointerEvent<HTMLCanvasElement>) => {
        const drawing = drawingRef.current;
        const point = pointAt(event);

        if (moving.current) {
            const { index, from, moved } = moving.current;

            moving.current = { index, from: point, moved: true };
            // Undo puts it back where it was before the drag, not one step of it.
            setMarks(
                marksRef.current.map((mark, at) =>
                    at === index
                        ? moveMark(mark, point.x - from.x, point.y - from.y)
                        : mark,
                ),
                !moved,
            );

            return;
        }

        if (!drawing) {
            setOverMark(
                markAt(event.currentTarget, marksRef.current, point, ratio) !==
                    -1,
            );

            return;
        }

        setDrawing(
            drawing.tool === 'pen'
                ? { ...drawing, points: [...drawing.points, point] }
                : drawing.tool === 'text'
                  ? drawing
                  : { ...drawing, to: point },
        );
    };

    const onPointerUp = () => {
        const drawing = drawingRef.current;

        if (moving.current) {
            moving.current = null;

            return;
        }

        if (drawing && !isTiny(drawing, ratio)) {
            setMarks([...marksRef.current, drawing]);
        }

        setDrawing(null);
    };

    const undo = () => {
        const previous = history.current.pop();

        if (previous) {
            setMarks(previous, false);
        }
    };

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (typing) {
                return;
            }

            if (event.key === 'Escape') {
                onCancel();
            } else if (
                (event.metaKey || event.ctrlKey) &&
                event.key.toLowerCase() === 'z'
            ) {
                event.preventDefault();
                undo();
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [typing, onCancel]);

    const finish = async () => {
        if (!canvas.current || !picture) {
            return;
        }

        setSaving(true);
        render(canvas.current, marks);

        const image = await toFile(canvas.current);

        setSaving(false);

        if (!image) {
            return;
        }

        onDone(
            image,
            marks.map((mark) => ({
                kind: KIND[mark.tool],
                color: COLORS.find((choice) => choice.value === mark.color)!
                    .name,
                note: mark.tool === 'text' ? mark.text : undefined,
                region: regionOf(mark, ratio),
            })),
        );
    };

    return (
        <div
            className="absolute inset-0 z-20 flex flex-col bg-muted"
            data-test="preview-annotator"
        >
            <div className="flex flex-wrap items-center gap-1 border-b bg-background px-2 py-1.5">
                {TOOLS.map((choice) => (
                    <button
                        key={choice.tool}
                        type="button"
                        aria-label={choice.label}
                        title={choice.label}
                        aria-pressed={tool === choice.tool}
                        onClick={() => setTool(choice.tool)}
                        data-test={`annotate-tool-${choice.tool}`}
                        className={cn(
                            'rounded p-1.5 hover:bg-muted',
                            tool === choice.tool && 'bg-muted text-foreground',
                        )}
                    >
                        {choice.icon}
                    </button>
                ))}
                <span className="mx-1 h-5 w-px bg-border" />
                {COLORS.map((choice) => (
                    <button
                        key={choice.value}
                        type="button"
                        aria-label={choice.name}
                        title={choice.name}
                        aria-pressed={color === choice.value}
                        onClick={() => setColor(choice.value)}
                        data-test={`annotate-color-${choice.name.toLowerCase()}`}
                        className={cn(
                            'm-0.5 size-5 rounded-full ring-offset-2 ring-offset-background',
                            color === choice.value && 'ring-2 ring-ring',
                        )}
                        style={{ backgroundColor: choice.value }}
                    />
                ))}
                <span className="mx-1 h-5 w-px bg-border" />
                <button
                    type="button"
                    aria-label="Undo"
                    title="Undo"
                    onClick={undo}
                    disabled={history.current.length === 0}
                    data-test="annotate-undo"
                    className="rounded p-1.5 hover:bg-muted disabled:opacity-40"
                >
                    <Undo2 className="size-4" />
                </button>
                <button
                    type="button"
                    aria-label="Clear"
                    title="Clear"
                    onClick={() => setMarks([])}
                    disabled={marks.length === 0}
                    data-test="annotate-clear"
                    className="rounded p-1.5 hover:bg-muted disabled:opacity-40"
                >
                    <Trash2 className="size-4" />
                </button>
                <div className="ml-auto flex items-center gap-1">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={onCancel}
                        data-test="annotate-cancel"
                    >
                        <X className="size-4" />
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        onClick={finish}
                        disabled={!picture || saving}
                        data-test="annotate-done"
                    >
                        Add to chat
                    </Button>
                </div>
            </div>
            <div className="min-h-0 flex-1 p-3">
                <div
                    ref={stage}
                    className="flex size-full items-center justify-center"
                >
                    <div className="relative">
                        <canvas
                            // The picture's own pixels; nothing can be drawn until it has loaded.
                            width={picture?.naturalWidth ?? capture.width}
                            height={picture?.naturalHeight ?? capture.height}
                            aria-busy={!picture}
                            ref={canvas}
                            role="img"
                            aria-label="Preview to draw on"
                            onPointerDown={onPointerDown}
                            onPointerMove={onPointerMove}
                            onPointerUp={onPointerUp}
                            onPointerCancel={onPointerUp}
                            data-test="annotate-canvas"
                            className={cn(
                                'block touch-none bg-white shadow-sm',
                                moving.current || overMark
                                    ? 'cursor-move'
                                    : tool === 'text'
                                      ? 'cursor-text'
                                      : 'cursor-crosshair',
                            )}
                            style={{
                                width: capture.width * fit,
                                height: capture.height * fit,
                            }}
                        />
                        {typing && (
                            <input
                                autoFocus
                                value={note}
                                onChange={(event) =>
                                    setNote(event.target.value)
                                }
                                onBlur={commitNote}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter') {
                                        event.preventDefault();
                                        commitNote();
                                    } else if (event.key === 'Escape') {
                                        event.preventDefault();
                                        setTyping(null);
                                        setNote('');
                                    }
                                }}
                                placeholder="Add a note…"
                                aria-label="Note"
                                data-test="annotate-note"
                                className="absolute w-48 rounded border bg-background px-1.5 py-0.5 text-sm shadow"
                                style={{
                                    left: typing.css.x,
                                    top: typing.css.y,
                                    color,
                                }}
                            />
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

function drawMark(
    context: CanvasRenderingContext2D,
    mark: Mark,
    ratio: number,
) {
    context.save();
    context.strokeStyle = mark.color;
    context.fillStyle = mark.color;
    context.lineWidth = 3 * ratio;
    context.lineCap = 'round';
    context.lineJoin = 'round';

    if (mark.tool === 'pen') {
        context.beginPath();
        mark.points.forEach((point, index) =>
            index === 0
                ? context.moveTo(point.x, point.y)
                : context.lineTo(point.x, point.y),
        );
        context.stroke();
    } else if (mark.tool === 'box') {
        context.strokeRect(
            mark.from.x,
            mark.from.y,
            mark.to.x - mark.from.x,
            mark.to.y - mark.from.y,
        );
    } else if (mark.tool === 'arrow') {
        const angle = Math.atan2(
            mark.to.y - mark.from.y,
            mark.to.x - mark.from.x,
        );
        const head = 14 * ratio;

        context.beginPath();
        context.moveTo(mark.from.x, mark.from.y);
        context.lineTo(mark.to.x, mark.to.y);
        context.stroke();
        context.beginPath();
        context.moveTo(mark.to.x, mark.to.y);
        context.lineTo(
            mark.to.x - head * Math.cos(angle - Math.PI / 6),
            mark.to.y - head * Math.sin(angle - Math.PI / 6),
        );
        context.lineTo(
            mark.to.x - head * Math.cos(angle + Math.PI / 6),
            mark.to.y - head * Math.sin(angle + Math.PI / 6),
        );
        context.closePath();
        context.fill();
    } else {
        context.font = noteFont(ratio);
        context.textBaseline = 'top';
        context.lineWidth = 4 * ratio;
        context.strokeStyle = 'white';
        context.strokeText(mark.text, mark.at.x, mark.at.y);
        context.fillText(mark.text, mark.at.x, mark.at.y);
    }

    context.restore();
}

/** The mark's number, where the chat's list refers to it. */
function drawBadge(
    context: CanvasRenderingContext2D,
    mark: Mark,
    number: number,
    ratio: number,
) {
    const start = badgeAt(mark, ratio);
    const radius = 10 * ratio;

    context.save();
    context.beginPath();
    context.arc(start.x, start.y, radius, 0, Math.PI * 2);
    context.fillStyle = mark.color;
    context.fill();
    context.lineWidth = 2 * ratio;
    context.strokeStyle = 'white';
    context.stroke();
    context.fillStyle = 'white';
    context.font = `700 ${12 * ratio}px ui-sans-serif, system-ui, sans-serif`;
    context.textAlign = 'center';
    context.textBaseline = 'middle';
    context.fillText(String(number), start.x, start.y + ratio);
    context.restore();
}

/** Where a mark's number goes: a box's top-left corner, the start of a line, left of a note. */
function badgeAt(mark: Mark, ratio: number): Point {
    if (mark.tool === 'pen') {
        return mark.points[0];
    }

    if (mark.tool === 'text') {
        // Left of the note, so it doesn't cover the text.
        return { x: mark.at.x - 14 * ratio, y: mark.at.y + 9 * ratio };
    }

    return mark.tool === 'box'
        ? {
              x: Math.min(mark.from.x, mark.to.x),
              y: Math.min(mark.from.y, mark.to.y),
          }
        : mark.from;
}

function noteFont(ratio: number): string {
    return `600 ${16 * ratio}px ui-sans-serif, system-ui, sans-serif`;
}

/** Keep following the pointer when it leaves the picture; a pointer that's already gone can't be captured. */
function capturePointer(event: ReactPointerEvent<HTMLCanvasElement>) {
    try {
        event.currentTarget.setPointerCapture(event.pointerId);
    } catch {
        // Drawing and dragging still work without it.
    }
}

/** The topmost mark under a point (its line, a note's text, or its number), or -1. */
function markAt(
    canvas: HTMLCanvasElement,
    marks: Mark[],
    point: Point,
    ratio: number,
): number {
    const reach = 8 * ratio;
    const context = canvas.getContext('2d');

    for (let index = marks.length - 1; index >= 0; index--) {
        const mark = marks[index];
        const badge = badgeAt(mark, ratio);

        if (Math.hypot(point.x - badge.x, point.y - badge.y) <= 12 * ratio) {
            return index;
        }

        if (mark.tool === 'text') {
            context?.save();

            if (context) {
                context.font = noteFont(ratio);
            }

            const width = context?.measureText(mark.text).width ?? 0;

            context?.restore();

            if (
                point.x >= mark.at.x - reach &&
                point.x <= mark.at.x + width + reach &&
                point.y >= mark.at.y - reach &&
                point.y <= mark.at.y + 20 * ratio + reach
            ) {
                return index;
            }

            continue;
        }

        const lines: [Point, Point][] =
            mark.tool === 'pen'
                ? mark.points.map((at, i) => [at, mark.points[i + 1] ?? at])
                : mark.tool === 'arrow'
                  ? [[mark.from, mark.to]]
                  : [
                        [mark.from, { x: mark.to.x, y: mark.from.y }],
                        [{ x: mark.to.x, y: mark.from.y }, mark.to],
                        [mark.to, { x: mark.from.x, y: mark.to.y }],
                        [{ x: mark.from.x, y: mark.to.y }, mark.from],
                    ];

        if (lines.some(([a, b]) => distanceToLine(point, a, b) <= reach)) {
            return index;
        }
    }

    return -1;
}

function distanceToLine(point: Point, a: Point, b: Point): number {
    const length = (b.x - a.x) ** 2 + (b.y - a.y) ** 2;
    const along =
        length === 0
            ? 0
            : Math.max(
                  0,
                  Math.min(
                      1,
                      ((point.x - a.x) * (b.x - a.x) +
                          (point.y - a.y) * (b.y - a.y)) /
                          length,
                  ),
              );

    return Math.hypot(
        point.x - (a.x + along * (b.x - a.x)),
        point.y - (a.y + along * (b.y - a.y)),
    );
}

function moveMark(mark: Mark, dx: number, dy: number): Mark {
    const by = (point: Point) => ({ x: point.x + dx, y: point.y + dy });

    return mark.tool === 'pen'
        ? { ...mark, points: mark.points.map(by) }
        : mark.tool === 'text'
          ? { ...mark, at: by(mark.at) }
          : { ...mark, from: by(mark.from), to: by(mark.to) };
}

/** A click with the pen, box or arrow, rather than a mark. */
function isTiny(mark: Mark, ratio: number): boolean {
    const region = regionOf(mark, ratio);

    return mark.tool === 'arrow'
        ? Math.hypot(mark.to.x - mark.from.x, mark.to.y - mark.from.y) <
              4 * ratio
        : mark.tool !== 'text' && region.width < 4 && region.height < 4;
}

/** Where on the page a mark points: a box's or drawing's area, an arrow's tip, a note's spot. */
function regionOf(mark: Mark, ratio: number): MarkRegion {
    const points =
        mark.tool === 'pen'
            ? mark.points
            : mark.tool === 'box'
              ? [mark.from, mark.to]
              : [mark.tool === 'arrow' ? mark.to : mark.at];
    const xs = points.map((point) => point.x / ratio);
    const ys = points.map((point) => point.y / ratio);
    const x = Math.min(...xs);
    const y = Math.min(...ys);

    return {
        x: Math.round(x),
        y: Math.round(y),
        width: Math.round(Math.max(...xs) - x),
        height: Math.round(Math.max(...ys) - y),
    };
}

/** The marked-up picture as a file for the chat: PNG, or JPEG if that would be over the attachment limit. */
async function toFile(canvas: HTMLCanvasElement): Promise<File | null> {
    const blob = (type: string, quality?: number) =>
        new Promise<Blob | null>((resolve) =>
            canvas.toBlob(resolve, type, quality),
        );
    let image = await blob('image/png');
    let name = 'preview-annotated.png';

    if (image && image.size > MAX_IMAGE_BYTES) {
        image = await blob('image/jpeg', 0.85);
        name = 'preview-annotated.jpg';
    }

    return image ? new File([image], name, { type: image.type }) : null;
}
