import {
    Calendar,
    Check,
    ChevronDown,
    Copy,
    ExternalLink,
    EyeOff,
    GripVertical,
    Plus,
    Share2,
    Star,
    Upload,
    X,
} from 'lucide-react';
import { useState } from 'react';
import type { DragEvent, KeyboardEvent, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';
import { request, TableRequestError } from './api';
import { FIELD_ICONS } from './field-icons';
import { isEditable } from './format';
import { Popover, PopoverContent, PopoverTrigger } from './popover';
import type { Field, FormConfig, FormField, TableView } from './types';
import { useTableStore } from './use-table';

/** Field types a public form leaves out: they'd show the app's people and records. */
export const PRIVATE_FORM_TYPES: Field['type'][] = ['user', 'link'];

/** A form view's settings before anyone has changed them. */
export function defaultForm(title: string): FormConfig {
    return {
        title,
        description: '',
        fields: [],
        submitLabel: 'Submit',
        thankYou: 'Thanks! Your answers were sent.',
        allowAnother: true,
        public: false,
    };
}

/** A path from the server as a full link, for sharing. */
function absoluteUrl(url: string): string {
    if (typeof window === 'undefined') {
        return url;
    }

    return new URL(url, window.location.origin).href;
}

const textInputClass =
    'h-8 w-full min-w-0 rounded-md border border-neutral-200 bg-white px-2.5 text-sm text-neutral-900 shadow-xs outline-none transition-colors placeholder:text-neutral-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-neutral-800 dark:bg-neutral-950 dark:text-neutral-100';

/**
 * A form view (TABLE-009): the form as people will see it, to build in place. Pick the fields from the side
 * panel (click or drag them in), drag to reorder, click a field for its help line and whether it's required.
 * Read-only for people who can't change the view.
 */
export function FormView() {
    const store = useTableStore();
    const { view } = store;
    const mayEdit = view.personal || store.can.manageViews;
    const form = view.config.form ?? defaultForm(store.name);
    const [selected, setSelected] = useState<string | null>(null);
    const [dragKey, setDragKey] = useState<string | null>(null);
    const [dropIndex, setDropIndex] = useState<number | null>(null);

    // Fields deleted or made read-only since they were added drop off the form.
    const entries = form.fields.flatMap((item) => {
        const field = store.fieldsByKey.get(item.key);

        return field && isEditable(field) ? [{ item, field }] : [];
    });
    const items = entries.map((entry) => entry.item);
    const onForm = new Set(items.map((item) => item.key));
    const available = store.orderedFields.filter(
        (field) => isEditable(field) && !onForm.has(field.key),
    );

    const save = (patch: Partial<FormConfig>) => {
        store.updateView({ form: { ...form, ...patch } });
    };

    /** Puts a field at a position (before the item there), adding it when it isn't on the form yet. */
    const place = (key: string, index: number) => {
        const from = items.findIndex((item) => item.key === key);
        const rest = items.filter((item) => item.key !== key);
        const at = from !== -1 && from < index ? index - 1 : index;

        rest.splice(at, 0, items[from] ?? { key, required: false, help: '' });
        save({ fields: rest });
    };

    const remove = (key: string) => {
        save({ fields: items.filter((item) => item.key !== key) });
        setSelected((current) => (current === key ? null : current));
    };

    const updateItem = (key: string, patch: Partial<FormField>) => {
        save({
            fields: items.map((item) =>
                item.key === key ? { ...item, ...patch } : item,
            ),
        });
    };

    const endDrag = () => {
        setDragKey(null);
        setDropIndex(null);
    };

    const dragStart = (event: DragEvent<HTMLElement>, key: string) => {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', key);
        setDragKey(key);
    };

    const dragOverField = (event: DragEvent<HTMLElement>, index: number) => {
        if (dragKey === null) {
            return;
        }

        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        const box = event.currentTarget.getBoundingClientRect();
        const next =
            event.clientY > box.top + box.height / 2 ? index + 1 : index;

        if (next !== dropIndex) {
            setDropIndex(next);
        }
    };

    const dropOnForm = (event: DragEvent<HTMLElement>) => {
        if (dragKey === null) {
            return;
        }

        event.preventDefault();
        place(dragKey, dropIndex ?? items.length);
        endDrag();
    };

    const indicator = (index: number) =>
        dragKey !== null && dropIndex === index ? (
            <div aria-hidden className="-my-1 h-0.5 rounded-full bg-blue-500" />
        ) : null;

    return (
        <div className="flex h-full min-h-0 flex-col">
            <div className="flex shrink-0 flex-wrap items-center justify-between gap-2 border-b border-neutral-200 px-3 py-2 dark:border-neutral-800">
                <p className="text-xs text-neutral-500">
                    {mayEdit
                        ? 'Click a field to change it. Drag to reorder.'
                        : 'Only people who manage views can change this form.'}
                </p>
                <div className="flex items-center gap-2">
                    <SharePopover view={view} form={form} mayEdit={mayEdit} />
                    {view.formUrl ? (
                        <Button variant="outline" size="sm" asChild>
                            <a
                                href={view.formUrl}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <ExternalLink />
                                Open form
                            </a>
                        </Button>
                    ) : (
                        <Button variant="outline" size="sm" disabled>
                            <ExternalLink />
                            Open form
                        </Button>
                    )}
                </div>
            </div>
            <div className="flex min-h-0 flex-1 flex-col md:flex-row">
                <div
                    className="min-h-0 flex-1 overflow-y-auto bg-neutral-50 px-4 py-8 dark:bg-neutral-900/40"
                    onClick={(event) => {
                        if (event.target === event.currentTarget) {
                            setSelected(null);
                        }
                    }}
                >
                    <div className="mx-auto flex w-full max-w-xl flex-col gap-6 rounded-xl border border-neutral-200 bg-white p-6 shadow-sm sm:p-8 dark:border-neutral-800 dark:bg-neutral-950">
                        <div className="flex flex-col gap-1">
                            {mayEdit ? (
                                <>
                                    <input
                                        value={form.title}
                                        aria-label="Form title"
                                        placeholder="Form title"
                                        onChange={(event) =>
                                            save({ title: event.target.value })
                                        }
                                        className="-mx-1.5 rounded-md border border-transparent px-1.5 py-0.5 text-2xl font-semibold text-neutral-900 outline-none placeholder:text-neutral-300 hover:border-neutral-200 focus:border-blue-500 dark:text-neutral-100 dark:hover:border-neutral-800"
                                    />
                                    <textarea
                                        value={form.description}
                                        aria-label="Form description"
                                        placeholder="Add a description"
                                        rows={1}
                                        onChange={(event) =>
                                            save({
                                                description: event.target.value,
                                            })
                                        }
                                        className="-mx-1.5 field-sizing-content min-h-8 resize-none rounded-md border border-transparent px-1.5 py-1 text-sm text-neutral-600 outline-none placeholder:text-neutral-400 hover:border-neutral-200 focus:border-blue-500 dark:text-neutral-400 dark:hover:border-neutral-800"
                                    />
                                </>
                            ) : (
                                <>
                                    <h2 className="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">
                                        {form.title || 'Untitled form'}
                                    </h2>
                                    {form.description && (
                                        <p className="text-sm whitespace-pre-line text-neutral-600 dark:text-neutral-400">
                                            {form.description}
                                        </p>
                                    )}
                                </>
                            )}
                        </div>
                        <div
                            className="flex flex-col gap-3"
                            onDragOver={(event) => {
                                if (dragKey === null) {
                                    return;
                                }

                                event.preventDefault();

                                if (event.target === event.currentTarget) {
                                    setDropIndex(items.length);
                                }
                            }}
                            onDrop={dropOnForm}
                        >
                            {entries.map(({ item, field }, index) => (
                                <div key={field.key} className="contents">
                                    {indicator(index)}
                                    <FormFieldCard
                                        field={field}
                                        item={item}
                                        mayEdit={mayEdit}
                                        selected={selected === field.key}
                                        dragging={dragKey === field.key}
                                        onSelect={() => setSelected(field.key)}
                                        onChange={(patch) =>
                                            updateItem(field.key, patch)
                                        }
                                        onRemove={() => remove(field.key)}
                                        onMove={(offset) => {
                                            const target = index + offset;

                                            if (
                                                target >= 0 &&
                                                target < items.length
                                            ) {
                                                place(
                                                    field.key,
                                                    offset > 0
                                                        ? target + 1
                                                        : target,
                                                );
                                            }
                                        }}
                                        onDragStart={(event) =>
                                            dragStart(event, field.key)
                                        }
                                        onDragOver={(event) =>
                                            dragOverField(event, index)
                                        }
                                        onDragEnd={endDrag}
                                    />
                                </div>
                            ))}
                            {indicator(items.length)}
                            {entries.length === 0 && (
                                <div
                                    className={cn(
                                        'flex flex-col items-center gap-1 rounded-lg border border-dashed border-neutral-300 px-4 py-10 text-center text-sm text-neutral-500 dark:border-neutral-700',
                                        dragKey !== null &&
                                            'border-blue-400 bg-blue-50/60 dark:border-blue-600 dark:bg-blue-950/40',
                                    )}
                                    onDragOver={(event) => {
                                        if (dragKey !== null) {
                                            event.preventDefault();
                                            setDropIndex(0);
                                        }
                                    }}
                                >
                                    <span className="font-medium text-neutral-700 dark:text-neutral-300">
                                        This form has no fields yet
                                    </span>
                                    {mayEdit && (
                                        <span>
                                            Add fields from the list, or drag
                                            them here.
                                        </span>
                                    )}
                                </div>
                            )}
                        </div>
                        <div>
                            <span
                                aria-hidden
                                className="inline-flex h-9 items-center rounded-md bg-neutral-900 px-4 text-sm font-medium text-white dark:bg-neutral-100 dark:text-neutral-900"
                            >
                                {form.submitLabel || 'Submit'}
                            </span>
                        </div>
                    </div>
                </div>
                {mayEdit && (
                    <FormSidePanel
                        form={form}
                        available={available}
                        dragKey={dragKey}
                        hasFields={entries.length > 0}
                        onSave={save}
                        onAdd={(key) => place(key, items.length)}
                        onAddAll={() =>
                            save({
                                fields: [
                                    ...items,
                                    ...available.map((field) => ({
                                        key: field.key,
                                        required: false,
                                        help: '',
                                    })),
                                ],
                            })
                        }
                        onRemoveAll={() => {
                            save({ fields: [] });
                            setSelected(null);
                        }}
                        onDragStart={dragStart}
                        onDragEnd={endDrag}
                        onDropRemove={() => {
                            if (dragKey !== null && onForm.has(dragKey)) {
                                remove(dragKey);
                            }

                            endDrag();
                        }}
                    />
                )}
            </div>
        </div>
    );
}

function FormFieldCard({
    field,
    item,
    mayEdit,
    selected,
    dragging,
    onSelect,
    onChange,
    onRemove,
    onMove,
    onDragStart,
    onDragOver,
    onDragEnd,
}: {
    field: Field;
    item: FormField;
    mayEdit: boolean;
    selected: boolean;
    dragging: boolean;
    onSelect: () => void;
    onChange: (patch: Partial<FormField>) => void;
    onRemove: () => void;
    onMove: (offset: number) => void;
    onDragStart: (event: DragEvent<HTMLElement>) => void;
    onDragOver: (event: DragEvent<HTMLElement>) => void;
    onDragEnd: () => void;
}) {
    const Icon = FIELD_ICONS[field.type];
    const helpId = `form-help-${field.key}`;

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.target !== event.currentTarget || !mayEdit) {
            return;
        }

        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            onSelect();
        } else if (event.altKey && event.key === 'ArrowUp') {
            event.preventDefault();
            onMove(-1);
        } else if (event.altKey && event.key === 'ArrowDown') {
            event.preventDefault();
            onMove(1);
        }
    };

    return (
        <div
            data-form-field={field.key}
            role={mayEdit ? 'group' : undefined}
            aria-label={mayEdit ? `Form field: ${field.name}` : undefined}
            tabIndex={mayEdit ? 0 : undefined}
            draggable={mayEdit}
            onDragStart={onDragStart}
            onDragOver={onDragOver}
            onDragEnd={onDragEnd}
            onClick={mayEdit ? onSelect : undefined}
            onKeyDown={onKeyDown}
            className={cn(
                'group relative flex flex-col gap-1.5 rounded-lg border p-3 transition-colors outline-none',
                mayEdit &&
                    'cursor-pointer focus-visible:ring-2 focus-visible:ring-blue-500/40',
                selected
                    ? 'border-blue-500 ring-2 ring-blue-500/20'
                    : 'border-transparent hover:border-neutral-200 dark:hover:border-neutral-800',
                dragging && 'opacity-50',
            )}
        >
            <div className="flex items-start gap-1.5">
                {mayEdit && (
                    <GripVertical
                        aria-hidden
                        className="mt-0.5 -ml-1 size-4 shrink-0 cursor-grab text-neutral-300 opacity-0 group-hover:opacity-100 dark:text-neutral-600"
                    />
                )}
                <div className="flex min-w-0 flex-1 items-center gap-1.5 text-sm font-medium text-neutral-900 dark:text-neutral-100">
                    <Icon
                        aria-hidden
                        className="size-3.5 shrink-0 text-neutral-400"
                    />
                    <span className="truncate">{field.name}</span>
                    {item.required && (
                        <span
                            className="text-red-600 dark:text-red-400"
                            title="Required"
                        >
                            *
                        </span>
                    )}
                </div>
                {mayEdit && (
                    <button
                        type="button"
                        aria-label={`Remove ${field.name} from the form`}
                        className={cn(
                            'flex size-6 shrink-0 items-center justify-center rounded text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700 focus-visible:opacity-100 dark:hover:bg-neutral-800 dark:hover:text-neutral-200',
                            selected
                                ? 'opacity-100'
                                : 'opacity-0 group-hover:opacity-100',
                        )}
                        onClick={(event) => {
                            event.stopPropagation();
                            onRemove();
                        }}
                    >
                        <X className="size-4" />
                    </button>
                )}
            </div>
            {selected && mayEdit ? (
                <input
                    id={helpId}
                    value={item.help}
                    autoFocus
                    aria-label={`Help for ${field.name}`}
                    placeholder="Add a help line"
                    onChange={(event) => onChange({ help: event.target.value })}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter' || event.key === 'Escape') {
                            event.preventDefault();
                            event.currentTarget
                                .closest<HTMLElement>('[data-form-field]')
                                ?.focus();
                        }
                    }}
                    className={cn(textInputClass, 'h-7 text-xs')}
                />
            ) : (
                item.help && (
                    <p className="text-xs whitespace-pre-line text-neutral-500">
                        {item.help}
                    </p>
                )
            )}
            <FieldPreview field={field} />
            {selected && mayEdit && (
                <label
                    className="flex w-fit items-center gap-2 text-xs text-neutral-700 dark:text-neutral-300"
                    onClick={(event) => event.stopPropagation()}
                >
                    <Checkbox
                        checked={item.required}
                        onCheckedChange={(checked) =>
                            onChange({ required: checked === true })
                        }
                    />
                    Required
                </label>
            )}
            {PRIVATE_FORM_TYPES.includes(field.type) && (
                <p className="flex items-center gap-1.5 text-xs text-amber-700 dark:text-amber-400">
                    <EyeOff aria-hidden className="size-3.5" />
                    Not shown on the public form
                </p>
            )}
        </div>
    );
}

