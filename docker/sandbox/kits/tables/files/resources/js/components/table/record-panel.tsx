import {
    ChevronDown,
    ChevronRight,
    ChevronUp,
    Link as LinkIcon,
    LoaderCircle,
    MoreHorizontal,
    Trash2,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { cn } from '@/lib/utils';
import { request, TableRequestError } from './api';
import { UserChip } from './cells';
import { FIELD_ICONS } from './field-icons';
import { displayText, isEditable, parseText } from './format';
import { ValueInput } from './inputs';
import type { ActivityItem, Field, TableRecord, TableUser } from './types';
import { useTableStore } from './use-table';

/** Opens the record in the URL's `?record=<id>` once, when the table first shows. */
export function useRecordFromUrl(): void {
    const { recordsById, expand } = useTableStore();
    const handled = useRef(false);

    useEffect(() => {
        if (handled.current) {
            return;
        }

        handled.current = true;

        const id = Number(
            new URLSearchParams(window.location.search).get('record'),
        );

        if (Number.isInteger(id) && id > 0 && recordsById.has(id)) {
            expand(id);
        }
    }, [recordsById, expand]);
}

export function RecordPanel() {
    const store = useTableStore();
    const { expandedId, expand } = store;
    const record =
        expandedId === null ? undefined : store.recordsById.get(expandedId);

    useRecordFromUrl();

    // Someone deleted the open record: close the panel.
    useEffect(() => {
        if (expandedId !== null && !record) {
            expand(null);
        }
    }, [expandedId, record, expand]);

    return (
        <DialogPrimitive.Root
            open={record !== undefined}
            onOpenChange={(open) => {
                if (!open) {
                    expand(null);
                }
            }}
        >
            <DialogPrimitive.Portal>
                {/* A light overlay, so the table stays readable behind the panel. */}
                <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-black/20 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0 dark:bg-black/50" />
                <DialogPrimitive.Content
                    className="fixed inset-y-0 right-0 z-50 flex h-full w-full flex-col border-l border-neutral-200 bg-white shadow-xl outline-none data-[state=closed]:animate-out data-[state=closed]:slide-out-to-right data-[state=open]:animate-in data-[state=open]:slide-in-from-right sm:max-w-[560px] dark:border-neutral-800 dark:bg-neutral-950"
                    onOpenAutoFocus={(event) => {
                        // Focus the panel itself, not its first field.
                        event.preventDefault();
                        (event.target as HTMLElement | null)?.focus();
                    }}
                >
                    {record && <RecordBody key={record.id} record={record} />}
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}

function inTextField(target: EventTarget | null): boolean {
    return (
        target instanceof HTMLElement &&
        (target.isContentEditable ||
            ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) ||
            target.getAttribute('role') === 'combobox')
    );
}

function RecordBody({ record }: { record: TableRecord }) {
    const store = useTableStore();
    const [showHidden, setShowHidden] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [copied, setCopied] = useState(false);
    const activity = useActivity(record);
    const primary = store.fields.find((field) => field.primary);
    const title = primary
        ? displayText(primary, record.values[primary.key], store.context)
        : '';
    const { rows, expand } = store;
    const index = rows.findIndex((row) => row.id === record.id);
    const hidden = new Set(store.view.config.hidden);
    const shownFields = store.orderedFields.filter(
        (field) => !field.primary && !hidden.has(field.key),
    );
    const hiddenFields = store.orderedFields.filter(
        (field) => !field.primary && hidden.has(field.key),
    );

    const step = (by: number) => {
        const next = store.rows[index + by];

        if (index !== -1 && next) {
            store.expand(next.id);
        }
    };

    // ↑/↓ or k/j step through the view's records, unless typing or in a menu.
    useEffect(() => {
        const onKeyDown = (event: globalThis.KeyboardEvent) => {
            if (
                confirming ||
                event.defaultPrevented ||
                event.metaKey ||
                event.ctrlKey ||
                event.altKey ||
                inTextField(event.target) ||
                (event.target instanceof HTMLElement &&
                    event.target.closest('[role="menu"], [role="listbox"]'))
            ) {
                return;
            }

            const by =
                event.key === 'ArrowUp' || event.key === 'k'
                    ? -1
                    : event.key === 'ArrowDown' || event.key === 'j'
                      ? 1
                      : 0;
            const next = index === -1 ? undefined : rows[index + by];

            if (by !== 0 && next) {
                event.preventDefault();
                expand(next.id);
            }
        };

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, [confirming, index, rows, expand]);

    const copyLink = () => {
        const url = new URL(window.location.href);

        url.searchParams.set('record', String(record.id));
        void navigator.clipboard?.writeText(url.toString()).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        });
    };

    return (
        <div className="flex h-full min-h-0 flex-col">
            <DialogPrimitive.Title className="sr-only">
                {title || 'Untitled'}
            </DialogPrimitive.Title>
            <DialogPrimitive.Description className="sr-only">
                The record’s fields, comments and history.
            </DialogPrimitive.Description>
            <header className="flex flex-col gap-2 border-b border-neutral-200 px-5 pt-3 pb-3 dark:border-neutral-800">
                <div className="flex items-center gap-1 pr-8 text-xs text-neutral-500">
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        aria-label="Previous record"
                        disabled={index <= 0}
                        onClick={() => step(-1)}
                    >
                        <ChevronUp className="size-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        aria-label="Next record"
                        disabled={
                            index === -1 || index >= store.rows.length - 1
                        }
                        onClick={() => step(1)}
                    >
                        <ChevronDown className="size-4" />
                    </Button>
                    <span className="ml-1 tabular-nums">
                        {index === -1
                            ? 'Not in this view'
                            : `Record ${index + 1} of ${store.rows.length} in this view`}
                    </span>
                    <span className="flex-1" />
                    {copied && (
                        <span className="text-xs text-green-600 dark:text-green-400">
                            Link copied
                        </span>
                    )}
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-7"
                                aria-label="Record actions"
                            >
                                <MoreHorizontal className="size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem onSelect={copyLink}>
                                <LinkIcon className="size-4" />
                                Copy link
                            </DropdownMenuItem>
                            {store.can.delete && (
                                <>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        className="text-red-600 focus:text-red-600 dark:text-red-400"
                                        onSelect={() => setConfirming(true)}
                                    >
                                        <Trash2 className="size-4" />
                                        Delete record
                                    </DropdownMenuItem>
                                </>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
                {primary && <TitleEditor field={primary} record={record} />}
            </header>
            <div className="min-h-0 flex-1 overflow-y-auto">
                <div className="flex flex-col gap-5 px-5 py-5">
                    {shownFields.map((field) => (
                        <FieldRow
                            key={field.key}
                            field={field}
                            record={record}
                        />
                    ))}
                    {hiddenFields.length > 0 && (
                        <div className="flex flex-col gap-5">
                            <button
                                type="button"
                                aria-expanded={showHidden}
                                className="flex items-center gap-1 self-start rounded px-1 py-0.5 text-xs font-medium text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                                onClick={() => setShowHidden(!showHidden)}
                            >
                                {showHidden ? (
                                    <ChevronDown className="size-3.5" />
                                ) : (
                                    <ChevronRight className="size-3.5" />
                                )}
                                {hiddenFields.length} hidden in this view
                            </button>
                            {showHidden &&
                                hiddenFields.map((field) => (
                                    <FieldRow
                                        key={field.key}
                                        field={field}
                                        record={record}
                                    />
                                ))}
                        </div>
                    )}
                </div>
                <Activity activity={activity} />
            </div>
            {store.can.comment && (
                <CommentBox record={record} onPosted={activity.add} />
            )}
            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Delete this record?</DialogTitle>
                        <DialogDescription>
                            “{title || 'Untitled'}” will be deleted for
                            everyone. You can undo it right after.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="outline">Cancel</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                setConfirming(false);
                                void store.deleteRecords([record.id]);
                            }}
                        >
                            Delete record
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

/** The primary field's value as the panel's heading, edited in place when it's text. */
function TitleEditor({ field, record }: { field: Field; record: TableRecord }) {
    const store = useTableStore();
    const [draft, setDraft] = useState<string | null>(null);
    const value = record.values[field.key];
    const text = displayText(field, value, store.context);
    const editable =
        isEditable(field) &&
        store.can.edit &&
        ['text', 'longText', 'url', 'email', 'phone', 'number'].includes(
            field.type,
        );

    if (!editable) {
        return (
            <h2
                className={cn(
                    'truncate text-lg font-semibold',
                    !text && 'text-neutral-400',
                )}
            >
                {text || 'Untitled'}
            </h2>
        );
    }

    const commit = () => {
        if (draft === null) {
            return;
        }

        const parsed = parseText(field, draft, store.context);

        setDraft(null);

        if (parsed.ok && parsed.value !== (value ?? null)) {
            store.setCell(record.id, field.key, parsed.value);
        }
    };

    return (
        <input
            aria-label={field.name}
            placeholder="Untitled"
            className="-mx-1.5 rounded-md border border-transparent bg-transparent px-1.5 py-0.5 text-lg font-semibold outline-none placeholder:text-neutral-400 hover:border-neutral-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:hover:border-neutral-800"
            value={draft ?? (typeof value === 'number' ? String(value) : text)}
            onChange={(event) => setDraft(event.target.value)}
            onBlur={commit}
            onKeyDown={(event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    commit();
                    event.currentTarget.blur();
                } else if (event.key === 'Escape' && draft !== null) {
                    event.stopPropagation();
                    setDraft(null);
                }
            }}
        />
    );
}

function FieldRow({ field, record }: { field: Field; record: TableRecord }) {
    const store = useTableStore();
    const Icon = FIELD_ICONS[field.type];
    const id = `record-field-${field.key}`;

    return (
        <div className="flex min-w-0 flex-col gap-1.5">
            <div className="flex min-w-0 flex-col">
                <span
                    id={id}
                    className="flex items-center gap-1.5 text-xs font-medium text-neutral-600 dark:text-neutral-400"
                    title={field.description ?? undefined}
                >
                    <Icon className="size-3.5 shrink-0 text-neutral-400" />
                    <span className="truncate">{field.name}</span>
                </span>
                {field.description && (
                    <span className="line-clamp-2 pl-5 text-xs text-neutral-400">
                        {field.description}
                    </span>
                )}
            </div>
            <div role="group" aria-labelledby={id} className="min-w-0">
                <ValueInput
                    field={field}
                    value={record.values[field.key] ?? null}
                    error={record.errors?.[field.key]}
                    variant="form"
                    onChange={(value) =>
                        store.setCell(record.id, field.key, value)
                    }
                />
            </div>
        </div>
    );
}

/* Comments and history */

const relative = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
const absolute = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

const UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 365 * 24 * 3600],
    ['month', 30 * 24 * 3600],
    ['week', 7 * 24 * 3600],
    ['day', 24 * 3600],
    ['hour', 3600],
    ['minute', 60],
];

