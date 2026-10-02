import { Check, ChevronDown, Info, Lock, Plus, Search, X } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import type {
    FormEvent,
    SyntheticEvent,
    KeyboardEvent,
    ReactNode,
    SelectHTMLAttributes,
} from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { ChoicesEditor } from './choices-editor';
import { nextChoiceColor } from './colors';
import { FIELD_ICONS } from './field-icons';
import {
    displayText,
    FIELD_TYPE_LABELS,
    formatNumber,
    isComputed,
    splitList,
} from './format';
import { FormulaInput } from './formula-input';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
    PopoverTrigger,
} from './popover';
import type {
    Choice,
    Field,
    FieldInput,
    FieldOptions,
    FieldType,
    ResultFormat,
    RollupFunction,
} from './types';
import { useTableStore } from './use-table';
import type { TableStore } from './use-table';

/** One line about each type, shown under the type picker. */
export const FIELD_TYPE_DESCRIPTIONS: Record<FieldType, string> = {
    text: 'A single line of text.',
    longText: 'Several lines of text, like notes or a description.',
    number: 'A number, with the decimal places you choose.',
    currency: 'An amount of money, shown with a currency symbol.',
    percent: 'A percentage, like 25%.',
    checkbox: 'A box to tick, for yes or no.',
    date: 'A date, with or without a time.',
    select: 'One option from a list, shown as a colored pill.',
    multiSelect: 'Any number of options from a list.',
    user: 'Someone who uses this app.',
    link: 'Records from another table, or from this one.',
    attachment: 'Files and images.',
    rating: 'A rating in stars.',
    url: 'A web address that opens when clicked.',
    email: 'An email address.',
    phone: 'A phone number.',
    formula: 'A value worked out from the record’s other fields.',
    lookup: 'A field of the records a link field links to.',
    rollup: 'A sum, average or other summary of the linked records.',
    count: 'How many records a link field links to.',
    createdAt: 'When each record was added.',
    updatedAt: 'When each record was last changed.',
};

const TYPE_GROUPS: { label: string; types: FieldType[] }[] = [
    {
        label: 'Basic',
        types: [
            'text',
            'longText',
            'number',
            'currency',
            'percent',
            'checkbox',
            'date',
            'rating',
        ],
    },
    {
        label: 'Choices and people',
        types: ['select', 'multiSelect', 'user', 'attachment'],
    },
    { label: 'Contact', types: ['url', 'email', 'phone'] },
    { label: 'Linked records', types: ['link', 'lookup', 'rollup', 'count'] },
    { label: 'Worked out', types: ['formula', 'createdAt', 'updatedAt'] },
];

const NEEDS_LINK: FieldType[] = ['lookup', 'rollup', 'count'];

const ROLLUP_LABELS: Record<RollupFunction, string> = {
    sum: 'Sum',
    average: 'Average',
    min: 'Smallest',
    max: 'Largest',
    count: 'Count of values',
    countAll: 'Count of all, empty included',
    join: 'Join as text',
    earliest: 'Earliest date',
    latest: 'Latest date',
};

const FORMAT_LABELS: Record<ResultFormat, string> = {
    auto: 'Auto',
    text: 'Text',
    number: 'Number',
    currency: 'Currency',
    percent: 'Percent',
    date: 'Date',
};

const NUMERIC_FORMATS: ResultFormat[] = ['number', 'currency', 'percent'];

const OPTION_KEYS: Partial<Record<FieldType, (keyof FieldOptions)[]>> = {
    select: ['choices'],
    multiSelect: ['choices'],
    number: ['precision'],
    currency: ['precision', 'symbol'],
    percent: ['precision'],
    date: ['includeTime'],
    createdAt: ['includeTime'],
    updatedAt: ['includeTime'],
    rating: ['max'],
    link: ['table', 'multiple', 'inverse', 'showInverse', 'source'],
    lookup: ['link', 'field'],
    rollup: ['link', 'field', 'function', 'format', 'precision', 'symbol'],
    count: ['link'],
    formula: ['formula', 'format', 'precision', 'symbol'],
};

/**
 * Stops a popover holding the editor from closing on Escape while a list inside it (field types, formula
 * suggestions) is open: those mark their input with data-keeps-escape="true" and close themselves.
 * Pass it as PopoverContent's onEscapeKeyDown.
 */
export function keepEscape(
    event: KeyboardEvent | globalThis.KeyboardEvent,
): void {
    const target = event.target;

    if (
        target instanceof HTMLElement &&
        target.closest('[data-keeps-escape="true"]')
    ) {
        event.preventDefault();
    }
}

/** Classes for a popover holding a FieldEditor: no padding, and scrolling when the screen is short. */
export const FIELD_EDITOR_POPOVER_CLASS =
    'w-auto max-h-[min(40rem,var(--radix-popover-content-available-height))] overflow-y-auto p-0';

function stopPropagation(event: SyntheticEvent): void {
    event.stopPropagation();
}

interface Draft {
    name: string;
    type: FieldType;
    description: string;
    showDescription: boolean;
    options: FieldOptions;
    renames: Record<string, string>;
}

function fieldsOfTable(
    store: TableStore,
    table: string | undefined,
): { key: string; name: string; type: FieldType }[] {
    if (!table) {
        return [];
    }

    return (
        store.linkedFields[table] ?? (table === store.key ? store.fields : [])
    );
}

function linkFieldsOf(store: TableStore, except?: string): Field[] {
    return store.fields.filter(
        (candidate) => candidate.type === 'link' && candidate.key !== except,
    );
}