/** What a field's input will look like on the form (not usable here). */
function FieldPreview({ field }: { field: Field }) {
    const box =
        'flex h-8 w-full items-center gap-2 rounded-md border border-neutral-200 bg-white px-2.5 text-sm text-neutral-400 shadow-xs dark:border-neutral-800 dark:bg-neutral-950';
    let content: ReactNode;

    switch (field.type) {
        case 'longText':
            content = <div className={cn(box, 'h-16')} />;
            break;
        case 'checkbox':
            content = (
                <span className="inline-flex h-5 w-9 items-center rounded-full bg-neutral-300 dark:bg-neutral-700">
                    <span className="ml-0.5 inline-block size-4 rounded-full bg-white shadow" />
                </span>
            );
            break;
        case 'rating':
            content = (
                <div className="flex h-8 items-center gap-0.5 text-neutral-300 dark:text-neutral-700">
                    {Array.from({ length: field.options.max ?? 5 }, (_, i) => (
                        <Star key={i} className="size-4.5" />
                    ))}
                </div>
            );
            break;
        case 'attachment':
            content = (
                <div className="flex h-16 w-full items-center justify-center gap-2 rounded-md border border-dashed border-neutral-300 text-sm text-neutral-400 dark:border-neutral-700">
                    <Upload className="size-4" />
                    Add files
                </div>
            );
            break;
        case 'date':
            content = (
                <div className={box}>
                    <Calendar className="size-4" />
                    {field.options.includeTime ? 'Date and time' : 'Date'}
                </div>
            );
            break;
        case 'select':
        case 'multiSelect':
        case 'user':
        case 'link':
            content = (
                <div className={cn(box, 'justify-between')}>
                    {field.type === 'user'
                        ? 'Pick a person'
                        : field.type === 'link'
                          ? 'Link a record'
                          : field.type === 'select'
                            ? 'Pick an option'
                            : 'Pick options'}
                    <ChevronDown className="size-4" />
                </div>
            );
            break;
        default:
            content = <div className={box} />;
    }

    return (
        <div aria-hidden className="pointer-events-none">
            {content}
        </div>
    );
}

