import { CircleAlert, CircleCheck, LoaderCircle } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { request, TableRequestError } from './api';
import { isEmpty } from './format';
import { ValueInput } from './inputs';
import type { CellValue, Field, FormPageData } from './types';
import { TableContext } from './use-table';
import type { TableStore } from './use-table';

type FormPageField = FormPageData['fields'][number];

/**
 * The inputs read the table store, and there's no <DataTable> on the form page: this is the part of the
 * store they use (fields, people, link titles, permissions, where uploads go), built from the page's data.
 * The rest (records, views, changes…) is never read outside <DataTable>, hence the cast.
 */
function formStore(data: FormPageData): TableStore {
    const usersById = new Map(data.users.map((user) => [user.id, user]));
    const titles = new Map(
        Object.entries(data.linked).map(([key, table]) => [
            key,
            new Map(table.records.map((record) => [record.id, record.title])),
        ]),
    );
    const store: Partial<TableStore> = {
        key: '',
        name: data.tableName,
        endpoint: '',
        uploadUrl: data.uploadUrl,
        fields: data.fields,
        fieldsByKey: new Map(data.fields.map((field) => [field.key, field])),
        users: data.users,
        context: { usersById, linked: data.linked, titles },
        can: {
            edit: true,
            create: true,
            delete: false,
            manageFields: false,
            manageViews: false,
            comment: false,
        },
        me: null,
        error: null,
        setError: () => {},
        expandedId: null,
        expand: () => {},
    };

    return store as TableStore;
}

/** A field's value before anything is entered. */
function emptyValue(field: Field): CellValue {
    switch (field.type) {
        case 'checkbox':
            return false;
        case 'multiSelect':
        case 'link':
        case 'attachment':
            return [];
        default:
            return null;
    }
}

function isBlank(field: Field, value: CellValue | undefined): boolean {
    return field.type === 'checkbox' ? value !== true : isEmpty(value);
}

/** Errors keyed by field key ("values.name" is taken as "name"). */
function fieldErrors(errors: Record<string, string[]>): Record<string, string> {
    return Object.fromEntries(
        Object.entries(errors).map(([key, messages]) => [
            key.replace(/^values\./, ''),
            messages[0] ?? '',
        ]),
    );
}

/**
 * A form view to fill in (TABLE-009): its title, description and fields, then the thank-you message once
 * sent. Give it the form page's data.
 */