/** The options a type starts with, keeping what fits from the ones it had. */
function withDefaults(
    type: FieldType,
    options: FieldOptions,
    store: TableStore,
    except?: string,
): FieldOptions {
    const next = { ...options };
    const firstLink = linkFieldsOf(store, except)[0];

    switch (type) {
        case 'select':
        case 'multiSelect':
            next.choices ??= [];
            break;
        case 'number':
            next.precision ??= 0;
            break;
        case 'currency':
            next.precision ??= 2;
            next.symbol ??= '$';
            break;
        case 'percent':
            next.precision ??= 0;
            break;
        case 'date':
            next.includeTime ??= false;
            break;
        case 'createdAt':
        case 'updatedAt':
            next.includeTime ??= true;
            break;
        case 'rating':
            next.max ??= 5;
            break;
        case 'link':
            next.table ??=
                store.linkableTables.find((table) => table.key !== store.key)
                    ?.key ?? store.key;
            next.multiple ??= true;
            next.showInverse ??= true;
            break;
        case 'lookup':
        case 'rollup':
        case 'count': {
            if (
                !next.link ||
                !linkFieldsOf(store, except).some(
                    (link) => link.key === next.link,
                )
            ) {
                next.link = firstLink?.key;
                next.field = undefined;
            }

            if (type !== 'count') {
                const linked = fieldsOfTable(
                    store,
                    store.fieldsByKey.get(next.link ?? '')?.options.table,
                );

                if (
                    !next.field ||
                    !linked.some((candidate) => candidate.key === next.field)
                ) {
                    next.field =
                        type === 'rollup'
                            ? (
                                  linked.find((candidate) =>
                                      [
                                          'number',
                                          'currency',
                                          'percent',
                                      ].includes(candidate.type),
                                  ) ?? linked[0]
                              )?.key
                            : linked[0]?.key;
                }
            }

            if (type === 'rollup') {
                next.function ??= 'sum';
                next.format ??= 'auto';
            }

            break;
        }
        case 'formula':
            next.formula ??= '';
            next.format ??= 'auto';
            break;
        default:
            break;
    }

    return next;
}

/** Only the options the type has, with empty options dropped. */
function optionsFor(type: FieldType, options: FieldOptions): FieldOptions {
    const picked: FieldOptions = {};

    for (const key of OPTION_KEYS[type] ?? []) {
        if (options[key] !== undefined) {
            Object.assign(picked, { [key]: options[key] });
        }
    }

    if (picked.choices) {
        const seen = new Set<string>();

        picked.choices = picked.choices
            .map((choice) => ({ ...choice, name: choice.name.trim() }))
            .filter((choice) => {
                const known = choice.name === '' || seen.has(choice.name);

                seen.add(choice.name);

                return !known;
            });
    }

    if (
        (type === 'formula' || type === 'rollup') &&
        !NUMERIC_FORMATS.includes(picked.format ?? 'auto')
    ) {
        delete picked.precision;
    }

    if (
        (type === 'formula' || type === 'rollup') &&
        picked.format !== 'currency'
    ) {
        delete picked.symbol;
    }

    return picked;
}

/** Options made from a field's values, when it's turned into a select. */
function choicesFromValues(field: Field, store: TableStore): Choice[] {
    const names: string[] = [];
    const seen = new Set<string>();

    for (const record of store.records) {
        const text = displayText(
            field,
            record.values[field.key],
            store.context,
        );
        const parts =
            field.type === 'multiSelect' || field.type === 'link'
                ? splitList(text)
                : [text.trim()];

        for (const part of parts) {
            if (part !== '' && !seen.has(part)) {
                seen.add(part);
                names.push(part);
            }
        }

        if (names.length >= 100) {
            break;
        }
    }

    const choices: Choice[] = [];

    for (const name of names) {
        choices.push({
            name,
            color: nextChoiceColor(choices.map((choice) => choice.color)),
        });
    }

    return choices;
}

function nameProblem(
    name: string,
    store: TableStore,
    field?: Field,
): string | null {
    const trimmed = name.trim();

    if (trimmed === '') {
        return 'Give the field a name.';
    }

    if (/[{}]/.test(trimmed)) {
        return 'Field names can’t have { or } in them.';
    }

    const taken = store.fields.find(
        (candidate) =>
            candidate.key !== field?.key &&
            candidate.name.trim().toLowerCase() === trimmed.toLowerCase(),
    );

    return taken ? `There’s already a field named “${taken.name}”.` : null;
}

function settingsProblem(draft: Draft): string | null {
    const options = draft.options;

    switch (draft.type) {
        case 'select':
        case 'multiSelect': {
            const names = (options.choices ?? [])
                .map((choice) => choice.name.trim().toLowerCase())
                .filter(Boolean);

            return new Set(names).size < names.length
                ? 'Two options have the same name.'
                : null;
        }
        case 'link':
            return options.table ? null : 'Pick a table to link to.';
        case 'lookup':
        case 'rollup':
            if (!options.link) {
                return 'Pick a link field.';
            }

            return options.field ? null : 'Pick a field of the linked records.';
        case 'count':
            return options.link ? null : 'Pick a link field.';
        case 'formula':
            return (options.formula ?? '').trim() === ''
                ? 'Write a formula.'
                : null;
        default:
            return null;
    }
}

/**
 * Adds a field or changes one, Airtable-style: name, type, the type's settings and a description.
 * Fields built with the app, and every field for people who can't change fields, open read-only.
 */
