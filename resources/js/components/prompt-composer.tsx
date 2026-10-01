import { useForm } from '@inertiajs/react';
import type { RouteDefinition } from '@/wayfinder';
import { ArrowUp, Paperclip, Square, Zap } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import type { ClipboardEvent, DragEvent, KeyboardEvent } from 'react';
import InputError from '@/components/input-error';
import MessageAttachments from '@/components/message-attachments';
import { cn } from '@/lib/utils';

/** Matches Attachment::MAX_FILES and MAX_KILOBYTES on the server. */
const MAX_ATTACHMENTS = 10;
const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;

/** Types the browser can preview (and a vision model can see). */
const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

type PickedFile = {
    key: string;
    file: File;
    imageUrl: string | null;
    /** Sent along for the agent only, while the file is attached (e.g. what a marked-up preview points at). */
    context?: string;
};

/** A file attached from outside the box, with an optional note for the agent that the chat doesn't show. */
export type AttachedFile = { file: File; context?: string };

/**
 * While the agent works: "auto" lets the server decide (queue it, or send it now when it corrects the work in
 * progress, AGT-012), "queue" always queues, "now" interrupts.
 */
type SendMode = 'auto' | 'queue' | 'now';

/** A message the server held for the user to answer first (SECRET-002, REQ-003), as the page's `held` prop. */
export type HeldMessage = { check: string; kind: string } & Record<
    string,
    unknown
>;

export type HeldActions = {
    /** Send the same text and files again with the user's answer (e.g. `{ confirm_decision: '1' }`). */
    resend: (reply: Record<string, string>) => void;
    /** Drop the question; the text stays in the box. */
    dismiss: () => void;
    processing: boolean;
};

/**
 * A chat-style textarea: Enter sends, Shift+Enter adds a line, and Up/Down
 * step through earlier prompts (like a terminal). With `attachments`, files
 * can be pasted, dropped, or picked, and are sent along as `attachments[]`.
 */
