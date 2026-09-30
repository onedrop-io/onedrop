import { useCallback, useEffect, useState } from "react";
import ProjectFileController from "@/actions/App/Http/Controllers/ProjectFileController";
import type { WorkspaceEntry, WorkspaceFile } from "@/types";

async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, {
        headers: { Accept: "application/json" },
        credentials: "same-origin",
    });
    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(body.message ?? `Request failed (${response.status})`);
    }

    return body as T;
}

/**
 * The file list of a project's sandbox, fetched on demand.
 */
export function useWorkspaceFiles(projectId: number) {
    const [entries, setEntries] = useState<WorkspaceEntry[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    const refresh = useCallback(async () => {
        setLoading(true);

        try {
            const body = await getJson<{ files: WorkspaceEntry[] }>(
                ProjectFileController.index.url(projectId),
            );
            setEntries(body.files);
            setError(null);
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setLoading(false);
        }
    }, [projectId]);

    return { entries, error, loading, refresh };
}

/** Without live updates, how often the Files panel asks whether files came or went; a database read, not a call into the sandbox. */
const VERSION_CHECK_MS = 3000;

/**
 * How many times the sandbox's file watcher has seen files added, removed or renamed (FILE-004); 0 until
 * one reports. Checked while `enabled`: when a live update says some sandbox of the project changed (LIVE-001),
 * or, without live updates, every few seconds while the page is visible.
 */
export function useFilesVersion(
    projectId: number,
    enabled: boolean,
    { live, changes }: { live: boolean; changes: number },
): number {
    const [version, setVersion] = useState(0);

    useEffect(() => {
        if (!enabled) {
            return;
        }

        let cancelled = false;

        const check = () => {
            if (document.visibilityState !== "visible") {
                return;
            }

            getJson<{ version: number }>(
                ProjectFileController.version.url(projectId),
            )
                .then((body) => !cancelled && setVersion(body.version))
                .catch(() => {
                    // Try again on the next check.
                });
        };

        check();
        const timer = live ? null : window.setInterval(check, VERSION_CHECK_MS);
        document.addEventListener("visibilitychange", check);

        return () => {
            cancelled = true;

            if (timer !== null) {
                window.clearInterval(timer);
            }

            document.removeEventListener("visibilitychange", check);
        };
    }, [projectId, enabled, live, changes]);

    return version;
}

/**
 * Load one file's contents.
 */
export function fetchWorkspaceFile(
    projectId: number,
    path: string,
): Promise<WorkspaceFile> {
    return getJson<WorkspaceFile>(
        ProjectFileController.show.url(projectId, { query: { path } }),
    );
}

/**
 * Save one file's contents into the sandbox.
 */
export async function saveWorkspaceFile(
    projectId: number,
    path: string,
    content: string,
): Promise<void> {
    await send(ProjectFileController.update.url(projectId), "PUT", {
        path,
        content,
    });
}

/**
 * Create an empty file or folder in the sandbox.
 */
export async function createWorkspaceEntry(
    projectId: number,
    path: string,
    type: WorkspaceEntry["type"],
): Promise<void> {
    await send(ProjectFileController.store.url(projectId), "POST", {
        path,
        type,
    });
}

/**
 * Upload one file from the user's computer to a path in the sandbox.
 */
export async function uploadWorkspaceFile(
    projectId: number,
    path: string,
    file: File,
): Promise<void> {
    const body = new FormData();
    body.append("path", path);
    body.append("file", file);

    await send(ProjectFileController.upload.url(projectId), "POST", body);
}

/**
 * Rename or move a file or folder in the sandbox.
 */
export async function moveWorkspaceEntry(
    projectId: number,
    from: string,
    to: string,
): Promise<void> {
    await send(ProjectFileController.move.url(projectId), "POST", {
        from,
        to,
    });
}

/**
 * Delete a file, or a folder and everything in it.
 */
export async function deleteWorkspaceEntry(
    projectId: number,
    path: string,
): Promise<void> {
    await send(ProjectFileController.destroy.url(projectId), "DELETE", {
        path,
    });
}

/**
 * Download through the browser: the workspace as a zip, or with a path, one file as it is or one folder as a zip.
 */
export async function downloadWorkspace(
    projectId: number,
    path?: string,
): Promise<void> {
    const response = await fetch(
        ProjectFileController.download.url(
            projectId,
            path ? { query: { path } } : undefined,
        ),
        { credentials: "same-origin" },
    );

    if (!response.ok) {
        const body = await response.json().catch(() => ({}));

        throw new Error(body.message ?? `Download failed (${response.status})`);
    }

    const disposition = response.headers.get("Content-Disposition") ?? "";
    const encoded = /filename\*=utf-8''([^;]+)/i.exec(disposition)?.[1];
    const name = encoded
        ? decodeURIComponent(encoded)
        : (/filename="?([^";]+)"?/.exec(disposition)?.[1] ?? "project.zip");
    const url = URL.createObjectURL(await response.blob());
    const link = document.createElement("a");
    link.href = url;
    link.download = name;
    link.click();
    URL.revokeObjectURL(url);
}

async function send(
    url: string,
    method: "POST" | "PUT" | "DELETE",
    body: Record<string, unknown> | FormData,
): Promise<void> {
    const token = document.cookie
        .split("; ")
        .find((cookie) => cookie.startsWith("XSRF-TOKEN="))
        ?.slice("XSRF-TOKEN=".length);
    const isForm = body instanceof FormData;
    const response = await fetch(url, {
        method,
        headers: {
            Accept: "application/json",
            ...(isForm ? {} : { "Content-Type": "application/json" }),
            "X-XSRF-TOKEN": decodeURIComponent(token ?? ""),
        },
        credentials: "same-origin",
        body: isForm ? body : JSON.stringify(body),
    });

    if (!response.ok) {
        const json = await response.json().catch(() => ({}));

        throw new Error(json.message ?? `Request failed (${response.status})`);
    }
}
