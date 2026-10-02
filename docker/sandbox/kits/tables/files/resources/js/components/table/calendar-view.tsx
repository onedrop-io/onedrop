import { CalendarOff, ChevronLeft, ChevronRight, Plus, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { DragEvent } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import {
    FilteredOutBar,
    PickField,
    recordTitle,
    useCreateAndOpen,
} from './board-view';
import { isEditable, parseDate, toDateString } from './format';
import { Popover, PopoverContent, PopoverTrigger } from './popover';
import type { CellValue, Field, TableRecord } from './types';
import { useTableStore } from './use-table';

const MAX_CHIPS = 3;

/** Fields records can sit on in a calendar: dates, created/modified times, and formulas shown as dates. */
function isDateField(field: Field): boolean {
    return (
        field.type === 'date' ||
        field.type === 'createdAt' ||
        field.type === 'updatedAt' ||
        ((field.type === 'formula' || field.type === 'rollup') &&
            field.options.format === 'date')
    );
}

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

function recordDate(record: TableRecord, field: Field): CellValue {
    const value = record.values[field.key];

    if (value !== undefined && value !== null) {
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

function dayOf(value: CellValue): string | null {
    if (typeof value !== 'string' || value === '') {
        return null;
    }

    const date = parseDate(value);

    return date ? toDateString(date) : null;
}

/** The value a record gets when it's put on a day: keeps its time when the field has one. */
function valueOnDay(field: Field, current: CellValue, day: string): string {
    if (!field.options.includeTime) {
        return day;
    }

    const target = parseDate(day)!;
    const existing =
        typeof current === 'string' && current !== ''
            ? parseDate(current)
            : null;

    if (existing) {
        target.setHours(
            existing.getHours(),
            existing.getMinutes(),
            existing.getSeconds(),
        );
    }

    return target.toISOString();
}

export function CalendarView() {
    const store = useTableStore();
    const createAndOpen = useCreateAndOpen();
    const dateKey = store.view.config.dateField;
    const candidate = dateKey ? store.fieldsByKey.get(dateKey) : undefined;
    const dateField =
        candidate && isDateField(candidate) ? candidate : undefined;
    const [month, setMonth] = useState(() => {
        const now = new Date();

        return new Date(now.getFullYear(), now.getMonth(), 1);
    });
    const [weekStart] = useState(firstDayOfWeek);
    const [trayOpen, setTrayOpen] = useState(false);
    const [dragging, setDragging] = useState<number | null>(null);
    const [over, setOver] = useState<string | null>(null);

    const { byDay, undated } = useMemo(() => {
        const days = new Map<string, TableRecord[]>();
        const none: TableRecord[] = [];

        if (!dateField) {
            return { byDay: days, undated: none };
        }

        for (const record of store.rows) {
            const day = dayOf(recordDate(record, dateField));

            if (day === null) {
                none.push(record);
            } else {
                days.set(day, [...(days.get(day) ?? []), record]);
            }
        }

        return { byDay: days, undated: none };
    }, [store.rows, dateField]);

    const weeks = useMemo(() => {
        const offset = (month.getDay() - weekStart + 7) % 7;
        const start = new Date(
            month.getFullYear(),
            month.getMonth(),
            1 - offset,
        );
        const daysInMonth = new Date(
            month.getFullYear(),
            month.getMonth() + 1,
            0,
        ).getDate();
        const count = Math.ceil((offset + daysInMonth) / 7);

        return Array.from({ length: count }, (_, week) =>
            Array.from(
                { length: 7 },
                (_, day) =>
                    new Date(
                        start.getFullYear(),
                        start.getMonth(),
                        start.getDate() + week * 7 + day,
                    ),
            ),
        );
    }, [month, weekStart]);

    if (!dateField) {
        return (
            <PickField
                title="Pick a date field for the calendar"
                description="Records show on the day in this field. You can change it later in Customize."
                emptyText="Add a date field to the table to use a calendar."
                label="Show records by"
                fields={store.fields.filter(isDateField)}
                onPick={(key) => store.updateView({ dateField: key })}
            />
        );
    }

    const canMove =
        dateField.type === 'date' && isEditable(dateField) && store.can.edit;
    const canCreate = canMove && store.can.create;
    const today = toDateString(new Date());
    const title = new Intl.DateTimeFormat(undefined, {
        month: 'long',
        year: 'numeric',
    }).format(month);
    const weekdays = weeks[0].map((day) =>
        new Intl.DateTimeFormat(undefined, { weekday: 'short' }).format(day),
    );

    const step = (months: number) =>
        setMonth(new Date(month.getFullYear(), month.getMonth() + months, 1));

    const createOn = (day: string) => {
        if (canCreate) {
            void createAndOpen({
                [dateField.key]: valueOnDay(dateField, null, day),
            });
        }
    };

    const dropOn = (event: DragEvent, day: string) => {
        event.preventDefault();
        setOver(null);
        setDragging(null);

        const id = Number(event.dataTransfer.getData('text/plain'));
        const record = store.recordsById.get(id);

        if (!record) {
            return;
        }

        const current = record.values[dateField.key] ?? null;

        if (dayOf(current) !== day) {
            store.setCell(
                id,
                dateField.key,
                valueOnDay(dateField, current, day),
            );
        }
    };

    const chip = (record: TableRecord) => (
        <RecordChip
            key={record.id}
            record={record}
            draggable={canMove}
            dragging={dragging === record.id}
            onDragStart={() => setDragging(record.id)}
            onDragEnd={() => {
                setDragging(null);
                setOver(null);
            }}
        />
    );

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <FilteredOutBar />
            <div className="flex items-center gap-2 border-b border-neutral-200 px-3 py-2 dark:border-neutral-800">
                <h2 className="min-w-40 text-base font-semibold">{title}</h2>
                <Button
                    variant="outline"
                    size="sm"
                    className="h-7"
                    onClick={() => {
                        const now = new Date();

                        setMonth(
                            new Date(now.getFullYear(), now.getMonth(), 1),
                        );
                    }}
                >
                    Today
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-7"
                    aria-label="Previous month"
                    onClick={() => step(-1)}
                >
                    <ChevronLeft className="size-4" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-7"
                    aria-label="Next month"
                    onClick={() => step(1)}
                >
                    <ChevronRight className="size-4" />
                </Button>
                <div className="flex-1" />
                <Button
                    variant={trayOpen ? 'secondary' : 'ghost'}
                    size="sm"
                    className="h-7 text-neutral-600 dark:text-neutral-300"
                    aria-expanded={trayOpen}
                    onClick={() => setTrayOpen(!trayOpen)}
                >
                    <CalendarOff className="size-3.5" />
                    No date
                    <span className="text-xs text-neutral-500 tabular-nums">
                        {undated.length}
                    </span>
                </Button>
            </div>
            <div className="flex min-h-0 flex-1">
                <div className="flex min-w-0 flex-1 flex-col overflow-auto">
                    <div className="grid min-w-[42rem] grid-cols-7 border-b border-neutral-200 dark:border-neutral-800">
                        {weekdays.map((name) => (
                            <div
                                key={name}
                                className="px-2 py-1.5 text-xs font-medium text-neutral-500"
                            >
                                {name}
                            </div>
                        ))}
                    </div>
                    <div
                        className="grid min-h-0 min-w-[42rem] flex-1 grid-cols-7"
                        style={{
                            gridTemplateRows: `repeat(${weeks.length}, minmax(6.5rem, 1fr))`,
                        }}
                    >
                        {weeks.flat().map((date) => {
                            const day = toDateString(date);
                            const records = byDay.get(day) ?? [];
                            const outside =
                                date.getMonth() !== month.getMonth();
                            const isOver = over === day && dragging !== null;

                            return (
                                <div
                                    key={day}
                                    data-day={day}
                                    className={cn(
                                        'group relative flex min-h-0 min-w-0 flex-col gap-1 border-r border-b border-neutral-200 p-1 dark:border-neutral-800',
                                        outside &&
                                            'bg-neutral-50 dark:bg-neutral-900/50',
                                        isOver &&
                                            'bg-blue-50 ring-2 ring-blue-400 ring-inset dark:bg-blue-950/40 dark:ring-blue-700',
                                    )}
                                    onDoubleClick={(event) => {
                                        if (
                                            event.target === event.currentTarget
                                        ) {
                                            createOn(day);
                                        }
                                    }}
                                    onDragOver={(event) => {
                                        if (!canMove || dragging === null) {
                                            return;
                                        }

                                        event.preventDefault();
                                        event.dataTransfer.dropEffect = 'move';

                                        if (over !== day) {
                                            setOver(day);
                                        }
                                    }}
                                    onDragLeave={(event) => {
                                        if (
                                            !event.currentTarget.contains(
                                                event.relatedTarget as Node,
                                            )
                                        ) {
                                            setOver((current) =>
                                                current === day
                                                    ? null
                                                    : current,
                                            );
                                        }
                                    }}
                                    onDrop={(event) => {
                                        if (canMove) {
                                            dropOn(event, day);
                                        }
                                    }}
                                >
                                    <div className="flex items-center justify-between">
                                        <span
                                            className={cn(
                                                'flex size-6 items-center justify-center rounded-full text-xs tabular-nums',
                                                outside
                                                    ? 'text-neutral-400 dark:text-neutral-600'
                                                    : 'text-neutral-700 dark:text-neutral-300',
                                                day === today &&
                                                    'bg-blue-600 font-semibold text-white dark:text-white',
                                            )}
                                        >
                                            {date.getDate()}
                                        </span>
                                        {canCreate && (
                                            <button
                                                type="button"
                                                aria-label={`New record on ${date.toLocaleDateString()}`}
                                                className="rounded p-0.5 text-neutral-400 opacity-0 transition-opacity group-hover:opacity-100 hover:bg-neutral-100 hover:text-neutral-900 focus-visible:opacity-100 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                                                onClick={() => createOn(day)}
                                            >
                                                <Plus className="size-3.5" />
                                            </button>
                                        )}
                                    </div>
                                    {records.slice(0, MAX_CHIPS).map(chip)}
                                    {records.length > MAX_CHIPS && (
                                        <Popover>
                                            <PopoverTrigger asChild>
                                                <button
                                                    type="button"
                                                    className="self-start rounded px-1.5 py-0.5 text-xs font-medium text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900 dark:hover:bg-neutral-800 dark:hover:text-neutral-100"
                                                >
                                                    +
                                                    {records.length - MAX_CHIPS}{' '}
                                                    more
                                                </button>
                                            </PopoverTrigger>
                                            <PopoverContent className="flex max-h-80 w-64 flex-col gap-1 overflow-y-auto p-2">
                                                <p className="px-1 pb-1 text-xs font-medium text-neutral-500">
                                                    {date.toLocaleDateString(
                                                        undefined,
                                                        { dateStyle: 'full' },
                                                    )}
                                                </p>
                                                {records.map(chip)}
                                            </PopoverContent>
                                        </Popover>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </div>
                {trayOpen && (
                    <aside
                        aria-label="Records without a date"
                        className="flex w-64 shrink-0 flex-col border-l border-neutral-200 bg-neutral-50/60 dark:border-neutral-800 dark:bg-neutral-950"
                    >
                        <div className="flex items-center justify-between px-3 py-2">
                            <p className="text-sm font-medium">
                                No date{' '}
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
                                    Every record has a date.
                                </p>
                            ) : (
                                <>
                                    {canMove && (
                                        <p className="px-1 pb-1 text-xs text-neutral-500">
                                            Drag a record onto a day to give it
                                            a date.
                                        </p>
                                    )}
                                    {undated.slice(0, 500).map(chip)}
                                </>
                            )}
                        </div>
                    </aside>
                )}
            </div>
        </div>
    );
}

function RecordChip({
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
                'w-full shrink-0 truncate rounded border border-neutral-200 bg-white px-1.5 py-0.5 text-left text-xs shadow-xs transition-colors hover:border-neutral-300 hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800',
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
