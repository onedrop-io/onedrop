import { useVirtualizer } from '@tanstack/react-virtual';
import { ChevronDown, ChevronRight, Maximize2, Plus } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type {
    CSSProperties,
    KeyboardEvent as ReactKeyboardEvent,
    MouseEvent as ReactMouseEvent,
} from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import { alignsRight, CellView, CheckboxMark, Stars } from './cells';
import { parseTsv, toTsv } from './clipboard';
import { AddFieldButton } from './field-editor';
import { FIELD_ICONS } from './field-icons';
import { FieldMenu } from './field-menu';
import { SUMMARY_LABELS, summariesFor, summarize } from './filters';
import type { GroupNode } from './filters';
import {
    asText,
    displayText,
    formatNumber,
    isEditable,
    isEmpty,
    parseText,
} from './format';
import { ValueInput } from './inputs';
import { Popover, PopoverAnchor, PopoverContent } from './popover';
import type {
    CellValue,
    Field,
    FieldType,
    RecordChanges,
    RowHeight,
    TableRecord,
} from './types';
import { useTableStore } from './use-table';
import type { TableStore } from './use-table';

const ROW_HEIGHTS: Record<RowHeight, number> = {
    short: 33,
    medium: 57,
    tall: 89,
    extraTall: 129,
};
const HEADER_HEIGHT = 34;
const GROUP_HEIGHT = 41;
const GUTTER = 72;
const MIN_WIDTH = 60;
const MAX_WIDTH = 800;

/** Types edited by typing into the cell; the rest open a picker. */
const TYPED: FieldType[] = [
    'text',
    'longText',
    'number',
    'currency',
    'percent',
    'url',
    'email',
    'phone',
];
/** Types changed right in the cell: no editor opens. */
const DIRECT: FieldType[] = ['checkbox', 'rating'];

function defaultWidth(field: Field): number {
    if (field.primary) {
        return 220;
    }

    switch (field.type) {
        case 'checkbox':
            return 110;
        case 'number':
        case 'currency':
        case 'percent':
        case 'count':
        case 'rating':
            return 130;
        case 'date':
        case 'createdAt':
        case 'updatedAt':
            return 160;
        case 'longText':
            return 260;
        default:
            return 180;
    }
}

type Item =
    | { kind: 'group'; node: GroupNode; collapsed: boolean }
    | { kind: 'record'; record: TableRecord; number: number }
    | { kind: 'add'; group: GroupNode | null };

interface Cell {
    row: number;
    col: number;
}

interface Editing {
    recordId: number;
    fieldKey: string;
    /** The key typed to start editing, replacing the value */
    text?: string;
}

function flatten(
    rows: TableRecord[],
    groups: GroupNode[] | null,
    collapsed: Set<string>,
    canCreate: boolean,
): Item[] {
    const items: Item[] = [];
    let number = 0;

    const walk = (nodes: GroupNode[]) => {
        for (const node of nodes) {
            const isCollapsed = collapsed.has(node.id);

            items.push({ kind: 'group', node, collapsed: isCollapsed });

            if (isCollapsed) {
                continue;
            }

            if (node.children) {
                walk(node.children);
            } else {
                for (const record of node.records) {
                    number += 1;
                    items.push({ kind: 'record', record, number });
                }

                if (canCreate) {
                    items.push({ kind: 'add', group: node });
                }
            }
        }
    };

    if (groups) {
        walk(groups);
    } else {
        for (const record of rows) {
            number += 1;
            items.push({ kind: 'record', record, number });
        }

        if (canCreate) {
            items.push({ kind: 'add', group: null });
        }
    }

    return items;
}

/** Values a new record in a group starts with: the group's values, where they can be set. */
function groupValues(
    group: GroupNode | null,
    groups: GroupNode[] | null,
): Record<string, CellValue> {
    const values: Record<string, CellValue> = {};

    if (!group || !groups) {
        return values;
    }

    const path: GroupNode[] = [];
    const find = (nodes: GroupNode[], trail: GroupNode[]): boolean => {
        for (const node of nodes) {
            if (node.id === group.id) {
                path.push(...trail, node);

                return true;
            }

            if (node.children && find(node.children, [...trail, node])) {
                return true;
            }
        }

        return false;
    };

    find(groups, []);

    for (const node of path) {
        if (isEditable(node.field) && !isEmpty(node.value)) {
            values[node.field.key] = node.value;
        }
    }

    return values;
}

function collapsedKey(table: string): string {
    return `table:${table}:collapsed`;
}