export function relativeTime(iso: string): string {
    const seconds = (new Date(iso).getTime() - Date.now()) / 1000;

    if (Number.isNaN(seconds)) {
        return '';
    }

    for (const [unit, size] of UNITS) {
        if (Math.abs(seconds) >= size) {
            return relative.format(Math.round(seconds / size), unit);
        }
    }

    return 'just now';
}

interface ActivityState {
    items: ActivityItem[] | null;
    failed: boolean;
    add: (item: ActivityItem) => void;
    remove: (item: ActivityItem) => void;
}

/** The record's comments and history: fetched on open, and again whenever the record changes. */
function useActivity(record: TableRecord): ActivityState {
    const store = useTableStore();
    const [items, setItems] = useState<ActivityItem[] | null>(null);
    const [failed, setFailed] = useState(false);
    const url = `${store.endpoint}/records/${record.id}/activity`;

    useEffect(() => {
        let cancelled = false;

        request<{ items: ActivityItem[] }>('GET', url)
            .then((result) => {
                if (!cancelled) {
                    setItems(result.items);
                    setFailed(false);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setFailed(true);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [url, record.updatedAt]);

    const add = (item: ActivityItem) =>
        setItems((current) => [...(current ?? []), item]);

    const remove = (item: ActivityItem) => {
        if (item.commentId === undefined) {
            return;
        }

        setItems((current) =>
            (current ?? []).filter((candidate) => candidate.id !== item.id),
        );
        request('DELETE', `${store.endpoint}/comments/${item.commentId}`).catch(
            (failure: unknown) => {
                add(item);
                store.setError(
                    failure instanceof TableRequestError
                        ? failure.firstErrors().join(' ')
                        : "Couldn't delete the comment.",
                );
            },
        );
    };

    return { items, failed, add, remove };
}

function Activity({ activity }: { activity: ActivityState }) {
    const store = useTableStore();
    const { items, failed, remove } = activity;
    const sorted = useMemo(
        () =>
            [...(items ?? [])].sort((a, b) =>
                a.createdAt.localeCompare(b.createdAt),
            ),
        [items],
    );

    return (
        <section
            aria-label="Comments and history"
            className="border-t border-neutral-200 bg-neutral-50/60 px-5 py-4 dark:border-neutral-800 dark:bg-neutral-900/40"
        >
            <h3 className="pb-3 text-xs font-semibold tracking-wide text-neutral-500 uppercase">
                Comments and history
            </h3>
            {items === null && !failed && (
                <p className="flex items-center gap-2 text-xs text-neutral-500">
                    <LoaderCircle className="size-3.5 animate-spin" />
                    Loading…
                </p>
            )}
            {failed && items === null && (
                <p className="text-xs text-neutral-500">
                    Couldn’t load the history.
                </p>
            )}
            {items !== null && sorted.length === 0 && (
                <p className="text-xs text-neutral-500">No activity yet.</p>
            )}
            <ol className="flex flex-col gap-3">
                {sorted.map((item) =>
                    item.kind === 'comment' ? (
                        <Comment
                            key={item.id}
                            item={item}
                            onDelete={
                                item.user?.id === store.me
                                    ? () => remove(item)
                                    : undefined
                            }
                        />
                    ) : (
                        <HistoryLine key={item.id} item={item} />
                    ),
                )}
            </ol>
        </section>
    );
}

function asUser(user: ActivityItem['user']): TableUser | undefined {
    return user
        ? { id: user.id, name: user.name, email: '', avatar: user.avatar }
        : undefined;
}

function Time({ iso }: { iso: string }) {
    const date = new Date(iso);

    return (
        <time
            dateTime={iso}
            title={
                Number.isNaN(date.getTime()) ? undefined : absolute.format(date)
            }
            className="text-neutral-400"
        >
            {relativeTime(iso)}
        </time>
    );
}

/** A comment's text with @Name mentions of the table's people highlighted. */
function MentionText({ body }: { body: string }) {
    const store = useTableStore();
    const names = useMemo(
        () =>
            store.users
                .map((user) => user.name)
                .filter((name) => name !== '')
                .sort((a, b) => b.length - a.length),
        [store.users],
    );

    if (names.length === 0 || !body.includes('@')) {
        return <>{body}</>;
    }

    const escaped = names.map((name) =>
        name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'),
    );
    const pattern = new RegExp(`(@(?:${escaped.join('|')}))`, 'g');
    const parts: ReactNode[] = body.split(pattern).map((part, index) =>
        index % 2 === 1 ? (
            <span
                key={index}
                className="rounded bg-blue-50 px-0.5 font-medium text-blue-700 dark:bg-blue-950 dark:text-blue-300"
            >
                {part}
            </span>
        ) : (
            part
        ),
    );

    return <>{parts}</>;
}

function Comment({
    item,
    onDelete,
}: {
    item: ActivityItem;
    onDelete?: () => void;
}) {
    return (
        <li className="group flex gap-2.5">
            <span className="pt-0.5">
                <UserChip user={asUser(item.user)} compact />
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-1 rounded-lg border border-neutral-200 bg-white px-3 py-2 dark:border-neutral-800 dark:bg-neutral-950">
                <div className="flex items-center gap-2 text-xs">
                    <span className="font-medium text-neutral-900 dark:text-neutral-100">
                        {item.user?.name ?? 'Someone'}
                    </span>
                    <Time iso={item.createdAt} />
                    <span className="flex-1" />
                    {onDelete && (
                        <button
                            type="button"
                            aria-label="Delete comment"
                            className="rounded p-0.5 text-neutral-400 opacity-0 transition-opacity group-hover:opacity-100 hover:text-red-600 focus-visible:opacity-100"
                            onClick={onDelete}
                        >
                            <Trash2 className="size-3.5" />
                        </button>
                    )}
                </div>
                <p className="text-sm break-words whitespace-pre-wrap text-neutral-800 dark:text-neutral-200">
                    <MentionText body={item.body ?? ''} />
                </p>
            </div>
        </li>
    );
}

function HistoryLine({ item }: { item: ActivityItem }) {
    const who = item.user?.name ?? 'Someone';
    const strong = (text: ReactNode) => (
        <span className="font-medium text-neutral-700 dark:text-neutral-300">
            {text}
        </span>
    );
    let text: ReactNode;

    if (item.kind === 'created') {
        text = <>{strong(who)} created this record</>;
    } else if (item.kind === 'deleted') {
        text = <>{strong(who)} deleted this record</>;
    } else {
        const field = strong(item.fieldName ?? item.field ?? 'a field');
        const from = item.from ?? '';
        const to = item.to ?? '';

        if (from === '' && to !== '') {
            text = (
                <>
                    {strong(who)} set {field} to {strong(to)}
                </>
            );
        } else if (to === '' && from !== '') {
            text = (
                <>
                    {strong(who)} cleared {field} (was {strong(from)})
                </>
            );
        } else if (from === '' && to === '') {
            text = (
                <>
                    {strong(who)} changed {field}
                </>
            );
        } else {
            text = (
                <>
                    {strong(who)} changed {field} from {strong(from)} to{' '}
                    {strong(to)}
                </>
            );
        }
    }

    return (
        <li className="flex items-start gap-2.5 text-xs text-neutral-500">
            <span className="mt-1.5 size-1.5 shrink-0 rounded-full bg-neutral-300 dark:bg-neutral-700" />
            <p className="min-w-0 break-words">
                {text} · <Time iso={item.createdAt} />
            </p>
        </li>
    );
}

function CommentBox({
    record,
    onPosted,
}: {
    record: TableRecord;
    onPosted: (item: ActivityItem) => void;
}) {
    const store = useTableStore();
    const [body, setBody] = useState('');
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const send = async () => {
        const text = body.trim();

        if (text === '' || sending) {
            return;
        }

        setSending(true);
        setError(null);

        try {
            const result = await request<{ item: ActivityItem }>(
                'POST',
                `${store.endpoint}/records/${record.id}/comments`,
                { body: text },
            );

            setBody('');
            onPosted(result.item);
        } catch (failure) {
            setError(
                failure instanceof TableRequestError
                    ? failure.firstErrors().join(' ')
                    : "Couldn't post the comment.",
            );
        } finally {
            setSending(false);
        }
    };

    return (
        <div className="flex flex-col gap-2 border-t border-neutral-200 bg-white px-5 py-3 dark:border-neutral-800 dark:bg-neutral-950">
            <textarea
                aria-label="Comment"
                rows={2}
                placeholder="Leave a comment… Use @ to mention someone."
                className="w-full resize-none rounded-md border border-neutral-200 bg-white px-2.5 py-1.5 text-sm outline-none placeholder:text-neutral-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-neutral-800 dark:bg-neutral-950"
                value={body}
                onChange={(event) => setBody(event.target.value)}
                onKeyDown={(event) => {
                    if (
                        event.key === 'Enter' &&
                        (event.metaKey || event.ctrlKey)
                    ) {
                        event.preventDefault();
                        void send();
                    }
                }}
            />
            <div className="flex items-center gap-2">
                {error && (
                    <p className="text-xs text-red-600 dark:text-red-400">
                        {error}
                    </p>
                )}
                <span className="flex-1" />
                <span className="hidden text-xs text-neutral-400 sm:inline">
                    ⌘↵ to send
                </span>
                <Button
                    size="sm"
                    disabled={body.trim() === '' || sending}
                    onClick={() => void send()}
                >
                    {sending && (
                        <LoaderCircle className="size-3.5 animate-spin" />
                    )}
                    Comment
                </Button>
            </div>
        </div>
    );
}
