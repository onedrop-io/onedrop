import { Check, ChevronDown, ListFilter, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { ChoicePill, UserChip } from './cells';
import { valueKind } from './format';
import type { ValueKind } from './format';
import {
    DATE_RANGES,
    OPERATOR_LABELS,
    operatorsFor,
    RELATIVE_DATES,
    VALUELESS,
} from './filters';
import { Popover, PopoverContent, PopoverTrigger } from './popover';
import { FieldSelect, plural, ToolbarButton } from './sort-menu';
import type {
    CellValue,
    Conjunction,
    Field,
    FilterCondition,
    FilterOperator,
} from './types';
import { useTableStore } from './use-table';

let counter = 0;

/** A new condition id (randomUUID isn't there on plain-http pages). */
export function conditionId(): string {
    counter += 1;

    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `c${Date.now().toString(36)}${counter}`;
}

/** Operators whose value is a list of options or people. */
const MULTI: FilterOperator[] = [
    'isAnyOf',
    'isNoneOf',
    'hasAnyOf',
    'hasAllOf',
    'hasNoneOf',
];

const EXACT_DATE = 'exactDate';

/** The value a condition starts with for a field and operator. */
function defaultValue(
    field: Field,
    operator: FilterOperator,
): CellValue | undefined {
    if (VALUELESS.includes(operator)) {
        return undefined;
    }

    return valueKind(field) === 'boolean' ? false : undefined;
}

/** The value kept when the operator changes, as far as it still fits. */
function convertValue(
    kind: ValueKind,
    from: FilterOperator,
    to: FilterOperator,
    value: CellValue | undefined,
): CellValue | undefined {
    if (VALUELESS.includes(to)) {
        return undefined;
    }

    if (VALUELESS.includes(from)) {
        return kind === 'boolean' ? false : undefined;
    }

    if (kind === 'date' && (from === 'isWithin') !== (to === 'isWithin')) {
        return undefined;
    }

    if (kind === 'choice' || kind === 'user') {
        const list = Array.isArray(value)
            ? (value as CellValue[])
            : value === undefined || value === null
              ? []
              : [value];

        if (MULTI.includes(to)) {
            return list as string[] | number[];
        }

        return list[0] ?? undefined;
    }

    return value;
}

/** The "Filter" toolbar button and its menu. */
export function FilterMenu() {
    const store = useTableStore();
    const { filters } = store.view.config;
    const fields = store.orderedFields;
    const conditions = filters.conditions.filter((condition) =>
        store.fieldsByKey.has(condition.field),
    );
    const activeFields = new Set(
        conditions.map((condition) => condition.field),
    );

    const save = (
        next: FilterCondition[],
        conjunction: Conjunction = filters.conjunction,
    ) => store.updateView({ filters: { conjunction, conditions: next } });

    const update = (id: string, patch: Partial<FilterCondition>) =>
        save(
            conditions.map((condition) =>
                condition.id === id ? { ...condition, ...patch } : condition,
            ),
        );

    const changeField = (condition: FilterCondition, key: string) => {
        const before = store.fieldsByKey.get(condition.field)!;
        const after = store.fieldsByKey.get(key)!;
        const sameKind = valueKind(before) === valueKind(after);
        const operators = operatorsFor(after);
        const operator =
            sameKind && operators.includes(condition.operator)
                ? condition.operator
                : operators[0];
        const keepsValue =
            sameKind &&
            !['choice', 'choices', 'user'].includes(valueKind(after));

        update(condition.id, {
            field: key,
            operator,
            value: keepsValue ? condition.value : defaultValue(after, operator),
        });
    };

    const add = () => {
        const field = fields[0];

        if (!field) {
            return;
        }

        const operator = operatorsFor(field)[0];

        save([
            ...conditions,
            {
                id: conditionId(),
                field: field.key,
                operator,
                value: defaultValue(field, operator),
            },
        ]);
    };

    return (
        <Popover>
            <PopoverTrigger asChild>
                <ToolbarButton
                    icon={ListFilter}
                    label={
                        activeFields.size > 0
                            ? `Filtered by ${plural(activeFields.size, 'field')}`
                            : 'Filter'
                    }
                    tint={activeFields.size > 0 ? 'amber' : null}
                />
            </PopoverTrigger>
            <PopoverContent className="w-[min(40rem,calc(100vw-1rem))] p-3">
                {conditions.length === 0 ? (
                    <p className="pb-2 text-[13px] text-neutral-500">
                        No filter conditions are applied to this view
                    </p>
                ) : (
                    <>
                        <p className="pb-2 text-xs text-neutral-500">
                            In this view, show records
                        </p>
                        <div className="flex flex-col gap-1.5">
                            {conditions.map((condition, index) => {
                                const field = store.fieldsByKey.get(
                                    condition.field,
                                )!;

                                return (
                                    <div
                                        key={condition.id}
                                        className="flex flex-wrap items-center gap-1.5 sm:flex-nowrap"
                                    >
                                        <div className="w-16 shrink-0 text-[13px] text-neutral-600 dark:text-neutral-400">
                                            {index === 0 ? (
                                                <span className="px-2">
                                                    Where
                                                </span>
                                            ) : index === 1 ? (
                                                <Select
                                                    value={filters.conjunction}
                                                    onValueChange={(value) =>
                                                        save(
                                                            conditions,
                                                            value as Conjunction,
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger
                                                        aria-label="And or or"
                                                        className="h-7 w-16 px-2 text-[13px] shadow-none"
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="and">
                                                            and
                                                        </SelectItem>
                                                        <SelectItem value="or">
                                                            or
                                                        </SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            ) : (
                                                <span className="px-2">
                                                    {filters.conjunction}
                                                </span>
                                            )}
                                        </div>
                                        <FieldSelect
                                            fields={fields}
                                            value={condition.field}
                                            onChange={(key) =>
                                                changeField(condition, key)
                                            }
                                            className="w-40"
                                        />
                                        <Select
                                            value={condition.operator}
                                            onValueChange={(value) => {
                                                const operator =
                                                    value as FilterOperator;

                                                update(condition.id, {
                                                    operator,
                                                    value: convertValue(
                                                        valueKind(field),
                                                        condition.operator,
                                                        operator,
                                                        condition.value,
                                                    ),
                                                });
                                            }}
                                        >
                                            <SelectTrigger
                                                aria-label="Operator"
                                                className="h-7 w-36 px-2 text-[13px] shadow-none"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {operatorsFor(field).map(
                                                    (operator) => (
                                                        <SelectItem
                                                            key={operator}
                                                            value={operator}
                                                            className="text-[13px]"
                                                        >
                                                            {
                                                                OPERATOR_LABELS[
                                                                    operator
                                                                ]
                                                            }
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                        <div className="min-w-40 flex-1">
                                            <ConditionValue
                                                field={field}
                                                condition={condition}
                                                onChange={(value) =>
                                                    update(condition.id, {
                                                        value,
                                                    })
                                                }
                                            />
                                        </div>
                                        <button
                                            type="button"
                                            aria-label="Remove condition"
                                            onClick={() =>
                                                save(
                                                    conditions.filter(
                                                        (candidate) =>
                                                            candidate.id !==
                                                            condition.id,
                                                    ),
                                                )
                                            }
                                            className="flex size-7 shrink-0 items-center justify-center rounded text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                                        >
                                            <Trash2 className="size-3.5" />
                                        </button>
                                    </div>
                                );
                            })}
                        </div>
                    </>
                )}
                <button
                    type="button"
                    onClick={add}
                    className="mt-2 inline-flex items-center gap-1.5 rounded px-1.5 py-1 text-[13px] text-blue-600 hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-blue-950"
                >
                    <Plus className="size-3.5" />
                    Add condition
                </button>
            </PopoverContent>
        </Popover>
    );
}

const inputClass =
    'h-7 w-full rounded-md border border-neutral-200 bg-transparent px-2 text-[13px] outline-none placeholder:text-neutral-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-neutral-800';

/** The value part of a condition, fitting the field and operator. */
function ConditionValue({
    field,
    condition,
    onChange,
}: {
    field: Field;
    condition: FilterCondition;
    onChange: (value: CellValue | undefined) => void;
}) {
    const store = useTableStore();
    const { operator, value } = condition;
    const kind = valueKind(field);

    if (VALUELESS.includes(operator)) {
        return null;
    }

    switch (kind) {
        case 'boolean':
            return (
                <label className="flex h-7 items-center gap-2 px-1 text-[13px] text-neutral-600 dark:text-neutral-400">
                    <Checkbox
                        checked={value === true}
                        onCheckedChange={(checked) =>
                            onChange(checked === true)
                        }
                        aria-label="Checked"
                    />
                    {value === true ? 'checked' : 'unchecked'}
                </label>
            );
        case 'number':
            return (
                <input
                    type="number"
                    step="any"
                    aria-label="Value"
                    placeholder="Enter a number"
                    value={typeof value === 'number' ? value : ''}
                    onChange={(event) =>
                        onChange(
                            event.target.value === '' ||
                                Number.isNaN(event.target.valueAsNumber)
                                ? null
                                : event.target.valueAsNumber,
                        )
                    }
                    className={inputClass}
                />
            );
        case 'date':
            return operator === 'isWithin' ? (
                <Select
                    value={
                        typeof value === 'string' && value in DATE_RANGES
                            ? value
                            : ''
                    }
                    onValueChange={onChange}
                >
                    <SelectTrigger
                        aria-label="Date range"
                        className="h-7 w-full px-2 text-[13px] shadow-none"
                    >
                        <SelectValue placeholder="Pick a range" />
                    </SelectTrigger>
                    <SelectContent>
                        {Object.entries(DATE_RANGES).map(([key, label]) => (
                            <SelectItem
                                key={key}
                                value={key}
                                className="text-[13px]"
                            >
                                {label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            ) : (
                <DateValue value={value} onChange={onChange} />
            );
        case 'choice':
        case 'choices':
            return (
                <PickList
                    multiple={MULTI.includes(operator)}
                    value={value}
                    onChange={onChange}
                    placeholder="Select an option"
                    options={(field.options.choices ?? []).map((choice) => ({
                        id: choice.name,
                        label: choice.name,
                        view: <ChoicePill field={field} name={choice.name} />,
                    }))}
                />
            );
        case 'user':
            return (
                <PickList
                    multiple={MULTI.includes(operator)}
                    value={value}
                    onChange={onChange}
                    placeholder="Select a person"
                    options={store.users.map((user) => ({
                        id: user.id,
                        label: user.name,
                        view: <UserChip user={user} />,
                    }))}
                />
            );
        default:
            return (
                <input
                    aria-label="Value"
                    placeholder="Enter a value"
                    value={typeof value === 'string' ? value : ''}
                    onChange={(event) => onChange(event.target.value)}
                    className={inputClass}
                />
            );
    }
}

function DateValue({
    value,
    onChange,
}: {
    value: CellValue | undefined;
    onChange: (value: CellValue | undefined) => void;
}) {
    const relative = typeof value === 'string' && value in RELATIVE_DATES;
    const [mode, setMode] = useState(relative ? String(value) : EXACT_DATE);
    const current = relative ? String(value) : mode;

    return (
        <div className="flex gap-1.5">
            <Select
                value={current}
                onValueChange={(next) => {
                    setMode(next);
                    onChange(next === EXACT_DATE ? undefined : next);
                }}
            >
                <SelectTrigger
                    aria-label="Date"
                    className="h-7 w-36 shrink-0 px-2 text-[13px] shadow-none"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {Object.entries(RELATIVE_DATES).map(([key, label]) => (
                        <SelectItem
                            key={key}
                            value={key}
                            className="text-[13px]"
                        >
                            {label}
                        </SelectItem>
                    ))}
                    <SelectItem value={EXACT_DATE} className="text-[13px]">
                        exact date
                    </SelectItem>
                </SelectContent>
            </Select>
            {current === EXACT_DATE && (
                <input
                    type="date"
                    aria-label="Exact date"
                    value={typeof value === 'string' ? value.slice(0, 10) : ''}
                    onChange={(event) =>
                        onChange(event.target.value || undefined)
                    }
                    className={inputClass}
                />
            )}
        </div>
    );
}

interface PickOption {
    id: string | number;
    label: string;
    view: ReactNode;
}

/** Picks one or several options (select options or people), with search. */
function PickList({
    options,
    value,
    multiple,
    onChange,
    placeholder,
}: {
    options: PickOption[];
    value: CellValue | undefined;
    multiple: boolean;
    onChange: (value: CellValue | undefined) => void;
    placeholder: string;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const picked: (string | number)[] = Array.isArray(value)
        ? (value as (string | number)[])
        : typeof value === 'string' || typeof value === 'number'
          ? [value]
          : [];
    const needle = query.trim().toLowerCase();
    const shown = options.filter((option) =>
        option.label.toLowerCase().includes(needle),
    );
    const current = Math.min(active, shown.length - 1);

    const toggle = (option: PickOption) => {
        if (!multiple) {
            onChange(option.id);
            setOpen(false);

            return;
        }

        const next = picked.includes(option.id)
            ? picked.filter((id) => id !== option.id)
            : [...picked, option.id];

        onChange(next as string[] | number[]);
    };

    return (
        <Popover
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                setQuery('');
                setActive(0);
            }}
        >
            <PopoverTrigger asChild>
                <button
                    type="button"
                    aria-label={placeholder}
                    className="flex min-h-7 w-full items-center gap-1 rounded-md border border-neutral-200 px-1.5 py-0.5 text-left text-[13px] outline-none focus-visible:border-blue-500 dark:border-neutral-800"
                >
                    <span className="flex min-w-0 flex-1 flex-wrap gap-1">
                        {picked.length === 0 ? (
                            <span className="px-0.5 text-neutral-400">
                                {placeholder}
                            </span>
                        ) : (
                            options
                                .filter((option) => picked.includes(option.id))
                                .map((option) => (
                                    <span key={option.id}>{option.view}</span>
                                ))
                        )}
                    </span>
                    <ChevronDown className="size-3.5 shrink-0 text-neutral-400" />
                </button>
            </PopoverTrigger>
            <PopoverContent className="w-60 p-1.5">
                <input
                    autoFocus
                    value={query}
                    onChange={(event) => {
                        setQuery(event.target.value);
                        setActive(0);
                    }}
                    onKeyDown={(event) => {
                        if (event.key === 'ArrowDown') {
                            event.preventDefault();
                            setActive(Math.min(current + 1, shown.length - 1));
                        } else if (event.key === 'ArrowUp') {
                            event.preventDefault();
                            setActive(Math.max(current - 1, 0));
                        } else if (event.key === 'Enter' && shown[current]) {
                            event.preventDefault();
                            toggle(shown[current]);
                        }
                    }}
                    placeholder="Find an option"
                    aria-label="Find an option"
                    className="mb-1 h-8 w-full border-b border-neutral-200 bg-transparent px-2 text-sm outline-none placeholder:text-neutral-400 dark:border-neutral-800"
                />
                <div
                    role="listbox"
                    aria-multiselectable={multiple}
                    className="max-h-60 overflow-y-auto"
                >
                    {shown.map((option, index) => (
                        <button
                            key={option.id}
                            type="button"
                            role="option"
                            aria-selected={picked.includes(option.id)}
                            onMouseEnter={() => setActive(index)}
                            onClick={() => toggle(option)}
                            className={cn(
                                'flex w-full items-center gap-2 rounded px-2 py-1 text-left',
                                index === current &&
                                    'bg-neutral-100 dark:bg-neutral-800',
                            )}
                        >
                            <span className="min-w-0 flex-1 truncate">
                                {option.view}
                            </span>
                            {picked.includes(option.id) && (
                                <Check className="size-3.5 shrink-0 text-blue-600" />
                            )}
                        </button>
                    ))}
                    {shown.length === 0 && (
                        <p className="px-2 py-1.5 text-[13px] text-neutral-500">
                            Nothing matches
                        </p>
                    )}
                </div>
            </PopoverContent>
        </Popover>
    );
}
