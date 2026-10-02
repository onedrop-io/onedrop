/**
 * Tab-separated text, as spreadsheets copy and paste it: cells with tabs, newlines or quotes are quoted.
 */

export function toTsv(rows: string[][]): string {
    return rows
        .map((row) =>
            row
                .map((cell) =>
                    /[\t\n\r"]/.test(cell)
                        ? `"${cell.replace(/"/g, '""')}"`
                        : cell,
                )
                .join('\t'),
        )
        .join('\n');
}

export function parseTsv(text: string): string[][] {
    const rows: string[][] = [];
    let row: string[] = [];
    let cell = '';
    let quoted = false;
    let index = 0;
    const input = text.replace(/\r\n?/g, '\n').replace(/\n$/, '');

    while (index < input.length) {
        const character = input[index];

        if (quoted) {
            if (character === '"' && input[index + 1] === '"') {
                cell += '"';
                index += 2;
                continue;
            }

            if (character === '"') {
                quoted = false;
            } else {
                cell += character;
            }
        } else if (character === '"' && cell === '') {
            quoted = true;
        } else if (character === '\t') {
            row.push(cell);
            cell = '';
        } else if (character === '\n') {
            row.push(cell);
            rows.push(row);
            row = [];
            cell = '';
        } else {
            cell += character;
        }

        index += 1;
    }

    row.push(cell);
    rows.push(row);

    return rows;
}
