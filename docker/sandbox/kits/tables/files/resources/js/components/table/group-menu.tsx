import { Layers } from 'lucide-react';
import { cn } from '@/lib/utils';
import { valueKind } from './format';
import { Popover, PopoverContent, PopoverTrigger } from './popover';
import { plural, RuleEditor, ToolbarButton } from './sort-menu';
import { useTableStore } from './use-table';

/** Grids group by up to three fields. */
export const MAX_GROUPS = 3;

/** The "Group" toolbar button and its menu (grid views). */
export function GroupMenu() {
    const store = useTableStore();
    const groups = store.view.config.groups.filter((rule) =>
        store.fieldsByKey.has(rule.field),
    );
    const fields = store.orderedFields.filter(
        (field) => valueKind(field) !== 'attachment',
    );

    return (
        <Popover>
            <PopoverTrigger asChild>
                <ToolbarButton
                    icon={Layers}
                    label={
                        groups.length > 0
                            ? `Grouped by ${plural(groups.length, 'field')}`
                            : 'Group'
                    }
                    tint={groups.length > 0 ? 'violet' : null}
                />
            </PopoverTrigger>
            <PopoverContent
                className={cn('p-0', groups.length > 0 ? 'w-auto' : 'w-72')}
            >
                <div className="border-b border-neutral-200 px-3 py-2 text-xs font-medium text-neutral-500 dark:border-neutral-800">
                    Group by
                </div>
                <RuleEditor
                    rules={groups}
                    fields={fields}
                    max={MAX_GROUPS}
                    onChange={(rules) => store.updateView({ groups: rules })}
                    addLabel="Add subgroup"
                    emptyText="Pick a field to group by"
                />
            </PopoverContent>
        </Popover>
    );
}
