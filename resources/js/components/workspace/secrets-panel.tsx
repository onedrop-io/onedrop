import {
    Check,
    Copy,
    Eye,
    EyeOff,
    Info,
    KeyRound,
    Pencil,
    Plus,
    RefreshCw,
    Search,
    Trash2,
    X,
} from "lucide-react";
import { useCallback, useEffect, useRef, useState } from "react";
import type { FormEvent, ReactNode } from "react";
import ProjectSecretController from "@/actions/App/Http/Controllers/ProjectSecretController";
import { Button } from "@/components/ui/button";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { useClipboard } from "@/hooks/use-clipboard";
import { parseDotenv } from "@/lib/dotenv";
import type { EnvEntry } from "@/lib/dotenv";
import { jsonRequest } from "@/lib/json-request";
import { cn } from "@/lib/utils";

type Names = { secrets: string[] };

const NAME_PATTERN = /^[A-Za-z_][A-Za-z0-9_]*$/;

const secretsApi = {
    list: (projectId: number) =>
        jsonRequest<Names>(ProjectSecretController.index.url(projectId)),

    value: (projectId: number, name: string) =>
        jsonRequest<{ name: string; value: string }>(
            ProjectSecretController.show.url(projectId, { query: { name } }),
        ),

    /** Add secrets; names in `replace` already exist and the user agreed to overwrite them. */
    add: (projectId: number, secrets: EnvEntry[], replace: string[]) =>
        jsonRequest<Names>(ProjectSecretController.store.url(projectId), {
            secrets,
            replace,
        }),

    update: (projectId: number, name: string, value: string) =>
        jsonRequest<Names>(
            ProjectSecretController.update.url(projectId),
            { name, value },
            "PUT",
        ),

    delete: (projectId: number, name: string) =>
        jsonRequest<Names>(
            ProjectSecretController.destroy.url(projectId),
            { name },
            "DELETE",
        ),
};

/**
 * Secrets: the variables in the app's .env inside its sandbox. Only names are loaded;
 * a value is fetched when the user reveals or copies it.
 */
