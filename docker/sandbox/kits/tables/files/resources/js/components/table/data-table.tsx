import { X } from 'lucide-react';
import { useEffect } from 'react';
import { cn } from '@/lib/utils';
import { BoardView } from './board-view';
import { CalendarView } from './calendar-view';
import { GalleryView } from './gallery-view';
import { Grid } from './grid';
import { RecordPanel } from './record-panel';
import { Toolbar } from './toolbar';
import type { TableData } from './types';
import { TableContext, useTable } from './use-table';

/**
 * An Airtable-style table: a toolbar with the table's views, the current view (grid, board, calendar or
 * gallery) and the record panel. Give it a height (it fills its parent and scrolls inside), e.g.
 * `<div className="h-[calc(100vh-8rem)]"><DataTable data={table} /></div>`.
 */
export function DataTable({
    data,
    className,
}: {
    data: TableData;
    className?: string;
}) {
    const store = useTable(data);
    const { error, setError } = store;

    useEffect(() => {
        if (!error) {
            return;
        }

        const timer = setTimeout(() => setError(null), 8000);

        return () => clearTimeout(timer);
    }, [error, setError]);

    return (
        <TableContext.Provider value={store}>
            <div
                data-table=""
                className={cn(
                    'flex h-full min-h-0 flex-col overflow-hidden rounded-lg border border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-950',
                    className,
                )}
            >
                <Toolbar />
                {store.truncated && (
                    <p className="border-b border-amber-200 bg-amber-50 px-3 py-1.5 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
                        Showing the first{' '}
                        {store.records.length.toLocaleString()} records.
                    </p>
                )}
                {error && (
                    <div
                        role="alert"
                        className="flex items-start gap-2 border-b border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-100"
                    >
                        <span className="flex-1">{error}</span>
                        <button
                            type="button"
                            aria-label="Dismiss"
                            className="rounded p-0.5 hover:bg-red-100 dark:hover:bg-red-900"
                            onClick={() => setError(null)}
                        >
                            <X className="size-4" />
                        </button>
                    </div>
                )}
                <div className="min-h-0 flex-1">
                    {store.view?.type === 'board' ? (
                        <BoardView />
                    ) : store.view?.type === 'calendar' ? (
                        <CalendarView />
                    ) : store.view?.type === 'gallery' ? (
                        <GalleryView />
                    ) : (
                        <Grid key={store.view?.id} />
                    )}
                </div>
                <RecordPanel />
            </div>
        </TableContext.Provider>
    );
}
