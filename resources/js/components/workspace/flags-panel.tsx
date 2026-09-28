import { EllipsisVertical } from "lucide-react";
import { useCallback, useEffect, useState } from "react";
import type { FormEvent } from "react";
import ProjectFlagController from "@/actions/App/Http/Controllers/ProjectFlagController";
import { Button } from "@/components/ui/button";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Switch } from "@/components/ui/switch";
import { askAgent } from "@/lib/ask-agent";
import { jsonRequest } from "@/lib/json-request";
import { cn } from "@/lib/utils";

type Flag = { key: string; description: string | null; enabled: boolean };

const QUEUED =
    "Asked the agent. It runs after the current task; follow along in the chat.";

/**
 * Feature flags: switch the app's flags (.zap/flags.json) on and off, and ask the agent to add or remove them.
 */
export default function FlagsPanel({
    projectId,
    running,
    working,
}: {
    projectId: number;
    running: boolean;
    /** The agent is running a task. */
    working: boolean;
}) {
    const [flags, setFlags] = useState<Flag[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<{
        text: string;
        error?: boolean;
    } | null>(null);

    const load = useCallback(
        () =>
            jsonRequest<{ flags: Flag[] }>(
                ProjectFlagController.index.url(projectId),
            ),
        [projectId],
    );

    useEffect(() => {
        if (!running) {
            return;
        }

        let cancelled = false;

        load()
            .then(({ flags }) => {
                if (!cancelled) {
                    setFlags(flags);
                    setError(null);
                }
            })
            .catch((e: Error) => !cancelled && setError(e.message));

        return () => {
            cancelled = true;
        };
        // Reload when the agent finishes, e.g. after adding a flag.
    }, [load, running, working]);

    if (!running) {
        return <Empty>Feature flags work when the sandbox is running.</Empty>;
    }

    if (error) {
        return <Empty tone="error">{error}</Empty>;
    }

    if (!flags) {
        return <Empty>Loading…</Empty>;
    }

    const toggle = (flag: Flag, enabled: boolean) => {
        setFlags((current) =>
            (current ?? []).map((f) =>
                f.key === flag.key ? { ...f, enabled } : f,
            ),
        );

        jsonRequest<{ flags: Flag[] }>(
            ProjectFlagController.update.url({
                project: projectId,
                flag: flag.key,
            }),
            { enabled },
            "PATCH",
        )
            .then(({ flags }) => {
                setFlags(flags);
                setNotice(null);
            })
            .catch((e: Error) => {
                setFlags((current) =>
                    (current ?? []).map((f) =>
                        f.key === flag.key ? { ...f, enabled: !enabled } : f,
                    ),
                );
                setNotice({ text: e.message, error: true });
            });
    };

    const remove = (flag: Flag) => {
        askAgent(
            ProjectFlagController.destroy.url({
                project: projectId,
                flag: flag.key,
            }),
            {},
            "DELETE",
        )
            .then(({ queued }) =>
                setNotice({
                    text: queued
                        ? QUEUED
                        : `The agent is removing "${flag.key}". Follow along in the chat.`,
                }),
            )
            .catch((e: Error) => setNotice({ text: e.message, error: true }));
    };

    return (
        <div className="max-w-3xl space-y-6" data-test="flags-panel">
            <AddFlag projectId={projectId} onSent={setNotice} />

            {notice && (
                <p
                    className={cn(
                        "text-sm",
                        notice.error ? "text-red-600" : "text-muted-foreground",
                    )}
                    data-test="flags-notice"
                >
                    {notice.text}
                </p>
            )}

            {flags.length === 0 ? (
                <Empty>
                    No flags yet. Describe a feature above and the agent puts it
                    behind a flag you can switch on and off here.
                </Empty>
            ) : (
                <ul
                    className="divide-y rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
                    data-test="flags-list"
                >
                    {flags.map((flag) => (
                        <li
                            key={flag.key}
                            className="flex items-center gap-3 p-3"
                            data-test={`flag-${flag.key}`}
                        >
                            <Switch
                                checked={flag.enabled}
                                onChange={(enabled) => toggle(flag, enabled)}
                                label={`Turn ${flag.key} ${flag.enabled ? "off" : "on"}`}
                                testId={`flag-switch-${flag.key}`}
                            />
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-medium">
                                    {flag.description ?? flag.key}
                                </p>
                                <p className="font-mono text-xs text-muted-foreground">
                                    {flag.key}
                                </p>
                            </div>
                            <span className="text-xs text-muted-foreground">
                                {flag.enabled ? "On" : "Off"}
                            </span>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        className="size-8"
                                        aria-label={`More options for ${flag.key}`}
                                        data-test={`flag-menu-${flag.key}`}
                                    >
                                        <EllipsisVertical />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        onSelect={() => remove(flag)}
                                        data-test={`flag-remove-${flag.key}`}
                                    >
                                        Remove with agent (keep it{" "}
                                        {flag.enabled ? "on" : "off"})
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </li>
                    ))}
                </ul>
            )}

            <p className="text-xs text-muted-foreground">
                Switching a flag applies right away, in the preview and the
                published app. Flags are saved in{" "}
                <code className="font-mono">.zap/flags.json</code>.
            </p>
        </div>
    );
}

/** Describe a feature and ask the agent to put it behind a new flag. */
function AddFlag({
    projectId,
    onSent,
}: {
    projectId: number;
    onSent: (notice: { text: string; error?: boolean }) => void;
}) {
    const [feature, setFeature] = useState("");
    const [sending, setSending] = useState(false);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setSending(true);

        askAgent(ProjectFlagController.store.url(projectId), { feature })
            .then(({ queued }) => {
                setFeature("");
                onSent({
                    text: queued
                        ? QUEUED
                        : "The agent is adding the flag. Follow along in the chat; it shows up here when it’s done.",
                });
            })
            .catch((e: Error) => onSent({ text: e.message, error: true }))
            .finally(() => setSending(false));
    };

    return (
        <form
            onSubmit={submit}
            className="space-y-2 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <label htmlFor="flag-feature" className="text-sm font-medium">
                Put a feature behind a flag
            </label>
            <textarea
                id="flag-feature"
                value={feature}
                onChange={(event) => setFeature(event.target.value)}
                rows={2}
                maxLength={2000}
                placeholder="e.g. The new pricing page, or dark mode"
                className="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                data-test="flag-feature"
            />
            <Button
                type="submit"
                size="sm"
                disabled={sending || feature.trim() === ""}
                data-test="flag-add"
            >
                Add with agent
            </Button>
        </form>
    );
}

function Empty({
    children,
    tone,
}: {
    children: React.ReactNode;
    tone?: "error";
}) {
    return (
        <div
            className={cn(
                "rounded-xl border border-dashed border-sidebar-border p-6 text-sm",
                tone === "error" ? "text-red-600" : "text-muted-foreground",
            )}
            data-test="flags-empty"
        >
            {children}
        </div>
    );
}
