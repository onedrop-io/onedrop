import { CircleAlert, LoaderCircle, SquareFunction } from 'lucide-react';
import { Fragment, useEffect, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { cn } from '@/lib/utils';
import { CellView } from './cells';
import { FIELD_ICONS } from './field-icons';
import { FIELD_TYPE_LABELS } from './format';
import {
    FORMULA_FUNCTIONS,
    FORMULA_FUNCTIONS_BY_NAME,
    FORMULA_OPERATORS,
    signatureArguments,
} from './formula-functions';
import type { FormulaFunction } from './formula-functions';
import type {
    Field,
    FieldOptions,
    FormulaPreview,
    ResultFormat,
} from './types';
import { useTableStore } from './use-table';

type Suggestion =
    | { kind: 'field'; field: Field }
    | { kind: 'function'; definition: FormulaFunction };

/** What's being typed at the caret: a field name after "{", or a word that may be a function. */
interface Typing {
    kind: 'field' | 'word';
    /** Where the "{" or the word starts */
    start: number;
    query: string;
}

/** The function call the caret is in, and which of its arguments. */
interface Call {
    name: string;
    argument: number;
}

/**
 * Reads the formula up to the caret, skipping text in quotes and field names in braces.
 */
function readFormula(
    text: string,
    caret: number,
): { typing: Typing | null; call: Call | null } {
    const calls: Call[] = [];
    let quote: string | null = null;
    let brace: number | null = null;

    for (let index = 0; index < caret; index++) {
        const character = text[index];

        if (brace !== null) {
            if (character === '}') {
                brace = null;
            }

            continue;
        }

        if (quote !== null) {
            if (character === '\\') {
                index++;
            } else if (character === quote) {
                quote = null;
            }

            continue;
        }

        if (character === '"' || character === "'") {
            quote = character;
        } else if (character === '{') {
            brace = index;
        } else if (character === '(') {
            const name = /([A-Za-z_][A-Za-z0-9_]*)\s*$/.exec(
                text.slice(0, index),
            );

            calls.push({
                name: name ? name[1].toUpperCase() : '',
                argument: 0,
            });
        } else if (character === ')') {
            calls.pop();
        } else if (character === ',' && calls.length > 0) {
            calls[calls.length - 1].argument += 1;
        }
    }

    const call =
        [...calls].reverse().find((candidate) => candidate.name !== '') ?? null;

    if (quote !== null) {
        return { typing: null, call };
    }

    if (brace !== null) {
        return {
            typing: {
                kind: 'field',
                start: brace,
                query: text.slice(brace + 1, caret),
            },
            call,
        };
    }

    const word = /[A-Za-z_][A-Za-z0-9_]*$/.exec(text.slice(0, caret));

    if (word && !/[\d.]$/.test(text.slice(0, word.index))) {
        return {
            typing: { kind: 'word', start: word.index, query: word[0] },
            call,
        };
    }

    return { typing: null, call };
}

function rank(name: string, query: string): number {
    const lower = name.toLowerCase();
    const needle = query.toLowerCase();

    if (lower === needle) {
        return 0;
    }

    if (lower.startsWith(needle)) {
        return 1;
    }

    return lower.includes(needle) ? 2 : -1;
}

function suggestionsFor(typing: Typing | null, fields: Field[]): Suggestion[] {
    if (!typing) {
        return [];
    }

    const matchingFields = fields
        .map((field) => ({ field, rank: rank(field.name, typing.query) }))
        .filter((match) => match.rank >= 0)
        .sort((a, b) => a.rank - b.rank)
        .map(({ field }): Suggestion => ({ kind: 'field', field }));

    if (typing.kind === 'field') {
        return matchingFields.slice(0, 8);
    }

    const functions = FORMULA_FUNCTIONS.map((definition) => ({
        definition,
        rank: rank(definition.name, typing.query),
    }))
        .filter((match) => match.rank === 0 || match.rank === 1)
        .sort((a, b) => a.rank - b.rank)
        .map(({ definition }): Suggestion => ({
            kind: 'function',
            definition,
        }));

    // A finished function name needs no suggestion.
    if (
        functions.length === 1 &&
        functions[0].kind === 'function' &&
        functions[0].definition.name === typing.query
    ) {
        return [];
    }

    return [
        ...functions,
        ...matchingFields.filter((_, index) => index < 3),
    ].slice(0, 8);
}

/** A signature with the argument the caret is on in bold. */
function Signature({
    definition,
    argument,
}: {
    definition: FormulaFunction;
    argument: number | null;
}) {
    const args = signatureArguments(definition.signature);
    const repeats = args[args.length - 1] === '…';
    const current =
        argument === null
            ? -1
            : repeats && argument >= args.length - 1
              ? Math.max(0, args.length - 2)
              : argument;

    return (
        <code className="font-mono text-xs text-neutral-900 dark:text-neutral-100">
            {definition.name}(
            {args.map((arg, index) => (
                <Fragment key={index}>
                    {index > 0 && ', '}
                    <span
                        className={cn(
                            index === current &&
                                'font-semibold text-blue-700 underline decoration-blue-300 underline-offset-2 dark:text-blue-300',
                        )}
                    >
                        {arg}
                    </span>
                </Fragment>
            ))}
            )
        </code>
    );
}

/**
 * A formula's text, with field names and functions suggested while typing, help for the function the caret is in,
 * and a live preview of the result on the first records.
 */
export function FormulaInput({
    value,
    onChange,
    fields,
    format = 'auto',
    options = {},
    autoFocus = false,
}: {
    value: string;
    onChange: (value: string) => void;
    /** The fields the formula can use */
    fields: Field[];
    /** The result format, for the preview */
    format?: ResultFormat;
    /** The result's precision and symbol, for the preview */
    options?: Pick<FieldOptions, 'precision' | 'symbol'>;
    autoFocus?: boolean;
}) {
    const store = useTableStore();
    const textarea = useRef<HTMLTextAreaElement>(null);
    const [caret, setCaret] = useState(value.length);
    const [focused, setFocused] = useState(autoFocus);
    const [dismissed, setDismissed] = useState<string | null>(null);
    const [highlight, setHighlight] = useState(0);
    const [preview, setPreview] = useState<{
        formula: string;
        format: ResultFormat;
        result: FormulaPreview;
    } | null>(null);
    const latest = useRef(0);
    const { previewFormula } = store;

    const { typing, call } = readFormula(value, Math.min(caret, value.length));
    const suggestions =
        focused && dismissed !== value ? suggestionsFor(typing, fields) : [];
    const open = suggestions.length > 0;
    const active = open
        ? suggestions[Math.min(highlight, suggestions.length - 1)]
        : null;
    const help =
        active?.kind === 'function'
            ? { definition: active.definition, argument: null }
            : call && FORMULA_FUNCTIONS_BY_NAME.has(call.name)
              ? {
                    definition: FORMULA_FUNCTIONS_BY_NAME.get(call.name)!,
                    argument: call.argument,
                }
              : typing?.kind === 'word' &&
                  FORMULA_FUNCTIONS_BY_NAME.has(typing.query.toUpperCase())
                ? {
                      definition: FORMULA_FUNCTIONS_BY_NAME.get(
                          typing.query.toUpperCase(),
                      )!,
                      argument: null,
                  }
                : null;

    useEffect(() => {
        const formula = value.trim();

        if (formula === '') {
            return;
        }

        const request = ++latest.current;
        const timer = setTimeout(() => {
            void previewFormula(value, format).then((result) => {
                if (request === latest.current) {
                    setPreview({ formula: value, format, result });
                }
            });
        }, 400);

        return () => clearTimeout(timer);
    }, [value, format, previewFormula]);

    function moveCaret(position: number): void {
        requestAnimationFrame(() => {
            const element = textarea.current;

            if (element) {
                element.focus();
                element.setSelectionRange(position, position);
                setCaret(position);
            }
        });
    }

    function insert(suggestion: Suggestion): void {
        if (!typing) {
            return;
        }

        const end = Math.min(caret, value.length);
        let after = value.slice(end);
        let text: string;
        let position: number;

        if (suggestion.kind === 'field') {
            if (typing.kind === 'field') {
                // Replace the rest of a name being edited, up to its "}".
                const rest = /^[^{}\n]*\}/.exec(after);

                after = rest ? after.slice(rest[0].length) : after;
            }

            text = `{${suggestion.field.name}}`;
            position = typing.start + text.length;
        } else {
            const name = suggestion.definition.name;
            const takesNothing = suggestion.definition.signature.endsWith('()');

            if (after.startsWith('(')) {
                text = name;
                position = typing.start + name.length + 1;
            } else {
                text = takesNothing ? `${name}()` : `${name}(`;
                position = typing.start + text.length;
            }
        }

        onChange(value.slice(0, typing.start) + text + after);
        setHighlight(0);
        moveCaret(position);
    }

    function onKeyDown(event: KeyboardEvent<HTMLTextAreaElement>): void {
        if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
            event.preventDefault();
            event.currentTarget.form?.requestSubmit();

            return;
        }

        if (!open || !active) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setHighlight((current) => (current + 1) % suggestions.length);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setHighlight(
                (current) =>
                    (current - 1 + suggestions.length) % suggestions.length,
            );
        } else if (event.key === 'Enter' || event.key === 'Tab') {
            event.preventDefault();
            insert(active);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            setDismissed(value);
        }
    }

    const showPreview = value.trim() !== '' && preview !== null;
    const stale =
        preview !== null &&
        (preview.formula !== value || preview.format !== format);
    const previewField: Field = {
        key: '__formula_preview',
        name: 'Preview',
        type: 'formula',
        description: null,
        builtIn: false,
        primary: false,
        readOnly: true,
        options: { formula: value, format, ...options },
    };

    return (
        <div className="space-y-2">
            <div className="relative">
                <textarea
                    ref={textarea}
                    value={value}
                    autoFocus={autoFocus}
                    onChange={(event) => {
                        onChange(event.target.value);
                        setCaret(event.target.selectionStart);
                        setHighlight(0);
                    }}
                    onSelect={(event) =>
                        setCaret(event.currentTarget.selectionStart)
                    }
                    onFocus={() => setFocused(true)}
                    onBlur={() => setFocused(false)}
                    onKeyDown={onKeyDown}
                    rows={3}
                    spellCheck={false}
                    autoComplete="off"
                    aria-label="Formula"
                    aria-autocomplete="list"
                    aria-expanded={open}
                    aria-controls={open ? 'formula-suggestions' : undefined}
                    data-keeps-escape={open ? 'true' : undefined}
                    placeholder={'e.g. {Value} * {Probability}'}
                    className="block min-h-20 w-full resize-y rounded-md border border-neutral-200 bg-white px-2.5 py-2 font-mono text-[13px] leading-5 text-neutral-900 outline-none placeholder:text-neutral-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-100"
                />
                {open && (
                    <ul
                        id="formula-suggestions"
                        role="listbox"
                        aria-label="Suggestions"
                        className="absolute inset-x-0 top-full z-10 mt-1 max-h-60 overflow-y-auto rounded-md border border-neutral-200 bg-white p-1 shadow-lg dark:border-neutral-800 dark:bg-neutral-950"
                    >
                        {suggestions.map((suggestion, index) => {
                            const Icon =
                                suggestion.kind === 'field'
                                    ? FIELD_ICONS[suggestion.field.type]
                                    : SquareFunction;

                            return (
                                <li
                                    key={
                                        suggestion.kind === 'field'
                                            ? `f:${suggestion.field.key}`
                                            : `fn:${suggestion.definition.name}`
                                    }
                                    role="option"
                                    aria-selected={suggestion === active}
                                    onMouseDown={(event) => {
                                        event.preventDefault();
                                        insert(suggestion);
                                    }}
                                    onMouseEnter={() => setHighlight(index)}
                                    className={cn(
                                        'flex cursor-pointer items-center gap-2 rounded px-2 py-1 text-sm',
                                        suggestion === active
                                            ? 'bg-blue-50 text-blue-900 dark:bg-blue-950 dark:text-blue-100'
                                            : 'text-neutral-700 dark:text-neutral-300',
                                    )}
                                >
                                    <Icon className="size-3.5 shrink-0 text-neutral-500" />
                                    {suggestion.kind === 'field' ? (
                                        <span className="min-w-0 flex-1 truncate">
                                            {suggestion.field.name}
                                        </span>
                                    ) : (
                                        <span className="min-w-0 flex-1 truncate font-mono text-xs">
                                            {suggestion.definition.name}
                                        </span>
                                    )}
                                    <span className="shrink-0 text-xs text-neutral-400">
                                        {suggestion.kind === 'field'
                                            ? FIELD_TYPE_LABELS[
                                                  suggestion.field.type
                                              ]
                                            : suggestion.definition.category}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>

            {help ? (
                <div className="rounded-md bg-neutral-50 px-2.5 py-1.5 dark:bg-neutral-900">
                    <Signature
                        definition={help.definition}
                        argument={help.argument}
                    />
                    <p className="mt-0.5 text-xs text-neutral-600 dark:text-neutral-400">
                        {help.definition.description}
                    </p>
                </div>
            ) : (
                <p className="text-xs leading-5 text-neutral-500 dark:text-neutral-400">
                    {FORMULA_OPERATORS.map((operator, index) => (
                        <Fragment key={operator.symbol}>
                            {index > 0 && (
                                <span className="text-neutral-300 dark:text-neutral-700">
                                    {' '}
                                    ·{' '}
                                </span>
                            )}
                            <code className="font-mono text-neutral-700 dark:text-neutral-300">
                                {operator.symbol}
                            </code>{' '}
                            {operator.description}
                        </Fragment>
                    ))}
                </p>
            )}

            {showPreview && (
                <div
                    aria-live="polite"
                    aria-label="Preview"
                    className={cn(
                        'rounded-md border border-neutral-200 px-2.5 py-1.5 transition-opacity dark:border-neutral-800',
                        stale && 'opacity-60',
                    )}
                >
                    <div className="mb-1 flex items-center gap-1.5 text-[11px] font-medium tracking-wide text-neutral-400 uppercase">
                        Preview
                        {stale && (
                            <LoaderCircle
                                className="size-3 animate-spin"
                                aria-label="Checking"
                            />
                        )}
                    </div>
                    {!preview.result.ok ? (
                        <p className="flex items-start gap-1.5 text-xs text-red-600 dark:text-red-400">
                            <CircleAlert className="mt-0.5 size-3.5 shrink-0" />
                            <span>
                                {preview.result.error ??
                                    'This formula has an error.'}
                            </span>
                        </p>
                    ) : (preview.result.results ?? []).length === 0 ? (
                        <p className="text-xs text-neutral-500">
                            The formula looks right. Add a record to see its
                            result.
                        </p>
                    ) : (
                        <ul className="space-y-0.5">
                            {(preview.result.results ?? [])
                                .slice(0, 5)
                                .map((row) => (
                                    <li
                                        key={row.id}
                                        className="flex min-w-0 items-center gap-2 text-xs"
                                    >
                                        <span className="max-w-[45%] shrink-0 truncate text-neutral-500 dark:text-neutral-400">
                                            {row.title || 'Untitled'}
                                        </span>
                                        <span className="text-neutral-300 dark:text-neutral-600">
                                            →
                                        </span>
                                        {row.error ? (
                                            <span
                                                className="truncate text-red-600 dark:text-red-400"
                                                title={row.error}
                                            >
                                                {row.error}
                                            </span>
                                        ) : row.value === null ||
                                          row.value === '' ? (
                                            <span className="text-neutral-400 italic">
                                                empty
                                            </span>
                                        ) : (
                                            <span className="min-w-0 flex-1 text-sm text-neutral-900 dark:text-neutral-100">
                                                <CellView
                                                    field={previewField}
                                                    value={row.value}
                                                    context={store.context}
                                                />
                                            </span>
                                        )}
                                    </li>
                                ))}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}
