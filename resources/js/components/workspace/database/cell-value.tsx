import { cn } from '@/lib/utils';
import type { DatabasePreview, DatabaseValue } from '@/types';

export function isPreview(value: DatabaseValue): value is DatabasePreview {
    return typeof value === 'object' && value !== null;
}

/** The text put in the editor for a value. */
export function editableText(value: DatabaseValue): string {
    if (value === null || isPreview(value)) {
        return '';
    }

    return String(value);
}

function formatBytes(bytes: number): string {
    return bytes < 1024
        ? `${bytes} B`
        : bytes < 1048576
          ? `${(bytes / 1024).toFixed(1)} KB`
          : `${(bytes / 1048576).toFixed(1)} MB`;
}

/**
 * One cell's value: NULL, defaults and binary values are shown in muted italics.
 */
export default function CellValue({
    value,
    placeholder,
}: {
    value: DatabaseValue | undefined;
    placeholder?: string;
}) {
    const muted = 'text-muted-foreground italic';

    if (value === undefined) {
        return <span className={muted}>{placeholder ?? 'default'}</span>;
    }

    if (value === null) {
        return <span className={muted}>NULL</span>;
    }

    if (isPreview(value)) {
        return (
            <span className={muted}>
                {value.preview === null
                    ? `binary · ${formatBytes(value.bytes)}`
                    : `${value.preview}… (${formatBytes(value.bytes)})`}
            </span>
        );
    }

    if (typeof value === 'boolean') {
        return (
            <span className={cn(value ? 'text-green-600' : 'text-red-600')}>
                {String(value)}
            </span>
        );
    }

    if (typeof value === 'number') {
        return <span className="tabular-nums">{value}</span>;
    }

    return value === '' ? <span className={muted}>empty</span> : value;
}