export function FieldEditor({
    field,
    onSaved,
    onCancel,
    initialType,
}: {
    field?: Field;
    onSaved: (field: Field) => void;
    onCancel: () => void;
    initialType?: FieldType;
}) {
    const store = useTableStore();
    const [draft, setDraft] = useState<Draft>(() => {
        if (field) {
            return {
                name: field.name,
                type: field.type,
                description: field.description ?? '',
                showDescription: Boolean(field.description),
                options: { ...field.options },
                renames: {},
            };
        }

        const type = initialType ?? 'text';

        return {
            name: '',
            type,
            description: '',
            showDescription: false,
            options: withDefaults(type, {}, store),
            renames: {},
        };
    });
    const [attempted, setAttempted] = useState(false);
    const [saving, setSaving] = useState(false);
    const [failed, setFailed] = useState(false);
    const nameInput = useRef<HTMLInputElement>(null);
    const nameId = useId();

    const readOnly = Boolean(field?.builtIn) || !store.can.manageFields;
    const inverse = field?.type === 'link' && field.options.inverse === true;
    const linkFields = linkFieldsOf(store, field?.key);
    const problemWithName = nameProblem(draft.name, store, field);
    const problemWithSettings = settingsProblem(draft);
    const retyped = field !== undefined && draft.type !== field.type;
    const removedChoices =
        field &&
        ['select', 'multiSelect'].includes(field.type) &&
        ['select', 'multiSelect'].includes(draft.type)
            ? (field.options.choices ?? []).filter(
                  (choice) =>
                      !(choice.name in draft.renames) &&
                      !(draft.options.choices ?? []).some(
                          (current) => current.name.trim() === choice.name,
                      ),
              )
            : [];

    function setOptions(patch: Partial<FieldOptions>): void {
        setDraft((current) => ({
            ...current,
            options: { ...current.options, ...patch },
        }));
    }

    function setType(type: FieldType): void {
        setDraft((current) => {
            let options = withDefaults(
                type,
                current.options,
                store,
                field?.key,
            );

            if (
                field &&
                ['select', 'multiSelect'].includes(type) &&
                !['select', 'multiSelect'].includes(field.type) &&
                (options.choices ?? []).length === 0
            ) {
                options = {
                    ...options,
                    choices: choicesFromValues(field, store),
                };
            }

            return { ...current, type, options };
        });
    }

    async function submit(event: FormEvent): Promise<void> {
        event.preventDefault();

        if (readOnly || saving) {
            return;
        }

        setAttempted(true);

        if (problemWithName) {
            nameInput.current?.focus();

            return;
        }

        if (problemWithSettings) {
            return;
        }

        const input: FieldInput = {
            name: draft.name.trim(),
            type: draft.type,
            description:
                draft.showDescription && draft.description.trim() !== ''
                    ? draft.description.trim()
                    : null,
            options: optionsFor(draft.type, draft.options),
        };

        // Only options the field already had can be renamed; ones made from its values are new.
        const existing = new Set(
            (field?.options.choices ?? []).map((choice) => choice.name),
        );
        const renames = Object.fromEntries(
            Object.entries(draft.renames).filter(([from]) =>
                existing.has(from),
            ),
        );

        if (
            Object.keys(renames).length > 0 &&
            ['select', 'multiSelect'].includes(draft.type)
        ) {
            input.renames = renames;
        }

        setSaving(true);
        setFailed(false);
        store.setError(null);

        const saved = await store.saveField(input, field?.key);

        setSaving(false);

        if (saved) {
            onSaved(saved);
        } else {
            setFailed(true);
        }
    }

    const wide = draft.type === 'formula';

    return (
        <form
            onSubmit={(event) => void submit(event)}
            // The editor often opens from a draggable column header: its drags aren't the header's.
            onDragStart={stopPropagation}
            onDragOver={stopPropagation}
            onDrop={stopPropagation}
            onDragEnd={stopPropagation}
            aria-label={field ? `Edit ${field.name}` : 'Add field'}
            className={cn(
                'flex flex-col gap-3 p-3 transition-[width]',
                wide ? 'w-[30rem]' : 'w-[22rem]',
            )}
        >
            {field?.builtIn && (
                <p className="flex items-start gap-2 rounded-md bg-neutral-100 px-2.5 py-2 text-xs text-neutral-600 dark:bg-neutral-900 dark:text-neutral-400">
                    <Lock className="mt-px size-3.5 shrink-0" />
                    Part of the app: it can’t be renamed, retyped or deleted
                    here.
                </p>
            )}
            {!field?.builtIn && readOnly && (
                <p className="flex items-start gap-2 rounded-md bg-neutral-100 px-2.5 py-2 text-xs text-neutral-600 dark:bg-neutral-900 dark:text-neutral-400">
                    <Lock className="mt-px size-3.5 shrink-0" />
                    You can’t change this table’s fields.
                </p>
            )}

            <fieldset
                disabled={readOnly}
                className="flex min-w-0 flex-col gap-3"
            >
                <div className="space-y-1">
                    <label htmlFor={nameId} className="sr-only">
                        Field name
                    </label>
                    <input
                        ref={nameInput}
                        id={nameId}
                        value={draft.name}
                        onChange={(event) =>
                            setDraft((current) => ({
                                ...current,
                                name: event.target.value,
                            }))
                        }
                        autoFocus={!readOnly}
                        autoComplete="off"
                        placeholder="Field name"
                        aria-invalid={
                            attempted && problemWithName ? true : undefined
                        }
                        className={cn(
                            'h-9 w-full rounded-md border border-neutral-200 bg-white px-2.5 text-sm font-medium text-neutral-900 outline-none placeholder:font-normal placeholder:text-neutral-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 disabled:bg-neutral-50 disabled:text-neutral-600 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100 dark:disabled:bg-neutral-900/50',
                            attempted &&
                                problemWithName &&
                                'border-red-400 focus:border-red-500 focus:ring-red-500/20',
                        )}
                    />
                    {attempted && problemWithName && (
                        <p className="text-xs text-red-600 dark:text-red-400">
                            {problemWithName}
                        </p>
                    )}
                </div>

                <TypePicker
                    value={draft.type}
                    onChange={setType}
                    disabled={readOnly || inverse}
                    unavailable={(type) =>
                        NEEDS_LINK.includes(type) && linkFields.length === 0
                            ? 'Add a link field first'
                            : null
                    }
                />

                {retyped && field && (
                    <Note>
                        {isComputed({ ...field, type: draft.type }) &&
                        !isComputed(field)
                            ? 'The values in this field will be replaced by worked-out ones.'
                            : 'Values will be converted where they can be.'}
                    </Note>
                )}

                <Settings
                    draft={draft}
                    field={field}
                    setOptions={setOptions}
                    setRenames={(renames) =>
                        setDraft((current) => ({ ...current, renames }))
                    }
                    readOnly={readOnly}
                />

                {removedChoices.length > 0 && (
                    <Note>
                        {removedChoices.length === 1
                            ? `Records with “${removedChoices[0].name}” will lose it.`
                            : `Records with the ${removedChoices.length} removed options will lose them.`}
                    </Note>
                )}

                {draft.showDescription ? (
                    <div className="space-y-1">
                        <div className="flex items-center justify-between">
                            <label
                                htmlFor={`${nameId}-description`}
                                className="text-xs font-medium text-neutral-600 dark:text-neutral-400"
                            >
                                Description
                            </label>
                            {!readOnly && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        setDraft((current) => ({
                                            ...current,
                                            showDescription: false,
                                        }))
                                    }
                                    aria-label="Remove description"
                                    className="rounded p-0.5 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                                >
                                    <X className="size-3.5" />
                                </button>
                            )}
                        </div>
                        <textarea
                            id={`${nameId}-description`}
                            value={draft.description}
                            onChange={(event) =>
                                setDraft((current) => ({
                                    ...current,
                                    description: event.target.value,
                                }))
                            }
                            autoFocus={!readOnly && !field?.description}
                            rows={2}
                            placeholder="What this field is for"
                            className="block w-full resize-y rounded-md border border-neutral-200 bg-white px-2.5 py-1.5 text-sm outline-none placeholder:text-neutral-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 disabled:bg-neutral-50 dark:border-neutral-800 dark:bg-neutral-900 dark:disabled:bg-neutral-900/50"
                        />
                    </div>
                ) : (
                    !readOnly && (
                        <button
                            type="button"
                            onClick={() =>
                                setDraft((current) => ({
                                    ...current,
                                    showDescription: true,
                                }))
                            }
                            className="flex h-7 items-center gap-1.5 self-start rounded-md px-1.5 text-sm text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                        >
                            <Plus className="size-3.5" />
                            Add description
                        </button>
                    )
                )}
            </fieldset>

            {attempted && !problemWithName && problemWithSettings && (
                <p className="text-xs text-red-600 dark:text-red-400">
                    {problemWithSettings}
                </p>
            )}
            {failed && store.error && (
                <p
                    role="alert"
                    className="rounded-md bg-red-50 px-2.5 py-2 text-xs text-red-700 dark:bg-red-950/50 dark:text-red-300"
                >
                    {store.error}
                </p>
            )}

            <div className="flex items-center justify-end gap-2 border-t border-neutral-200 pt-3 dark:border-neutral-800">
                {readOnly ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onCancel}
                    >
                        Close
                    </Button>
                ) : (
                    <>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={onCancel}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" size="sm" disabled={saving}>
                            {saving
                                ? 'Saving…'
                                : field
                                  ? 'Save'
                                  : 'Create field'}
                        </Button>
                    </>
                )}
            </div>
        </form>
    );
}