export default function PromptComposer({
    action,
    field,
    placeholder,
    value,
    onValueChange,
    footer,
    header,
    allowEmpty = false,
    autoFocus = false,
    size = 'default',
    disabled = false,
    disabledPlaceholder,
    extraData,
    working = false,
    onStop,
    history = [],
    attachments = false,
    incomingFiles = [],
    onIncomingFilesAdded,
    renderHeld,
}: {
    action: RouteDefinition<'post'>;
    field: string;
    placeholder: string;
    /** Control the text from outside (e.g. suggestion chips). */
    value?: string;
    onValueChange?: (value: string) => void;
    footer?: React.ReactNode;
    /** Shown above the text (e.g. the repository to import). */
    header?: React.ReactNode;
    /** Allow sending with no text (e.g. importing a repository, where the prompt is optional). */
    allowEmpty?: boolean;
    autoFocus?: boolean;
    size?: 'default' | 'large';
    /** Block sending (typing is still allowed). */
    disabled?: boolean;
    disabledPlaceholder?: string;
    /** Sent along with the text (e.g. the chosen model). */
    extraData?: Record<string, string | null>;
    /**
     * The agent is busy: Enter queues the message, ⌘/Ctrl+Enter sends it now
     * (interrupting), and an empty box shows a Stop button.
     */
    working?: boolean;
    onStop?: () => void;
    /** Earlier prompts, oldest first, for Up/Down recall. */
    history?: string[];
    /** Allow attaching files (AGT-006). */
    attachments?: boolean;
    /** Files to attach from outside (e.g. a marked-up picture of the preview, AGT-013); added once, then reported. */
    incomingFiles?: AttachedFile[];
    onIncomingFilesAdded?: () => void;
    /**
     * Show a message the server held (the page's `held` prop) above the box; without it, held messages aren't expected.
     * The text and files stay in the box until it's sent.
     */
    renderHeld?: (held: HeldMessage, actions: HeldActions) => React.ReactNode;
}) {
    const form = useForm<Record<string, string>>({ [field]: '' });
    const text = value ?? form.data[field];

    const setText = (next: string) => {
        form.setData(field, next);
        onValueChange?.(next);
    };

    const fileInput = useRef<HTMLInputElement>(null);
    const [picked, setPicked] = useState<PickedFile[]>([]);
    const [attachError, setAttachError] = useState<string | null>(null);
    const [dragging, setDragging] = useState(false);
    const [held, setHeld] = useState<HeldMessage | null>(null);
    // The answers given to the holds so far, sent along until the message goes through.
    const replies = useRef<Record<string, string>>({});
    const lastMode = useRef<SendMode>('auto');
    const canSend = allowEmpty || text.trim() !== '' || picked.length > 0;

    const addFiles = (items: (File | AttachedFile)[]) => {
        const added = items.map((item) =>
            item instanceof File ? { file: item } : item,
        );
        const files = added.map((item) => item.file);
        const tooBig = files.filter((file) => file.size > MAX_ATTACHMENT_BYTES);
        const room = MAX_ATTACHMENTS - picked.length;
        const accepted = added
            .filter((item) => item.file.size <= MAX_ATTACHMENT_BYTES)
            .slice(0, Math.max(room, 0));

        setAttachError(
            tooBig.length > 0
                ? `${tooBig.map((file) => file.name).join(', ')} is over 10 MB.`
                : files.length - tooBig.length > room
                  ? `You can attach up to ${MAX_ATTACHMENTS} files.`
                  : null,
        );
        setPicked((current) => [
            ...current,
            ...accepted.map(({ file, context }) => ({
                key: `${Date.now()}-${Math.random()}`,
                file,
                imageUrl: IMAGE_TYPES.includes(file.type)
                    ? URL.createObjectURL(file)
                    : null,
                context,
            })),
        ]);
    };

    useEffect(() => {
        if (attachments && incomingFiles.length > 0) {
            addFiles(incomingFiles);
            onIncomingFilesAdded?.();
        }
    }, [incomingFiles]);

    const removeFile = (key: string | number) => {
        setAttachError(null);
        setPicked((current) =>
            current.filter((item) => {
                if (item.key === key && item.imageUrl) {
                    URL.revokeObjectURL(item.imageUrl);
                }

                return item.key !== key;
            }),
        );
    };

    const clearFiles = () => {
        picked.forEach(
            (item) => item.imageUrl && URL.revokeObjectURL(item.imageUrl),
        );
        setPicked([]);
        setAttachError(null);
    };

    const onPaste = (event: ClipboardEvent<HTMLTextAreaElement>) => {
        const files = Array.from(event.clipboardData.files);

        if (!attachments || files.length === 0) {
            return;
        }

        // Keep pasted text (e.g. copied from a page along with an image); only files skip the textarea.
        if (!event.clipboardData.getData('text/plain')) {
            event.preventDefault();
        }

        addFiles(files);
    };

    const dragHandlers = attachments
        ? {
              onDragOver: (event: DragEvent) => {
                  if (event.dataTransfer.types.includes('Files')) {
                      event.preventDefault();
                      setDragging(true);
                  }
              },
              onDragLeave: (event: DragEvent) => {
                  if (
                      !event.currentTarget.contains(event.relatedTarget as Node)
                  ) {
                      setDragging(false);
                  }
              },
              onDrop: (event: DragEvent) => {
                  if (event.dataTransfer.files.length > 0) {
                      event.preventDefault();
                      addFiles(Array.from(event.dataTransfer.files));
                  }

                  setDragging(false);
              },
          }
        : {};

    /** Which earlier prompt is showing (null = none, the box is the user's). */
    const recallIndex = useRef<number | null>(null);

    const recallHistory = (
        event: KeyboardEvent<HTMLTextAreaElement>,
        step: -1 | 1,
    ): void => {
        const box = event.currentTarget;
        const index = recallIndex.current;
        const caretOnEdgeLine =
            box.selectionStart === box.selectionEnd &&
            (step === -1
                ? !text.slice(0, box.selectionStart).includes('\n')
                : !text.slice(box.selectionEnd).includes('\n'));

        if (history.length === 0 || !caretOnEdgeLine) {
            return;
        }

        if (index === null && (step === 1 || text !== '')) {
            // Only start browsing from an empty box, so typing is never lost.
            return;
        }

        event.preventDefault();
        const next = (index ?? history.length) + step;

        if (next < 0) {
            return;
        }

        if (next >= history.length) {
            recallIndex.current = null;
            setText('');

            return;
        }

        recallIndex.current = next;
        // Put the recalled text in the box now, then the caret at its end: done a frame later, the caret
        // jump would undo a quick next Up press (moving within a multi-line prompt) and lose it.
        flushSync(() => setText(history[next]));
        box.setSelectionRange(box.value.length, box.value.length);
    };

    const submit = (
        mode: SendMode = 'auto',
        reply?: Record<string, string>,
    ) => {
        if (!canSend || form.processing || disabled) {
            return;
        }

        lastMode.current = mode;
        replies.current =
            reply && held
                ? { ...replies.current, ...reply, check: held.check }
                : {};

        const agentContext = picked
            .map((item) => item.context)
            .filter(Boolean)
            .join('\n\n');

        form.transform(() => ({
            ...extraData,
            ...(agentContext ? { agent_context: agentContext } : {}),
            ...replies.current,
            [field]: text,
            ...(working ? { mode } : {}),
            ...(picked.length > 0
                ? { attachments: picked.map((item) => item.file) }
                : {}),
        }));
        form.submit(action, {
            preserveScroll: true,
            onSuccess: (page) => {
                const next = (page.props as { held?: HeldMessage | null }).held;

                if (next && renderHeld) {
                    setHeld(next);

                    return;
                }

                setHeld(null);
                replies.current = {};
                recallIndex.current = null;
                setText('');
                clearFiles();
            },
        });
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            submit(
                event.metaKey || event.ctrlKey
                    ? 'now'
                    : event.altKey
                      ? 'queue'
                      : 'auto',
            );
        } else if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
            recallHistory(event, event.key === 'ArrowUp' ? -1 : 1);
        }
    };

    return (
        <>
            {held &&
                renderHeld?.(held, {
                    resend: (reply) => submit(lastMode.current, reply),
                    dismiss: () => {
                        setHeld(null);
                        replies.current = {};
                    },
                    processing: form.processing,
                })}
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
                className={cn(
                    '@container rounded-2xl border border-input bg-card shadow-xs focus-within:ring-2 focus-within:ring-ring/30',
                    dragging && 'border-dashed border-ring ring-2 ring-ring/30',
                )}
                {...dragHandlers}
            >
                {picked.length > 0 && (
                    <MessageAttachments
                        attachments={picked.map((item) => ({
                            key: item.key,
                            name: item.file.name,
                            imageUrl: item.imageUrl,
                        }))}
                        onRemove={removeFile}
                        className="px-4 pt-4"
                    />
                )}
                {header}
                <label htmlFor={`composer-${field}`} className="sr-only">
                    {placeholder}
                </label>
                <textarea
                    id={`composer-${field}`}
                    name={field}
                    value={text}
                    onChange={(event) => {
                        recallIndex.current = null;
                        setText(event.target.value);
                    }}
                    onKeyDown={onKeyDown}
                    onPaste={onPaste}
                    placeholder={
                        disabled
                            ? (disabledPlaceholder ?? placeholder)
                            : placeholder
                    }
                    autoFocus={autoFocus}
                    rows={size === 'large' ? 3 : 2}
                    className={cn(
                        'block w-full resize-none bg-transparent px-4 pt-4 outline-none placeholder:text-muted-foreground',
                        size === 'large' ? 'text-lg' : 'text-sm',
                    )}
                />
                <div className="flex items-center justify-between gap-2 px-3 pb-3">
                    <div className="flex min-w-0 items-center gap-1 text-xs text-muted-foreground">
                        {attachments && (
                            <>
                                <button
                                    type="button"
                                    onClick={() => fileInput.current?.click()}
                                    aria-label="Attach files"
                                    title="Attach images or files (or paste or drop them)"
                                    data-test="composer-attach"
                                    className="flex size-7 shrink-0 items-center justify-center rounded-full hover:bg-muted hover:text-foreground"
                                >
                                    <Paperclip className="size-4" />
                                </button>
                                <input
                                    ref={fileInput}
                                    type="file"
                                    multiple
                                    hidden
                                    data-test="composer-file-input"
                                    onChange={(event) => {
                                        addFiles(
                                            Array.from(
                                                event.target.files ?? [],
                                            ),
                                        );
                                        event.target.value = '';
                                    }}
                                />
                            </>
                        )}
                        {footer}
                    </div>
                    <div className="flex shrink-0 items-center gap-1.5">
                        {working && canSend && (
                            <button
                                type="button"
                                onClick={() => submit('now')}
                                disabled={form.processing}
                                aria-label="Send now"
                                title="Send now: stop the agent and send this (⌘/Ctrl+Enter)"
                                data-test="composer-send-now"
                                className="flex size-8 items-center justify-center rounded-full border border-input text-foreground hover:bg-muted disabled:opacity-30"
                            >
                                <Zap className="size-4" />
                            </button>
                        )}
                        {working && !canSend && onStop ? (
                            <button
                                type="button"
                                onClick={onStop}
                                aria-label="Stop"
                                title="Stop the agent"
                                data-test="composer-stop"
                                className="flex size-8 items-center justify-center rounded-full bg-primary text-primary-foreground"
                            >
                                <Square className="size-3 fill-current" />
                            </button>
                        ) : (
                            <button
                                type="submit"
                                disabled={
                                    !canSend || form.processing || disabled
                                }
                                aria-label={working ? 'Queue' : 'Send'}
                                title={
                                    working
                                        ? 'Send: queued for when the agent finishes, or sent now if it changes what the agent is doing (Enter; ⌥/Alt+Enter always queues)'
                                        : 'Send (Enter)'
                                }
                                data-test="composer-send"
                                className="flex size-8 items-center justify-center rounded-full bg-primary text-primary-foreground transition-opacity disabled:opacity-30"
                            >
                                <ArrowUp className="size-4" />
                            </button>
                        )}
                    </div>
                </div>
                {(form.errors[field] ||
                    attachError ||
                    otherErrors(form.errors)) && (
                    <InputError
                        message={
                            form.errors[field] ??
                            attachError ??
                            otherErrors(form.errors)
                        }
                        className="px-4 pb-3"
                    />
                )}
            </form>
        </>
    );
}

/** The first server error about anything else sent (`attachments.N`, or extra data like `repository`). */
function otherErrors(errors: Record<string, string>): string | undefined {
    return Object.values(errors)[0];
}