export default function SecretsPanel({
    projectId,
    running,
    working,
}: {
    projectId: number;
    running: boolean;
    /** The agent is running a task. */
    working: boolean;
}) {
    const [names, setNames] = useState<string[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [filter, setFilter] = useState("");
    const [revealed, setRevealed] = useState<Record<string, string>>({});
    const [editing, setEditing] = useState<{ name: string | null } | null>(
        null,
    );
    const [deleting, setDeleting] = useState<string | null>(null);

    const load = useCallback(() => {
        setLoading(true);
        secretsApi
            .list(projectId)
            .then(({ secrets }) => {
                setNames(secrets);
                setError(null);
            })
            .catch((e: Error) => setError(e.message))
            .finally(() => setLoading(false));
    }, [projectId]);

    // Reload when the sandbox starts and whenever the agent finishes a run (it may have added some).
    useEffect(() => {
        if (running && !working) {
            load();
        }
    }, [running, working, load]);

    const changed = (secrets: string[]) => {
        setNames(secrets);
        setRevealed({});
    };

    if (!running) {
        return <Empty>Secrets work when the sandbox is running.</Empty>;
    }

    if (error && !names) {
        return (
            <Empty tone="error">
                {error}
                <Button
                    size="sm"
                    variant="outline"
                    className="mx-auto mt-4 flex"
                    onClick={load}
                >
                    <RefreshCw /> Try again
                </Button>
            </Empty>
        );
    }

    if (!names) {
        return <Empty>Loading secrets…</Empty>;
    }

    const query = filter.trim().toLowerCase();
    const shown = names.filter((name) => name.toLowerCase().includes(query));

    return (
        <div className="max-w-4xl space-y-4" data-test="secrets-panel">
            <div className="flex gap-3 rounded-lg border border-blue-500/30 bg-blue-500/10 p-3 text-sm">
                <Info className="mt-0.5 size-4 shrink-0 text-blue-600 dark:text-blue-400" />
                <p>
                    Secrets are saved in your app’s <code>.env</code> file
                    inside its sandbox, and your app reads them as environment
                    variables. Anyone who can open this project can see them.
                    The agent uses them by name and won’t show their values.
                </p>
            </div>

            <div className="flex items-center gap-2">
                <label className="flex h-9 flex-1 items-center gap-2 rounded-md border border-input px-3">
                    <Search className="size-4 text-muted-foreground" />
                    <input
                        value={filter}
                        onChange={(event) => setFilter(event.target.value)}
                        placeholder="Filter secrets by name"
                        className="min-w-0 flex-1 bg-transparent text-sm outline-none"
                        aria-label="Filter secrets by name"
                        data-test="secrets-filter"
                    />
                </label>
                <Button
                    size="icon"
                    variant="ghost"
                    aria-label="Refresh secrets"
                    onClick={load}
                >
                    <RefreshCw className={cn(loading && "animate-spin")} />
                </Button>
                <Button
                    onClick={() => setEditing({ name: null })}
                    data-test="secrets-new"
                >
                    <Plus /> New secret
                </Button>
            </div>

            {error && (
                <p className="text-sm text-red-600" data-test="secrets-error">
                    {error}
                </p>
            )}

            {names.length === 0 ? (
                <div
                    className="rounded-xl border border-dashed border-sidebar-border p-8 text-center text-sm text-muted-foreground"
                    data-test="secrets-empty"
                >
                    <KeyRound className="mx-auto mb-2 size-5" />
                    <p className="font-medium text-foreground">
                        No secrets yet
                    </p>
                    <p className="mt-1">
                        Add API keys and passwords your app needs, like{" "}
                        <code>STRIPE_SECRET_KEY</code>, so they stay out of the
                        code.
                    </p>
                </div>
            ) : shown.length === 0 ? (
                <p className="p-4 text-center text-sm text-muted-foreground">
                    No secrets match that filter.
                </p>
            ) : (
                <ul className="space-y-2" data-test="secrets-list">
                    {shown.map((name) => (
                        <SecretRow
                            key={name}
                            projectId={projectId}
                            name={name}
                            value={revealed[name]}
                            onReveal={(value) =>
                                setRevealed((current) => {
                                    const next = { ...current };

                                    if (value === null) {
                                        delete next[name];
                                    } else {
                                        next[name] = value;
                                    }

                                    return next;
                                })
                            }
                            onEdit={() => setEditing({ name })}
                            onDelete={() => setDeleting(name)}
                            onError={setError}
                        />
                    ))}
                </ul>
            )}

            {editing?.name === null && (
                <NewSecretsDialog
                    projectId={projectId}
                    taken={names}
                    onClose={() => setEditing(null)}
                    onSaved={(secrets) => {
                        setEditing(null);
                        changed(secrets);
                    }}
                />
            )}
            {editing?.name && (
                <EditSecretDialog
                    projectId={projectId}
                    name={editing.name}
                    onClose={() => setEditing(null)}
                    onSaved={(secrets) => {
                        setEditing(null);
                        changed(secrets);
                    }}
                />
            )}
            <DeleteSecretDialog
                projectId={projectId}
                name={deleting}
                onClose={() => setDeleting(null)}
                onDeleted={(secrets) => {
                    setDeleting(null);
                    changed(secrets);
                }}
            />
        </div>
    );
}

function SecretRow({
    projectId,
    name,
    value,
    onReveal,
    onEdit,
    onDelete,
    onError,
}: {
    projectId: number;
    name: string;
    /** The value, when revealed. */
    value: string | undefined;
    onReveal: (value: string | null) => void;
    onEdit: () => void;
    onDelete: () => void;
    onError: (message: string | null) => void;
}) {
    const [copied, setCopied] = useState<"name" | "value" | null>(null);
    const [, copy] = useClipboard();

    const copyText = (what: "name" | "value", text: string) =>
        copy(text).then((ok) => {
            if (ok) {
                setCopied(what);
                window.setTimeout(() => setCopied(null), 1500);
            }
        });

    const fetchValue = () =>
        value !== undefined
            ? Promise.resolve(value)
            : secretsApi.value(projectId, name).then((secret) => {
                  onError(null);

                  return secret.value;
              });

    const toggle = () => {
        if (value !== undefined) {
            onReveal(null);

            return;
        }

        fetchValue()
            .then(onReveal)
            .catch((e: Error) => onError(e.message));
    };

    const copyValue = () =>
        fetchValue()
            .then((text) => copyText("value", text))
            .catch((e: Error) => onError(e.message));

    return (
        <li
            className="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)_auto] gap-2"
            data-test="secret"
        >
            <div className="flex min-w-0 items-center gap-2 rounded-md bg-muted/50 px-2 py-1.5">
                <CopyButton
                    label={`Copy name ${name}`}
                    copied={copied === "name"}
                    onClick={() => void copyText("name", name)}
                    testId="secret-copy-name"
                />
                <span
                    className="truncate font-mono text-sm"
                    data-test="secret-name"
                >
                    {name}
                </span>
            </div>
            <div className="flex min-w-0 items-center gap-2 rounded-md bg-muted/50 px-2 py-1.5">
                <CopyButton
                    label={`Copy value of ${name}`}
                    copied={copied === "value"}
                    onClick={() => void copyValue()}
                    testId="secret-copy-value"
                />
                <span
                    className={cn(
                        "min-w-0 flex-1 font-mono text-sm",
                        value === undefined
                            ? "tracking-widest text-muted-foreground"
                            : "break-all whitespace-pre-wrap",
                    )}
                    data-test="secret-value"
                >
                    {value === undefined ? "••••••••" : value || "(empty)"}
                </span>
                <Button
                    size="icon"
                    variant="ghost"
                    className="size-7 shrink-0"
                    aria-label={
                        value === undefined ? `Show ${name}` : `Hide ${name}`
                    }
                    aria-pressed={value !== undefined}
                    onClick={toggle}
                    data-test="secret-reveal"
                >
                    {value === undefined ? <Eye /> : <EyeOff />}
                </Button>
            </div>
            <div className="flex items-center">
                <Button
                    size="icon"
                    variant="ghost"
                    className="size-8"
                    aria-label={`Edit ${name}`}
                    onClick={onEdit}
                    data-test="secret-edit"
                >
                    <Pencil />
                </Button>
                <Button
                    size="icon"
                    variant="ghost"
                    className="size-8 text-muted-foreground hover:text-red-600"
                    aria-label={`Delete ${name}`}
                    onClick={onDelete}
                    data-test="secret-delete"
                >
                    <Trash2 />
                </Button>
            </div>
        </li>
    );
}

function CopyButton({
    label,
    copied,
    onClick,
    testId,
}: {
    label: string;
    copied: boolean;
    onClick: () => void;
    testId: string;
}) {
    return (
        <Button
            size="icon"
            variant="ghost"
            className="size-7 shrink-0"
            aria-label={label}
            onClick={onClick}
            data-test={testId}
        >
            {copied ? <Check className="text-green-600" /> : <Copy />}
        </Button>
    );
}

type Row = EnvEntry & { id: number };

let nextRowId = 1;

const blankRow = (): Row => ({ id: nextRowId++, name: "", value: "" });

const textareaClass =
    "w-full resize-y rounded-md border border-input bg-transparent px-3 py-1.5 font-mono text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50";

/**
 * Add secrets, one row each. Pasting NAME=value lines (like an .env file) into a name
 * fills in a row per line, as on Vercel.
 */
function NewSecretsDialog({
    projectId,
    taken,
    onClose,
    onSaved,
}: {
    projectId: number;
    taken: string[];
    onClose: () => void;
    onSaved: (secrets: string[]) => void;
}) {
    const [rows, setRows] = useState<Row[]>(() => [blankRow()]);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const focusRow = useRef<number | null>(null);

    // Focus the name of a row just added with "Add another".
    useEffect(() => {
        if (focusRow.current !== null) {
            document
                .querySelector<HTMLInputElement>(
                    `[data-row="${focusRow.current}"] [data-test="secret-name-input"]`,
                )
                ?.focus();
            focusRow.current = null;
        }
    }, [rows]);

    const update = (id: number, change: Partial<EnvEntry>) =>
        setRows((current) =>
            current.map((row) => (row.id === id ? { ...row, ...change } : row)),
        );

    const paste = (id: number, text: string): boolean => {
        const pasted = parseDotenv(text);

        if (pasted.length === 0) {
            return false;
        }

        setRows((current) => {
            const index = current.findIndex((row) => row.id === id);
            const target = current[index];
            const keep = target.name.trim() !== "" || target.value !== "";
            const rest = current.filter((row) => row.id !== id);
            // A pasted name that's already a row updates that row instead of repeating it.
            const updated = rest.map((row) => {
                const match = pasted.find(
                    (entry) => entry.name === row.name.trim(),
                );

                return match ? { ...row, value: match.value } : row;
            });
            const added = pasted
                .filter(
                    (entry) =>
                        !rest.some((row) => row.name.trim() === entry.name),
                )
                .map((entry) => ({ id: nextRowId++, ...entry }));
            const position = rest.slice(0, index).length;

            return [
                ...updated.slice(0, position),
                ...(keep ? [target] : []),
                ...added,
                ...updated.slice(position),
            ];
        });

        return true;
    };

    const filled = rows.filter(
        (row) => row.name.trim() !== "" || row.value !== "",
    );
    const problems = new Map<number, string>();
    const replacing = new Set<string>();

    for (const row of filled) {
        const name = row.name.trim();

        if (name === "") {
            problems.set(row.id, "Needs a name.");
        } else if (!NAME_PATTERN.test(name) || name.length > 100) {
            problems.set(
                row.id,
                "Use letters, digits and underscores, starting with a letter or underscore.",
            );
        } else if (
            filled.filter((other) => other.name.trim() === name).length > 1
        ) {
            problems.set(row.id, `${name} is listed twice.`);
        } else if (taken.includes(name)) {
            replacing.add(name);
        }
    }

    const save = (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        secretsApi
            .add(
                projectId,
                filled.map((row) => ({
                    name: row.name.trim(),
                    value: row.value,
                })),
                [...replacing],
            )
            .then(({ secrets }) => onSaved(secrets))
            .catch((e: Error) => setError(e.message))
            .finally(() => setSaving(false));
    };

    const count = filled.length;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-2xl" data-test="secret-dialog">
                <DialogHeader>
                    <DialogTitle>New secret</DialogTitle>
                    <DialogDescription>
                        Paste lines like <code>NAME=value</code> (for example
                        from an <code>.env</code> file) into a name to add
                        several at once. Your app restarts to use them.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={save} className="space-y-3">
                    <div className="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)_2rem] gap-x-2 text-sm font-medium">
                        <span>Name</span>
                        <span>Value</span>
                    </div>
                    <ul
                        className="-mx-1 -mt-1 max-h-[50vh] space-y-2 overflow-x-hidden overflow-y-auto px-1 py-1"
                        data-test="secret-rows"
                    >
                        {rows.map((row, index) => {
                            const problem = problems.get(row.id);
                            const replaces = replacing.has(row.name.trim());

                            return (
                                <li
                                    key={row.id}
                                    data-row={row.id}
                                    data-test="secret-row"
                                >
                                    <div className="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)_2rem] items-start gap-2">
                                        <Input
                                            value={row.name}
                                            onChange={(event) =>
                                                update(row.id, {
                                                    name: event.target.value,
                                                })
                                            }
                                            onPaste={(event) => {
                                                if (
                                                    paste(
                                                        row.id,
                                                        event.clipboardData.getData(
                                                            "text",
                                                        ),
                                                    )
                                                ) {
                                                    event.preventDefault();
                                                }
                                            }}
                                            placeholder="STRIPE_SECRET_KEY"
                                            className="font-mono"
                                            autoComplete="off"
                                            spellCheck={false}
                                            aria-label="Name"
                                            aria-invalid={problem !== undefined}
                                            autoFocus={index === 0}
                                            data-test="secret-name-input"
                                        />
                                        <textarea
                                            value={row.value}
                                            onChange={(event) =>
                                                update(row.id, {
                                                    value: event.target.value,
                                                })
                                            }
                                            rows={
                                                row.value.includes("\n") ? 3 : 1
                                            }
                                            autoComplete="off"
                                            spellCheck={false}
                                            aria-label={`Value of ${row.name.trim() || "new secret"}`}
                                            className={cn(
                                                textareaClass,
                                                "min-h-9",
                                            )}
                                            data-test="secret-value-input"
                                        />
                                        <Button
                                            type="button"
                                            size="icon"
                                            variant="ghost"
                                            className="size-9 text-muted-foreground"
                                            aria-label="Remove this row"
                                            disabled={rows.length === 1}
                                            onClick={() =>
                                                setRows((current) =>
                                                    current.filter(
                                                        (other) =>
                                                            other.id !== row.id,
                                                    ),
                                                )
                                            }
                                            data-test="secret-row-remove"
                                        >
                                            <X />
                                        </Button>
                                    </div>
                                    {problem ? (
                                        <p
                                            className="mt-1 text-xs text-red-600"
                                            data-test="secret-row-problem"
                                        >
                                            {problem}
                                        </p>
                                    ) : (
                                        replaces && (
                                            <p
                                                className="mt-1 text-xs text-amber-600 dark:text-amber-500"
                                                data-test="secret-row-replaces"
                                            >
                                                {row.name.trim()} already
                                                exists. Saving replaces its
                                                value.
                                            </p>
                                        )
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => {
                            const row = blankRow();
                            focusRow.current = row.id;
                            setRows((current) => [...current, row]);
                        }}
                        data-test="secret-add-row"
                    >
                        <Plus /> Add another
                    </Button>
                    {error && (
                        <p
                            className="text-sm text-red-600"
                            data-test="secret-dialog-error"
                        >
                            {error}
                        </p>
                    )}
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                saving || count === 0 || problems.size > 0
                            }
                            data-test="secret-save"
                        >
                            {count > 1 ? `Save ${count} secrets` : "Add secret"}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Change an existing secret's value, starting from the current one. */
function EditSecretDialog({
    projectId,
    name,
    onClose,
    onSaved,
}: {
    projectId: number;
    name: string;
    onClose: () => void;
    onSaved: (secrets: string[]) => void;
}) {
    const [value, setValue] = useState("");
    const [loaded, setLoaded] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const valueInput = useRef<HTMLTextAreaElement>(null);

    useEffect(() => {
        secretsApi
            .value(projectId, name)
            .then((secret) => setValue(secret.value))
            .catch((e: Error) => setError(e.message))
            .finally(() => setLoaded(true));
    }, [projectId, name]);

    // The value field is disabled while it loads, so focus it once it's ready.
    useEffect(() => {
        if (loaded) {
            valueInput.current?.focus();
        }
    }, [loaded]);

    const save = (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        secretsApi
            .update(projectId, name, value)
            .then(({ secrets }) => onSaved(secrets))
            .catch((e: Error) => setError(e.message))
            .finally(() => setSaving(false));
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-lg" data-test="secret-dialog">
                <DialogHeader>
                    <DialogTitle>Edit {name}</DialogTitle>
                    <DialogDescription>
                        Your app restarts to use the change.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={save} className="space-y-3">
                    <div className="space-y-1">
                        <Label htmlFor="secret-value">Value</Label>
                        <textarea
                            id="secret-value"
                            value={value}
                            onChange={(event) => setValue(event.target.value)}
                            rows={4}
                            disabled={!loaded}
                            autoComplete="off"
                            spellCheck={false}
                            ref={valueInput}
                            className={textareaClass}
                            data-test="secret-value-input"
                        />
                    </div>
                    {error && (
                        <p
                            className="text-sm text-red-600"
                            data-test="secret-dialog-error"
                        >
                            {error}
                        </p>
                    )}
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={saving || !loaded}
                            data-test="secret-save"
                        >
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function DeleteSecretDialog({
    projectId,
    name,
    onClose,
    onDeleted,
}: {
    projectId: number;
    name: string | null;
    onClose: () => void;
    onDeleted: (secrets: string[]) => void;
}) {
    const [deleting, setDeleting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => setError(null), [name]);

    const remove = () => {
        if (!name) {
            return;
        }

        setDeleting(true);
        secretsApi
            .delete(projectId, name)
            .then(({ secrets }) => onDeleted(secrets))
            .catch((e: Error) => setError(e.message))
            .finally(() => setDeleting(false));
    };

    return (
        <Dialog
            open={name !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent
                className="sm:max-w-md"
                data-test="secret-delete-dialog"
            >
                <DialogHeader>
                    <DialogTitle>Delete {name}?</DialogTitle>
                    <DialogDescription>
                        It’s removed from your app’s <code>.env</code> and the
                        app restarts. Anything in the app that uses it stops
                        working until it’s added again.
                    </DialogDescription>
                </DialogHeader>
                {error && <p className="text-sm text-red-600">{error}</p>}
                <DialogFooter>
                    <Button variant="ghost" onClick={onClose} autoFocus>
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={remove}
                        disabled={deleting}
                        data-test="secret-delete-confirm"
                    >
                        <Trash2 /> Delete secret
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function Empty({ children, tone }: { children: ReactNode; tone?: "error" }) {
    return (
        <div
            className={cn(
                "max-w-xl rounded-lg border border-dashed p-6 text-center text-sm",
                tone === "error" ? "text-red-600" : "text-muted-foreground",
            )}
            data-test="secrets-empty-state"
        >
            {children}
        </div>
    );
}
