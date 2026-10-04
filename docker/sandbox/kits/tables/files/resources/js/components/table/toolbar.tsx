import {
    Download,
    Ellipsis,
    Plus,
    Rows3,
    Search,
    SlidersHorizontal,
    Upload,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { exportView } from './csv';
import { FieldsMenu } from './fields-menu';
import { FilterMenu } from './filter-menu';
import { isEditable, toDateString, valueKind } from './format';
import { isComplete, resolveDate } from './filters';
import { GroupMenu } from './group-menu';
import { ImportDialog } from './import-dialog';
import { Popover, PopoverContent, PopoverTrigger } from './popover';
import { FieldIcon, plural, SortMenu, ToolbarButton } from './sort-menu';
import type {
    CellValue,
    Field,
    FilterCondition,
    RowHeight,
    ViewConfig,
} from './types';
import type { TableStore } from './use-table';
import { useTableStore } from './use-table';
import { ViewMenu } from './view-menu';

const ROW_HEIGHTS: { value: RowHeight; label: string }[] = [
    { value: 'short', label: 'Short' },
    { value: 'medium', label: 'Medium' },
    { value: 'tall', label: 'Tall' },
    { value: 'extraTall', label: 'Extra tall' },
];

const NONE = '__none';

/** The value a condition clearly asks for, so a record added in a filtered view shows in it. */
function impliedValue(
    field: Field,
    condition: FilterCondition,
    me: number | null,
): CellValue | undefined {
    const { operator, value } = condition;
    const kind = valueKind(field);

    if (operator === 'isMe') {
        return kind === 'user' && me !== null ? me : undefined;
    }

    if (!isComplete(condition)) {
        return undefined;
    }

    switch (kind) {
        case 'boolean':
            return operator === 'is' ? value === true : undefined;
        case 'number':
            return operator === 'eq' && typeof value === 'number'
                ? value
                : undefined;
        case 'date': {
            if (operator !== 'is') {
                return undefined;
            }

            const date = resolveDate(value);

            return date ? toDateString(date) : undefined;
        }
        case 'choice':
        case 'user': {
            const list = Array.isArray(value) ? value : [value];

            return operator === 'is' ||
                (operator === 'isAnyOf' && list.length === 1)
                ? (list[0] as CellValue)
                : undefined;
        }
        case 'choices':
            return operator === 'hasAnyOf' || operator === 'hasAllOf'
                ? value
                : undefined;
        case 'text':
            return operator === 'is' ? value : undefined;
        default:
            return undefined;
    }
}

/** Values for a new record that match the view's filters, where that's obvious. */
export function valuesForView(store: TableStore): Record<string, CellValue> {
    const { filters } = store.view.config;
    const values: Record<string, CellValue> = {};

    if (filters.conjunction === 'or' && filters.conditions.length > 1) {
        return values;
    }

    for (const condition of filters.conditions) {
        const field = store.fieldsByKey.get(condition.field);

        if (!field || !isEditable(field) || field.key in values) {
            continue;
        }

        const value = impliedValue(field, condition, store.me);

        if (value !== undefined) {
            values[field.key] = value;
        }
    }

    return values;
}

/** The toolbar above a table: the view switcher, the view's settings, search, the record count and actions. */
export function Toolbar() {
    const store = useTableStore();
    const { view, can } = store;
    const [importing, setImporting] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);
    const filtered = store.rows.length !== store.records.length;
    // A form view has its own settings: the toolbar only switches views and offers the "…" menu.
    const isForm = view.type === 'form';

    useEffect(() => {
        if (notice === null) {
            return;
        }

        const timer = setTimeout(() => setNotice(null), 8000);

        return () => clearTimeout(timer);
    }, [notice]);

    const addRecord = async () => {
        const record = await store.createRecord(valuesForView(store));

        if (record) {
            store.expand(record.id);
        }
    };

    return (
        <div className="flex flex-wrap items-center gap-x-1 gap-y-1 border-b border-neutral-200 px-2 py-1.5 dark:border-neutral-800">
            <ViewMenu />
            <div className="mx-1 h-4 w-px bg-neutral-200 dark:bg-neutral-800" />
            {!isForm && (
                <>
                    <FieldsMenu />
                    <FilterMenu />
                    {(view.type === 'grid' || view.type === 'timeline') && (
                        <GroupMenu />
                    )}
                    <SortMenu />
                    {view.type === 'grid' ? (
                        <RowHeightMenu />
                    ) : (
                        <CustomizeMenu />
                    )}
                    <SearchBox />
                </>
            )}

            <div className="ml-auto flex items-center gap-1">
                {notice && (
                    <span
                        role="status"
                        className="flex items-center gap-1 rounded-md bg-green-50 px-2 py-1 text-xs text-green-800 dark:bg-green-950 dark:text-green-200"
                    >
                        {notice}
                        <button
                            type="button"
                            aria-label="Dismiss"
                            onClick={() => setNotice(null)}
                            className="rounded p-0.5 hover:bg-green-100 dark:hover:bg-green-900"
                        >
                            <X className="size-3" />
                        </button>
                    </span>
                )}
                {!isForm && (
                    <span className="px-1 text-xs whitespace-nowrap text-neutral-500 tabular-nums">
                        {filtered
                            ? `${store.rows.length.toLocaleString()} of ${plural(store.records.length, 'record')}`
                            : plural(store.records.length, 'record')}
                    </span>
                )}
                {can.create && !isForm && (
                    <button
                        type="button"
                        onClick={() => void addRecord()}
                        className="inline-flex h-7 items-center gap-1 rounded-md bg-blue-600 px-2.5 text-[13px] font-medium whitespace-nowrap text-white hover:bg-blue-700 focus-visible:ring-2 focus-visible:ring-blue-500/50 focus-visible:outline-none"
                    >
                        <Plus className="size-4" />
                        Add record
                    </button>
                )}
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <ToolbarButton
                            icon={Ellipsis}
                            aria-label="More actions"
                        />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-44">
                        {can.create && (
                            <DropdownMenuItem
                                onSelect={() => setImporting(true)}
                            >
                                <Upload />
                                Import CSV
                            </DropdownMenuItem>
                        )}
                        <DropdownMenuItem onSelect={() => exportView(store)}>
                            <Download />
                            Export CSV
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
            {can.create && (
                <ImportDialog
                    open={importing}
                    onOpenChange={setImporting}
                    onImported={setNotice}
                />
            )}
        </div>
    );
}

