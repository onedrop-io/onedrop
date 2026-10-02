import { FileUp } from 'lucide-react';
import { useState } from 'react';
import type { DragEvent } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectSeparator,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { parseCsv } from './csv';
import { isEditable, parseText } from './format';
import { FieldIcon, plural } from './sort-menu';
import type { CellValue, Field } from './types';
import { useTableStore } from './use-table';

/** Records are sent in batches of this many. */
const CHUNK = 200;

const SKIP = '__skip';
const CREATE = '__create';

interface ParsedFile {
    name: string;
    headers: string[];
    rows: string[][];
}

/** A column's target: a field key, SKIP or CREATE. */
type Mapping = string[];

function autoMap(headers: string[], fields: Field[]): Mapping {
    const used = new Set<string>();

    return headers.map((header) => {
        const name = header.trim().toLowerCase();
        const field = fields.find(
            (candidate) =>
                candidate.name.trim().toLowerCase() === name &&
                !used.has(candidate.key),
        );

        if (!field || name === '') {
            return SKIP;
        }

        used.add(field.key);

        return field.key;
    });
}

/** Lets the event loop breathe between batches, so the page keeps responding. */
function nextFrame(): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

/** Import a CSV file into the table: match its columns to fields (or new fields, or skip them) and add its rows. */
export function ImportDialog({
    open,
    onOpenChange,
    onImported,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Called with a sentence saying what was imported, after the dialog closes */
    onImported?: (message: string) => void;
}) {
    const store = useTableStore();
    const [file, setFile] = useState<ParsedFile | null>(null);
    const [mapping, setMapping] = useState<Mapping>([]);
    const [problem, setProblem] = useState<string | null>(null);
    const [dragOver, setDragOver] = useState(false);
    const [progress, setProgress] = useState<string | null>(null);
    const busy = progress !== null;
    const importable = store.fields.filter(isEditable);
    const mapped = mapping.filter((target) => target !== SKIP).length;

    const reset = () => {
        setFile(null);
        setMapping([]);
        setProblem(null);
        setProgress(null);
        setDragOver(false);
    };

    const close = (next: boolean) => {
        if (busy) {
            return;
        }

        if (!next) {
            reset();
        }

        onOpenChange(next);
    };

    const read = async (picked: File | undefined) => {
        if (!picked) {
            return;
        }

        setProblem(null);

        const text = await picked.text();
        const [headers = [], ...rest] = parseCsv(text);
        const rows = rest.filter((row) =>
            row.some((cell) => cell.trim() !== ''),
        );

        if (headers.length === 0 || rows.length === 0) {
            setProblem(
                'This file has no rows to import. The first row should name the columns.',
            );

            return;
        }

        setFile({ name: picked.name, headers, rows });
        setMapping(autoMap(headers, importable));
    };

    const onDrop = (event: DragEvent<HTMLElement>) => {
        event.preventDefault();
        setDragOver(false);
        void read(event.dataTransfer.files[0]);
    };

    const runImport = async () => {
        if (!file) {
            return;
        }

        const { can, context } = store;
        const targets: (Field | null)[] = [];

        setProgress('Getting fields ready…');

        // New fields first, as single line text.
        for (const [index, target] of mapping.entries()) {
            if (target === CREATE) {
                const field = await store.saveField({
                    name: file.headers[index].trim() || `Field ${index + 1}`,
                    type: 'text',
                });

                if (!field) {
                    setProgress(null);
                    setProblem(
                        `Couldn't add the field “${file.headers[index]}”.`,
                    );

                    return;
                }

                targets.push(field);
            } else {
                targets.push(
                    target === SKIP
                        ? null
                        : (importable.find((field) => field.key === target) ??
                              null),
                );
            }
        }

        let added = 0;
        let unreadable = 0;
        let failed = false;

        for (let start = 0; start < file.rows.length; start += CHUNK) {
            setProgress(
                `Adding records… ${start.toLocaleString()} of ${file.rows.length.toLocaleString()}`,
            );
            await nextFrame();

            const creates = file.rows.slice(start, start + CHUNK).map((row) => {
                const values: Record<string, CellValue> = {};

                targets.forEach((field, index) => {
                    const text = row[index] ?? '';

                    if (!field || text.trim() === '') {
                        return;
                    }

                    const result = parseText(
                        field,
                        text,
                        context,
                        can.manageFields,
                    );

                    if (result.ok) {
                        values[field.key] = result.value;
                    } else {
                        unreadable += 1;
                    }
                });

                return { values };
            });
            const result = await store.change({ creates }, { undoable: false });

            if (!result) {
                failed = true;
                break;
            }

            added += creates.length;
        }

        setProgress(null);

        const parts = [`Added ${plural(added, 'record')}.`];

        if (unreadable > 0) {
            parts.push(
                `${plural(unreadable, 'cell')} couldn't be read and ${unreadable === 1 ? 'was' : 'were'} left empty.`,
            );
        }

        if (failed) {
            parts.push(
                `The rest weren't added: ${file.rows.length - added} rows.`,
            );
        }

        reset();
        onOpenChange(false);
        onImported?.(parts.join(' '));
    };

    const setTarget = (index: number, target: string) =>
        setMapping((current) =>
            current.map((value, position) =>
                position === index ? target : value,
            ),
        );

    const shownColumns = file
        ? file.headers
              .map((header, index) => ({ header, index }))
              .filter(({ index }) => mapping[index] !== SKIP)
        : [];

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Import CSV</DialogTitle>
                    <DialogDescription>
                        {file
                            ? `${file.name}: ${plural(file.rows.length, 'row')}. Pick the field each column goes into.`
                            : `Add records to ${store.name} from a CSV file. Its first row should name the columns.`}
                    </DialogDescription>
                </DialogHeader>

                {!file && (
                    <label
                        onDragOver={(event) => {
                            event.preventDefault();
                            setDragOver(true);
                        }}
                        onDragLeave={() => setDragOver(false)}
                        onDrop={onDrop}
                        className={cn(
                            'flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed px-6 py-10 text-center text-sm transition-colors',
                            dragOver
                                ? 'border-blue-500 bg-blue-50 dark:bg-blue-950'
                                : 'border-neutral-300 hover:border-neutral-400 dark:border-neutral-700',
                        )}
                    >
                        <FileUp className="size-6 text-neutral-400" />
                        <span className="font-medium">
                            Choose a CSV file or drop it here
                        </span>
                        <span className="text-xs text-neutral-500">
                            Comma, semicolon or tab separated
                        </span>
                        <input
                            type="file"
                            accept=".csv,.tsv,.txt,text/csv,text/tab-separated-values"
                            aria-label="CSV file"
                            className="sr-only"
                            onChange={(event) => {
                                void read(event.target.files?.[0]);
                                event.target.value = '';
                            }}
                        />
                    </label>
                )}

                {problem && (
                    <p
                        role="alert"
                        className="text-sm text-red-600 dark:text-red-400"
                    >
                        {problem}
                    </p>
                )}

                {file && (
                    <div className="flex flex-col gap-4">
                        <div className="overflow-hidden rounded-md border border-neutral-200 dark:border-neutral-800">
                            <table className="w-full text-[13px]">
                                <thead className="bg-neutral-50 text-left text-xs text-neutral-500 dark:bg-neutral-900">
                                    <tr>
                                        <th className="px-3 py-1.5 font-medium">
                                            CSV column
                                        </th>
                                        <th className="px-3 py-1.5 font-medium">
                                            Field
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {file.headers.map((header, index) => (
                                        <tr
                                            key={index}
                                            className="border-t border-neutral-200 dark:border-neutral-800"
                                        >
                                            <td className="max-w-56 truncate px-3 py-1.5">
                                                {header || (
                                                    <span className="text-neutral-400">
                                                        Column {index + 1}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-3 py-1">
                                                <Select
                                                    value={mapping[index]}
                                                    onValueChange={(target) =>
                                                        setTarget(index, target)
                                                    }
                                                    disabled={busy}
                                                >
                                                    <SelectTrigger
                                                        aria-label={`Field for ${header || `column ${index + 1}`}`}
                                                        className="h-7 w-full px-2 text-[13px] shadow-none"
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem
                                                            value={SKIP}
                                                            className="text-[13px]"
                                                        >
                                                            Skip
                                                        </SelectItem>
                                                        {store.can
                                                            .manageFields && (
                                                            <SelectItem
                                                                value={CREATE}
                                                                className="text-[13px]"
                                                            >
                                                                Create a new
                                                                field
                                                            </SelectItem>
                                                        )}
                                                        <SelectSeparator />
                                                        {importable.map(
                                                            (field) => (
                                                                <SelectItem
                                                                    key={
                                                                        field.key
                                                                    }
                                                                    value={
                                                                        field.key
                                                                    }
                                                                    disabled={
                                                                        mapping[
                                                                            index
                                                                        ] !==
                                                                            field.key &&
                                                                        mapping.includes(
                                                                            field.key,
                                                                        )
                                                                    }
                                                                    className="text-[13px]"
                                                                >
                                                                    <FieldIcon
                                                                        field={
                                                                            field
                                                                        }
                                                                    />
                                                                    {field.name}
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {shownColumns.length > 0 && (
                            <div>
                                <p className="pb-1.5 text-xs font-medium text-neutral-500">
                                    Preview
                                </p>
                                <div className="overflow-x-auto rounded-md border border-neutral-200 dark:border-neutral-800">
                                    <table className="w-full text-[13px]">
                                        <thead className="bg-neutral-50 text-left text-xs text-neutral-500 dark:bg-neutral-900">
                                            <tr>
                                                {shownColumns.map(
                                                    ({ header, index }) => (
                                                        <th
                                                            key={index}
                                                            className="px-3 py-1.5 font-medium whitespace-nowrap"
                                                        >
                                                            {mapping[index] ===
                                                            CREATE
                                                                ? `${header} (new)`
                                                                : (store.fieldsByKey.get(
                                                                      mapping[
                                                                          index
                                                                      ],
                                                                  )?.name ??
                                                                  header)}
                                                        </th>
                                                    ),
                                                )}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {file.rows
                                                .slice(0, 3)
                                                .map((row, rowIndex) => (
                                                    <tr
                                                        key={rowIndex}
                                                        className="border-t border-neutral-200 dark:border-neutral-800"
                                                    >
                                                        {shownColumns.map(
                                                            ({ index }) => (
                                                                <td
                                                                    key={index}
                                                                    className="max-w-48 truncate px-3 py-1.5"
                                                                >
                                                                    {row[
                                                                        index
                                                                    ] ?? ''}
                                                                </td>
                                                            ),
                                                        )}
                                                    </tr>
                                                ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}
                    </div>
                )}

                {progress && (
                    <p
                        role="status"
                        className="text-sm text-neutral-600 dark:text-neutral-400"
                    >
                        {progress}
                    </p>
                )}

                <DialogFooter>
                    {file && (
                        <Button
                            variant="ghost"
                            disabled={busy}
                            onClick={reset}
                            className="mr-auto"
                        >
                            Choose another file
                        </Button>
                    )}
                    <Button
                        variant="outline"
                        disabled={busy}
                        onClick={() => close(false)}
                    >
                        Cancel
                    </Button>
                    {file && (
                        <Button
                            disabled={busy || mapped === 0}
                            onClick={() => void runImport()}
                        >
                            Import {plural(file.rows.length, 'record')}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
