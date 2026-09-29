import { useForm } from '@inertiajs/react';
import type { RouteDefinition } from '@/wayfinder';
import { ArrowUp, Paperclip, Square, Zap } from 'lucide-react';
import { useRef, useState } from 'react';
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

type PickedFile = { key: string; file: File; imageUrl: string | null };

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
    autoFocus = false,
    size = 'default',
    disabled = false,
    disabledPlaceholder,
    extraData,
    working = false,
    onStop,
    history = [],
    attachments = false,
}: {
    action: RouteDefinition<'post'>;
    field: string;
    placeholder: string;
    /** Control the text from outside (e.g. suggestion chips). */
    value?: string;
    onValueChange?: (value: string) => void;
    footer?: React.ReactNode;
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
    const canSend = text.trim() !== '' || picked.length > 0;

    const addFiles = (files: File[]) => {
        const tooBig = files.filter((file) => file.size > MAX_ATTACHMENT_BYTES);
        const room = MAX_ATTACHMENTS - picked.length;
        const accepted = files
            .filter((file) => file.size <= MAX_ATTACHMENT_BYTES)
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
            ...accepted.map((file) => ({
                key: `${Date.now()}-${Math.random()}`,
                file,
                imageUrl: IMAGE_TYPES.includes(file.type)
                    ? URL.createObjectURL(file)
                    : null,
            })),
        ]);
    };

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

    const submit = (mode: 'queue' | 'now' = 'queue') => {
        if (!canSend || form.processing || disabled) {
            return;
        }

        form.transform(() => ({
            ...extraData,
            [field]: text,
            ...(working ? { mode } : {}),
            ...(picked.length > 0
                ? { attachments: picked.map((item) => item.file) }
                : {}),
        }));
        form.submit(action, {
            preserveScroll: true,
            onSuccess: () => {
                recallIndex.current = null;
                setText('');
                clearFiles();
            },
        });
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            submit(event.metaKey || event.ctrlKey ? 'now' : 'queue');
        } else if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
            recallHistory(event, event.key === 'ArrowUp' ? -1 : 1);
        }
    };

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                submit();
            }}
            className={cn(
                'rounded-2xl border border-input bg-card shadow-xs focus-within:ring-2 focus-within:ring-ring/30',
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
                                        Array.from(event.target.files ?? []),
                                    );
                                    event.target.value = '';
                                }}
                            />
                        </>
                    )}
                    {footer}
                </div>
                <div className="flex items-center gap-1.5">
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
                            disabled={!canSend || form.processing || disabled}
                            aria-label={working ? 'Queue' : 'Send'}
                            title={
                                working
                                    ? 'Queue: send when the agent finishes (Enter)'
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
                attachmentErrors(form.errors)) && (
                <InputError
                    message={
                        form.errors[field] ??
                        attachError ??
                        attachmentErrors(form.errors)
                    }
                    className="px-4 pb-3"
                />
            )}
        </form>
    );
}

/** The first server error about the attachments (`attachments` or `attachments.N`). */
function attachmentErrors(errors: Record<string, string>): string | undefined {
    return Object.entries(errors).find(([key]) =>
        key.startsWith('attachments'),
    )?.[1];
}