function FormSidePanel({
    form,
    available,
    dragKey,
    hasFields,
    onSave,
    onAdd,
    onAddAll,
    onRemoveAll,
    onDragStart,
    onDragEnd,
    onDropRemove,
}: {
    form: FormConfig;
    available: Field[];
    dragKey: string | null;
    hasFields: boolean;
    onSave: (patch: Partial<FormConfig>) => void;
    onAdd: (key: string) => void;
    onAddAll: () => void;
    onRemoveAll: () => void;
    onDragStart: (event: DragEvent<HTMLElement>, key: string) => void;
    onDragEnd: () => void;
    onDropRemove: () => void;
}) {
    const fromForm =
        dragKey !== null && !available.some((field) => field.key === dragKey);
    const linkButton =
        'rounded px-1.5 py-0.5 text-xs font-medium text-blue-600 hover:bg-blue-50 disabled:pointer-events-none disabled:text-neutral-300 dark:text-blue-400 dark:hover:bg-blue-950 dark:disabled:text-neutral-700';

    return (
        <aside
            aria-label="Form fields and settings"
            className={cn(
                'flex max-h-[45%] shrink-0 flex-col overflow-y-auto border-t border-neutral-200 bg-white md:max-h-none md:w-72 md:border-t-0 md:border-l dark:border-neutral-800 dark:bg-neutral-950',
                fromForm && 'bg-red-50/40 dark:bg-red-950/20',
            )}
            onDragOver={(event) => {
                if (fromForm) {
                    event.preventDefault();
                    event.dataTransfer.dropEffect = 'move';
                }
            }}
            onDrop={(event) => {
                if (fromForm) {
                    event.preventDefault();
                    onDropRemove();
                }
            }}
        >
            <section className="flex flex-col gap-2 p-3">
                <div className="flex items-center justify-between gap-2">
                    <h3 className="text-xs font-medium tracking-wide text-neutral-500 uppercase">
                        Fields
                    </h3>
                    <div className="flex items-center gap-0.5">
                        <button
                            type="button"
                            className={linkButton}
                            disabled={available.length === 0}
                            onClick={onAddAll}
                        >
                            Add all
                        </button>
                        <button
                            type="button"
                            className={linkButton}
                            disabled={!hasFields}
                            onClick={onRemoveAll}
                        >
                            Remove all
                        </button>
                    </div>
                </div>
                {available.length === 0 ? (
                    <p className="px-1 py-2 text-xs text-neutral-500">
                        Every field is on the form.
                    </p>
                ) : (
                    <ul className="flex flex-col gap-0.5">
                        {available.map((field) => {
                            const Icon = FIELD_ICONS[field.type];

                            return (
                                <li key={field.key}>
                                    <button
                                        type="button"
                                        draggable
                                        aria-label={`Add ${field.name} to the form`}
                                        onClick={() => onAdd(field.key)}
                                        onDragStart={(event) =>
                                            onDragStart(event, field.key)
                                        }
                                        onDragEnd={onDragEnd}
                                        className={cn(
                                            'group flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-[13px] text-neutral-800 outline-none hover:bg-neutral-100 focus-visible:ring-2 focus-visible:ring-blue-500/50 dark:text-neutral-200 dark:hover:bg-neutral-800',
                                            dragKey === field.key &&
                                                'opacity-50',
                                        )}
                                    >
                                        <Icon
                                            aria-hidden
                                            className="size-3.5 shrink-0 text-neutral-400"
                                        />
                                        <span className="min-w-0 flex-1 truncate">
                                            {field.name}
                                        </span>
                                        <Plus
                                            aria-hidden
                                            className="size-3.5 shrink-0 text-neutral-400 opacity-0 group-hover:opacity-100 group-focus-visible:opacity-100"
                                        />
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </section>
            <section className="flex flex-col gap-3 border-t border-neutral-200 p-3 dark:border-neutral-800">
                <h3 className="text-xs font-medium tracking-wide text-neutral-500 uppercase">
                    Settings
                </h3>
                <label className="flex flex-col gap-1 text-xs font-medium text-neutral-700 dark:text-neutral-300">
                    Button text
                    <input
                        value={form.submitLabel}
                        placeholder="Submit"
                        onChange={(event) =>
                            onSave({ submitLabel: event.target.value })
                        }
                        className={cn(textInputClass, 'font-normal')}
                    />
                </label>
                <label className="flex flex-col gap-1 text-xs font-medium text-neutral-700 dark:text-neutral-300">
                    Message after sending
                    <textarea
                        value={form.thankYou}
                        rows={3}
                        placeholder="Thanks! Your answers were sent."
                        onChange={(event) =>
                            onSave({ thankYou: event.target.value })
                        }
                        className={cn(
                            textInputClass,
                            'h-auto resize-none py-1.5 font-normal',
                        )}
                    />
                </label>
                <label className="flex items-center gap-2 text-xs text-neutral-700 dark:text-neutral-300">
                    <Checkbox
                        checked={form.allowAnother}
                        onCheckedChange={(checked) =>
                            onSave({ allowAnother: checked === true })
                        }
                    />
                    Show “Send another”
                </label>
            </section>
        </aside>
    );
}

function SharePopover({
    view,
    form,
    mayEdit,
}: {
    view: TableView;
    form: FormConfig;
    mayEdit: boolean;
}) {
    const store = useTableStore();
    const [copied, setCopied] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [renewing, setRenewing] = useState(false);
    const link = view.publicFormUrl ? absoluteUrl(view.publicFormUrl) : null;

    const copy = () => {
        if (!link) {
            return;
        }

        void navigator.clipboard?.writeText(link).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        });
    };

    const renew = async () => {
        setRenewing(true);

        try {
            const result = await request<{ view: TableView }>(
                'POST',
                `${store.endpoint}/views/${view.id}/form-link`,
            );

            store.applyViewLinks(result.view);
        } catch (failure) {
            store.setError(
                failure instanceof TableRequestError
                    ? failure.firstErrors().join(' ')
                    : "Couldn't make a new link. Try again.",
            );
        } finally {
            setRenewing(false);
            setConfirming(false);
        }
    };

    return (
        <Popover onOpenChange={() => setConfirming(false)}>
            <PopoverTrigger asChild>
                <Button variant="outline" size="sm">
                    <Share2 />
                    Share
                    {form.public && (
                        <span className="size-1.5 rounded-full bg-green-500" />
                    )}
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="flex w-80 flex-col gap-3">
                {mayEdit ? (
                    <div className="flex items-start gap-3">
                        <button
                            id="form-public-switch"
                            type="button"
                            role="switch"
                            aria-checked={form.public}
                            autoFocus
                            onClick={() =>
                                store.updateView({
                                    form: { ...form, public: !form.public },
                                })
                            }
                            className={cn(
                                'relative mt-0.5 inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40',
                                form.public
                                    ? 'bg-green-600'
                                    : 'bg-neutral-300 dark:bg-neutral-700',
                            )}
                        >
                            <span
                                className={cn(
                                    'inline-block size-4 rounded-full bg-white shadow transition-transform',
                                    form.public
                                        ? 'translate-x-4.5'
                                        : 'translate-x-0.5',
                                )}
                            />
                        </button>
                        <div className="flex flex-col gap-0.5">
                            <label
                                htmlFor="form-public-switch"
                                className="text-sm font-medium"
                            >
                                Anyone with the link can send it
                            </label>
                            <p className="text-xs text-neutral-500">
                                No account needed. Person and link fields are
                                left out.
                            </p>
                        </div>
                    </div>
                ) : (
                    <p className="text-sm text-neutral-600 dark:text-neutral-400">
                        {form.public
                            ? 'Anyone with this link can send the form.'
                            : 'Only people signed in to this app can open this form.'}
                    </p>
                )}
                {form.public &&
                    (link ? (
                        <div className="flex flex-col gap-2">
                            <div className="flex items-center gap-1.5">
                                <input
                                    readOnly
                                    value={link}
                                    aria-label="Public link"
                                    onFocus={(event) => event.target.select()}
                                    className={cn(textInputClass, 'text-xs')}
                                />
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={copy}
                                    aria-label="Copy link"
                                >
                                    {copied ? <Check /> : <Copy />}
                                    {copied ? 'Copied' : 'Copy'}
                                </Button>
                            </div>
                            {mayEdit &&
                                (confirming ? (
                                    <div className="flex flex-col gap-2 rounded-md bg-amber-50 p-2.5 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                                        <p>
                                            The old link stops working. Anyone
                                            who has it will need the new one.
                                        </p>
                                        <div className="flex justify-end gap-1.5">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setConfirming(false)
                                                }
                                            >
                                                Cancel
                                            </Button>
                                            <Button
                                                variant="destructive"
                                                size="sm"
                                                autoFocus
                                                disabled={renewing}
                                                onClick={() => void renew()}
                                            >
                                                Make a new link
                                            </Button>
                                        </div>
                                    </div>
                                ) : (
                                    <button
                                        type="button"
                                        className="w-fit rounded px-1 py-0.5 text-xs font-medium text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                                        onClick={() => setConfirming(true)}
                                    >
                                        New link
                                    </button>
                                ))}
                        </div>
                    ) : (
                        <p className="text-xs text-neutral-500">
                            Making the link…
                        </p>
                    ))}
            </PopoverContent>
        </Popover>
    );
}
