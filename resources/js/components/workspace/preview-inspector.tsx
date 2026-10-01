import { ArrowUpToLine, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { RefObject } from 'react';
import { Button } from '@/components/ui/button';
import { askPreview, toFile } from '@/components/workspace/preview-annotator';
import type { PreviewCapture } from '@/components/workspace/preview-annotator';

/** An element on the preview's page, as docker/sandbox/inspector.js describes it. */
export type InspectedElement = {
    selector: string;
    tag: string;
    text: string;
    /** The component that rendered it and its file, for React and Vue apps in development. */
    component: string | null;
    source: string | null;
};

/** Something the user picked on the page, or moved: before or after another element, or swapped with it. */
export type InspectorItem = {
    id: number;
    kind: 'pick' | 'move';
    element: InspectedElement;
    target?: InspectedElement;
    position?: 'before' | 'after' | 'swap';
};

type ItemRect = {
    id: number;
    x: number;
    y: number;
    width: number;
    height: number;
};

const RECTS_TIMEOUT_MS = 2_000;
const COLOR = '#3b82f6';

/**
 * Inspect the live preview (AGT-014): while `active`, the page's inspector runs and its picks and moves come back
 * here. `onExit` is called when the user presses Esc in the page, or the inspector couldn't start.
 */
export function usePreviewInspector(
    frame: RefObject<HTMLIFrameElement | null>,
    active: boolean,
    onExit: () => void,
): InspectorItem[] {
    const [items, setItems] = useState<InspectorItem[]>([]);
    const exit = useRef(onExit);

    useEffect(() => {
        exit.current = onExit;
    });

    useEffect(() => {
        const page = frame.current?.contentWindow;

        if (!active || !page) {
            return;
        }

        const onMessage = (event: MessageEvent) => {
            const data = event.data as {
                onedrop?: string;
                items?: InspectorItem[];
            };

            if (event.source !== page) {
                return;
            }

            if (data?.onedrop === 'inspector' && Array.isArray(data.items)) {
                setItems(data.items);
            } else if (data?.onedrop === 'inspector-exit') {
                exit.current();
            }
        };

        window.addEventListener('message', onMessage);
        page.postMessage({ onedrop: 'inspector-start' }, '*');

        return () => {
            window.removeEventListener('message', onMessage);
            page.postMessage({ onedrop: 'inspector-stop' }, '*');
            setItems([]);
        };
    }, [active, frame]);

    return items;
}

/** Tell the page's inspector to do something with one of its items. */
export function inspectorAction(
    frame: HTMLIFrameElement | null,
    action: 'remove' | 'parent',
    item: number,
): void {
    frame?.contentWindow?.postMessage(
        { onedrop: `inspector-${action}`, item },
        '*',
    );
}

/** Where each item is on the page right now, in its CSS pixels. */
export async function inspectorRects(
    frame: HTMLIFrameElement,
): Promise<ItemRect[]> {
    const answer = await askPreview<{ rects?: ItemRect[] }>(
        frame,
        { onedrop: 'inspector-rects' },
        'inspector-rects',
        RECTS_TIMEOUT_MS,
    );

    return Array.isArray(answer.rects) ? answer.rects : [];
}

/** The picture of the page with each item outlined and numbered, as a file for the chat. */
export async function inspectionImage(
    capture: PreviewCapture,
    items: InspectorItem[],
    rects: ItemRect[],
): Promise<File | null> {
    const picture = new Image();
    picture.src = capture.image;
    await picture.decode();

    const ratio = picture.naturalWidth / capture.width;
    const canvas = document.createElement('canvas');
    canvas.width = picture.naturalWidth;
    canvas.height = picture.naturalHeight;

    const context = canvas.getContext('2d');

    if (!context) {
        return null;
    }

    context.drawImage(picture, 0, 0);
    items.forEach((item, index) => {
        const rect = rects.find((candidate) => candidate.id === item.id);

        if (!rect) {
            return;
        }

        const [x, y, width, height] = [
            rect.x * ratio,
            rect.y * ratio,
            rect.width * ratio,
            rect.height * ratio,
        ];
        const radius = 10 * ratio;

        context.lineWidth = 3 * ratio;
        context.strokeStyle = COLOR;
        context.strokeRect(x, y, width, height);
        context.beginPath();
        context.arc(
            Math.max(radius, x),
            Math.max(radius, y),
            radius,
            0,
            Math.PI * 2,
        );
        context.fillStyle = COLOR;
        context.fill();
        context.fillStyle = 'white';
        context.font = `700 ${12 * ratio}px ui-sans-serif, system-ui, sans-serif`;
        context.textAlign = 'center';
        context.textBaseline = 'middle';
        context.fillText(
            String(index + 1),
            Math.max(radius, x),
            Math.max(radius, y) + ratio,
        );
    });

    return toFile(canvas, 'preview-inspected');
}

/** How an element reads for the agent: `button#save ("Save changes"), <SaveButton> in resources/js/app.tsx`. */
export function elementText(element: InspectedElement): string {
    const where = element.component
        ? `, <${element.component}>${element.source ? ` in ${element.source}` : ''}`
        : element.source
          ? `, in ${element.source}`
          : '';

    return `${element.selector}${element.text ? ` ("${element.text}")` : ''}${where}`;
}

/** What the agent is told about an inspection (not shown in the chat): the page, then each numbered pick and move. */
export function inspectionText(
    capture: PreviewCapture,
    items: InspectorItem[],
    notes: Record<number, string>,
    fileName: string,
): string {
    const where = capture.page ? ` at ${capture.page}` : '';
    const lines = items.map((item, index) => {
        const note = notes[item.id]?.trim();
        const said = note ? ` The user says: "${note}"` : '';

        if (item.kind === 'move' && item.target) {
            const how =
                item.position === 'swap'
                    ? 'Swap it with'
                    : `Move it ${item.position}`;

            return `${index + 1}. ${elementText(item.element)}. ${how} ${elementText(item.target)}.${said}`;
        }

        return `${index + 1}. ${elementText(item.element)}.${said}`;
    });

    return [
        `${fileName} is a screenshot of the app's preview${where} (${capture.width}×${capture.height}px). The user picked the numbered elements in it, and showed moves by dragging elements in the page (only in their browser; the code is unchanged).`,
        'Make these changes in the code (the selectors and components were found in the page, so search the code for them):',
        ...lines,
    ].join('\n');
}

/** What an item is, for the panel. */
function itemLabel(item: InspectorItem): string {
    const name = (element: InspectedElement) =>
        element.component
            ? `${element.selector} · ${element.component}`
            : element.selector;

    if (item.kind === 'move' && item.target) {
        const how =
            item.position === 'swap'
                ? 'swapped with'
                : `moved ${item.position}`;

        return `${name(item.element)} ${how} ${name(item.target)}`;
    }

    return name(item.element);
}

/**
 * The list over the preview while inspecting: each pick and move with a note, "pick the parent" and remove, then
 * Cancel or "Add to chat".
 */
export default function PreviewInspectorPanel({
    items,
    notes,
    busy,
    onNote,
    onParent,
    onRemove,
    onCancel,
    onDone,
}: {
    items: InspectorItem[];
    notes: Record<number, string>;
    busy: boolean;
    onNote: (item: number, note: string) => void;
    onParent: (item: number) => void;
    onRemove: (item: number) => void;
    onCancel: () => void;
    onDone: () => void;
}) {
    return (
        <div
            className="absolute inset-x-3 bottom-3 z-10 flex max-h-[45%] flex-col rounded-lg border bg-background text-sm shadow-lg"
            data-test="preview-inspector"
        >
            <div className="min-h-0 flex-1 overflow-auto p-2">
                {items.length === 0 ? (
                    <p className="px-1 py-1 text-muted-foreground">
                        Hover to highlight, click to pick, drag onto another
                        element to move or swap it. Esc stops.
                    </p>
                ) : (
                    <ol className="space-y-1">
                        {items.map((item, index) => (
                            <li
                                key={item.id}
                                className="flex items-center gap-2"
                                data-test="inspector-item"
                            >
                                <span
                                    className="flex size-5 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white"
                                    style={{ backgroundColor: COLOR }}
                                >
                                    {index + 1}
                                </span>
                                <span
                                    className="min-w-0 flex-1 truncate font-mono text-xs"
                                    title={itemLabel(item)}
                                >
                                    {itemLabel(item)}
                                </span>
                                <input
                                    value={notes[item.id] ?? ''}
                                    onChange={(event) =>
                                        onNote(item.id, event.target.value)
                                    }
                                    placeholder="Note…"
                                    aria-label={`Note for ${index + 1}`}
                                    data-test="inspector-note"
                                    className="w-40 shrink rounded border bg-background px-1.5 py-0.5"
                                />
                                {item.kind === 'pick' && (
                                    <button
                                        type="button"
                                        aria-label="Pick its parent"
                                        title="Pick its parent"
                                        onClick={() => onParent(item.id)}
                                        data-test="inspector-parent"
                                        className="rounded p-1 hover:bg-muted"
                                    >
                                        <ArrowUpToLine className="size-4" />
                                    </button>
                                )}
                                <button
                                    type="button"
                                    aria-label="Remove"
                                    title="Remove"
                                    onClick={() => onRemove(item.id)}
                                    data-test="inspector-remove"
                                    className="rounded p-1 hover:bg-muted"
                                >
                                    <X className="size-4" />
                                </button>
                            </li>
                        ))}
                    </ol>
                )}
            </div>
            <div className="flex items-center justify-end gap-1 border-t px-2 py-1.5">
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={onCancel}
                    data-test="inspector-cancel"
                >
                    Cancel
                </Button>
                <Button
                    type="button"
                    size="sm"
                    onClick={onDone}
                    disabled={items.length === 0 || busy}
                    data-test="inspector-done"
                >
                    Add to chat
                </Button>
            </div>
        </div>
    );
}
