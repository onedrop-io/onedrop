import { Deferred, Head, router, useForm, usePoll } from "@inertiajs/react";
import { Download, RotateCcw, Trash2 } from "lucide-react";
import { useEffect, useState } from "react";
import type { FormEvent } from "react";
import DatabaseBackupController from "@/actions/App/Http/Controllers/Admin/DatabaseBackupController";
import Heading from "@/components/heading";
import InputError from "@/components/input-error";
import { Button } from "@/components/ui/button";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Switch } from "@/components/ui/switch";

type Settings = {
    enabled: boolean;
    schedule: string;
    keep: number;
    destination: "local" | "s3";
    prefix: string;
    s3: {
        bucket: string | null;
        region: string | null;
        endpoint: string | null;
        key: string | null;
        path_style: boolean;
        secret_set: boolean;
    };
};

type Outcome = {
    at: string;
    ok: boolean;
    message: string | null;
    file: string | null;
} | null;

type Backup = { name: string; size: number; created_at: string | null };

function formatBytes(bytes: number): string {
    const units = ["B", "KB", "MB", "GB", "TB"];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}

const formatDate = (iso: string) => new Date(iso).toLocaleString();

/** Backups of the app's own database (ADMIN-005). */
export default function Backups({
    settings,
    database,
    last,
    lastRestore,
    busy,
    backups,
}: {
    settings: Settings;
    database: string;
    last: Outcome;
    lastRestore: Outcome;
    busy: "backup" | "restore" | null;
    backups?: { items: Backup[]; error: string | null };
}) {
    const [restoring, setRestoring] = useState<Backup | null>(null);
    const { start, stop } = usePoll(
        3000,
        { only: ["busy", "last", "lastRestore", "backups"] },
        { autoStart: false },
    );

    useEffect(() => {
        if (busy) {
            start();
        } else {
            stop();
        }
    }, [busy, start, stop]);

    return (
        <>
            <Head title="Backups" />

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        variant="small"
                        title="Backups"
                        description={`Copies of OneDrop's own ${database === "pgsql" ? "Postgres" : "SQLite"} database: users, projects, settings. Project files are backed up separately, as git history.`}
                    />
                    <Button
                        variant="outline"
                        disabled={busy !== null}
                        data-test="backup-now-button"
                        onClick={() =>
                            router.post(
                                DatabaseBackupController.store.url(),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        {busy === "backup" ? "Backing up…" : "Back up now"}
                    </Button>
                </div>

                <div className="space-y-1 text-sm" data-test="backup-status">
                    {busy === "restore" && (
                        <p className="text-amber-600 dark:text-amber-400">
                            Restoring a backup…
                        </p>
                    )}
                    {last ? (
                        <p
                            className={
                                last.ok
                                    ? "text-muted-foreground"
                                    : "text-destructive"
                            }
                        >
                            {last.ok
                                ? `Last backup ${formatDate(last.at)}: ${last.file}`
                                : `Last backup failed ${formatDate(last.at)}: ${last.message}`}
                        </p>
                    ) : (
                        <p className="text-muted-foreground">No backups yet.</p>
                    )}
                    {lastRestore && (
                        <p
                            className={
                                lastRestore.ok
                                    ? "text-muted-foreground"
                                    : "text-destructive"
                            }
                        >
                            {lastRestore.ok
                                ? `Restored ${lastRestore.file} ${formatDate(lastRestore.at)}`
                                : `Restoring ${lastRestore.file} failed ${formatDate(lastRestore.at)}: ${lastRestore.message}`}
                        </p>
                    )}
                </div>

                <BackupList
                    backups={backups}
                    onRestore={(backup) => setRestoring(backup)}
                />
            </div>

            <SettingsForm settings={settings} />

            <RestoreDialog
                backup={restoring}
                onClose={() => setRestoring(null)}
            />
        </>
    );
}

function BackupList({
    backups,
    onRestore,
}: {
    backups?: { items: Backup[]; error: string | null };
    onRestore: (backup: Backup) => void;
}) {
    return (
        <Deferred
            data="backups"
            fallback={
                <div className="space-y-2">
                    <Skeleton className="h-10 w-full animate-pulse" />
                    <Skeleton className="h-10 w-full animate-pulse" />
                </div>
            }
        >
            {backups?.error ? (
                <p className="text-sm text-destructive">
                    Couldn't list the backups: {backups.error}
                </p>
            ) : backups?.items.length ? (
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b text-muted-foreground">
                            <tr>
                                <th className="p-3 font-medium">Backup</th>
                                <th className="p-3 font-medium">Size</th>
                                <th className="p-3 font-medium">Date</th>
                                <th className="p-3" />
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {backups.items.map((backup) => (
                                <tr
                                    key={backup.name}
                                    data-test={`backup-${backup.name}`}
                                >
                                    <td className="p-3 font-mono text-xs">
                                        {backup.name}
                                    </td>
                                    <td className="p-3 tabular-nums">
                                        {formatBytes(backup.size)}
                                    </td>
                                    <td className="p-3">
                                        {backup.created_at
                                            ? formatDate(backup.created_at)
                                            : ""}
                                    </td>
                                    <td className="p-3">
                                        <div className="flex justify-end gap-1">
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                asChild
                                            >
                                                <a
                                                    href={DatabaseBackupController.download.url(
                                                        backup.name,
                                                    )}
                                                    aria-label={`Download ${backup.name}`}
                                                >
                                                    <Download className="size-4" />
                                                </a>
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Restore ${backup.name}`}
                                                data-test="restore-backup-button"
                                                onClick={() =>
                                                    onRestore(backup)
                                                }
                                            >
                                                <RotateCcw className="size-4" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Delete ${backup.name}`}
                                                onClick={() => {
                                                    if (
                                                        confirm(
                                                            `Delete ${backup.name}?`,
                                                        )
                                                    ) {
                                                        router.delete(
                                                            DatabaseBackupController.destroy.url(
                                                                backup.name,
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        );
                                                    }
                                                }}
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <p className="rounded-xl border border-dashed p-4 text-sm text-muted-foreground">
                    No backups at this destination yet.
                </p>
            )}
        </Deferred>
    );
}

function SettingsForm({ settings }: { settings: Settings }) {
    const form = useForm({
        enabled: settings.enabled,
        schedule: settings.schedule,
        keep: settings.keep,
        destination: settings.destination,
        prefix: settings.prefix,
        s3: {
            bucket: settings.s3.bucket ?? "",
            region: settings.s3.region ?? "",
            endpoint: settings.s3.endpoint ?? "",
            key: settings.s3.key ?? "",
            secret: "",
            path_style: settings.s3.path_style,
        },
    });
    const errors = form.errors as Record<string, string | undefined>;
    const setS3 = (key: keyof typeof form.data.s3, value: string | boolean) =>
        form.setData("s3", { ...form.data.s3, [key]: value });

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.put(DatabaseBackupController.update.url(), {
            preserveScroll: true,
            onSuccess: () => setS3("secret", ""),
        });
    };

    return (
        <form onSubmit={save} className="space-y-6" data-test="backup-settings">
            <Heading
                variant="small"
                title="Schedule and destination"
                description="Where backups go, and when they're made"
            />

            <div className="flex items-center justify-between gap-4 rounded-xl border p-4">
                <div>
                    <p className="font-medium">Scheduled backups</p>
                    <p className="text-sm text-muted-foreground">
                        Back up automatically on the schedule below.
                    </p>
                </div>
                <Switch
                    checked={form.data.enabled}
                    onChange={(checked) => form.setData("enabled", checked)}
                    label="Scheduled backups"
                    testId="backups-enabled"
                />
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
                <div className="grid content-start gap-2">
                    <Label htmlFor="schedule">Schedule</Label>
                    <Input
                        id="schedule"
                        value={form.data.schedule}
                        onChange={(event) =>
                            form.setData("schedule", event.target.value)
                        }
                        className="font-mono"
                    />
                    <p className="text-xs text-muted-foreground">
                        Cron, in UTC. 0 0 * * * is daily at midnight.
                    </p>
                    <InputError message={errors.schedule} />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor="keep">Keep latest</Label>
                    <Input
                        id="keep"
                        type="number"
                        min={1}
                        value={form.data.keep}
                        onChange={(event) =>
                            form.setData("keep", Number(event.target.value))
                        }
                    />
                    <InputError message={errors.keep} />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor="prefix">Folder</Label>
                    <Input
                        id="prefix"
                        value={form.data.prefix}
                        placeholder="/"
                        onChange={(event) =>
                            form.setData("prefix", event.target.value)
                        }
                    />
                    <InputError message={errors.prefix} />
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="destination">Destination</Label>
                <select
                    id="destination"
                    value={form.data.destination}
                    onChange={(event) =>
                        form.setData(
                            "destination",
                            event.target.value as "local" | "s3",
                        )
                    }
                    className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                    data-test="backup-destination"
                >
                    <option value="local">This server's disk</option>
                    <option value="s3">S3-compatible storage</option>
                </select>
                {form.data.destination === "local" && (
                    <p className="text-xs text-muted-foreground">
                        In storage/app/backups. A copy on the same server won't
                        survive losing the server; use S3 for that.
                    </p>
                )}
            </div>

            {form.data.destination === "s3" && (
                <div className="grid gap-4 sm:grid-cols-2">
                    {(
                        [
                            ["bucket", "Bucket", "text"],
                            ["region", "Region", "text"],
                            ["endpoint", "Endpoint", "url"],
                            ["key", "Access key ID", "text"],
                        ] as const
                    ).map(([key, label, type]) => (
                        <div key={key} className="grid content-start gap-2">
                            <Label htmlFor={`s3-${key}`}>{label}</Label>
                            <Input
                                id={`s3-${key}`}
                                type={type}
                                autoComplete="off"
                                value={form.data.s3[key]}
                                placeholder={
                                    key === "endpoint"
                                        ? "Empty for AWS"
                                        : key === "region"
                                          ? "us-east-1"
                                          : undefined
                                }
                                onChange={(event) =>
                                    setS3(key, event.target.value)
                                }
                            />
                            <InputError message={errors[`s3.${key}`]} />
                        </div>
                    ))}
                    <div className="grid content-start gap-2">
                        <Label htmlFor="s3-secret">Secret access key</Label>
                        <Input
                            id="s3-secret"
                            type="password"
                            autoComplete="off"
                            value={form.data.s3.secret}
                            placeholder={
                                settings.s3.secret_set
                                    ? "Saved (leave empty to keep)"
                                    : undefined
                            }
                            onChange={(event) =>
                                setS3("secret", event.target.value)
                            }
                        />
                        <InputError message={errors["s3.secret"]} />
                    </div>
                    <label className="flex items-center gap-2 self-end pb-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.s3.path_style}
                            onChange={(event) =>
                                setS3("path_style", event.target.checked)
                            }
                        />
                        Path-style addresses (MinIO and some others)
                    </label>
                </div>
            )}

            <Button
                type="submit"
                disabled={form.processing}
                data-test="save-backups-button"
            >
                Save
            </Button>
        </form>
    );
}

function RestoreDialog({
    backup,
    onClose,
}: {
    backup: Backup | null;
    onClose: () => void;
}) {
    const form = useForm({ confirm: "" });

    const restore = (event: FormEvent) => {
        event.preventDefault();

        if (!backup) {
            return;
        }

        form.post(DatabaseBackupController.restore.url(backup.name), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Dialog
            open={backup !== null}
            onOpenChange={(open) => {
                if (!open) {
                    form.reset();
                    form.clearErrors();
                    onClose();
                }
            }}
        >
            <DialogContent>
                <form onSubmit={restore} className="space-y-4">
                    <DialogTitle>Restore this backup?</DialogTitle>
                    <DialogDescription>
                        Everything in OneDrop's database (users, projects,
                        settings, chats) goes back to how it was in{" "}
                        <span className="font-mono">{backup?.name}</span>. The
                        current database is backed up first. Type the backup's
                        name to confirm.
                    </DialogDescription>
                    <Input
                        autoFocus
                        value={form.data.confirm}
                        onChange={(event) =>
                            form.setData("confirm", event.target.value)
                        }
                        placeholder={backup?.name}
                        className="font-mono"
                        data-test="restore-confirm-input"
                    />
                    <InputError message={form.errors.confirm} />
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={
                                form.processing ||
                                form.data.confirm !== backup?.name
                            }
                            data-test="restore-confirm-button"
                        >
                            Restore
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
