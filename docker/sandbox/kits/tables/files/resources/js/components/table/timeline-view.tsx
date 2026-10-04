import { useVirtualizer } from '@tanstack/react-virtual';
import {
    CalendarOff,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    X,
} from 'lucide-react';
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import type {
    CSSProperties,
    DragEvent,
    KeyboardEvent as ReactKeyboardEvent,
    PointerEvent as ReactPointerEvent,
    ReactNode,
} from 'react';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { FilteredOutBar, recordTitle } from './board-view';
import { CellView } from './cells';
import { CHOICE_CLASSES, CHOICE_SWATCHES } from './colors';
import { FIELD_ICONS } from './field-icons';
import {
    formatDate,
    isEditable,
    isEmpty,
    parseDate,
    toDateString,
} from './format';
import { groupRecords } from './filters';
import type { GroupNode } from './filters';
import type {
    CellValue,
    ChoiceColor,
    Field,
    TableRecord,
    TimelineScale,
} from './types';
import { useTableStore } from './use-table';

/** Pixels per day at each scale. */
const DAY_WIDTH: Record<TimelineScale, number> = {
    day: 40,
    week: 18,
    month: 5,
    quarter: 2,
};

const SCALES: { value: TimelineScale; label: string }[] = [
    { value: 'day', label: 'Day' },
    { value: 'week', label: 'Week' },
    { value: 'month', label: 'Month' },
    { value: 'quarter', label: 'Quarter' },
];

/** Days of room before the first and after the last record, per scale. */
const PADDING_DAYS: Record<TimelineScale, number> = {
    day: 60,
    week: 120,
    month: 365,
    quarter: 730,
};

/** Never more than this many years either side of today, however far off a date is. */
const MAX_YEARS = 50;

const TITLE_WIDTH = 240;
const HEADER_ROW = 28;
const HEADER_HEIGHT = HEADER_ROW * 2;
const ROW_HEIGHT = 36;
const LANE_HEIGHT = 32;
const BAR_HEIGHT = 24;
/** Narrowest a bar is drawn, so one-day bars stay visible and clickable when zoomed out. */
const MIN_BAR_WIDTH = 20;
/** Pointer travel before a press on a bar counts as a drag rather than a click. */
const DRAG_THRESHOLD = 4;
const DAY_MS = 86_400_000;
/** Days given to a record dropped from the tray when the view has an end field. */
const DROPPED_LENGTH = 7;

type Unit = 'day' | 'week' | 'month' | 'quarter' | 'year';
type DragMode = 'move' | 'start' | 'end';

interface Span {
    start: number;
    /** Inclusive */
    end: number;
}

type Item =
    | { kind: 'lane'; node: GroupNode; collapsed: boolean }
    | { kind: 'row'; record: TableRecord; span: Span };

/* Day math on plain dates: a day is its number of days since 1970-01-01 (local calendar day). */

function dayNumber(date: Date): number {
    return Math.round(
        Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()) / DAY_MS,
    );
}

function dateOfDay(day: number): Date {
    const utc = new Date(day * DAY_MS);

    return new Date(utc.getUTCFullYear(), utc.getUTCMonth(), utc.getUTCDate());
}

/** Fields bars can start or end on: dates, created/modified times, and formulas shown as dates. */
function isDateField(field: Field): boolean {
    return (
        field.type === 'date' ||
        field.type === 'createdAt' ||
        field.type === 'updatedAt' ||
        ((field.type === 'formula' || field.type === 'rollup') &&
            field.options.format === 'date')
    );
}

function recordDate(record: TableRecord, field: Field): string | null {
    const value = record.values[field.key];

    if (typeof value === 'string' && value !== '') {
        return value;
    }

    if (field.type === 'createdAt') {
        return record.createdAt;
    }

    if (field.type === 'updatedAt') {
        return record.updatedAt;
    }

    return null;
}

function dayOfValue(value: string | null): number | null {
    if (value === null) {
        return null;
    }

    const date = parseDate(value);

    return date ? dayNumber(date) : null;
}

/** A date value moved by some days, keeping its time when the field has one. */
function shiftValue(field: Field, value: string, days: number): string {
    const date = parseDate(value);

    if (!date) {
        return value;
    }

    const shifted = new Date(
        date.getFullYear(),
        date.getMonth(),
        date.getDate() + days,
        date.getHours(),
        date.getMinutes(),
        date.getSeconds(),
        date.getMilliseconds(),
    );

    return field.options.includeTime
        ? shifted.toISOString()
        : toDateString(shifted);
}

/** The value a record gets when it's put on a day: keeps its time when the field has one. */
function valueOnDay(field: Field, current: string | null, day: number): string {
    const target = dateOfDay(day);

    if (!field.options.includeTime) {
        return toDateString(target);
    }

    const existing = current ? parseDate(current) : null;

    if (existing) {
        target.setHours(
            existing.getHours(),
            existing.getMinutes(),
            existing.getSeconds(),
        );
    }

    return target.toISOString();
}

function startOfUnit(date: Date, unit: Unit, weekStart: number): Date {
    switch (unit) {
        case 'day':
            return new Date(
                date.getFullYear(),
                date.getMonth(),
                date.getDate(),
            );
        case 'week':
            return new Date(
                date.getFullYear(),
                date.getMonth(),
                date.getDate() - ((date.getDay() - weekStart + 7) % 7),
            );
        case 'month':
            return new Date(date.getFullYear(), date.getMonth(), 1);
        case 'quarter':
            return new Date(
                date.getFullYear(),
                date.getMonth() - (date.getMonth() % 3),
                1,
            );
        case 'year':
            return new Date(date.getFullYear(), 0, 1);
    }
}