export function FormFill({ data }: { data: FormPageData }) {
    const store = useMemo(() => formStore(data), [data]);
    const [values, setValues] = useState<Record<string, CellValue>>({});
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [problem, setProblem] = useState<string | null>(null);
    const [sending, setSending] = useState(false);
    const [sent, setSent] = useState(false);
    const latest = useRef<Record<string, CellValue>>({});
    const form = useRef<HTMLFormElement>(null);
    const honeypot = useRef<HTMLInputElement>(null);
    const known = new Set(data.fields.map((field) => field.key));
    const failing = data.fields.filter((field) => errors[field.key]);
    const otherErrors = Object.entries(errors).filter(
        ([key]) => !known.has(key),
    );

    const change = (key: string, value: CellValue) => {
        latest.current = { ...latest.current, [key]: value };
        setValues(latest.current);
        setErrors((current) => {
            if (!(key in current)) {
                return current;
            }

            const rest = { ...current };

            delete rest[key];

            return rest;
        });
    };

    const focusField = (key: string) => {
        form.current
            ?.querySelector(`[data-form-field="${CSS.escape(key)}"]`)
            ?.querySelector<HTMLElement>(
                'input, textarea, button, [tabindex="0"]',
            )
            ?.focus();
    };

    const submit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (sending) {
            return;
        }

        const current = latest.current;
        const missing: Record<string, string> = {};

        for (const field of data.fields) {
            if (field.required && isBlank(field, current[field.key])) {
                missing[field.key] = `${field.name} is required.`;
            }
        }

        const firstMissing = data.fields.find((field) => missing[field.key]);

        if (firstMissing) {
            setErrors(missing);
            setProblem(null);
            focusField(firstMissing.key);

            return;
        }

        setSending(true);
        setErrors({});
        setProblem(null);

        try {
            await request('POST', data.submitUrl, {
                // Only what was answered, so the table's defaults fill in the rest.
                values: Object.fromEntries(
                    data.fields
                        .filter(
                            (field) =>
                                !isEmpty(current[field.key]) &&
                                current[field.key] !== false,
                        )
                        .map((field) => [field.key, current[field.key]]),
                ),
                website: honeypot.current?.value ?? '',
            });
            setSent(true);
            window.scrollTo({ top: 0 });
        } catch (failure) {
            if (
                failure instanceof TableRequestError &&
                failure.status === 422 &&
                Object.keys(failure.errors).length > 0
            ) {
                const next = fieldErrors(failure.errors);
                const first = data.fields.find((field) => next[field.key]);

                setErrors(next);

                if (first) {
                    focusField(first.key);
                }
            } else if (
                failure instanceof TableRequestError &&
                failure.status === 429
            ) {
                setProblem(
                    "That's a lot of sends in a short time. Wait a minute and try again.",
                );
            } else {
                setProblem(
                    failure instanceof TableRequestError
                        ? failure.message
                        : 'Something went wrong. Try again.',
                );
            }
        } finally {
            setSending(false);
        }
    };

    const another = () => {
        latest.current = {};
        setValues({});
        setErrors({});
        setProblem(null);
        setSent(false);
    };

    const card =
        'rounded-xl border border-neutral-200 bg-white p-6 shadow-sm sm:p-8 dark:border-neutral-800 dark:bg-neutral-950';

    if (sent) {
        return (
            <div
                className={cn(card, 'flex flex-col items-center gap-4 py-12')}
                role="status"
            >
                <CircleCheck
                    aria-hidden
                    className="size-10 text-green-600 dark:text-green-500"
                />
                <p className="max-w-md text-center text-base whitespace-pre-line text-neutral-800 dark:text-neutral-200">
                    {data.thankYou || 'Thanks! Your answers were sent.'}
                </p>
                {data.allowAnother && (
                    <Button variant="outline" onClick={another}>
                        Send another
                    </Button>
                )}
            </div>
        );
    }

    return (
        <TableContext.Provider value={store}>
            <form
                ref={form}
                noValidate
                onSubmit={(event) => void submit(event)}
                aria-labelledby="form-title"
                className={cn(
                    card,
                    'relative flex flex-col gap-6 max-sm:[&_input]:text-base max-sm:[&_textarea]:text-base',
                )}
            >
                <header className="flex flex-col gap-1.5">
                    <h1
                        id="form-title"
                        className="text-2xl font-semibold text-neutral-900 dark:text-neutral-100"
                    >
                        {data.title || data.tableName}
                    </h1>
                    {data.description && (
                        <p className="text-sm whitespace-pre-line text-neutral-600 dark:text-neutral-400">
                            {data.description}
                        </p>
                    )}
                </header>
                {(failing.length > 0 || otherErrors.length > 0 || problem) && (
                    <div
                        role="alert"
                        className="flex gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-100"
                    >
                        <CircleAlert
                            aria-hidden
                            className="mt-0.5 size-4 shrink-0"
                        />
                        <div className="flex flex-col gap-1">
                            {problem ?? (
                                <>
                                    <p className="font-medium">
                                        {failing.length + otherErrors.length ===
                                        1
                                            ? 'One answer needs another look.'
                                            : `${failing.length + otherErrors.length} answers need another look.`}
                                    </p>
                                    <ul className="flex flex-col gap-0.5">
                                        {failing.map((field) => (
                                            <li key={field.key}>
                                                <button
                                                    type="button"
                                                    className="text-left underline-offset-2 hover:underline"
                                                    onClick={() =>
                                                        focusField(field.key)
                                                    }
                                                >
                                                    {field.name}:{' '}
                                                    {errors[field.key]}
                                                </button>
                                            </li>
                                        ))}
                                        {otherErrors.map(([key, message]) => (
                                            <li key={key}>{message}</li>
                                        ))}
                                    </ul>
                                </>
                            )}
                        </div>
                    </div>
                )}
                {data.fields.length === 0 && (
                    <p className="text-sm text-neutral-500">
                        This form has no questions yet.
                    </p>
                )}
                {data.fields.map((field) => (
                    <FormQuestion
                        key={field.key}
                        field={field}
                        value={values[field.key] ?? emptyValue(field)}
                        error={errors[field.key]}
                        onChange={(value) => change(field.key, value)}
                    />
                ))}
                {/* Left empty by people; bots that fill in every input get turned away. */}
                <div
                    aria-hidden="true"
                    className="pointer-events-none absolute -left-[9999px] size-px overflow-hidden opacity-0"
                >
                    <label>
                        Website
                        <input
                            ref={honeypot}
                            type="text"
                            name="website"
                            tabIndex={-1}
                            autoComplete="off"
                            defaultValue=""
                        />
                    </label>
                </div>
                <div>
                    <Button
                        type="submit"
                        disabled={sending}
                        className="w-full sm:w-auto"
                    >
                        {sending && <LoaderCircle className="animate-spin" />}
                        {data.submitLabel || 'Submit'}
                    </Button>
                </div>
            </form>
        </TableContext.Provider>
    );
}

function FormQuestion({
    field,
    value,
    error,
    onChange,
}: {
    field: FormPageField;
    value: CellValue;
    error: string | undefined;
    onChange: (value: CellValue) => void;
}) {
    const id = `form-field-${field.key}`;
    const described = [
        field.help ? `${id}-help` : null,
        error ? `${id}-error` : null,
    ]
        .filter(Boolean)
        .join(' ');

    return (
        <div
            data-form-field={field.key}
            className="flex min-w-0 flex-col gap-1.5"
        >
            <div className="flex flex-col gap-0.5">
                <span
                    id={id}
                    className="text-sm font-medium text-neutral-900 dark:text-neutral-100"
                >
                    {field.name}
                    {field.required && (
                        <>
                            <span
                                aria-hidden
                                className="ml-0.5 text-red-600 dark:text-red-400"
                            >
                                *
                            </span>
                            <span className="sr-only"> (required)</span>
                        </>
                    )}
                </span>
                {field.help && (
                    <p
                        id={`${id}-help`}
                        className="text-xs whitespace-pre-line text-neutral-500"
                    >
                        {field.help}
                    </p>
                )}
            </div>
            <div
                role="group"
                aria-labelledby={id}
                aria-describedby={described || undefined}
                className={cn(
                    'min-w-0',
                    error &&
                        '[&_[role=button]]:border-red-400 [&_input]:border-red-400 [&_textarea]:border-red-400',
                )}
            >
                <ValueInput
                    field={field}
                    value={value}
                    variant="form"
                    onChange={onChange}
                />
            </div>
            {error && (
                <p
                    id={`${id}-error`}
                    className="text-xs text-red-600 dark:text-red-400"
                >
                    {error}
                </p>
            )}
        </div>
    );
}
