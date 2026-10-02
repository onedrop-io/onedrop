import { EyeOff } from 'lucide-react';
import { useState } from 'react';
import { cn } from '@/lib/utils';
import { Popover, PopoverContent, PopoverTrigger } from './popover';
import {
    DragHandle,
    FieldIcon,
    plural,
    ToolbarButton,
    useDragReorder,
} from './sort-menu';
import { useTableStore } from './use-table';

function Switch({
    checked,
    disabled,
    label,
    onChange,
}: {
    checked: boolean;
    disabled?: boolean;
    label: string;
    onChange: (checked: boolean) => void;
}) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            disabled={disabled}
            onClick={() => onChange(!checked)}
            className={cn(
                'relative inline-flex h-3.5 w-6 shrink-0 items-center rounded-full transition-colors outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50 disabled:cursor-not-allowed disabled:opacity-50',
                checked ? 'bg-green-600' : 'bg-neutral-300 dark:bg-neutral-700',
            )}
        >
            <span
                className={cn(
                    'size-2.5 rounded-full bg-white shadow transition-transform',
                    checked ? 'translate-x-3' : 'translate-x-0.5',
                )}
            />
        </button>
    );
}

/** The "Hide fields" toolbar button and its menu: show, hide and reorder the view's fields. */
export function FieldsMenu() {
    const store = useTableStore();
    const [query, setQuery] = useState('');
    const fields = store.orderedFields;
    const hidden = new Set(store.view.config.hidden);
    const hiddenCount = fields.filter(
        (field) => !field.primary && hidden.has(field.key),
    ).length;
    const needle = query.trim().toLowerCase();
    const shown = fields.filter((field) =>
        field.name.toLowerCase().includes(needle),
    );
    const { dragging, over, handleProps, rowProps } = useDragReorder(
        fields.map((field) => field.key),
        (order) => store.updateView({ order }),
    );

    const setHidden = (key: string, hide: boolean) => {
        const next = new Set(store.view.config.hidden);

        if (hide) {
            next.add(key);
        } else {
            next.delete(key);
        }

        store.updateView({ hidden: [...next] });
    };

    return (
        <Popover onOpenChange={() => setQuery('')}>
            <PopoverTrigger asChild>
                <ToolbarButton
                    icon={EyeOff}
                    label={
                        hiddenCount > 0
                            ? `${plural(hiddenCount, 'hidden field')}`
                            : 'Hide fields'
                    }
                    tint={hiddenCount > 0 ? 'blue' : null}
                />
            </PopoverTrigger>
            <PopoverContent className="w-72 p-0">
                <div className="border-b border-neutral-200 p-2 dark:border-neutral-800">
                    <input
                        autoFocus
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Find a field"
                        aria-label="Find a field"
                        className="h-7 w-full bg-transparent px-1 text-[13px] outline-none placeholder:text-neutral-400"
                    />
                </div>
                <div className="max-h-80 overflow-y-auto p-1.5">
                    {shown.map((field) => {
                        const visible = field.primary || !hidden.has(field.key);

                        return (
                            <div
                                key={field.key}
                                {...(needle === '' ? rowProps(field.key) : {})}
                                className={cn(
                                    'flex items-center gap-2 rounded px-1 py-1 hover:bg-neutral-50 dark:hover:bg-neutral-900',
                                    dragging === field.key && 'opacity-50',
                                    over === field.key &&
                                        dragging !== field.key &&
                                        'ring-2 ring-blue-500/40',
                                )}
                            >
                                <Switch
                                    checked={visible}
                                    disabled={field.primary}
                                    label={`Show ${field.name}`}
                                    onChange={(show) =>
                                        setHidden(field.key, !show)
                                    }
                                />
                                <FieldIcon field={field} />
                                <button
                                    type="button"
                                    disabled={field.primary}
                                    onClick={() =>
                                        setHidden(field.key, visible)
                                    }
                                    className="min-w-0 flex-1 truncate text-left text-[13px] disabled:cursor-default"
                                >
                                    {field.name}
                                </button>
                                {needle === '' && !field.primary && (
                                    <DragHandle {...handleProps(field.key)} />
                                )}
                            </div>
                        );
                    })}
                    {shown.length === 0 && (
                        <p className="px-2 py-1.5 text-[13px] text-neutral-500">
                            No fields match
                        </p>
                    )}
                </div>
                <div className="flex gap-2 border-t border-neutral-200 p-2 dark:border-neutral-800">
                    <button
                        type="button"
                        onClick={() =>
                            store.updateView({
                                hidden: fields
                                    .filter((field) => !field.primary)
                                    .map((field) => field.key),
                            })
                        }
                        className="flex-1 rounded bg-neutral-100 px-2 py-1 text-xs text-neutral-700 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700"
                    >
                        Hide all
                    </button>
                    <button
                        type="button"
                        onClick={() => store.updateView({ hidden: [] })}
                        className="flex-1 rounded bg-neutral-100 px-2 py-1 text-xs text-neutral-700 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700"
                    >
                        Show all
                    </button>
                </div>
            </PopoverContent>
        </Popover>
    );
}
