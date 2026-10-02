import { Plus } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import {
    CardFields,
    coverImage,
    NoRecords,
    recordTitle,
    useCoverField,
    useCreateAndOpen,
} from './board-view';
import { FIELD_ICONS } from './field-icons';
import type { Field, TableRecord } from './types';
import { useTableStore } from './use-table';

const PAGE = 300;

export function GalleryView() {
    const store = useTableStore();
    const coverField = useCoverField();
    const createAndOpen = useCreateAndOpen();
    const [limit, setLimit] = useState(PAGE);
    const primary = store.fields.find((field) => field.primary);
    const PlaceholderIcon =
        FIELD_ICONS[coverField?.type ?? primary?.type ?? 'text'];

    if (store.rows.length === 0) {
        return (
            <div className="flex min-h-0 flex-1 flex-col overflow-auto">
                <NoRecords>
                    {store.can.create && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => void createAndOpen({})}
                        >
                            <Plus className="size-4" />
                            New record
                        </Button>
                    )}
                </NoRecords>
            </div>
        );
    }

    return (
        <div className="min-h-0 flex-1 overflow-y-auto bg-neutral-50/60 p-4 dark:bg-neutral-950">
            <div className="grid grid-cols-[repeat(auto-fill,minmax(15rem,1fr))] gap-4">
                {store.rows.slice(0, limit).map((record) => (
                    <GalleryCard
                        key={record.id}
                        record={record}
                        coverField={coverField}
                        placeholder={
                            <PlaceholderIcon className="size-8 text-neutral-300 dark:text-neutral-700" />
                        }
                    />
                ))}
                {store.can.create && limit >= store.rows.length && (
                    <button
                        type="button"
                        className="flex min-h-56 flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-neutral-300 text-sm text-neutral-500 transition-colors hover:border-neutral-400 hover:bg-white hover:text-neutral-900 dark:border-neutral-700 dark:hover:bg-neutral-900 dark:hover:text-neutral-100"
                        onClick={() => void createAndOpen({})}
                    >
                        <Plus className="size-5" />
                        New record
                    </button>
                )}
            </div>
            {store.rows.length > limit && (
                <div className="flex justify-center pt-4">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setLimit(limit + PAGE)}
                    >
                        Show more ({store.rows.length - limit})
                    </Button>
                </div>
            )}
        </div>
    );
}

function GalleryCard({
    record,
    coverField,
    placeholder,
}: {
    record: TableRecord;
    coverField: Field | undefined;
    placeholder: ReactNode;
}) {
    const store = useTableStore();
    const cover = coverImage(record, coverField);
    const title = recordTitle(store, record);

    return (
        <div
            role="button"
            tabIndex={0}
            aria-label={title}
            className="flex cursor-pointer flex-col overflow-hidden rounded-lg border border-neutral-200 bg-white text-left shadow-xs transition-shadow outline-none hover:shadow-md focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:border-neutral-800 dark:bg-neutral-950"
            onClick={() => store.expand(record.id)}
            onKeyDown={(event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    store.expand(record.id);
                }
            }}
        >
            <div className="flex h-40 items-center justify-center border-b border-neutral-100 bg-neutral-100 dark:border-neutral-800 dark:bg-neutral-900">
                {cover ? (
                    <img
                        src={cover.url}
                        alt=""
                        loading="lazy"
                        className="h-full w-full object-cover"
                    />
                ) : (
                    placeholder
                )}
            </div>
            <div className="flex flex-col gap-2.5 p-3">
                <p
                    className={cn(
                        'truncate text-sm font-semibold',
                        title === 'Untitled' && 'text-neutral-400',
                    )}
                >
                    {title}
                </p>
                <CardFields
                    record={record}
                    exclude={[coverField?.key ?? '']}
                    limit={6}
                />
            </div>
        </div>
    );
}