function nextUnit(date: Date, unit: Unit): Date {
    switch (unit) {
        case 'day':
            return new Date(
                date.getFullYear(),
                date.getMonth(),
                date.getDate() + 1,
            );
        case 'week':
            return new Date(
                date.getFullYear(),
                date.getMonth(),
                date.getDate() + 7,
            );
        case 'month':
            return new Date(date.getFullYear(), date.getMonth() + 1, 1);
        case 'quarter':
            return new Date(date.getFullYear(), date.getMonth() + 3, 1);
        case 'year':
            return new Date(date.getFullYear() + 1, 0, 1);
    }
}

const monthYear = new Intl.DateTimeFormat(undefined, {
    month: 'long',
    year: 'numeric',
});
const monthShort = new Intl.DateTimeFormat(undefined, { month: 'short' });
const weekdayShort = new Intl.DateTimeFormat(undefined, { weekday: 'short' });

function unitLabel(date: Date, unit: Unit, last: Date): string {
    switch (unit) {
        case 'day':
            return String(date.getDate());
        case 'week':
            return `${date.getDate()} – ${last.getDate()}`;
        case 'month':
            return monthShort.format(date);
        case 'quarter':
            return `Q${Math.floor(date.getMonth() / 3) + 1}`;
        case 'year':
            return String(date.getFullYear());
    }
}

interface HeaderCell {
    key: number;
    start: number;
    days: number;
    label: string;
    date: Date;
}

/** The header cells of one unit that overlap [from, to] (day numbers). */
function headerCells(
    unit: Unit,
    from: number,
    to: number,
    weekStart: number,
): HeaderCell[] {
    const cells: HeaderCell[] = [];
    let date = startOfUnit(dateOfDay(from), unit, weekStart);

    while (dayNumber(date) <= to) {
        const next = nextUnit(date, unit);
        const start = dayNumber(date);
        const days = dayNumber(next) - start;

        cells.push({
            key: start,
            start,
            days,
            label: unitLabel(date, unit, dateOfDay(start + days - 1)),
            date,
        });
        date = next;
    }

    return cells;
}

const HEADER_UNITS: Record<TimelineScale, [Unit, Unit]> = {
    day: ['month', 'day'],
    week: ['month', 'week'],
    month: ['year', 'month'],
    quarter: ['year', 'quarter'],
};

/** 0 for Sunday, 1 for Monday…, from the browser's locale where it says. */
function firstDayOfWeek(): number {
    try {
        const locale = new Intl.Locale(navigator.language) as unknown as {
            getWeekInfo?: () => { firstDay: number };
            weekInfo?: { firstDay: number };
        };
        const info = locale.getWeekInfo?.() ?? locale.weekInfo;

        if (info && typeof info.firstDay === 'number') {
            return info.firstDay % 7;
        }
    } catch {
        // Older browsers: Sunday.
    }

    return 0;
}

function choiceColor(
    field: Field | undefined,
    value: CellValue | undefined,
): ChoiceColor | null {
    if (!field || typeof value !== 'string') {
        return null;
    }

    return (
        field.options.choices?.find((choice) => choice.name === value)?.color ??
        null
    );
}

export function TimelineView() {
    const store = useTableStore();
    const { config } = store.view;
    const startCandidate = config.dateField
        ? store.fieldsByKey.get(config.dateField)
        : undefined;
    const startField =
        startCandidate && isDateField(startCandidate)
            ? startCandidate
            : undefined;
    const endCandidate = config.endField
        ? store.fieldsByKey.get(config.endField)
        : undefined;
    const endField =
        endCandidate &&
        isDateField(endCandidate) &&
        endCandidate.key !== startField?.key
            ? endCandidate
            : undefined;

    if (!startField) {
        return <PickDates />;
    }

    return (
        <Timeline
            key={store.view.id}
            startField={startField}
            endField={endField}
        />
    );
}

/** Asks which fields the bars start and end on. */
function PickDates() {
    const store = useTableStore();
    const fields = store.fields.filter(isDateField);
    const [start, setStart] = useState('');
    const [end, setEnd] = useState(NO_END);
    const allowed = store.view.personal || store.can.manageViews;

    return (
        <div className="flex h-full min-h-64 flex-col items-center justify-center gap-4 px-6 py-16 text-center">
            <div className="flex max-w-sm flex-col gap-1">
                <p className="text-sm font-medium text-neutral-900 dark:text-neutral-100">
                    Pick the dates for the timeline
                </p>
                <p className="text-sm text-neutral-500 dark:text-neutral-400">
                    {fields.length > 0
                        ? 'Each record shows as a bar from its start date to its end date. You can change them later in Customize.'
                        : 'Add a date field to the table to use a timeline.'}
                </p>
            </div>
            {fields.length > 0 && (
                <form
                    className="flex w-64 flex-col gap-3 text-left"
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (start !== '') {
                            store.updateView({
                                dateField: start,
                                endField: end === NO_END ? null : end,
                            });
                        }
                    }}
                >
                    <DatePicker
                        label="Start date"
                        fields={fields}
                        value={start}
                        disabled={!allowed}
                        onChange={setStart}
                    />
                    <DatePicker
                        label="End date"
                        fields={fields.filter((field) => field.key !== start)}
                        value={end}
                        none
                        disabled={!allowed}
                        onChange={setEnd}
                    />
                    <Button type="submit" size="sm" disabled={start === ''}>
                        Show timeline
                    </Button>
                </form>
            )}
        </div>
    );
}

const NO_END = '__none';

