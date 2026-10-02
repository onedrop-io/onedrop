import { displayText } from './format';
import type { TableStore } from './use-table';

/** The delimiter a file uses: commas, unless its first line has none and has tabs or semicolons. */
function detectDelimiter(text: string): string {
    const end = text.search(/\r?\n/);
    const header = end === -1 ? text : text.slice(0, end);

    if (header.includes(',')) {
        return ',';
    }

    if (header.includes('\t')) {
        return '\t';
    }

    if (header.includes(';')) {
        return ';';
    }

    return ',';
}

/**
 * CSV text as rows of cells (RFC 4180): quoted cells may hold delimiters, line breaks and doubled quotes.
 * Takes CRLF or LF line ends and a byte order mark, and semicolon or tab separated files.
 */
export function parseCsv(text: string): string[][] {
    const source = text.charCodeAt(0) === 0xfeff ? text.slice(1) : text;
    const delimiter = detectDelimiter(source);
    const rows: string[][] = [];
    let row: string[] = [];
    let cell = '';
    let quoted = false;
    /** The current line has something in it, even if only an empty quoted cell */
    let started = false;
    let index = 0;

    while (index < source.length) {
        const character = source[index];

        if (quoted) {
            if (character === '"') {
                if (source[index + 1] === '"') {
                    cell += '"';
                    index += 2;

                    continue;
                }

                quoted = false;
            } else {
                cell += character;
            }

            index += 1;

            continue;
        }

        started = character !== '\r' && character !== '\n';

        if (character === '"' && cell === '') {
            quoted = true;
        } else if (character === delimiter) {
            row.push(cell);
            cell = '';
        } else if (character === '\r' || character === '\n') {
            row.push(cell);
            rows.push(row);
            row = [];
            cell = '';

            if (character === '\r' && source[index + 1] === '\n') {
                index += 1;
            }
        } else {
            cell += character;
        }

        index += 1;
    }

    // The last line, unless the file ends with a line break.
    if (started || cell !== '' || row.length > 0) {
        row.push(cell);
        rows.push(row);
    }

    return rows;
}

function quoteCell(cell: string): string {
    return /[",\r\n]/.test(cell) || cell !== cell.trim()
        ? `"${cell.replace(/"/g, '""')}"`
        : cell;
}

/** Rows of cells as CSV text, quoting the cells that need it. */
export function toCsv(rows: string[][]): string {
    return rows.map((row) => row.map(quoteCell).join(',')).join('\r\n');
}

/** A name that's safe as a file name everywhere. */
function fileName(name: string): string {
    return name.replace(/[\\/:*?"<>|]+/g, '-').trim() || 'export';
}

/** Downloads the current view (its filters, sorts, search and shown fields) as a CSV file. */
export function exportView(store: TableStore): void {
    const fields = store.visibleFields;
    const rows = [
        fields.map((field) => field.name),
        ...store.rows.map((record) =>
            fields.map((field) =>
                displayText(field, record.values[field.key], store.context),
            ),
        ),
    ];
    // The byte order mark makes Excel read the file as UTF-8.
    const blob = new Blob(['﻿', toCsv(rows)], {
        type: 'text/csv;charset=utf-8',
    });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = `${fileName(`${store.name} - ${store.view.name}`)}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