function RowHeightMenu() {
    const store = useTableStore();
    const current = store.view.config.rowHeight;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <ToolbarButton
                    icon={Rows3}
                    aria-label="Row height"
                    title="Row height"
                />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-40">
                <DropdownMenuLabel className="text-xs text-neutral-500">
                    Row height
                </DropdownMenuLabel>
                <DropdownMenuRadioGroup
                    value={current}
                    onValueChange={(value) =>
                        store.updateView({ rowHeight: value as RowHeight })
                    }
                >
                    {ROW_HEIGHTS.map((height) => (
                        <DropdownMenuRadioItem
                            key={height.value}
                            value={height.value}
                        >
                            {height.label}
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** A labelled field dropdown in the Customize menu; `none` adds a "None" choice. */
function SettingSelect({
    label,
    fields,
    value,
    none,
    onChange,
}: {
    label: string;
    fields: Field[];
    value: string | null;
    none?: boolean;
    onChange: (key: string | null) => void;
}) {
    const current =
        value !== null && fields.some((field) => field.key === value)
            ? value
            : none
              ? NONE
              : '';

    return (
        <label className="flex flex-col gap-1">
            <span className="text-xs font-medium text-neutral-500">
                {label}
            </span>
            <Select
                value={current}
                onValueChange={(key) => onChange(key === NONE ? null : key)}
            >
                <SelectTrigger
                    aria-label={label}
                    className="h-8 w-full px-2 text-[13px] shadow-none"
                >
                    <SelectValue placeholder="Pick a field" />
                </SelectTrigger>
                <SelectContent>
                    {none && (
                        <SelectItem value={NONE} className="text-[13px]">
                            None
                        </SelectItem>
                    )}
                    {fields.map((field) => (
                        <SelectItem
                            key={field.key}
                            value={field.key}
                            className="text-[13px]"
                        >
                            <FieldIcon field={field} />
                            {field.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            {fields.length === 0 && (
                <span className="text-xs text-neutral-500">
                    This table has no fields that fit.
                </span>
            )}
        </label>
    );
}

/** Board, calendar, gallery and timeline settings: what to stack by, the date fields, the cover field. */
function CustomizeMenu() {
    const store = useTableStore();
    const { view } = store;
    const fields = store.orderedFields;
    const stackable = fields.filter(
        (field) => field.type === 'select' || field.type === 'user',
    );
    const dates = fields.filter((field) => valueKind(field) === 'date');
    const attachments = fields.filter((field) => field.type === 'attachment');
    const set = (patch: Partial<ViewConfig>) => store.updateView(patch);

    return (
        <Popover>
            <PopoverTrigger asChild>
                <ToolbarButton icon={SlidersHorizontal} label="Customize" />
            </PopoverTrigger>
            <PopoverContent className="flex w-64 flex-col gap-3">
                {view.type === 'board' && (
                    <SettingSelect
                        label="Stack by"
                        fields={stackable}
                        value={view.config.stackBy}
                        onChange={(stackBy) => set({ stackBy })}
                    />
                )}
                {view.type === 'calendar' && (
                    <SettingSelect
                        label="Date field"
                        fields={dates}
                        value={view.config.dateField}
                        onChange={(dateField) => set({ dateField })}
                    />
                )}
                {view.type === 'timeline' && (
                    <>
                        <SettingSelect
                            label="Start date field"
                            fields={dates}
                            value={view.config.dateField}
                            onChange={(dateField) =>
                                set(
                                    dateField === view.config.endField
                                        ? { dateField, endField: null }
                                        : { dateField },
                                )
                            }
                        />
                        <SettingSelect
                            label="End date field"
                            fields={dates.filter(
                                (field) => field.key !== view.config.dateField,
                            )}
                            value={view.config.endField}
                            none
                            onChange={(endField) => set({ endField })}
                        />
                    </>
                )}
                {(view.type === 'board' || view.type === 'gallery') && (
                    <SettingSelect
                        label="Cover field"
                        fields={attachments}
                        value={view.config.coverField}
                        none
                        onChange={(coverField) => set({ coverField })}
                    />
                )}
            </PopoverContent>
        </Popover>
    );
}

/** A search button that opens into a box; ⌘F / Ctrl F inside the table opens it, Escape clears and closes it. */
function SearchBox() {
    const store = useTableStore();
    const [open, setOpen] = useState(store.search !== '');
    const input = useRef<HTMLInputElement>(null);
    const root = useRef<HTMLDivElement>(null);
    const { setSearch } = store;

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (
                !(event.metaKey || event.ctrlKey) ||
                event.key.toLowerCase() !== 'f'
            ) {
                return;
            }

            // The table's own container: the toolbar's parent.
            const table =
                root.current?.closest('[data-table]') ??
                root.current?.parentElement?.parentElement;
            const focused = document.activeElement;

            if (!table || !focused || !table.contains(focused)) {
                return;
            }

            event.preventDefault();
            setOpen(true);
            requestAnimationFrame(() => input.current?.select());
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    const close = () => {
        setSearch('');
        setOpen(false);
    };

    return (
        <div ref={root} className="flex items-center">
            {open ? (
                <div className="flex h-7 items-center gap-1 rounded-md border border-neutral-200 px-1.5 focus-within:border-blue-500 focus-within:ring-2 focus-within:ring-blue-500/20 dark:border-neutral-800">
                    <Search className="size-3.5 shrink-0 text-neutral-400" />
                    <input
                        ref={input}
                        autoFocus
                        value={store.search}
                        onChange={(event) => setSearch(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Escape') {
                                event.preventDefault();
                                close();
                            }
                        }}
                        onBlur={() => store.search === '' && setOpen(false)}
                        placeholder="Find in view"
                        aria-label="Search records"
                        className="h-6 w-36 bg-transparent text-[13px] outline-none placeholder:text-neutral-400 sm:w-44"
                    />
                    {store.search !== '' && (
                        <button
                            type="button"
                            aria-label="Clear search"
                            onMouseDown={(event) => event.preventDefault()}
                            onClick={close}
                            className="rounded p-0.5 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-800"
                        >
                            <X className="size-3" />
                        </button>
                    )}
                </div>
            ) : (
                <ToolbarButton
                    icon={Search}
                    aria-label="Search"
                    title="Search (⌘F)"
                    onClick={() => setOpen(true)}
                />
            )}
        </div>
    );
}