function DatePicker({
    label,
    fields,
    value,
    none,
    disabled,
    onChange,
}: {
    label: string;
    fields: Field[];
    value: string;
    none?: boolean;
    disabled: boolean;
    onChange: (key: string) => void;
}) {
    return (
        <label className="flex flex-col gap-1">
            <span className="text-xs font-medium text-neutral-500">
                {label}
            </span>
            <Select value={value} onValueChange={onChange} disabled={disabled}>
                <SelectTrigger aria-label={label} className="w-full">
                    <SelectValue placeholder="Pick a field" />
                </SelectTrigger>
                <SelectContent>
                    {none && <SelectItem value={NO_END}>None</SelectItem>}
                    {fields.map((field) => {
                        const Icon = FIELD_ICONS[field.type];

                        return (
                            <SelectItem key={field.key} value={field.key}>
                                <span className="flex items-center gap-2">
                                    <Icon className="size-3.5 text-neutral-500" />
                                    {field.name}
                                </span>
                            </SelectItem>
                        );
                    })}
                </SelectContent>
            </Select>
        </label>
    );
}

function Timeline({
    startField,
    endField,
}: {
    startField: Field;
    endField: Field | undefined;
}) {
    'use no memo';

    const store = useTableStore();
    const { config } = store.view;
    const scale: TimelineScale =
        config.timelineScale in DAY_WIDTH ? config.timelineScale : 'week';
    const dayWidth = DAY_WIDTH[scale];
    const scrollRef = useRef<HTMLDivElement>(null);
    const contentRef = useRef<HTMLDivElement>(null);
    /** The day in the middle of the visible time axis, kept across scale and range changes. */
    const centerDay = useRef(dayNumber(new Date()));
    const [weekStart] = useState(firstDayOfWeek);
    const [viewport, setViewport] = useState({ left: 0, width: 0 });
    const [collapsed, setCollapsed] = useState<Set<string>>(new Set());
    const [trayOpen, setTrayOpen] = useState(false);
    const [draggingId, setDraggingId] = useState<number | null>(null);
    const [dropDay, setDropDay] = useState<number | null>(null);
    const today = dayNumber(new Date());

    const editable = (field: Field | undefined) =>
        !!field && field.type === 'date' && isEditable(field) && store.can.edit;
    const canEditStart = editable(startField);
    const canEditEnd = editable(endField);

    const colorField = store.visibleFields.find(
        (field) => field.type === 'select',
    );
    const laneRule = config.groups.find((rule) =>
        store.fieldsByKey.has(rule.field),
    );

    const { dated, undated } = useMemo(() => {
        const withDates: { record: TableRecord; span: Span }[] = [];
        const without: TableRecord[] = [];

        for (const record of store.rows) {
            const start = dayOfValue(recordDate(record, startField));

            if (start === null) {
                without.push(record);
                continue;
            }

            const end = endField
                ? dayOfValue(recordDate(record, endField))
                : null;

            withDates.push({
                record,
                span: {
                    start,
                    end: end !== null && end >= start ? end : start,
                },
            });
        }

        return { dated: withDates, undated: without };
    }, [store.rows, startField, endField]);

    const lanes = useMemo(() => {
        if (!laneRule) {
            return null;
        }

        return groupRecords(
            dated.map((entry) => entry.record),
            [laneRule],
            store.fieldsByKey,
            store.context,
        );
    }, [dated, laneRule, store.fieldsByKey, store.context]);

    const items = useMemo<Item[]>(() => {
        if (!lanes) {
            return dated.map((entry) => ({ kind: 'row', ...entry }));
        }

        const spans = new Map(
            dated.map((entry) => [entry.record.id, entry.span]),
        );
        const list: Item[] = [];

        for (const node of lanes) {
            const isCollapsed = collapsed.has(node.id);

            list.push({ kind: 'lane', node, collapsed: isCollapsed });

            if (!isCollapsed) {
                for (const record of node.records) {
                    list.push({
                        kind: 'row',
                        record,
                        span: spans.get(record.id)!,
                    });
                }
            }
        }

        return list;
    }, [dated, lanes, collapsed]);

    /** The days the axis covers: every bar and today, with room either side. */
    const range = useMemo(() => {
        let first = today;
        let last = today;

        for (const { span } of dated) {
            first = Math.min(first, span.start);
            last = Math.max(last, span.end);
        }

        const pad = PADDING_DAYS[scale];
        const limit = MAX_YEARS * 366;
        const from = startOfUnit(
            dateOfDay(Math.max(first - pad, today - limit)),
            scale === 'quarter' || scale === 'month' ? 'year' : 'month',
            weekStart,
        );
        const to = dayNumber(
            nextUnit(
                startOfUnit(
                    dateOfDay(Math.min(last + pad, today + limit)),
                    scale === 'quarter' || scale === 'month' ? 'year' : 'month',
                    weekStart,
                ),
                scale === 'quarter' || scale === 'month' ? 'year' : 'month',
            ),
        );

        return { origin: dayNumber(from), days: to - dayNumber(from) };
    }, [dated, today, scale, weekStart]);

    const axisWidth = Math.max(
        range.days * dayWidth,
        viewport.width - TITLE_WIDTH,
    );
    const xOf = (day: number) => (day - range.origin) * dayWidth;

    const virtualizer = useVirtualizer({
        count: items.length,
        getScrollElement: () => scrollRef.current,
        estimateSize: (index) =>
            items[index]?.kind === 'lane' ? LANE_HEIGHT : ROW_HEIGHT,
        getItemKey: (index) => {
            const item = items[index];

            return item?.kind === 'lane'
                ? `lane:${item.node.id}`
                : `row:${item?.record.id ?? index}`;
        },
        paddingStart: HEADER_HEIGHT,
        paddingEnd: 16,
        overscan: 8,
    });

    /** Keeps the middle day in place when the scale or the range changes, and starts on today. */
    useLayoutEffect(() => {
        const scroller = scrollRef.current;

        if (!scroller) {
            return;
        }

        const axis = scroller.clientWidth - TITLE_WIDTH;

        scroller.scrollLeft = Math.max(
            0,
            (centerDay.current - range.origin) * dayWidth - axis / 2,
        );
        setViewport({ left: scroller.scrollLeft, width: scroller.clientWidth });
    }, [range.origin, dayWidth]);

    useEffect(() => {
        const scroller = scrollRef.current;

        if (!scroller) {
            return;
        }

        const observer = new ResizeObserver(() =>
            setViewport({
                left: scroller.scrollLeft,
                width: scroller.clientWidth,
            }),
        );

        observer.observe(scroller);

        return () => observer.disconnect();
    }, []);

    const onScroll = () => {
        const scroller = scrollRef.current;

        if (!scroller) {
            return;
        }

        const axis = scroller.clientWidth - TITLE_WIDTH;

        centerDay.current =
            range.origin + (scroller.scrollLeft + axis / 2) / dayWidth;

        if (scroller.scrollLeft !== viewport.left) {
            setViewport({
                left: scroller.scrollLeft,
                width: scroller.clientWidth,
            });
        }
    };

    const scrollToDay = (day: number) => {
        const scroller = scrollRef.current;

        if (scroller) {
            scroller.scrollTo({
                left: Math.max(
                    0,
                    xOf(day) - (scroller.clientWidth - TITLE_WIDTH) / 3,
                ),
                behavior: 'smooth',
            });
        }
    };

    const page = (direction: 1 | -1) => {
        const scroller = scrollRef.current;

        if (scroller) {
            scroller.scrollBy({
                left: direction * (scroller.clientWidth - TITLE_WIDTH) * 0.9,
                behavior: 'smooth',
            });
        }
    };

    /** The visible days, with some to spare, for drawing the header and grid lines. */
    const axisView = Math.max(viewport.width - TITLE_WIDTH, 0);
    const visibleFrom = range.origin + Math.floor(viewport.left / dayWidth) - 7;
    const visibleTo =
        range.origin + Math.ceil((viewport.left + axisView) / dayWidth) + 7;
    const [topUnit, bottomUnit] = HEADER_UNITS[scale];
    const topCells = headerCells(topUnit, visibleFrom, visibleTo, weekStart);
    const bottomCells = headerCells(
        bottomUnit,
        visibleFrom,
        visibleTo,
        weekStart,
    );
    const middle = dateOfDay(
        Math.round(range.origin + (viewport.left + axisView / 2) / dayWidth),
    );
    const title =
        scale === 'month' || scale === 'quarter'
            ? String(middle.getFullYear())
            : monthYear.format(middle);

    /** Saves new dates for a bar: one undoable change. */
    const save = (record: TableRecord, values: Record<string, CellValue>) => {
        const changed = Object.entries(values).filter(
            ([key, value]) => record.values[key] !== value,
        );

        if (changed.length > 0) {
            void store.change({
                updates: [
                    { id: record.id, values: Object.fromEntries(changed) },
                ],
            });
        }
    };

    const commitDrag = (
        record: TableRecord,
        span: Span,
        mode: DragMode,
        delta: number,
    ) => {
        if (delta === 0) {
            return;
        }

        const start = recordDate(record, startField);
        const end = endField ? recordDate(record, endField) : null;
        const values: Record<string, CellValue> = {};

        if (mode === 'move') {
            if (start !== null) {
                values[startField.key] = shiftValue(startField, start, delta);
            }

            if (endField && end !== null && canEditEnd) {
                values[endField.key] = shiftValue(endField, end, delta);
            }
        } else if (mode === 'start' && start !== null) {
            const shift = Math.min(delta, span.end - span.start);

            values[startField.key] = shiftValue(startField, start, shift);
        } else if (mode === 'end' && endField && start !== null) {
            const shift = Math.max(delta, span.start - span.end);

            values[endField.key] =
                end !== null && dayOfValue(end) === span.end
                    ? shiftValue(endField, end, shift)
                    : valueOnDay(endField, null, span.end + shift);
        }

        save(record, values);
    };

    const dayAt = (clientX: number): number | null => {
        const content = contentRef.current;

        if (!content) {
            return null;
        }

        const x = clientX - content.getBoundingClientRect().left - TITLE_WIDTH;

        return x < 0 ? null : range.origin + Math.floor(x / dayWidth);
    };

    const onDragOver = (event: DragEvent) => {
        if (draggingId === null || !canEditStart) {
            return;
        }

        const day = dayAt(event.clientX);

        if (day === null) {
            setDropDay(null);

            return;
        }

        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        if (day !== dropDay) {
            setDropDay(day);
        }
    };

    const onDrop = (event: DragEvent) => {
        event.preventDefault();

        const day = dayAt(event.clientX);
        const record = store.recordsById.get(
            Number(event.dataTransfer.getData('text/plain')),
        );

        setDropDay(null);
        setDraggingId(null);

        if (day === null || !record || !canEditStart) {
            return;
        }

        const values: Record<string, CellValue> = {
            [startField.key]: valueOnDay(
                startField,
                recordDate(record, startField),
                day,
            ),
        };

        if (endField && canEditEnd) {
            values[endField.key] = valueOnDay(
                endField,
                null,
                day + DROPPED_LENGTH,
            );
        }

        save(record, values);
    };

    const toggleLane = (id: string) =>
        setCollapsed((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });

    const weekendShading: CSSProperties =
        scale === 'day' || scale === 'week'
            ? {
                  backgroundImage: `linear-gradient(to right, rgb(115 115 115 / 0.07) 0 ${dayWidth * 2}px, transparent ${dayWidth * 2}px)`,
                  backgroundSize: `${dayWidth * 7}px 100%`,
                  backgroundRepeat: 'repeat-x',
                  // Saturday's position: the pattern shades Saturday and Sunday.
                  backgroundPositionX: `${((6 - dateOfDay(range.origin).getDay() + 7) % 7) * dayWidth}px`,
              }
            : {};
    const totalHeight = virtualizer.getTotalSize();

    return (
        <div className="flex h-full min-h-0 flex-1 flex-col">
            <FilteredOutBar />
            <div className="flex flex-wrap items-center gap-2 border-b border-neutral-200 px-3 py-2 dark:border-neutral-800">
                <h2 className="min-w-36 text-base font-semibold">{title}</h2>
                <Button
                    variant="outline"
                    size="sm"
                    className="h-7"
                    onClick={() => scrollToDay(today)}
                >
                    Today
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-7"
                    aria-label="Earlier"
                    onClick={() => page(-1)}
                >
                    <ChevronLeft className="size-4" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-7"
                    aria-label="Later"
                    onClick={() => page(1)}
                >
                    <ChevronRight className="size-4" />
                </Button>
                <div
                    role="radiogroup"
                    aria-label="Timescale"
                    className="ml-2 flex items-center rounded-md bg-neutral-100 p-0.5 dark:bg-neutral-900"
                >
                    {SCALES.map((option) => (
                        <button
                            key={option.value}
                            type="button"
                            role="radio"
                            aria-checked={scale === option.value}
                            className={cn(
                                'h-6 rounded px-2 text-xs font-medium text-neutral-600 transition-colors hover:text-neutral-900 focus-visible:ring-2 focus-visible:ring-blue-500/50 focus-visible:outline-none dark:text-neutral-400 dark:hover:text-neutral-100',
                                scale === option.value &&
                                    'bg-white text-neutral-900 shadow-xs dark:bg-neutral-700 dark:text-neutral-100',
                            )}
                            onClick={() =>
                                store.updateView({
                                    timelineScale: option.value,
                                })
                            }
                        >
                            {option.label}
                        </button>
                    ))}
                </div>
                <div className="flex-1" />
                <Button
                    variant={trayOpen ? 'secondary' : 'ghost'}
                    size="sm"
                    className="h-7 text-neutral-600 dark:text-neutral-300"
                    aria-expanded={trayOpen}
                    onClick={() => setTrayOpen(!trayOpen)}
                >
                    <CalendarOff className="size-3.5" />
                    No dates
                    <span className="text-xs text-neutral-500 tabular-nums">
                        {undated.length}
                    </span>
                </Button>
            </div>
            <div className="flex min-h-0 flex-1">
                <div
                    ref={scrollRef}
                    className="relative min-w-0 flex-1 overflow-auto"
                    onScroll={onScroll}
                >
                    <div
                        ref={contentRef}
                        className="relative"
                        style={{
                            width: TITLE_WIDTH + axisWidth,
                            height: totalHeight,
                            minHeight: '100%',
                        }}
                        onDragOver={onDragOver}
                        onDragLeave={(event) => {
                            if (
                                !event.currentTarget.contains(
                                    event.relatedTarget as Node,
                                )
                            ) {
                                setDropDay(null);
                            }
                        }}
                        onDrop={onDrop}
                    >
                        {/* Header: units over sub-units */}
                        <div
                            className="sticky top-0 z-20 flex border-b border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-950"
                            style={{ height: HEADER_HEIGHT }}
                        >
                            <div
                                className="sticky left-0 z-30 flex shrink-0 items-end border-r border-neutral-200 bg-white px-3 pb-1.5 text-xs font-medium text-neutral-500 dark:border-neutral-800 dark:bg-neutral-950"
                                style={{ width: TITLE_WIDTH }}
                            >
                                {lanes
                                    ? `${lanes.length} ${lanes.length === 1 ? 'group' : 'groups'}`
                                    : `${dated.length.toLocaleString()} ${dated.length === 1 ? 'record' : 'records'}`}
                            </div>
                            <div
                                className="relative shrink-0"
                                style={{ width: axisWidth }}
                            >
                                {topCells.map((cell) => (
                                    <div
                                        key={cell.key}
                                        className="absolute top-0 flex items-center border-l border-neutral-200 text-xs font-semibold text-neutral-700 dark:border-neutral-800 dark:text-neutral-300"
                                        style={{
                                            left: xOf(cell.start),
                                            width: cell.days * dayWidth,
                                            height: HEADER_ROW,
                                        }}
                                    >
                                        <span
                                            className="sticky truncate px-2"
                                            style={{ left: TITLE_WIDTH }}
                                        >
                                            {topUnit === 'month'
                                                ? monthYear.format(cell.date)
                                                : cell.label}
                                        </span>
                                    </div>
                                ))}
                                {bottomCells.map((cell) => {
                                    const isToday =
                                        today >= cell.start &&
                                        today < cell.start + cell.days;

                                    return (
                                        <div
                                            key={cell.key}
                                            className={cn(
                                                'absolute flex items-center justify-center gap-1 overflow-hidden border-t border-l border-neutral-200 text-[11px] whitespace-nowrap text-neutral-500 tabular-nums dark:border-neutral-800',
                                                isToday &&
                                                    'font-semibold text-blue-600 dark:text-blue-400',
                                            )}
                                            style={{
                                                top: HEADER_ROW,
                                                left: xOf(cell.start),
                                                width: cell.days * dayWidth,
                                                height: HEADER_ROW,
                                            }}
                                        >
                                            {bottomUnit === 'day' && (
                                                <span className="text-[10px] text-neutral-400">
                                                    {weekdayShort
                                                        .format(cell.date)
                                                        .slice(0, 2)}
                                                </span>
                                            )}
                                            {cell.label}
                                        </div>
                                    );
                                })}
                            </div>
                        </div>

                        {/* Background: weekends, unit lines, today, the drop target */}
                        <div
                            aria-hidden
                            className="pointer-events-none absolute"
                            style={{
                                top: HEADER_HEIGHT,
                                left: TITLE_WIDTH,
                                width: axisWidth,
                                bottom: 0,
                                ...weekendShading,
                            }}
                        >
                            {bottomCells.map((cell) => (
                                <div
                                    key={cell.key}
                                    className="absolute top-0 bottom-0 w-px bg-neutral-100 dark:bg-neutral-900"
                                    style={{ left: xOf(cell.start) }}
                                />
                            ))}
                            {dropDay !== null && (
                                <div
                                    className="absolute top-0 bottom-0 bg-blue-500/15"
                                    style={{
                                        left: xOf(dropDay),
                                        width: Math.max(dayWidth, 2),
                                    }}
                                />
                            )}
                            <div
                                className="absolute top-0 bottom-0 w-0.5 bg-blue-500"
                                style={{
                                    left: xOf(today) + dayWidth / 2 - 1,
                                }}
                            />
                        </div>
                        <div
                            aria-hidden
                            className="pointer-events-none absolute z-20 size-2 -translate-x-1/2 rounded-full bg-blue-500"
                            style={{
                                top: HEADER_HEIGHT - 4,
                                left: TITLE_WIDTH + xOf(today) + dayWidth / 2,
                            }}
                        />

                        {items.length === 0 && (
                            <div
                                className="sticky left-0 flex flex-col items-center gap-1 px-6 py-16 text-center text-sm text-neutral-500"
                                style={{ width: viewport.width || '100%' }}
                            >
                                {store.rows.length === 0
                                    ? store.records.length === 0
                                        ? 'No records yet'
                                        : 'No records match this view'
                                    : 'No records have a start date yet.'}
                                {undated.length > 0 && canEditStart && (
                                    <button
                                        type="button"
                                        className="text-blue-600 hover:underline dark:text-blue-400"
                                        onClick={() => setTrayOpen(true)}
                                    >
                                        Drag them on from “No dates”
                                    </button>
                                )}
                            </div>
                        )}

                        {virtualizer.getVirtualItems().map((virtual) => {
                            const item = items[virtual.index];

                            if (!item) {
                                return null;
                            }

                            const style: CSSProperties = {
                                top: virtual.start,
                                height: virtual.size,
                                width: TITLE_WIDTH + axisWidth,
                            };

                            if (item.kind === 'lane') {
                                return (
                                    <LaneHeader
                                        key={virtual.key}
                                        node={item.node}
                                        collapsed={item.collapsed}
                                        style={style}
                                        onToggle={() =>
                                            toggleLane(item.node.id)
                                        }
                                    />
                                );
                            }

                            return (
                                <TimelineRow
                                    key={virtual.key}
                                    record={item.record}
                                    style={style}
                                    indent={lanes !== null}
                                >
                                    <Bar
                                        record={item.record}
                                        span={item.span}
                                        left={xOf(item.span.start)}
                                        dayWidth={dayWidth}
                                        color={choiceColor(
                                            colorField,
                                            colorField
                                                ? item.record.values[
                                                      colorField.key
                                                  ]
                                                : undefined,
                                        )}
                                        canMove={
                                            canEditStart &&
                                            (!endField ||
                                                canEditEnd ||
                                                recordDate(
                                                    item.record,
                                                    endField,
                                                ) === null)
                                        }
                                        canResizeStart={
                                            canEditStart && !!endField
                                        }
                                        canResizeEnd={canEditEnd}
                                        onCommit={(mode, delta) =>
                                            commitDrag(
                                                item.record,
                                                item.span,
                                                mode,
                                                delta,
                                            )
                                        }
                                    />
                                    {viewport.width > 0 &&
                                        (xOf(item.span.end + 1) <
                                            viewport.left ||
                                            xOf(item.span.start) >
                                                viewport.left + axisView) && (
                                            <OffscreenArrow
                                                side={
                                                    xOf(item.span.start) >
                                                    viewport.left + axisView
                                                        ? 'right'
                                                        : 'left'
                                                }
                                                left={viewport.left}
                                                right={viewport.left + axisView}
                                                onClick={() =>
                                                    scrollToDay(item.span.start)
                                                }
                                            />
                                        )}
                                </TimelineRow>
                            );
                        })}
                    </div>
                </div>
                {trayOpen && (
                    <aside
                        aria-label="Records without a start date"
                        className="flex w-64 shrink-0 flex-col border-l border-neutral-200 bg-neutral-50/60 dark:border-neutral-800 dark:bg-neutral-950"
                    >
                        <div className="flex items-center justify-between px-3 py-2">
                            <p className="text-sm font-medium">
                                No dates{' '}
                                <span className="text-xs text-neutral-500 tabular-nums">
                                    {undated.length}
                                </span>
                            </p>
                            <button
                                type="button"
                                aria-label="Close"
                                className="rounded p-1 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-900 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                                onClick={() => setTrayOpen(false)}
                            >
                                <X className="size-3.5" />
                            </button>
                        </div>
                        <div className="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto px-2 pb-2">
                            {undated.length === 0 ? (
                                <p className="px-1 py-4 text-center text-xs text-neutral-500">
                                    Every record has a start date.
                                </p>
                            ) : (
                                <>
                                    {canEditStart && (
                                        <p className="px-1 pb-1 text-xs text-neutral-500">
                                            Drag a record onto the timeline to
                                            give it dates.
                                        </p>
                                    )}
                                    {undated.slice(0, 500).map((record) => (
                                        <TrayChip
                                            key={record.id}
                                            record={record}
                                            draggable={canEditStart}
                                            dragging={draggingId === record.id}
                                            onDragStart={() =>
                                                setDraggingId(record.id)
                                            }
                                            onDragEnd={() => {
                                                setDraggingId(null);
                                                setDropDay(null);
                                            }}
                                        />
                                    ))}
                                </>
                            )}
                        </div>
                    </aside>
                )}
            </div>
        </div>
    );
}

