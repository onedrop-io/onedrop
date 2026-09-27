import { useCallback, useState } from 'react';
import ProjectFileController from '@/actions/App/Http/Controllers/ProjectFileController';
import type { WorkspaceEntry, WorkspaceFile } from '@/types';

async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
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