function Note({ children }: { children: ReactNode }) {
    return (
        <p className="flex items-start gap-2 rounded-md bg-amber-50 px-2.5 py-2 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
            <Info className="mt-px size-3.5 shrink-0" />
            <span>{children}</span>
        </p>
    );
}

/** A searchable list of field types, grouped, with arrow keys and Enter. */
function TypePicker({
    value,
    onChange,
    disabled,
    unavailable,
}: {
    value: FieldType;
    onChange: (type: FieldType) => void;
    disabled: boolean;
    /** Why a type can't be picked now, or null */
    unavailable: (type: FieldType) => string | null;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [highlight, setHighlight] = useState<FieldType | null>(null);
    const button = useRef<HTMLButtonElement>(null);
    const listId = useId();
    const Icon = FIELD_ICONS[value];

    const needle = query.trim().toLowerCase();
    const groups = TYPE_GROUPS.map((group) => ({
        ...group,
        types: group.types.filter(
            (type) =>
                needle === '' ||
                FIELD_TYPE_LABELS[type].toLowerCase().includes(needle) ||
                FIELD_TYPE_DESCRIPTIONS[type].toLowerCase().includes(needle),
        ),
    })).filter((group) => group.types.length > 0);
    const choosable = groups
        .flatMap((group) => group.types)
        .filter((type) => unavailable(type) === null);
    const active =
        highlight !== null && choosable.includes(highlight)
            ? highlight
            : (choosable[0] ?? null);

    function close(): void {
        setOpen(false);
        setQuery('');
        setHighlight(null);
        requestAnimationFrame(() => button.current?.focus());
    }

    function choose(type: FieldType): void {
        onChange(type);
        close();
    }

    function onKeyDown(event: KeyboardEvent<HTMLInputElement>): void {
        const index = active ? choosable.indexOf(active) : -1;

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();

            if (choosable.length > 0) {
                const step = event.key === 'ArrowDown' ? 1 : -1;

                setHighlight(
                    choosable[
                        (index + step + choosable.length) % choosable.length
                    ],
                );
            }
        } else if (event.key === 'Enter') {
            event.preventDefault();

            if (active) {
                choose(active);
            }
        } else if (event.key === 'Escape') {
            event.preventDefault();
            close();
        }
    }

    return (
        <div className="space-y-1">
            <span className="sr-only" id={`${listId}-label`}>
                Field type
            </span>
            {open ? (
                <div className="overflow-hidden rounded-md border border-blue-500 ring-2 ring-blue-500/20">
                    <div className="flex items-center gap-2 border-b border-neutral-200 px-2.5 dark:border-neutral-800">
                        <Search className="size-3.5 shrink-0 text-neutral-400" />
                        <input
                            value={query}
                            onChange={(event) => {
                                setQuery(event.target.value);
                                setHighlight(null);
                            }}
                            onKeyDown={onKeyDown}
                            onBlur={() => {
                                setOpen(false);
                                setQuery('');
                            }}
                            autoFocus
                            autoComplete="off"
                            placeholder="Find a field type"
                            aria-label="Find a field type"
                            aria-controls={listId}
                            aria-activedescendant={
                                active ? `${listId}-${active}` : undefined
                            }
                            data-keeps-escape="true"
                            className="h-8 min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-neutral-400"
                        />
                    </div>
                    <ul
                        id={listId}
                        role="listbox"
                        aria-labelledby={`${listId}-label`}
                        onMouseDown={(event) => event.preventDefault()}
                        className="max-h-64 overflow-y-auto p-1"
                    >
                        {groups.length === 0 && (
                            <li className="px-2 py-1.5 text-sm text-neutral-500">
                                No field types match “{query}”.
                            </li>
                        )}
                        {groups.map((group) => (
                            <li key={group.label} role="presentation">
                                <div className="px-2 pt-1.5 pb-0.5 text-[11px] font-medium tracking-wide text-neutral-400 uppercase">
                                    {group.label}
                                </div>
                                <ul role="presentation">
                                    {group.types.map((type) => {
                                        const TypeIcon = FIELD_ICONS[type];
                                        const reason = unavailable(type);

                                        return (
                                            <li
                                                key={type}
                                                id={`${listId}-${type}`}
                                                role="option"
                                                aria-selected={type === value}
                                                aria-disabled={
                                                    reason !== null || undefined
                                                }
                                                onMouseDown={(event) =>
                                                    event.preventDefault()
                                                }
                                                onMouseEnter={() =>
                                                    reason === null &&
                                                    setHighlight(type)
                                                }
                                                onClick={() =>
                                                    reason === null &&
                                                    choose(type)
                                                }
                                                title={
                                                    reason ??
                                                    FIELD_TYPE_DESCRIPTIONS[
                                                        type
                                                    ]
                                                }
                                                className={cn(
                                                    'flex items-center gap-2 rounded px-2 py-1 text-sm',
                                                    reason !== null
                                                        ? 'cursor-not-allowed text-neutral-400 dark:text-neutral-600'
                                                        : 'cursor-pointer text-neutral-800 dark:text-neutral-200',
                                                    type === active &&
                                                        'bg-blue-50 text-blue-900 dark:bg-blue-950 dark:text-blue-100',
                                                )}
                                            >
                                                <TypeIcon className="size-4 shrink-0 opacity-70" />
                                                <span className="min-w-0 flex-1 truncate">
                                                    {FIELD_TYPE_LABELS[type]}
                                                </span>
                                                {reason !== null ? (
                                                    <span className="shrink-0 text-xs">
                                                        {reason}
                                                    </span>
                                                ) : (
                                                    type === value && (
                                                        <Check className="size-3.5 shrink-0 text-blue-600" />
                                                    )
                                                )}
                                            </li>
                                        );
                                    })}
                                </ul>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : (
                <button
                    ref={button}
                    type="button"
                    onClick={() => setOpen(true)}
                    disabled={disabled}
                    aria-labelledby={`${listId}-label ${listId}-value`}
                    aria-haspopup="listbox"
                    aria-expanded={false}
                    className="flex h-9 w-full items-center gap-2 rounded-md border border-neutral-200 bg-white px-2.5 text-left text-sm text-neutral-900 outline-none hover:bg-neutral-50 focus-visible:border-blue-500 focus-visible:ring-2 focus-visible:ring-blue-500/20 disabled:bg-neutral-50 disabled:text-neutral-600 disabled:hover:bg-neutral-50 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100 dark:hover:bg-neutral-800 dark:disabled:bg-neutral-900/50"
                >
                    <Icon className="size-4 shrink-0 text-neutral-500" />
                    <span
                        id={`${listId}-value`}
                        className="min-w-0 flex-1 truncate"
                    >
                        {FIELD_TYPE_LABELS[value]}
                    </span>
                    {!disabled && (
                        <ChevronDown className="size-4 shrink-0 text-neutral-400" />
                    )}
                </button>
            )}
            {!open && (
                <p className="px-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                    {FIELD_TYPE_DESCRIPTIONS[value]}
                </p>
            )}
        </div>
    );
}

function Setting({
    label,
    children,
}: {
    label: string;
    children: (id: string) => ReactNode;
}) {
    const id = useId();

    return (
        <div className="min-w-0 flex-1 space-y-1">
            <label
                htmlFor={id}
                className="block text-xs font-medium text-neutral-600 dark:text-neutral-400"
            >
                {label}
            </label>
            {children(id)}
        </div>
    );
}

function NativeSelect({
    className,
    children,
    ...props
}: SelectHTMLAttributes<HTMLSelectElement>) {
    return (
        <div className="relative">
            <select
                {...props}
                className={cn(
                    'h-8 w-full appearance-none truncate rounded-md border border-neutral-200 bg-white pr-8 pl-2.5 text-sm text-neutral-900 outline-none hover:bg-neutral-50 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 disabled:bg-neutral-50 disabled:text-neutral-600 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100 dark:hover:bg-neutral-800 dark:disabled:bg-neutral-900/50',
                    className,
                )}
            >
                {children}
            </select>
            <ChevronDown className="pointer-events-none absolute top-1/2 right-2.5 size-4 -translate-y-1/2 text-neutral-400" />
        </div>
    );
}

function Toggle({
    label,
    hint,
    checked,
    onChange,
}: {
    label: string;
    hint?: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <label className="flex cursor-pointer items-start justify-between gap-3 has-disabled:cursor-default">
            <span className="min-w-0">
                <span className="block text-sm text-neutral-800 dark:text-neutral-200">
                    {label}
                </span>
                {hint && (
                    <span className="block text-xs text-neutral-500 dark:text-neutral-400">
                        {hint}
                    </span>
                )}
            </span>
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                onClick={() => onChange(!checked)}
                className={cn(
                    'relative mt-0.5 inline-flex h-4.5 w-8 shrink-0 items-center rounded-full transition-colors outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 disabled:opacity-60',
                    checked
                        ? 'bg-blue-600'
                        : 'bg-neutral-300 dark:bg-neutral-700',
                )}
            >
                <span
                    className={cn(
                        'inline-block size-3.5 rounded-full bg-white shadow transition-transform',
                        checked ? 'translate-x-4' : 'translate-x-0.5',
                    )}
                />
            </button>
        </label>
    );
}

function PrecisionSelect({
    type,
    options,
    onChange,
}: {
    type: FieldType;
    options: FieldOptions;
    onChange: (precision: number) => void;
}) {
    const sample = type === 'percent' ? 0.5 : 1234;

    return (
        <Setting label="Decimal places">
            {(id) => (
                <NativeSelect
                    id={id}
                    value={options.precision ?? 0}
                    onChange={(event) => onChange(Number(event.target.value))}
                >
                    {[0, 1, 2, 3, 4, 5, 6, 7, 8].map((precision) => (
                        <option key={precision} value={precision}>
                            {formatNumber(sample, type, {
                                ...options,
                                precision,
                            })}
                            {precision === 0 ? ' (whole number)' : ''}
                        </option>
                    ))}
                </NativeSelect>
            )}
        </Setting>
    );
}

function SymbolInput({
    value,
    onChange,
}: {
    value: string;
    onChange: (symbol: string) => void;
}) {
    return (
        <Setting label="Currency symbol">
            {(id) => (
                <input
                    id={id}
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    maxLength={5}
                    placeholder="$"
                    className="h-8 w-full rounded-md border border-neutral-200 bg-white px-2.5 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 disabled:bg-neutral-50 dark:border-neutral-800 dark:bg-neutral-900 dark:disabled:bg-neutral-900/50"
                />
            )}
        </Setting>
    );
}

/** A result format, with decimal places and symbol for numbers. */
function ResultFormatSettings({
    options,
    setOptions,
}: {
    options: FieldOptions;
    setOptions: (patch: Partial<FieldOptions>) => void;
}) {
    const format = options.format ?? 'auto';
    const numeric = NUMERIC_FORMATS.includes(format);

    return (
        <div className="space-y-3">
            <div className="flex gap-2">
                <Setting label="Format the result as">
                    {(id) => (
                        <NativeSelect
                            id={id}
                            value={format}
                            onChange={(event) => {
                                const next = event.target.value as ResultFormat;

                                setOptions({
                                    format: next,
                                    precision: NUMERIC_FORMATS.includes(next)
                                        ? (options.precision ??
                                          (next === 'currency' ? 2 : 0))
                                        : options.precision,
                                    symbol:
                                        next === 'currency'
                                            ? (options.symbol ?? '$')
                                            : options.symbol,
                                });
                            }}
                        >
                            {(Object.keys(FORMAT_LABELS) as ResultFormat[]).map(
                                (candidate) => (
                                    <option key={candidate} value={candidate}>
                                        {FORMAT_LABELS[candidate]}
                                    </option>
                                ),
                            )}
                        </NativeSelect>
                    )}
                </Setting>
                {numeric && (
                    <PrecisionSelect
                        type={format as FieldType}
                        options={options}
                        onChange={(precision) => setOptions({ precision })}
                    />
                )}
            </div>
            {format === 'currency' && (
                <SymbolInput
                    value={options.symbol ?? '$'}
                    onChange={(symbol) => setOptions({ symbol })}
                />
            )}
        </div>
    );
}

function LinkFieldSelect({
    value,
    links,
    onChange,
}: {
    value: string | undefined;
    links: Field[];
    onChange: (key: string) => void;
}) {
    const store = useTableStore();

    return (
        <Setting label="Link field">
            {(id) => (
                <NativeSelect
                    id={id}
                    value={value ?? ''}
                    onChange={(event) => onChange(event.target.value)}
                >
                    {!value && <option value="">Pick a link field</option>}
                    {links.map((link) => {
                        const table = store.linkableTables.find(
                            (candidate) => candidate.key === link.options.table,
                        );

                        return (
                            <option key={link.key} value={link.key}>
                                {link.name}
                                {table ? ` (${table.name})` : ''}
                            </option>
                        );
                    })}
                </NativeSelect>
            )}
        </Setting>
    );
}

function Settings({
    draft,
    field,
    setOptions,
    setRenames,
    readOnly,
}: {
    draft: Draft;
    field?: Field;
    setOptions: (patch: Partial<FieldOptions>) => void;
    setRenames: (renames: Record<string, string>) => void;
    readOnly: boolean;
}) {
    const store = useTableStore();
    const { type, options } = draft;
    const links = linkFieldsOf(store, field?.key);

    function pickLink(link: string): void {
        const linked = fieldsOfTable(
            store,
            store.fieldsByKey.get(link)?.options.table,
        );

        setOptions({
            link,
            field:
                type === 'rollup'
                    ? (
                          linked.find((candidate) =>
                              ['number', 'currency', 'percent'].includes(
                                  candidate.type,
                              ),
                          ) ?? linked[0]
                      )?.key
                    : linked[0]?.key,
        });
    }

    switch (type) {
        case 'select':
        case 'multiSelect':
            return (
                <div className="space-y-1">
                    <span className="block text-xs font-medium text-neutral-600 dark:text-neutral-400">
                        Options
                    </span>
                    <ChoicesEditor
                        choices={options.choices ?? []}
                        onChange={(choices) => setOptions({ choices })}
                        onRename={setRenames}
                        disabled={readOnly}
                    />
                </div>
            );
        case 'number':
        case 'percent':
            return (
                <PrecisionSelect
                    type={type}
                    options={options}
                    onChange={(precision) => setOptions({ precision })}
                />
            );
        case 'currency':
            return (
                <div className="flex gap-2">
                    <div className="w-28 shrink-0">
                        <SymbolInput
                            value={options.symbol ?? '$'}
                            onChange={(symbol) => setOptions({ symbol })}
                        />
                    </div>
                    <PrecisionSelect
                        type={type}
                        options={options}
                        onChange={(precision) => setOptions({ precision })}
                    />
                </div>
            );
        case 'date':
        case 'createdAt':
        case 'updatedAt':
            return (
                <Toggle
                    label="Include time"
                    hint={
                        type === 'date'
                            ? 'Pick a time as well as a day.'
                            : undefined
                    }
                    checked={options.includeTime ?? type !== 'date'}
                    onChange={(includeTime) => setOptions({ includeTime })}
                />
            );
        case 'rating':
            return (
                <Setting label="Maximum">
                    {(id) => (
                        <NativeSelect
                            id={id}
                            value={options.max ?? 5}
                            onChange={(event) =>
                                setOptions({ max: Number(event.target.value) })
                            }
                        >
                            {[1, 2, 3, 4, 5, 6, 7, 8, 9, 10].map((max) => (
                                <option key={max} value={max}>
                                    {max} {max === 1 ? 'star' : 'stars'}
                                </option>
                            ))}
                        </NativeSelect>
                    )}
                </Setting>
            );
        case 'link': {
            if (options.inverse) {
                const sourceTable = store.linkableTables.find(
                    (table) => table.key === options.table,
                );
                const source = fieldsOfTable(store, options.table).find(
                    (candidate) => candidate.key === options.source,
                );

                return (
                    <Note>
                        This shows the records in{' '}
                        {sourceTable?.name ?? 'another table'} that link here
                        {source ? ` with “${source.name}”` : ''}. Change the
                        link there.
                    </Note>
                );
            }

            const tables =
                store.linkableTables.some(
                    (table) => table.key === options.table,
                ) || !options.table
                    ? store.linkableTables
                    : [
                          ...store.linkableTables,
                          { key: options.table, name: options.table },
                      ];
            const tableName =
                tables.find((table) => table.key === options.table)?.name ??
                'the linked table';

            return (
                <div className="space-y-3">
                    <Setting label="Link to">
                        {(id) => (
                            <NativeSelect
                                id={id}
                                value={options.table ?? ''}
                                onChange={(event) =>
                                    setOptions({ table: event.target.value })
                                }
                            >
                                {!options.table && (
                                    <option value="">Pick a table</option>
                                )}
                                {tables.map((table) => (
                                    <option key={table.key} value={table.key}>
                                        {table.name}
                                        {table.key === store.key
                                            ? ' (this table)'
                                            : ''}
                                    </option>
                                ))}
                            </NativeSelect>
                        )}
                    </Setting>
                    {field?.type === 'link' &&
                        options.table !== field.options.table && (
                            <Note>
                                Links to records in the old table will be
                                removed.
                            </Note>
                        )}
                    <Toggle
                        label="Allow linking to multiple records"
                        checked={options.multiple ?? true}
                        onChange={(multiple) => setOptions({ multiple })}
                    />
                    {options.table !== store.key && (
                        <Toggle
                            label={`Show in ${tableName}`}
                            hint="Adds a field there with the records linking to each of its records."
                            checked={options.showInverse ?? true}
                            onChange={(showInverse) =>
                                setOptions({ showInverse })
                            }
                        />
                    )}
                </div>
            );
        }
        case 'lookup':
        case 'rollup': {
            const linkedTable = store.fieldsByKey.get(options.link ?? '')
                ?.options.table;
            const linked = fieldsOfTable(store, linkedTable);
            const tableName = store.linkableTables.find(
                (table) => table.key === linkedTable,
            )?.name;

            return (
                <div className="space-y-3">
                    <LinkFieldSelect
                        value={options.link}
                        links={links}
                        onChange={pickLink}
                    />
                    {options.link && (
                        <Setting
                            label={
                                tableName
                                    ? `Field in ${tableName}`
                                    : 'Field of the linked records'
                            }
                        >
                            {(id) => (
                                <NativeSelect
                                    id={id}
                                    value={options.field ?? ''}
                                    onChange={(event) =>
                                        setOptions({
                                            field: event.target.value,
                                        })
                                    }
                                >
                                    {!options.field && (
                                        <option value="">Pick a field</option>
                                    )}
                                    {linked.map((candidate) => (
                                        <option
                                            key={candidate.key}
                                            value={candidate.key}
                                        >
                                            {candidate.name} ·{' '}
                                            {FIELD_TYPE_LABELS[candidate.type]}
                                        </option>
                                    ))}
                                </NativeSelect>
                            )}
                        </Setting>
                    )}
                    {type === 'rollup' && (
                        <>
                            <Setting label="Summarize with">
                                {(id) => (
                                    <NativeSelect
                                        id={id}
                                        value={options.function ?? 'sum'}
                                        onChange={(event) =>
                                            setOptions({
                                                function: event.target
                                                    .value as RollupFunction,
                                            })
                                        }
                                    >
                                        {(
                                            Object.keys(
                                                ROLLUP_LABELS,
                                            ) as RollupFunction[]
                                        ).map((candidate) => (
                                            <option
                                                key={candidate}
                                                value={candidate}
                                            >
                                                {ROLLUP_LABELS[candidate]}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                )}
                            </Setting>
                            <ResultFormatSettings
                                options={options}
                                setOptions={setOptions}
                            />
                        </>
                    )}
                </div>
            );
        }
        case 'count':
            return (
                <LinkFieldSelect
                    value={options.link}
                    links={links}
                    onChange={(link) => setOptions({ link })}
                />
            );
        case 'formula':
            return (
                <div className="space-y-3">
                    <div className="space-y-1">
                        <span className="block text-xs font-medium text-neutral-600 dark:text-neutral-400">
                            Formula
                        </span>
                        <FormulaInput
                            value={options.formula ?? ''}
                            onChange={(formula) => setOptions({ formula })}
                            fields={store.fields.filter(
                                (candidate) => candidate.key !== field?.key,
                            )}
                            format={options.format ?? 'auto'}
                            options={{
                                precision: options.precision,
                                symbol: options.symbol,
                            }}
                        />
                    </div>
                    <ResultFormatSettings
                        options={options}
                        setOptions={setOptions}
                    />
                </div>
            );
        default:
            return null;
    }
}

/** The "+" at the end of the grid's header, adding a field. */
export function AddFieldButton() {
    const store = useTableStore();
    const [open, setOpen] = useState(false);

    if (!store.can.manageFields) {
        return null;
    }

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    aria-label="Add field"
                    title="Add field"
                    className={cn(
                        'flex h-full min-h-8 w-8 shrink-0 items-center justify-center text-neutral-500 outline-none hover:bg-neutral-100 hover:text-neutral-900 focus-visible:bg-neutral-100 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100 dark:focus-visible:bg-neutral-800',
                        open &&
                            'bg-neutral-100 text-neutral-900 dark:bg-neutral-800 dark:text-neutral-100',
                    )}
                >
                    <Plus className="size-4" />
                </button>
            </PopoverTrigger>
            <PopoverContent
                align="end"
                className={FIELD_EDITOR_POPOVER_CLASS}
                onEscapeKeyDown={keepEscape}
            >
                <FieldEditor
                    onSaved={() => setOpen(false)}
                    onCancel={() => setOpen(false)}
                />
            </PopoverContent>
        </Popover>
    );
}

/**
 * The field editor in a popover anchored to `anchor` (rendered inside the popover's anchor, a div with
 * `anchorClassName`). Creating with insertAfter or insertBefore (a field key) puts the new field there in the view.
 */
export function FieldEditorPopover({
    field,
    open,
    onOpenChange,
    anchor,
    anchorClassName,
    onSaved,
    insertAfter,
    insertBefore,
    initialType,
}: {
    field?: Field;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    anchor: ReactNode;
    anchorClassName?: string;
    onSaved?: (field: Field) => void;
    insertAfter?: string;
    insertBefore?: string;
    initialType?: FieldType;
}) {
    const store = useTableStore();

    function saved(result: Field): void {
        const target = insertAfter ?? insertBefore;

        if (!field && target) {
            const order = store.orderedFields
                .map((candidate) => candidate.key)
                .filter((key) => key !== result.key);
            const index = order.indexOf(target);

            if (index >= 0) {
                order.splice(insertAfter ? index + 1 : index, 0, result.key);
                store.updateView({ order });
            }
        }

        onSaved?.(result);
        onOpenChange(false);
    }

    return (
        <Popover open={open} onOpenChange={onOpenChange}>
            <PopoverAnchor className={anchorClassName}>{anchor}</PopoverAnchor>
            <PopoverContent
                className={FIELD_EDITOR_POPOVER_CLASS}
                onEscapeKeyDown={keepEscape}
            >
                <FieldEditor
                    field={field}
                    initialType={initialType}
                    onSaved={saved}
                    onCancel={() => onOpenChange(false)}
                />
            </PopoverContent>
        </Popover>
    );
}
