import { Fragment } from 'react';
import type { ReactNode } from 'react';
import type { LogSearch } from '@/lib/log-search';
import { cn } from '@/lib/utils';

/** ANSI colors from the theme's terminal palette (themes.css), like the Shell's; black shows as a dim grey. */
const ANSI_COLORS = [
    'text-(--terminal-bright-black)',
    'text-(--terminal-red)',
    'text-(--terminal-green)',
    'text-(--terminal-yellow)',
    'text-(--terminal-blue)',
    'text-(--terminal-magenta)',
    'text-(--terminal-cyan)',
    'text-(--terminal-white)',
];
const ANSI_BRIGHT_COLORS = [
    'text-(--terminal-bright-black)',
    'text-(--terminal-bright-red)',
    'text-(--terminal-bright-green)',
    'text-(--terminal-bright-yellow)',
    'text-(--terminal-bright-blue)',
    'text-(--terminal-bright-magenta)',
    'text-(--terminal-bright-cyan)',
    'text-(--terminal-bright-white)',
];

const LEVEL_COLORS: [RegExp, string][] = [
    [
        /^(fatal|panic|crit|critical|error|err|emerg|alert)$/i,
        'text-(--terminal-red) font-semibold',
    ],
    [/^(warn|warning)$/i, 'text-(--terminal-yellow)'],
    [/^(info|notice)$/i, 'text-(--terminal-blue)'],
    [/^(debug|trace)$/i, 'text-(--terminal-bright-black)'],
];

const LEVEL_WORDS =
    'FATAL|PANIC|CRIT(?:ICAL)?|ERROR|ERR|WARN(?:ING)?|INFO|NOTICE|DEBUG|TRACE';

const TOKEN = new RegExp(
    [
        String.raw`(?<time>\b\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:[.,]\d+)?(?:Z|[+-]\d{2}:?\d{2})?|\b\d{2}:\d{2}:\d{2}(?:\.\d+)?\b)`,
        String.raw`(?<level>\b(?:${LEVEL_WORDS})\b|(?<=\blevel=)\w+)`,
        String.raw`(?<url>\bhttps?://[^\s"'<>]+)`,
        String.raw`(?<duration>(?<![\w.])\d+(?:\.\d+)?\s?(?:µs|ms|s)\b)`,
        String.raw`(?<leader>\.{4,})`,
        String.raw`(?<key>"[\w.@-]+"(?=\s*:)|\b[\w.-]+(?==))`,
        String.raw`(?<string>"(?:[^"\\\n]|\\.)*")`,
    ].join('|'),
    'g',
);

