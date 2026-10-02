import { Check, GripVertical, Plus, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { DragEvent, KeyboardEvent } from 'react';
import { cn } from '@/lib/utils';
import { CHOICE_COLORS, CHOICE_SWATCHES, nextChoiceColor } from './colors';
import {
    Popover,
    PopoverClose,
    PopoverContent,
    PopoverTrigger,
} from './popover';
import type { Choice, ChoiceColor } from './types';

/** What the editor knows about each row besides the choice: a React key, and its name when the editor opened. */
interface RowMeta {
    id: string;
    original: string | null;
}

let rowSequence = 0;

function newRowId(): string {
    rowSequence += 1;

    return `choice-${rowSequence}`;
}

/**
 * The options of a single or multiple select field: colors, names, order.
 *
 * `onRename` is called with every change, with the whole map of options that existed when the editor opened and
 * have been renamed since (old name => new name), ready for FieldInput.renames. Removed options drop out of it.
 */
export function ChoicesEditor({
    choices,
    onChange,
    onRename,
    disabled = false,
}: {
    choices: Choice[];
    onChange: (choices: Choice[]) => void;
    onRename?: (renames: Record<string, string>) => void;
    disabled?: boolean;
}) {
    const [meta, setMeta] = useState<RowMeta[]>(() =>
        choices.map((choice) => ({ id: newRowId(), original: choice.name })),
    );
    const [focus, setFocus] = useState<{ id: string; at: number } | null>(null);
    const [dragging, setDragging] = useState<number | null>(null);
    const [over, setOver] = useState<number | null>(null);
    const inputs = useRef(new Map<string, HTMLInputElement>());

    // Someone else changed the list's length (it shouldn't happen): keep keys stable enough to render.
    const rows: RowMeta[] = choices.map(
        (_, index) => meta[index] ?? { id: `extra-${index}`, original: null },
    );

    useEffect(() => {
        if (focus) {
            inputs.current.get(focus.id)?.focus();
        }
    }, [focus]);

    const counts = new Map<string, number>();

    for (const choice of choices) {
        const name = choice.name.trim().toLowerCase();

        if (name !== '') {
            counts.set(name, (counts.get(name) ?? 0) + 1);
        }
    }

    function commit(next: Choice[], nextMeta: RowMeta[]): void {
        setMeta(nextMeta);
        onChange(next);

        if (onRename) {
            const renames: Record<string, string> = {};

            nextMeta.forEach((row, index) => {
                const name = next[index]?.name.trim() ?? '';

                if (
                    row.original !== null &&
                    name !== '' &&
                    name !== row.original
                ) {
                    renames[row.original] = name;
                }
            });

            onRename(renames);
        }
    }

    function focusRow(id: string): void {
        setFocus({ id, at: Date.now() });
    }

    function add(): void {
        const row = { id: newRowId(), original: null };

        commit(
            [
                ...choices,
                {
                    name: '',
                    color: nextChoiceColor(
                        choices.map((choice) => choice.color),
                    ),
                },
            ],
            [...rows, row],
        );
        focusRow(row.id);
    }

    function update(index: number, patch: Partial<Choice>): void {
        commit(
            choices.map((choice, position) =>
                position === index ? { ...choice, ...patch } : choice,
            ),
            rows,
        );
    }

    function remove(index: number): void {
        commit(
            choices.filter((_, position) => position !== index),
            rows.filter((_, position) => position !== index),
        );
    }

    function move(from: number, to: number): void {
        if (from === to) {
            return;
        }

        const nextChoices = [...choices];
        const nextRows = [...rows];
        const [choice] = nextChoices.splice(from, 1);
        const [row] = nextRows.splice(from, 1);

        nextChoices.splice(to, 0, choice);
        nextRows.splice(to, 0, row);
        commit(nextChoices, nextRows);
    }

    function onKeyDown(
        event: KeyboardEvent<HTMLInputElement>,
        index: number,
    ): void {
        if (event.key === 'Enter') {
            event.preventDefault();

            if (index === choices.length - 1) {
                add();
            } else {
                focusRow(rows[index + 1].id);
            }
        } else if (event.key === 'ArrowDown' && index < choices.length - 1) {
            event.preventDefault();
            focusRow(rows[index + 1].id);
        } else if (event.key === 'ArrowUp' && index > 0) {
            event.preventDefault();
            focusRow(rows[index - 1].id);
        } else if (
            event.key === 'Backspace' &&
            choices[index].name === '' &&
            choices.length > 1
        ) {
            event.preventDefault();
            remove(index);

            if (index > 0) {
                focusRow(rows[index - 1].id);
            }
        }
    }

    function onDragStart(event: DragEvent<HTMLElement>, index: number): void {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(index));

        const row = event.currentTarget.closest('li');

        if (row) {
            event.dataTransfer.setDragImage(row, 12, 12);
        }

        setDragging(index);
    }

    function onDragEnd(): void {
        setDragging(null);
        setOver(null);
    }

    return (
        <div
            className="space-y-1.5"
            // Drags here reorder options, not whatever holds the editor (like a draggable column header).
            onDragStart={(event) => event.stopPropagation()}
            onDragOver={(event) => event.stopPropagation()}
            onDrop={(event) => event.stopPropagation()}
            onDragEnd={(event) => event.stopPropagation()}
        >
            {choices.length === 0 ? (
                <p className="rounded-md border border-dashed border-neutral-200 px-3 py-2 text-xs text-neutral-500 dark:border-neutral-800 dark:text-neutral-400">
                    No options yet. Add the ones people can pick.
                </p>
            ) : (
                <ul
                    className="max-h-56 space-y-0.5 overflow-y-auto"
                    aria-label="Options"
                >
                    {choices.map((choice, index) => {
                        const id = rows[index].id;
                        const duplicate =
                            (counts.get(choice.name.trim().toLowerCase()) ??
                                0) > 1;

                        return (
                            <li
                                key={id}
                                onDragOver={(event) => {
                                    if (dragging === null) {
                                        return;
                                    }

                                    event.preventDefault();
                                    event.dataTransfer.dropEffect = 'move';
                                    setOver(index);
                                }}
                                onDrop={(event) => {
                                    event.preventDefault();

                                    if (dragging !== null) {
                                        move(dragging, index);
                                    }

                                    onDragEnd();
                                }}
                                className={cn(
                                    'group flex items-center gap-1 rounded-md py-0.5 pr-0.5',
                                    dragging === index && 'opacity-40',
                                    over === index &&
                                        dragging !== null &&
                                        dragging !== index &&
                                        (dragging < index
                                            ? 'shadow-[0_2px_0_0_var(--color-blue-500)]'
                                            : 'shadow-[0_-2px_0_0_var(--color-blue-500)]'),
                                )}
                            >
                                <span
                                    draggable={!disabled}
                                    onDragStart={(event) =>
                                        onDragStart(event, index)
                                    }
                                    onDragEnd={onDragEnd}
                                    aria-hidden="true"
                                    title="Drag to reorder"
                                    className={cn(
                                        'flex h-7 w-4 shrink-0 items-center justify-center text-neutral-300 dark:text-neutral-600',
                                        !disabled &&
                                            'cursor-grab group-hover:text-neutral-500 active:cursor-grabbing',
                                    )}
                                >
                                    <GripVertical className="size-3.5" />
                                </span>
                                <ColorPicker
                                    color={choice.color}
                                    label={choice.name || 'new option'}
                                    disabled={disabled}
                                    onChange={(color) =>
                                        update(index, { color })
                                    }
                                />
                                <input
                                    ref={(element) => {
                                        if (element) {
                                            inputs.current.set(id, element);
                                        } else {
                                            inputs.current.delete(id);
                                        }
                                    }}
                                    value={choice.name}
                                    disabled={disabled}
                                    onChange={(event) =>
                                        update(index, {
                                            name: event.target.value,
                                        })
                                    }
                                    onKeyDown={(event) =>
                                        onKeyDown(event, index)
                                    }
                                    placeholder="Option name"
                                    aria-label={`Option ${index + 1}`}
                                    aria-invalid={duplicate || undefined}
                                    title={
                                        duplicate
                                            ? 'Another option has this name'
                                            : undefined
                                    }
                                    className={cn(
                                        'h-7 min-w-0 flex-1 rounded-md border border-transparent bg-transparent px-2 text-sm outline-none placeholder:text-neutral-400 hover:border-neutral-200 focus:border-blue-500 focus:bg-white dark:hover:border-neutral-700 dark:focus:bg-neutral-900',
                                        duplicate &&
                                            'border-red-300 hover:border-red-300 dark:border-red-800 dark:hover:border-red-800',
                                    )}
                                />
                                <button
                                    type="button"
                                    onClick={() => remove(index)}
                                    disabled={disabled}
                                    aria-label={`Remove ${choice.name || 'option'}`}
                                    className="flex size-6 shrink-0 items-center justify-center rounded text-neutral-400 opacity-0 transition group-focus-within:opacity-100 group-hover:opacity-100 hover:bg-neutral-100 hover:text-neutral-700 focus-visible:opacity-100 dark:hover:bg-neutral-800 dark:hover:text-neutral-200"
                                >
                                    <X className="size-3.5" />
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
            {!disabled && (
                <button
                    type="button"
                    onClick={add}
                    className="flex h-7 items-center gap-1.5 rounded-md px-1.5 text-sm text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                >
                    <Plus className="size-3.5" />
                    Add option
                </button>
            )}
        </div>
    );
}

function ColorPicker({
    color,
    label,
    disabled,
    onChange,
}: {
    color: ChoiceColor;
    label: string;
    disabled: boolean;
    onChange: (color: ChoiceColor) => void;
}) {
    return (
        <Popover>
            <PopoverTrigger
                type="button"
                disabled={disabled}
                aria-label={`Color of ${label}: ${color}`}
                className="flex size-6 shrink-0 items-center justify-center rounded-md hover:bg-neutral-100 disabled:pointer-events-none dark:hover:bg-neutral-800"
            >
                <span
                    className={cn(
                        'size-3.5 rounded-full',
                        CHOICE_SWATCHES[color],
                    )}
                />
            </PopoverTrigger>
            <PopoverContent className="w-auto p-2" sideOffset={2}>
                <div
                    className="grid grid-cols-7 gap-1"
                    role="group"
                    aria-label="Colors"
                >
                    {CHOICE_COLORS.map((candidate) => (
                        <PopoverClose
                            key={candidate}
                            type="button"
                            onClick={() => onChange(candidate)}
                            aria-label={candidate}
                            aria-pressed={candidate === color}
                            className={cn(
                                'flex size-6 items-center justify-center rounded-full ring-offset-1 ring-offset-white outline-none hover:ring-2 hover:ring-neutral-300 focus-visible:ring-2 focus-visible:ring-blue-500 dark:ring-offset-neutral-950 dark:hover:ring-neutral-600',
                                CHOICE_SWATCHES[candidate],
                            )}
                        >
                            {candidate === color && (
                                <Check
                                    className="size-3.5 text-white"
                                    strokeWidth={3}
                                />
                            )}
                        </PopoverClose>
                    ))}
                </div>
            </PopoverContent>
        </Popover>
    );
}