function LaneHeader({
    node,
    collapsed,
    style,
    onToggle,
}: {
    node: GroupNode;
    collapsed: boolean;
    style: CSSProperties;
    onToggle: () => void;
}) {
    const store = useTableStore();

    return (
        <div
            className="absolute left-0 flex border-b border-neutral-200 bg-neutral-50/90 dark:border-neutral-800 dark:bg-neutral-900/90"
            style={style}
        >
            <button
                type="button"
                aria-expanded={!collapsed}
                aria-label={`${collapsed ? 'Expand' : 'Collapse'} group`}
                className="sticky left-0 flex max-w-[min(100%,32rem)] min-w-0 items-center gap-2 px-2 text-left text-sm hover:text-neutral-900 dark:hover:text-neutral-100"
                onClick={onToggle}
            >
                <ChevronDown
                    className={cn(
                        'size-4 shrink-0 text-neutral-500 transition-transform',
                        collapsed && '-rotate-90',
                    )}
                />
                <span className="text-xs text-neutral-500">
                    {node.field.name}
                </span>
                <span className="flex min-w-0 items-center font-medium">
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
                <span className="text-xs text-neutral-500 tabular-nums">
                    {node.records.length}
                </span>
            </button>
        </div>
    );
}

function TimelineRow({
    record,
    style,
    indent,
    children,
}: {
    record: TableRecord;
    style: CSSProperties;
    indent: boolean;
    children: ReactNode;
}) {
    const store = useTableStore();
    const title = recordTitle(store, record);

    return (
        <div
            className="group/row absolute left-0 flex border-b border-neutral-100 hover:bg-neutral-500/[0.04] dark:border-neutral-900"
            style={style}
        >
            <button
                type="button"
                className={cn(
                    'sticky left-0 z-10 flex shrink-0 items-center truncate border-r border-neutral-200 bg-white pr-3 text-left text-[13px] group-hover/row:bg-neutral-50 dark:border-neutral-800 dark:bg-neutral-950 dark:group-hover/row:bg-neutral-900',
                    indent ? 'pl-8' : 'pl-3',
                    title === 'Untitled' && 'text-neutral-400',
                )}
                style={{ width: TITLE_WIDTH }}
                title={title}
                onClick={() => store.expand(record.id)}
            >
                <span className="truncate">{title}</span>
            </button>
            <div className="relative min-w-0 flex-1">{children}</div>
        </div>
    );
}