export function Grid() {
    'use no memo';

    const store = useTableStore();
    const { view, can } = store;
    const fields = store.visibleFields;
    const scrollRef = useRef<HTMLDivElement>(null);
    const [active, setActive] = useState<Cell | null>(null);
    const [rangeEnd, setRangeEnd] = useState<Cell | null>(null);
    const [editing, setEditing] = useState<Editing | null>(null);
    const [checked, setChecked] = useState<Set<number>>(new Set());
    const [collapsed, setCollapsed] = useState<Set<string>>(() => {
        try {
            return new Set(
                JSON.parse(
                    window.localStorage.getItem(collapsedKey(store.key)) ??
                        '[]',
                ) as string[],
            );
        } catch {
            return new Set();
        }
    });
    const [liveWidths, setLiveWidths] = useState<Record<string, number>>({});
    const [fillTo, setFillTo] = useState<number | null>(null);
    const [menu, setMenu] = useState<{ x: number; y: number } | null>(null);
    const [dragKey, setDragKey] = useState<string | null>(null);
    const [dropKey, setDropKey] = useState<string | null>(null);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const selecting = useRef(false);
    const filling = useRef(false);

    const rowHeight = ROW_HEIGHTS[view.config.rowHeight] ?? ROW_HEIGHTS.short;
    const wrap = view.config.rowHeight !== 'short';

    const items = useMemo(
        () => flatten(store.rows, store.groups, collapsed, can.create),
        [store.rows, store.groups, collapsed, can.create],
    );
    const navRecords = useMemo(
        () =>
            items.flatMap((item) =>
                item.kind === 'record' ? [item.record] : [],
            ),
        [items],
    );
    const itemIndexByRecord = useMemo(() => {
        const map = new Map<number, number>();

        items.forEach((item, index) => {
            if (item.kind === 'record') {
                map.set(item.record.id, index);
            }
        });

        return map;
    }, [items]);
    const rowIndexByRecord = useMemo(
        () => new Map(navRecords.map((record, index) => [record.id, index])),
        [navRecords],
    );

    const widths = fields.map(
        (field) =>
            liveWidths[field.key] ??
            view.config.widths[field.key] ??
            defaultWidth(field),
    );
    const lefts: number[] = [];

    widths.reduce((left, width, index) => {
        lefts[index] = left;

        return left + width;
    }, GUTTER);

    const addFieldWidth = can.manageFields ? 44 : 0;
    const totalWidth =
        GUTTER + widths.reduce((sum, width) => sum + width, 0) + addFieldWidth;
    const frozenWidth = GUTTER + (widths[0] ?? 0);

    const virtualizer = useVirtualizer({
        count: items.length,
        getScrollElement: () => scrollRef.current,
        estimateSize: (index) =>
            items[index]?.kind === 'group'
                ? GROUP_HEIGHT
                : items[index]?.kind === 'add'
                  ? ROW_HEIGHTS.short
                  : rowHeight,
        getItemKey: (index) => {
            const item = items[index];

            if (!item) {
                return index;
            }

            return item.kind === 'record'
                ? `r${item.record.id}`
                : item.kind === 'group'
                  ? `g${item.node.id}`
                  : `a${item.group?.id ?? ''}`;
        },
        overscan: 12,
        scrollMargin: HEADER_HEIGHT,
    });

    useEffect(() => {
        virtualizer.measure();
    }, [rowHeight, virtualizer]);

    // Keep the cursor on a cell that still exists.
    const rowCount = navRecords.length;
    const colCount = fields.length;
    const clampedActive =
        active && rowCount > 0 && colCount > 0
            ? {
                  row: Math.min(active.row, rowCount - 1),
                  col: Math.min(active.col, colCount - 1),
              }
            : null;
    const rect = clampedActive
        ? {
              r1: Math.min(
                  clampedActive.row,
                  rangeEnd?.row ?? clampedActive.row,
              ),
              r2: Math.max(
                  clampedActive.row,
                  rangeEnd?.row ?? clampedActive.row,
              ),
              c1: Math.min(
                  clampedActive.col,
                  rangeEnd?.col ?? clampedActive.col,
              ),
              c2: Math.max(
                  clampedActive.col,
                  rangeEnd?.col ?? clampedActive.col,
              ),
          }
        : null;
    const activeRecord = clampedActive ? navRecords[clampedActive.row] : null;
    const activeField = clampedActive ? fields[clampedActive.col] : null;

    const toggleGroup = (id: string) => {
        setCollapsed((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            window.localStorage.setItem(
                collapsedKey(store.key),
                JSON.stringify([...next]),
            );

            return next;
        });
    };

    const scrollToCell = (cell: Cell) => {
        const record = navRecords[cell.row];
        const element = scrollRef.current;

        if (record) {
            const index = itemIndexByRecord.get(record.id);

            if (index !== undefined) {
                virtualizer.scrollToIndex(index, { align: 'auto' });
            }
        }

        if (element && cell.col > 0) {
            const left = lefts[cell.col];
            const right = left + widths[cell.col];

            if (left < element.scrollLeft + frozenWidth) {
                element.scrollLeft = left - frozenWidth;
            } else if (right > element.scrollLeft + element.clientWidth) {
                element.scrollLeft = right - element.clientWidth;
            }
        }
    };

    const moveTo = (cell: Cell, extend = false) => {
        const next = {
            row: Math.max(0, Math.min(rowCount - 1, cell.row)),
            col: Math.max(0, Math.min(colCount - 1, cell.col)),
        };

        if (extend) {
            setRangeEnd(next);
        } else {
            setActive(next);
            setRangeEnd(null);
        }

        scrollToCell(next);
    };

    const focusGrid = () => {
        scrollRef.current?.focus({ preventScroll: true });
    };

    const startEditing = (text?: string) => {
        if (!activeRecord || !activeField || !can.edit) {
            return;
        }

        if (!isEditable(activeField)) {
            if (text === undefined) {
                store.expand(activeRecord.id);
            }

            return;
        }

        if (activeField.type === 'checkbox') {
            store.setCell(
                activeRecord.id,
                activeField.key,
                !activeRecord.values[activeField.key],
            );

            return;
        }

        if (activeField.type === 'rating') {
            return;
        }

        setRangeEnd(null);
        setEditing({
            recordId: activeRecord.id,
            fieldKey: activeField.key,
            text: TYPED.includes(activeField.type) ? text : undefined,
        });
    };

    const stopEditing = (move?: 'down' | 'right' | 'left') => {
        setEditing(null);

        if (move && clampedActive) {
            moveTo({
                row: clampedActive.row + (move === 'down' ? 1 : 0),
                col:
                    clampedActive.col +
                    (move === 'right' ? 1 : move === 'left' ? -1 : 0),
            });
        }

        focusGrid();
    };

    /** The changes that set every editable cell in the range from `valueFor`. */
    const rangeUpdates = (
        valueFor: (
            record: TableRecord,
            field: Field,
            row: number,
            col: number,
        ) => CellValue | undefined,
        rows: [number, number],
        cols: [number, number],
    ): RecordChanges['updates'] => {
        const updates: NonNullable<RecordChanges['updates']> = [];

        for (let row = rows[0]; row <= rows[1]; row += 1) {
            const record = navRecords[row];

            if (!record) {
                continue;
            }

            const values: Record<string, CellValue> = {};

            for (let col = cols[0]; col <= cols[1]; col += 1) {
                const field = fields[col];

                if (!field || !isEditable(field)) {
                    continue;
                }

                const value = valueFor(record, field, row, col);

                if (value !== undefined) {
                    values[field.key] = value;
                }
            }

            if (Object.keys(values).length > 0) {
                updates.push({ id: record.id, values });
            }
        }

        return updates;
    };

    const emptyValue = (field: Field): CellValue => {
        const parsed = parseText(field, '', store.context);

        return parsed.ok ? parsed.value : null;
    };

    const clearRange = () => {
        if (!rect || !can.edit) {
            return;
        }

        const updates = rangeUpdates(
            (record, field) =>
                isEmpty(record.values[field.key] ?? null) &&
                record.values[field.key] !== true
                    ? undefined
                    : emptyValue(field),
            [rect.r1, rect.r2],
            [rect.c1, rect.c2],
        );

        if (updates && updates.length > 0) {
            void store.change({ updates });
        }
    };

    /** Copies the range's top rows down over the rest of it, or the row above into a one-row range. */
    const fillDown = (toRow?: number) => {
        if (!rect || !can.edit) {
            return;
        }

        let sourceRows: [number, number];
        let targetRows: [number, number];

        if (toRow !== undefined) {
            if (toRow <= rect.r2) {
                return;
            }

            sourceRows = [rect.r1, rect.r2];
            targetRows = [rect.r2 + 1, toRow];
        } else if (rect.r1 === rect.r2) {
            if (rect.r1 === 0) {
                return;
            }

            sourceRows = [rect.r1 - 1, rect.r1 - 1];
            targetRows = [rect.r1, rect.r1];
        } else {
            sourceRows = [rect.r1, rect.r1];
            targetRows = [rect.r1 + 1, rect.r2];
        }

        const height = sourceRows[1] - sourceRows[0] + 1;
        const updates = rangeUpdates(
            (_record, field, row) => {
                const source =
                    navRecords[
                        sourceRows[0] + ((row - targetRows[0]) % height)
                    ];

                return source?.values[field.key] ?? emptyValue(field);
            },
            targetRows,
            [rect.c1, rect.c2],
        );

        if (updates && updates.length > 0) {
            void store.change({ updates });
        }

        if (toRow !== undefined) {
            setRangeEnd({ row: toRow, col: rect.c2 });
            setActive({ row: rect.r1, col: rect.c1 });
        }
    };

    const copyText = (): string | null => {
        if (!rect) {
            return null;
        }

        const rows: string[][] = [];

        for (let row = rect.r1; row <= rect.r2; row += 1) {
            const record = navRecords[row];

            rows.push(
                fields
                    .slice(rect.c1, rect.c2 + 1)
                    .map((field) =>
                        displayText(
                            field,
                            record?.values[field.key],
                            store.context,
                        ),
                    ),
            );
        }

        return toTsv(rows);
    };

    const paste = (text: string) => {
        if (!rect || !can.edit) {
            return;
        }

        const block = parseTsv(text);
        const single = block.length === 1 && block[0].length === 1;
        const rowsToFill = single ? rect.r2 - rect.r1 + 1 : block.length;
        const colsToFill = single ? rect.c2 - rect.c1 + 1 : block[0].length;
        const updates: NonNullable<RecordChanges['updates']> = [];
        const creates: NonNullable<RecordChanges['creates']> = [];
        let skipped = 0;

        for (let i = 0; i < rowsToFill; i += 1) {
            const record = navRecords[rect.r1 + i];
            const values: Record<string, CellValue> = {};

            for (let j = 0; j < colsToFill; j += 1) {
                const field = fields[rect.c1 + j];

                if (!field || !isEditable(field)) {
                    continue;
                }

                const cellText = single ? block[0][0] : (block[i]?.[j] ?? '');
                const parsed = parseText(
                    field,
                    cellText,
                    store.context,
                    can.manageFields,
                );

                if (parsed.ok) {
                    values[field.key] = parsed.value;
                } else {
                    skipped += 1;
                }
            }

            if (record) {
                updates.push({ id: record.id, values });
            } else if (can.create) {
                creates.push({ values });
            }
        }

        void store.change({ updates, creates }).then((result) => {
            if (result && skipped > 0) {
                store.setError(
                    `${skipped} ${skipped === 1 ? "cell didn't" : "cells didn't"} fit ${skipped === 1 ? 'its field' : 'their fields'} and ${skipped === 1 ? 'was' : 'were'} left as ${skipped === 1 ? 'it was' : 'they were'}.`,
                );
            }
        });

        setRangeEnd({
            row: Math.min(
                rect.r1 + rowsToFill - 1,
                rowCount - 1 + creates.length,
            ),
            col: Math.min(rect.c1 + colsToFill - 1, colCount - 1),
        });
    };

    // Copy, cut and paste through the clipboard events, so no permission prompt is needed.
    const handlers = useRef({ copyText, paste, clearRange });

    useEffect(() => {
        handlers.current = { copyText, paste, clearRange };
    });

    useEffect(() => {
        const inGrid = () =>
            scrollRef.current !== null &&
            document.activeElement === scrollRef.current;

        const onCopy = (event: ClipboardEvent) => {
            if (!inGrid()) {
                return;
            }

            const text = handlers.current.copyText();

            if (text !== null) {
                event.clipboardData?.setData('text/plain', text);
                event.preventDefault();
            }
        };
        const onCut = (event: ClipboardEvent) => {
            onCopy(event);

            if (inGrid()) {
                handlers.current.clearRange();
            }
        };
        const onPaste = (event: ClipboardEvent) => {
            if (!inGrid()) {
                return;
            }

            const text = event.clipboardData?.getData('text/plain');

            if (text) {
                event.preventDefault();
                handlers.current.paste(text);
            }
        };

        document.addEventListener('copy', onCopy);
        document.addEventListener('cut', onCut);
        document.addEventListener('paste', onPaste);

        return () => {
            document.removeEventListener('copy', onCopy);
            document.removeEventListener('cut', onCut);
            document.removeEventListener('paste', onPaste);
        };
    }, []);

    useEffect(() => {
        const stop = () => {
            selecting.current = false;

            if (filling.current) {
                filling.current = false;
                setFillTo((target) => {
                    if (target !== null) {
                        fillDown(target);
                    }

                    return null;
                });
            }
        };

        window.addEventListener('mouseup', stop);

        return () => window.removeEventListener('mouseup', stop);
    });

    const onKeyDown = (event: ReactKeyboardEvent<HTMLDivElement>) => {
        if (editing || event.target !== scrollRef.current) {
            return;
        }

        const meta = event.metaKey || event.ctrlKey;
        const key = event.key;

        if (meta && key.toLowerCase() === 'z') {
            event.preventDefault();

            if (event.shiftKey) {
                store.redo();
            } else {
                store.undo();
            }

            return;
        }

        if (meta && key.toLowerCase() === 'y') {
            event.preventDefault();
            store.redo();

            return;
        }

        if (!clampedActive) {
            if (key.startsWith('Arrow') || key === 'Tab') {
                event.preventDefault();
                moveTo({ row: 0, col: 0 });
            }

            return;
        }

        const { row, col } =
            rangeEnd && event.shiftKey ? rangeEnd : clampedActive;

        switch (key) {
            case 'ArrowUp':
                event.preventDefault();
                moveTo({ row: meta ? 0 : row - 1, col }, event.shiftKey);

                return;
            case 'ArrowDown':
                event.preventDefault();
                moveTo(
                    { row: meta ? rowCount - 1 : row + 1, col },
                    event.shiftKey,
                );

                return;
            case 'ArrowLeft':
                event.preventDefault();
                moveTo({ row, col: meta ? 0 : col - 1 }, event.shiftKey);

                return;
            case 'ArrowRight':
                event.preventDefault();
                moveTo(
                    { row, col: meta ? colCount - 1 : col + 1 },
                    event.shiftKey,
                );

                return;
            case 'Tab':
                event.preventDefault();

                if (event.shiftKey) {
                    moveTo(
                        col > 0
                            ? { row, col: col - 1 }
                            : { row: row - 1, col: colCount - 1 },
                    );
                } else {
                    moveTo(
                        col < colCount - 1
                            ? { row, col: col + 1 }
                            : { row: row + 1, col: 0 },
                    );
                }

                return;
            case 'Enter':
            case 'F2':
                event.preventDefault();

                if (event.shiftKey && key === 'Enter') {
                    if (activeRecord) {
                        store.expand(activeRecord.id);
                    }
                } else {
                    startEditing();
                }

                return;
            case 'Escape':
                setRangeEnd(null);
                setChecked(new Set());

                return;
            case 'Delete':
            case 'Backspace':
                event.preventDefault();
                clearRange();

                return;
            case ' ':
                event.preventDefault();

                if (event.shiftKey && activeRecord) {
                    store.expand(activeRecord.id);
                } else if (activeField?.type === 'checkbox') {
                    startEditing();
                }

                return;
            default:
                break;
        }

        if (meta && key.toLowerCase() === 'd') {
            event.preventDefault();
            fillDown();

            return;
        }

        if (
            activeField?.type === 'rating' &&
            /^[0-9]$/.test(key) &&
            activeRecord &&
            isEditable(activeField) &&
            can.edit
        ) {
            const max = activeField.options.max ?? 5;

            store.setCell(
                activeRecord.id,
                activeField.key,
                Math.min(max, Number(key)),
            );

            return;
        }

        if (key.length === 1 && !meta && !event.altKey) {
            event.preventDefault();
            startEditing(key);
        }
    };

    const cellAt = (event: ReactMouseEvent): Cell | null => {
        const target = (event.target as HTMLElement).closest<HTMLElement>(
            '[data-cell]',
        );

        if (!target) {
            return null;
        }

        return {
            row: Number(target.dataset.row),
            col: Number(target.dataset.col),
        };
    };

    const onCellMouseDown = (event: ReactMouseEvent) => {
        const cell = cellAt(event);

        if (!cell || event.button !== 0) {
            return;
        }

        if (
            (event.target as HTMLElement).closest(
                'a, button, input, textarea, [data-direct]',
            )
        ) {
            setActive(cell);
            setRangeEnd(null);

            return;
        }

        event.preventDefault();
        focusGrid();

        if (editing) {
            setEditing(null);
        }

        if (event.shiftKey && clampedActive) {
            setRangeEnd(cell);
        } else {
            setActive(cell);
            setRangeEnd(null);
            selecting.current = true;
        }
    };

    const onCellMouseEnter = (event: ReactMouseEvent) => {
        const cell = cellAt(event);

        if (!cell) {
            return;
        }

        if (selecting.current) {
            setRangeEnd(cell);
        } else if (filling.current) {
            setFillTo(cell.row);
        }
    };

    const onContextMenu = (event: ReactMouseEvent) => {
        const cell = cellAt(event);

        if (!cell) {
            return;
        }

        event.preventDefault();

        const inRange =
            rect &&
            cell.row >= rect.r1 &&
            cell.row <= rect.r2 &&
            cell.col >= rect.c1 &&
            cell.col <= rect.c2;

        if (!inRange) {
            setActive(cell);
            setRangeEnd(null);
        }

        setMenu({ x: event.clientX, y: event.clientY });
    };

    const startResize = (
        event: ReactMouseEvent,
        field: Field,
        width: number,
    ) => {
        event.preventDefault();
        event.stopPropagation();

        const startX = event.clientX;
        let latest = width;

        const onMove = (move: MouseEvent) => {
            latest = Math.max(
                MIN_WIDTH,
                Math.min(MAX_WIDTH, width + move.clientX - startX),
            );
            setLiveWidths((current) => ({ ...current, [field.key]: latest }));
        };
        const onUp = () => {
            window.removeEventListener('mousemove', onMove);
            window.removeEventListener('mouseup', onUp);
            store.updateView({
                widths: { ...view.config.widths, [field.key]: latest },
            });
            setLiveWidths((current) => {
                const next = { ...current };

                delete next[field.key];

                return next;
            });
        };

        window.addEventListener('mousemove', onMove);
        window.addEventListener('mouseup', onUp);
    };

    const dropOn = (target: Field) => {
        if (!dragKey || dragKey === target.key || target.primary) {
            return;
        }

        const order = store.orderedFields
            .map((field) => field.key)
            .filter((key) => key !== dragKey);
        const index = order.indexOf(target.key);
        const from = store.orderedFields.findIndex(
            (field) => field.key === dragKey,
        );
        const to = store.orderedFields.findIndex(
            (field) => field.key === target.key,
        );

        order.splice(from < to ? index + 1 : index, 0, dragKey);
        store.updateView({ order });
    };

    const selectedIds = [...checked].filter((id) => store.recordsById.has(id));
    const menuIds =
        selectedIds.length > 0
            ? selectedIds
            : rect
              ? navRecords
                    .slice(rect.r1, rect.r2 + 1)
                    .map((record) => record.id)
              : [];

    const cellStyle = (index: number): CSSProperties => ({
        width: widths[index],
        ...(index === 0 ? { left: GUTTER } : {}),
    });

    const renderRecord = (
        record: TableRecord,
        number: number,
        rowIndex: number,
    ) => {
        const isChecked = checked.has(record.id);

        return (
            <div
                className="group/row flex h-full border-b border-neutral-200 dark:border-neutral-800"
                data-record={record.id}
            >
                <div
                    className={cn(
                        'sticky left-0 z-[2] flex shrink-0 items-start gap-1 border-r border-neutral-200 bg-white px-2 pt-2 text-xs text-neutral-500 dark:border-neutral-800 dark:bg-neutral-950',
                        isChecked && 'bg-blue-50 dark:bg-blue-950',
                    )}
                    style={{ width: GUTTER }}
                >
                    <span
                        className={cn(
                            'flex h-4 w-6 items-center',
                            isChecked ? 'hidden' : 'group-hover/row:hidden',
                        )}
                    >
                        {number}
                    </span>
                    <span
                        className={cn(
                            'h-4 w-6 items-center',
                            isChecked ? 'flex' : 'hidden group-hover/row:flex',
                        )}
                    >
                        <Checkbox
                            checked={isChecked}
                            aria-label={`Select record ${number}`}
                            onCheckedChange={(value) =>
                                setChecked((current) => {
                                    const next = new Set(current);

                                    if (value) {
                                        next.add(record.id);
                                    } else {
                                        next.delete(record.id);
                                    }

                                    return next;
                                })
                            }
                        />
                    </span>
                    <button
                        type="button"
                        aria-label="Expand record"
                        className="ml-auto hidden size-5 items-center justify-center rounded text-blue-600 group-hover/row:flex hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-blue-950"
                        onClick={() => store.expand(record.id)}
                    >
                        <Maximize2 className="size-3.5" />
                    </button>
                </div>
                {fields.map((field, col) => {
                    const inRange =
                        rect !== null &&
                        rowIndex >= rect.r1 &&
                        rowIndex <= rect.r2 &&
                        col >= rect.c1 &&
                        col <= rect.c2;
                    const isActive =
                        clampedActive?.row === rowIndex &&
                        clampedActive?.col === col;
                    const isEditing =
                        editing?.recordId === record.id &&
                        editing.fieldKey === field.key;
                    const inFill =
                        fillTo !== null &&
                        rect !== null &&
                        rowIndex > rect.r2 &&
                        rowIndex <= fillTo &&
                        col >= rect.c1 &&
                        col <= rect.c2;
                    const showHandle =
                        rect !== null &&
                        rowIndex === rect.r2 &&
                        col === rect.c2 &&
                        !editing &&
                        can.edit;
                    const value = record.values[field.key];

                    return (
                        <div
                            key={field.key}
                            data-cell=""
                            data-row={rowIndex}
                            data-col={col}
                            role="gridcell"
                            aria-selected={inRange}
                            className={cn(
                                'relative flex shrink-0 border-r border-neutral-200 px-2 text-sm dark:border-neutral-800',
                                wrap ? 'items-start py-1.5' : 'items-center',
                                alignsRight(field) &&
                                    'justify-end text-right tabular-nums',
                                col === 0 &&
                                    'sticky z-[1] bg-white font-medium dark:bg-neutral-950',
                                inRange && 'bg-blue-50/70 dark:bg-blue-950/40',
                                inFill && 'bg-blue-50 dark:bg-blue-950/60',
                                isChecked &&
                                    'bg-blue-50/50 dark:bg-blue-950/30',
                                isActive &&
                                    'z-[3] bg-white outline-2 -outline-offset-2 outline-blue-500 dark:bg-neutral-950',
                            )}
                            style={cellStyle(col)}
                            onMouseDown={onCellMouseDown}
                            onMouseEnter={onCellMouseEnter}
                            onDoubleClick={() => {
                                if (
                                    isEditable(field) &&
                                    !DIRECT.includes(field.type)
                                ) {
                                    startEditing();
                                } else if (!isEditable(field)) {
                                    store.expand(record.id);
                                }
                            }}
                            onContextMenu={onContextMenu}
                        >
                            <GridCellContent
                                store={store}
                                field={field}
                                record={record}
                                value={value}
                                wrap={wrap}
                            />
                            {isEditing && (
                                <CellEditor
                                    store={store}
                                    field={field}
                                    record={record}
                                    editing={editing}
                                    onDone={stopEditing}
                                />
                            )}
                            {showHandle && (
                                <span
                                    aria-hidden
                                    className="absolute -right-[4px] -bottom-[4px] z-[4] size-[7px] cursor-crosshair border border-white bg-blue-500 dark:border-neutral-950"
                                    onMouseDown={(event) => {
                                        event.preventDefault();
                                        event.stopPropagation();
                                        filling.current = true;
                                        setFillTo(rect.r2);
                                    }}
                                />
                            )}
                        </div>
                    );
                })}
            </div>
        );
    };

    const renderGroup = (node: GroupNode, isCollapsed: boolean) => (
        <div
            className="flex h-full items-end border-b border-neutral-200 bg-neutral-50 dark:border-neutral-800 dark:bg-neutral-900"
            style={{ paddingLeft: node.depth * 16 }}
        >
            <button
                type="button"
                className="sticky left-0 flex h-[calc(100%-6px)] items-center gap-2 px-3 text-left text-sm"
                onClick={() => toggleGroup(node.id)}
                aria-expanded={!isCollapsed}
            >
                {isCollapsed ? (
                    <ChevronRight className="size-4 text-neutral-500" />
                ) : (
                    <ChevronDown className="size-4 text-neutral-500" />
                )}
                <span className="text-xs text-neutral-500">
                    {node.field.name}
                </span>
                <span className="flex min-w-0 items-center">
                    {isEmpty(node.value) && node.field.type !== 'checkbox' ? (
                        <span className="text-neutral-400 italic">Empty</span>
                    ) : (
                        <CellView
                            field={node.field}
                            value={node.value}
                            context={store.context}
                        />
                    )}
                </span>
                <span className="rounded-full bg-neutral-200 px-1.5 text-xs text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                    {node.records.length}
                </span>
            </button>
        </div>
    );

    const renderAdd = (group: GroupNode | null) => (
        <button
            type="button"
            className="sticky left-0 flex h-full items-center gap-1.5 border-b border-neutral-200 px-3 text-sm text-neutral-500 hover:bg-neutral-50 hover:text-neutral-900 dark:border-neutral-800 dark:hover:bg-neutral-900 dark:hover:text-neutral-100"
            style={{ width: frozenWidth }}
            onClick={async () => {
                const record = await store.createRecord(
                    groupValues(group, store.groups),
                );

                if (record) {
                    setTimeout(() => {
                        const row = rowIndexByRecordRef.current.get(record.id);

                        if (row !== undefined) {
                            moveTo({ row, col: 0 });
                            focusGrid();
                        }
                    }, 0);
                }
            }}
        >
            <Plus className="size-4" />
            Add record
        </button>
    );

    const rowIndexByRecordRef = useRef(rowIndexByRecord);

    useEffect(() => {
        rowIndexByRecordRef.current = rowIndexByRecord;
    }, [rowIndexByRecord]);

    const virtualItems = virtualizer.getVirtualItems();
    const filtering =
        view.config.filters.conditions.length > 0 || store.search.trim() !== '';

    return (
        <div className="relative h-full min-h-0">
            <div
                ref={scrollRef}
                role="grid"
                aria-label={`${store.name}: ${view.name}`}
                aria-rowcount={navRecords.length}
                tabIndex={0}
                className="h-full overflow-auto overscroll-contain bg-white outline-none select-none dark:bg-neutral-950"
                onKeyDown={onKeyDown}
            >
                <div
                    className="flex min-h-full flex-col"
                    style={{ width: Math.max(totalWidth, 0), minWidth: '100%' }}
                >
                    <div
                        role="row"
                        className="sticky top-0 z-10 flex border-b border-neutral-200 bg-neutral-50 dark:border-neutral-800 dark:bg-neutral-900"
                        style={{ height: HEADER_HEIGHT, width: totalWidth }}
                    >
                        <div
                            className="sticky left-0 z-[2] flex shrink-0 items-center border-r border-neutral-200 bg-neutral-50 px-2 dark:border-neutral-800 dark:bg-neutral-900"
                            style={{ width: GUTTER }}
                        >
                            <Checkbox
                                aria-label="Select all records"
                                checked={
                                    navRecords.length > 0 &&
                                    selectedIds.length === navRecords.length
                                }
                                onCheckedChange={(value) =>
                                    setChecked(
                                        value
                                            ? new Set(
                                                  navRecords.map(
                                                      (record) => record.id,
                                                  ),
                                              )
                                            : new Set(),
                                    )
                                }
                            />
                        </div>
                        {fields.map((field, col) => (
                            <HeaderCell
                                key={field.key}
                                field={field}
                                style={cellStyle(col)}
                                sorted={
                                    view.config.sorts.find(
                                        (rule) => rule.field === field.key,
                                    )?.direction
                                }
                                filtered={view.config.filters.conditions.some(
                                    (condition) =>
                                        condition.field === field.key,
                                )}
                                isDropTarget={
                                    dropKey === field.key &&
                                    dragKey !== field.key
                                }
                                draggable={!field.primary && can.manageViews}
                                onDragStart={() => setDragKey(field.key)}
                                onDragEnd={() => {
                                    setDragKey(null);
                                    setDropKey(null);
                                }}
                                onDragOver={() => setDropKey(field.key)}
                                onDrop={() => {
                                    dropOn(field);
                                    setDragKey(null);
                                    setDropKey(null);
                                }}
                                onResizeStart={(event) =>
                                    startResize(event, field, widths[col])
                                }
                            />
                        ))}
                        {can.manageFields && (
                            <div className="flex shrink-0 items-stretch border-r border-neutral-200 dark:border-neutral-800">
                                <AddFieldButton />
                            </div>
                        )}
                    </div>

                    <div
                        className="relative flex-1"
                        style={{ height: virtualizer.getTotalSize() }}
                    >
                        {virtualItems.map((virtualItem) => {
                            const item = items[virtualItem.index];

                            if (!item) {
                                return null;
                            }

                            return (
                                <div
                                    key={virtualItem.key}
                                    role={
                                        item.kind === 'record'
                                            ? 'row'
                                            : undefined
                                    }
                                    className="absolute top-0 left-0"
                                    style={{
                                        width: totalWidth,
                                        height: virtualItem.size,
                                        transform: `translateY(${virtualItem.start - HEADER_HEIGHT}px)`,
                                    }}
                                >
                                    {item.kind === 'record' &&
                                        renderRecord(
                                            item.record,
                                            item.number,
                                            rowIndexByRecord.get(
                                                item.record.id,
                                            ) ?? 0,
                                        )}
                                    {item.kind === 'group' &&
                                        renderGroup(item.node, item.collapsed)}
                                    {item.kind === 'add' &&
                                        renderAdd(item.group)}
                                </div>
                            );
                        })}
                        {navRecords.length === 0 && (
                            <div
                                className="sticky left-0 px-4 py-10 text-center text-sm text-neutral-500"
                                style={{
                                    width:
                                        scrollRef.current?.clientWidth ??
                                        '100%',
                                    marginTop: can.create
                                        ? ROW_HEIGHTS.short
                                        : 0,
                                }}
                            >
                                {filtering
                                    ? 'No records match this view.'
                                    : 'No records yet.'}
                                {filtering && (
                                    <button
                                        type="button"
                                        className="ml-2 font-medium text-blue-600 hover:underline dark:text-blue-400"
                                        onClick={() => {
                                            store.setSearch('');
                                            store.updateView({
                                                filters: {
                                                    ...view.config.filters,
                                                    conditions: [],
                                                },
                                            });
                                        }}
                                    >
                                        Clear filters
                                    </button>
                                )}
                            </div>
                        )}
                    </div>

                    <SummaryBar
                        store={store}
                        fields={fields}
                        widths={widths}
                        records={navRecords}
                        totalWidth={totalWidth}
                    />
                </div>
            </div>

            {selectedIds.length > 0 && (
                <div className="absolute bottom-12 left-1/2 z-20 flex -translate-x-1/2 items-center gap-3 rounded-lg border border-neutral-200 bg-white px-3 py-2 text-sm shadow-lg dark:border-neutral-800 dark:bg-neutral-900">
                    <span>
                        {selectedIds.length}{' '}
                        {selectedIds.length === 1 ? 'record' : 'records'}{' '}
                        selected
                    </span>
                    {can.delete && (
                        <button
                            type="button"
                            className={cn(
                                'rounded px-2 py-1 font-medium',
                                confirmDelete
                                    ? 'bg-red-600 text-white hover:bg-red-700'
                                    : 'text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950',
                            )}
                            onClick={() => {
                                if (!confirmDelete) {
                                    setConfirmDelete(true);

                                    return;
                                }

                                setConfirmDelete(false);
                                void store.deleteRecords(selectedIds);
                                setChecked(new Set());
                            }}
                            onBlur={() => setConfirmDelete(false)}
                        >
                            {confirmDelete ? 'Click again to delete' : 'Delete'}
                        </button>
                    )}
                    <button
                        type="button"
                        className="rounded px-2 py-1 text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800"
                        onClick={() => setChecked(new Set())}
                    >
                        Clear
                    </button>
                </div>
            )}

            <DropdownMenu
                open={menu !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setMenu(null);
                        focusGrid();
                    }
                }}
            >
                <DropdownMenuTrigger asChild>
                    <span
                        aria-hidden
                        className="pointer-events-none fixed size-0"
                        style={{ left: menu?.x ?? 0, top: menu?.y ?? 0 }}
                    />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" className="w-52">
                    {activeRecord && (
                        <DropdownMenuItem
                            onSelect={() => store.expand(activeRecord.id)}
                        >
                            Expand record
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuItem
                        onSelect={() => {
                            const text = copyText();

                            if (text !== null) {
                                void navigator.clipboard?.writeText(text);
                            }
                        }}
                    >
                        Copy
                    </DropdownMenuItem>
                    {can.create && activeRecord && (
                        <DropdownMenuItem
                            onSelect={() => {
                                const values: Record<string, CellValue> = {};

                                for (const field of store.fields) {
                                    if (isEditable(field)) {
                                        values[field.key] =
                                            activeRecord.values[field.key] ??
                                            null;
                                    }
                                }

                                void store.createRecord(values);
                            }}
                        >
                            Duplicate record
                        </DropdownMenuItem>
                    )}
                    {can.delete && menuIds.length > 0 && (
                        <>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                className="text-red-600 focus:text-red-700 dark:text-red-400"
                                onSelect={() => {
                                    void store.deleteRecords(menuIds);
                                    setChecked(new Set());
                                    setRangeEnd(null);
                                }}
                            >
                                {menuIds.length === 1
                                    ? 'Delete record'
                                    : `Delete ${menuIds.length} records`}
                            </DropdownMenuItem>
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}

function HeaderCell({
    field,
    style,
    sorted,
    filtered,
    isDropTarget,
    draggable,
    onDragStart,
    onDragEnd,
    onDragOver,
    onDrop,
    onResizeStart,
}: {
    field: Field;
    style: CSSProperties;
    sorted?: 'asc' | 'desc';
    filtered: boolean;
    isDropTarget: boolean;
    draggable: boolean;
    onDragStart: () => void;
    onDragEnd: () => void;
    onDragOver: () => void;
    onDrop: () => void;
    onResizeStart: (event: ReactMouseEvent) => void;
}) {
    const Icon = FIELD_ICONS[field.type];

    return (
        <div
            role="columnheader"
            className={cn(
                'group/header relative flex shrink-0 items-center border-r border-neutral-200 text-xs font-medium text-neutral-700 dark:border-neutral-800 dark:text-neutral-200',
                field.primary &&
                    'sticky z-[1] bg-neutral-50 dark:bg-neutral-900',
                isDropTarget && 'bg-blue-50 dark:bg-blue-950',
                (sorted || filtered) &&
                    !field.primary &&
                    'bg-amber-50/60 dark:bg-amber-950/20',
            )}
            style={style}
            draggable={draggable}
            onDragStart={(event) => {
                event.dataTransfer.effectAllowed = 'move';
                onDragStart();
            }}
            onDragEnd={onDragEnd}
            onDragOver={(event) => {
                if (draggable || field.primary) {
                    event.preventDefault();
                    onDragOver();
                }
            }}
            onDrop={(event) => {
                event.preventDefault();
                onDrop();
            }}
        >
            <FieldMenu field={field}>
                <button
                    type="button"
                    className="flex h-full min-w-0 flex-1 items-center gap-1.5 px-2 text-left outline-none hover:bg-neutral-100 focus-visible:bg-neutral-100 dark:hover:bg-neutral-800 dark:focus-visible:bg-neutral-800"
                    title={field.description ?? field.name}
                >
                    <Icon className="size-3.5 shrink-0 text-neutral-500" />
                    <span className="truncate">{field.name}</span>
                    <ChevronDown className="ml-auto size-3.5 shrink-0 text-neutral-400 opacity-0 group-hover/header:opacity-100" />
                </button>
            </FieldMenu>
            <span
                aria-hidden
                className="absolute top-0 -right-[3px] z-[2] h-full w-[6px] cursor-col-resize hover:bg-blue-500/60"
                onMouseDown={onResizeStart}
                onClick={(event) => event.stopPropagation()}
            />
        </div>
    );
}

/** A cell's content in the grid: what CellView shows, plus in-place checkboxes and stars. */
function GridCellContent({
    store,
    field,
    record,
    value,
    wrap,
}: {
    store: TableStore;
    field: Field;
    record: TableRecord;
    value: CellValue | undefined;
    wrap: boolean;
}) {
    const editable = isEditable(field) && store.can.edit;

    if (field.type === 'checkbox') {
        return (
            <span
                data-direct=""
                className={cn('flex', editable && 'cursor-pointer')}
                onClick={
                    editable
                        ? () => store.setCell(record.id, field.key, !value)
                        : undefined
                }
            >
                <CheckboxMark checked={Boolean(value)} />
            </span>
        );
    }

    if (field.type === 'rating' && editable) {
        return (
            <span data-direct="">
                <Stars
                    value={typeof value === 'number' ? value : 0}
                    max={field.options.max ?? 5}
                    onChange={(stars) =>
                        store.setCell(record.id, field.key, stars)
                    }
                />
            </span>
        );
    }

    return (
        <span
            className={cn(
                'flex min-w-0 flex-1',
                alignsRight(field) && 'justify-end',
                wrap ? 'max-h-full overflow-hidden' : 'overflow-hidden',
            )}
        >
            <CellView
                field={field}
                value={value}
                error={record.errors?.[field.key]}
                context={store.context}
                wrap={wrap}
            />
        </span>
    );
}

function initialText(field: Field, value: CellValue | undefined): string {
    if (value === null || value === undefined) {
        return '';
    }

    if (field.type === 'percent' && typeof value === 'number') {
        return String(Math.round(value * 100 * 1e8) / 1e8);
    }

    if (typeof value === 'number') {
        return String(value);
    }

    return asText(value);
}

/** The editor over a cell: typed into for text and numbers, a picker for the rest. */
function CellEditor({
    store,
    field,
    record,
    editing,
    onDone,
}: {
    store: TableStore;
    field: Field;
    record: TableRecord;
    editing: Editing;
    onDone: (move?: 'down' | 'right' | 'left') => void;
}) {
    const value = record.values[field.key];
    const [text, setText] = useState(
        () => editing.text ?? initialText(field, value),
    );
    const committed = useRef(false);

    const commit = (move?: 'down' | 'right' | 'left') => {
        if (committed.current) {
            return;
        }

        committed.current = true;

        const parsed = parseText(field, text, store.context);

        if (!parsed.ok) {
            store.setError(
                field.type === 'percent' ||
                    field.type === 'number' ||
                    field.type === 'currency'
                    ? `“${text}” isn't a number.`
                    : `“${text}” doesn't fit ${field.name}.`,
            );
        } else if (
            displayText(field, parsed.value, store.context) !==
            displayText(field, value, store.context)
        ) {
            store.setCell(record.id, field.key, parsed.value);
        }

        onDone(move);
    };

    if (TYPED.includes(field.type)) {
        const multiline = field.type === 'longText';
        const className =
            'absolute top-0 left-0 z-[5] w-full bg-white px-2 text-sm outline-2 -outline-offset-2 outline-blue-500 dark:bg-neutral-950';

        if (multiline) {
            return (
                <textarea
                    autoFocus
                    aria-label={field.name}
                    value={text}
                    className={cn(
                        className,
                        'min-h-32 min-w-72 resize-y py-1.5 shadow-lg',
                    )}
                    onChange={(event) => setText(event.target.value)}
                    onFocus={(event) => {
                        const end = event.target.value.length;

                        event.target.setSelectionRange(end, end);
                    }}
                    onBlur={() => commit()}
                    onKeyDown={(event) => {
                        event.stopPropagation();

                        if (
                            event.key === 'Escape' ||
                            (event.key === 'Enter' &&
                                (event.metaKey || event.ctrlKey))
                        ) {
                            event.preventDefault();
                            commit();
                        } else if (event.key === 'Tab') {
                            event.preventDefault();
                            commit(event.shiftKey ? 'left' : 'right');
                        }
                    }}
                />
            );
        }

        return (
            <input
                autoFocus
                aria-label={field.name}
                value={text}
                inputMode={
                    ['number', 'currency', 'percent'].includes(field.type)
                        ? 'decimal'
                        : undefined
                }
                className={cn(
                    className,
                    'h-full',
                    alignsRight(field) && 'text-right',
                )}
                onChange={(event) => setText(event.target.value)}
                onBlur={() => commit()}
                onKeyDown={(event) => {
                    event.stopPropagation();

                    if (event.key === 'Enter') {
                        event.preventDefault();
                        commit('down');
                    } else if (event.key === 'Tab') {
                        event.preventDefault();
                        commit(event.shiftKey ? 'left' : 'right');
                    } else if (event.key === 'Escape') {
                        event.preventDefault();
                        committed.current = true;
                        onDone();
                    }
                }}
            />
        );
    }

    return (
        <Popover
            open
            onOpenChange={(open) => {
                if (!open) {
                    onDone();
                }
            }}
        >
            <PopoverAnchor asChild>
                <span className="absolute inset-0" />
            </PopoverAnchor>
            <PopoverContent
                className="w-72 p-0"
                onOpenAutoFocus={(event) => event.preventDefault()}
                onKeyDown={(event) => {
                    event.stopPropagation();

                    if (event.key === 'Escape') {
                        onDone();
                    }
                }}
            >
                <ValueInput
                    field={field}
                    value={value ?? null}
                    autoFocus
                    variant="popover"
                    onChange={(next) =>
                        store.setCell(record.id, field.key, next)
                    }
                    onDone={() => onDone()}
                />
            </PopoverContent>
        </Popover>
    );
}

function SummaryBar({
    store,
    fields,
    widths,
    records,
    totalWidth,
}: {
    store: TableStore;
    fields: Field[];
    widths: number[];
    records: TableRecord[];
    totalWidth: number;
}) {
    const summaries = store.view.config.summaries;

    return (
        <div
            className="sticky bottom-0 z-10 flex border-t border-neutral-200 bg-neutral-50 text-xs text-neutral-500 dark:border-neutral-800 dark:bg-neutral-900"
            style={{ height: 30, width: totalWidth }}
        >
            <div
                className="sticky left-0 z-[2] flex shrink-0 items-center bg-neutral-50 px-2 dark:bg-neutral-900"
                style={{ width: GUTTER }}
            />
            {fields.map((field, index) => {
                const summary = summaries[field.key] ?? 'none';
                const result =
                    summary === 'none'
                        ? null
                        : summarize(field, records, summary, store.context);

                return (
                    <DropdownMenu key={field.key}>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                className={cn(
                                    'group/summary flex shrink-0 items-center justify-end gap-1 truncate px-2 hover:bg-neutral-100 dark:hover:bg-neutral-800',
                                    index === 0 &&
                                        'sticky z-[1] justify-start bg-neutral-50 dark:bg-neutral-900',
                                )}
                                style={{
                                    width: widths[index],
                                    ...(index === 0 ? { left: GUTTER } : {}),
                                }}
                                aria-label={`Summary of ${field.name}`}
                            >
                                {index === 0 && summary === 'none' && (
                                    <span>
                                        {records.length}{' '}
                                        {records.length === 1
                                            ? 'record'
                                            : 'records'}
                                    </span>
                                )}
                                {result ? (
                                    <>
                                        <span className="text-neutral-400">
                                            {SUMMARY_LABELS[summary]}
                                        </span>
                                        <span className="font-medium text-neutral-700 dark:text-neutral-200">
                                            {result.isCount ||
                                            typeof result.value !== 'number'
                                                ? typeof result.value ===
                                                      'string' &&
                                                  !result.isCount
                                                    ? displayText(
                                                          {
                                                              ...field,
                                                              type: 'date',
                                                          },
                                                          result.value,
                                                          store.context,
                                                      )
                                                    : asText(result.value) ||
                                                      '–'
                                                : formatNumber(
                                                      result.value,
                                                      field.type === 'count'
                                                          ? 'number'
                                                          : (field.options
                                                                .result?.type ??
                                                                ([
                                                                    'formula',
                                                                    'rollup',
                                                                ].includes(
                                                                    field.type,
                                                                )
                                                                    ? field
                                                                          .options
                                                                          .format ===
                                                                      'currency'
                                                                        ? 'currency'
                                                                        : field
                                                                                .options
                                                                                .format ===
                                                                            'percent'
                                                                          ? 'percent'
                                                                          : 'number'
                                                                    : field.type)),
                                                      field.options,
                                                  )}
                                        </span>
                                    </>
                                ) : (
                                    index !== 0 && (
                                        <span className="opacity-0 group-hover/summary:opacity-100">
                                            Summarize
                                        </span>
                                    )
                                )}
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-44">
                            {summariesFor(field).map((option) => (
                                <DropdownMenuItem
                                    key={option}
                                    onSelect={() =>
                                        store.updateView({
                                            summaries: {
                                                ...summaries,
                                                [field.key]: option,
                                            },
                                        })
                                    }
                                >
                                    {SUMMARY_LABELS[option]}
                                    {option === summary && (
                                        <span className="ml-auto text-blue-600">
                                            ✓
                                        </span>
                                    )}
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                );
            })}
        </div>
    );
}