// eslint-disable-next-line no-control-regex
const ESCAPE = /\x1b\[([0-9;?]*)([A-Za-z])|\x1b[()][A-Za-z0-9]|\r/g;

type AnsiStyle = { color: string | null; bold: boolean; dim: boolean };

/** A log line as shown: its position in the log (from 0) and its colored content. */
export type LogLine = { index: number; nodes: ReactNode[] };

/** Colors told apart in the theme's terminal palette, one per label of merged logs. */
const LABEL_COLORS = [
    'text-(--terminal-cyan)',
    'text-(--terminal-magenta)',
    'text-(--terminal-yellow)',
    'text-(--terminal-green)',
    'text-(--terminal-blue)',
    'text-(--terminal-bright-red)',
    'text-(--terminal-bright-magenta)',
    'text-(--terminal-bright-green)',
];

/**
 * Log output as colored lines, in the theme's terminal colors (on a `bg-well`). Lines a program colored itself (ANSI escapes) keep
 * those colors; other lines get their timestamps, levels, keys, quoted strings, URLs and durations colored
 * (slow ones amber or red) and dot leaders dimmed, and URLs open in a new tab. Other escapes (cursor moves,
 * `\r` redraws) are dropped, so they don't show as junk. With a search, only the lines passing it are kept,
 * and its terms are marked. `labelled` logs merge several programs' output as "label<TAB>line": each label
 * gets its own color, is searched with its line, and keeps its own ANSI colors so one can't bleed into another.
 */
export function highlightLog(
    text: string,
    search: LogSearch | null = null,
    labelled = false,
): LogLine[] {
    const lines: LogLine[] = [];
    const marker = search?.terms.length
        ? new RegExp(`(${search.terms.map(escapeRegExp).join('|')})`, 'gi')
        : null;
    const marked = (part: string): ReactNode =>
        marker ? markMatches(part, marker) : part;
    const styles = new Map<string, AnsiStyle>();
    const rows = text.replace(/\n$/, '').split('\n');
    const labels = labelled
        ? rows.map((row) => {
              const tab = row.indexOf('\t');

              return tab > 0 ? row.slice(0, tab) : null;
          })
        : [];
    const labelWidth = Math.max(
        0,
        ...labels.map((label) => label?.length ?? 0),
    );

    rows.forEach((row, index) => {
        const label = labels[index] ?? null;
        const line = label ? row.slice(label.length + 1) : row;
        let style: AnsiStyle = styles.get(label ?? '') ?? {
            color: null,
            bold: false,
            dim: false,
        };
        const plain = line.replace(ESCAPE, '');
        const shown =
            !search || search.matches(label ? `${label} ${plain}` : plain);
        const nodes: ReactNode[] = [];

        if (label && shown) {
            nodes.push(
                <span
                    key="label"
                    className={cn('inline-block', labelColor(label))}
                    style={{ width: `${labelWidth + 2}ch` }}
                    data-test="log-label"
                >
                    {marked(label)}
                </span>,
            );
        }

        // eslint-disable-next-line no-control-regex
        const styled = /\x1b\[[0-9;]*m/.test(line) || style.color !== null;

        if (!styled) {
            if (shown) {
                highlightPlain(plain, nodes, marked);
            }
        } else {
            let last = 0;

            // Escapes are still read on hidden lines, so the colors they start carry on.
            for (const match of line.matchAll(ESCAPE)) {
                if (shown) {
                    pushStyled(
                        line.slice(last, match.index),
                        style,
                        nodes,
                        marked,
                    );
                }

                last = match.index + match[0].length;

                if (match[2] === 'm') {
                    style = applySgr(style, match[1]);
                }
            }

            if (shown) {
                pushStyled(line.slice(last), style, nodes, marked);
            }
        }

        styles.set(label ?? '', style);

        if (shown) {
            lines.push({ index, nodes });
        }
    });

    return lines;
}

/** A label's color, from its name, so it keeps it as lines come and go. */
function labelColor(label: string): string {
    let hash = 0;

    for (const character of label) {
        hash = (hash * 31 + character.charCodeAt(0)) >>> 0;
    }

    return LABEL_COLORS[hash % LABEL_COLORS.length];
}

function escapeRegExp(text: string): string {
    return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** Text with each match of `marker` (a capturing, global regex) in a <mark>. */
function markMatches(text: string, marker: RegExp): ReactNode {
    const parts = text.split(marker);

    if (parts.length === 1) {
        return text;
    }

    // split() puts the captured matches at the odd positions.
    return parts.map((part, at) =>
        at % 2 ? (
            <mark
                key={at}
                className="rounded-sm bg-amber-400/80 text-neutral-950"
            >
                {part}
            </mark>
        ) : (
            part
        ),
    );
}

function highlightPlain(
    line: string,
    nodes: ReactNode[],
    marked: (part: string) => ReactNode,
): void {
    let last = 0;

    for (const match of line.matchAll(TOKEN)) {
        if (match.index > last) {
            nodes.push(
                <Fragment key={nodes.length}>
                    {marked(line.slice(last, match.index))}
                </Fragment>,
            );
        }

        if (match.groups?.url) {
            // Punctuation ending a sentence ("Live at https://….") isn't part of the address.
            const url = match[0].replace(/[.,;:!?)\]]+$/, '');

            nodes.push(
                <a
                    key={nodes.length}
                    href={url}
                    target="_blank"
                    rel="noreferrer"
                    className={tokenClass(match)}
                >
                    {marked(url)}
                </a>,
            );
            last = match.index + url.length;

            continue;
        }

        nodes.push(
            <span key={nodes.length} className={tokenClass(match)}>
                {marked(match[0])}
            </span>,
        );
        last = match.index + match[0].length;
    }

    if (last < line.length) {
        nodes.push(
            <Fragment key={nodes.length}>{marked(line.slice(last))}</Fragment>,
        );
    }
}

function tokenClass(match: RegExpMatchArray): string {
    const groups = match.groups ?? {};

    if (groups.time) {
        return 'text-(--terminal-bright-black)';
    }

    if (groups.level) {
        return levelClass(groups.level) ?? '';
    }

    if (groups.url) {
        return 'text-(--terminal-bright-blue) underline';
    }

    if (groups.duration) {
        return durationClass(groups.duration);
    }

    if (groups.leader) {
        return 'text-(--terminal-bright-black) opacity-60';
    }

    if (groups.key) {
        return 'text-(--terminal-magenta)';
    }

    // A quoted level, as in JSON logs' "level":"error".
    return levelClass(match[0].slice(1, -1)) ?? 'text-(--terminal-green)';
}

/** Durations dimmed when quick, amber from 100ms and red from 500ms, so slow requests stand out. */
function durationClass(duration: string): string {
    const amount = parseFloat(duration);
    const unit = duration.replace(/[\d.\s]/g, '');
    const ms =
        unit === 's' ? amount * 1000 : unit === 'µs' ? amount / 1000 : amount;

    if (ms >= 500) {
        return 'text-(--terminal-red)';
    }

    return ms >= 100
        ? 'text-(--terminal-yellow)'
        : 'text-(--terminal-bright-black)';
}

function levelClass(word: string): string | null {
    return LEVEL_COLORS.find(([pattern]) => pattern.test(word))?.[1] ?? null;
}

function pushStyled(
    text: string,
    style: AnsiStyle,
    nodes: ReactNode[],
    marked: (part: string) => ReactNode,
): void {
    if (!text) {
        return;
    }

    if (!style.color && !style.bold && !style.dim) {
        nodes.push(<Fragment key={nodes.length}>{marked(text)}</Fragment>);

        return;
    }

    nodes.push(
        <span
            key={nodes.length}
            className={cn(
                style.color,
                style.bold && 'font-semibold',
                style.dim && 'opacity-60',
            )}
        >
            {marked(text)}
        </span>,
    );
}

/** The style after an SGR escape (`ESC[…m`); 256-color and RGB colors fall back to the default color. */
function applySgr(style: AnsiStyle, params: string): AnsiStyle {
    const codes = params === '' ? [0] : params.split(';').map(Number);
    let next = { ...style };

    for (let i = 0; i < codes.length; i++) {
        const code = codes[i];

        if (code === 0) {
            next = { color: null, bold: false, dim: false };
        } else if (code === 1) {
            next.bold = true;
        } else if (code === 2) {
            next.dim = true;
        } else if (code === 22) {
            next.bold = false;
            next.dim = false;
        } else if (code >= 30 && code <= 37) {
            next.color = ANSI_COLORS[code - 30];
        } else if (code >= 90 && code <= 97) {
            next.color = ANSI_BRIGHT_COLORS[code - 90];
        } else if (code === 39) {
            next.color = null;
        } else if (code === 38 || code === 48) {
            if (code === 38) {
                next.color = null;
            }

            // Skip the color's own parameters: `5;n` or `2;r;g;b`.
            i += codes[i + 1] === 5 ? 2 : codes[i + 1] === 2 ? 4 : 0;
        }
    }

    return next;
}