function Bar({
    record,
    span,
    left,
    dayWidth,
    color,
    canMove,
    canResizeStart,
    canResizeEnd,
    onCommit,
}: {
    record: TableRecord;
    span: Span;
    left: number;
    dayWidth: number;
    color: ChoiceColor | null;
    canMove: boolean;
    canResizeStart: boolean;
    canResizeEnd: boolean;
    onCommit: (mode: DragMode, delta: number) => void;
}) {
    const store = useTableStore();
    const title = recordTitle(store, record);
    const [drag, setDrag] = useState<{
        mode: DragMode;
        originX: number;
        dx: number;
        moved: boolean;
    } | null>(null);
    /** Set when a press turned into a drag, so the click that follows doesn't open the record. */
    const dragged = useRef(false);
    const moving = drag !== null && drag.moved;

    const delta = moving ? Math.round(drag.dx / dayWidth) : 0;
    const shown: Span =
        !moving || delta === 0
            ? span
            : drag.mode === 'move'
              ? { start: span.start + delta, end: span.end + delta }
              : drag.mode === 'start'
                ? {
                      start: Math.min(span.start + delta, span.end),
                      end: span.end,
                  }
                : {
                      start: span.start,
                      end: Math.max(span.end + delta, span.start),
                  };
    const x = left + (shown.start - span.start) * dayWidth;
    const width = Math.max(
        (shown.end - shown.start + 1) * dayWidth,
        MIN_BAR_WIDTH,
    );
    const inside = width >= 64;
    const startText = formatDate(toDateString(dateOfDay(shown.start)), false);
    const endText = formatDate(toDateString(dateOfDay(shown.end)), false);
    const dates =
        shown.start === shown.end ? startText : `${startText} – ${endText}`;

    const begin = (event: ReactPointerEvent, mode: DragMode) => {
        if (event.button !== 0) {
            return;
        }

        event.stopPropagation();
        dragged.current = false;
        event.currentTarget.setPointerCapture(event.pointerId);
        setDrag({ mode, originX: event.clientX, dx: 0, moved: false });
    };

    const move = (event: ReactPointerEvent) => {
        if (!drag) {
            return;
        }

        const dx = event.clientX - drag.originX;
        const moved = drag.moved || Math.abs(dx) >= DRAG_THRESHOLD;

        dragged.current = moved;
        setDrag({ ...drag, dx, moved });
    };

    const end = (event: ReactPointerEvent) => {
        if (!drag) {
            return;
        }

        event.currentTarget.releasePointerCapture(event.pointerId);
        setDrag(null);

        if (drag.moved) {
            onCommit(drag.mode, Math.round(drag.dx / dayWidth));
        }
    };

    const onKeyDown = (event: ReactKeyboardEvent) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            store.expand(record.id);
        } else if (
            canMove &&
            (event.key === 'ArrowLeft' || event.key === 'ArrowRight')
        ) {
            event.preventDefault();
            onCommit('move', event.key === 'ArrowLeft' ? -1 : 1);
        }
    };

    const handle = (side: 'start' | 'end') => (
        <span
            aria-hidden
            className={cn(
                'absolute top-0 bottom-0 z-10 w-2 cursor-ew-resize opacity-0 group-hover/bar:opacity-100',
                side === 'start' ? '-left-0.5' : '-right-0.5',
            )}
            onPointerDown={(event) => begin(event, side)}
            onPointerMove={move}
            onPointerUp={end}
            onPointerCancel={() => setDrag(null)}
        >
            <span className="absolute inset-y-1 left-1/2 w-0.5 -translate-x-1/2 rounded-full bg-neutral-500/60" />
        </span>
    );

    return (
        <div
            role="button"
            tabIndex={0}
            aria-label={`${title}, ${dates}`}
            title={`${title}\n${dates}`}
            className={cn(
                'group/bar absolute flex items-center rounded-md text-xs font-medium shadow-xs ring-1 ring-black/5 outline-none select-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:ring-white/10',
                CHOICE_CLASSES[color ?? 'blue'],
                canMove && (moving ? 'cursor-grabbing' : 'cursor-grab'),
                !canMove && 'cursor-pointer',
                moving && 'z-20 shadow-md',
            )}
            style={{
                left: x,
                width,
                top: (ROW_HEIGHT - BAR_HEIGHT) / 2,
                height: BAR_HEIGHT,
                touchAction: canMove ? 'none' : undefined,
            }}
            onPointerDown={(event) => canMove && begin(event, 'move')}
            onPointerMove={move}
            onPointerUp={end}
            onPointerCancel={() => setDrag(null)}
            onClick={() => {
                if (!dragged.current) {
                    store.expand(record.id);
                }

                dragged.current = false;
            }}
            onKeyDown={onKeyDown}
        >
            {/* Sticky, so a bar that starts left of the visible axis still shows its title. */}
            <span
                className="sticky flex min-w-0 items-center"
                style={{ left: TITLE_WIDTH + 4 }}
            >
                <span
                    className={cn(
                        'ml-1 h-3.5 w-1 shrink-0 rounded-full',
                        CHOICE_SWATCHES[color ?? 'blue'],
                    )}
                />
                {inside && <span className="truncate px-1.5">{title}</span>}
            </span>
            {!inside && (
                <span className="pointer-events-none absolute left-full ml-1.5 max-w-60 truncate text-neutral-700 dark:text-neutral-300">
                    {title}
                </span>
            )}
            {canResizeStart && handle('start')}
            {canResizeEnd && handle('end')}
            <span
                className={cn(
                    'pointer-events-none absolute top-full left-0 z-30 mt-1 rounded bg-neutral-900 px-1.5 py-0.5 text-[11px] font-normal whitespace-nowrap text-white shadow dark:bg-neutral-100 dark:text-neutral-900',
                    moving ? 'block' : 'hidden group-hover/bar:block',
                )}
            >
                {dates}
            </span>
        </div>
    );
}

/** A small arrow at the edge of the visible axis for a bar that's scrolled out of view; scrolls to it. */
function OffscreenArrow({
    side,
    left,
    right,
    onClick,
}: {
    side: 'left' | 'right';
    left: number;
    right: number;
    onClick: () => void;
}) {
    const Icon = side === 'left' ? ChevronLeft : ChevronRight;

    return (
        <button
            type="button"
            aria-label={
                side === 'left'
                    ? 'Earlier: scroll to the bar'
                    : 'Later: scroll to the bar'
            }
            className="absolute top-1/2 flex size-6 -translate-y-1/2 items-center justify-center rounded-full border border-neutral-200 bg-white text-neutral-500 opacity-0 shadow-xs transition-opacity group-hover/row:opacity-100 hover:text-neutral-900 focus-visible:opacity-100 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:text-neutral-100"
            style={{ left: side === 'left' ? left + 6 : right - 30 }}
            onClick={onClick}
        >
            <Icon className="size-3.5" />
        </button>
    );
}

function TrayChip({
    record,
    draggable,
    dragging,
    onDragStart,
    onDragEnd,
}: {
    record: TableRecord;
    draggable: boolean;
    dragging: boolean;
    onDragStart: () => void;
    onDragEnd: () => void;
}) {
    const store = useTableStore();
    const title = recordTitle(store, record);

    return (
        <button
            type="button"
            draggable={draggable}
            title={title}
            className={cn(
                'w-full shrink-0 truncate rounded border border-neutral-200 bg-white px-1.5 py-1 text-left text-xs shadow-xs transition-colors hover:border-neutral-300 hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800',
                draggable && 'cursor-grab',
                title === 'Untitled' && 'text-neutral-400',
                dragging && 'opacity-40',
            )}
            onClick={() => store.expand(record.id)}
            onDragStart={(event) => {
                event.dataTransfer.setData('text/plain', String(record.id));
                event.dataTransfer.effectAllowed = 'move';
                onDragStart();
            }}
            onDragEnd={onDragEnd}
        >
            {title}
        </button>
    );
}
