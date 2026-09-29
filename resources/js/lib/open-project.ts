import { router } from '@inertiajs/react';
import { show } from '@/routes/projects';

/** The server reads this cookie to share `openProject` (see HandleInertiaRequests). */
const OPEN_PROJECT_COOKIE = 'open_project';

/**
 * "Open" a project (TASK-001): the sidebar shows just it (Main, Board, New task and its
 * tasks) while the user is on its pages, until they go back.
 */
export function openProject(projectId: number): void {
    document.cookie = `${OPEN_PROJECT_COOKIE}=${projectId}; path=/; max-age=${60 * 60 * 24 * 365}; samesite=lax`;
    router.visit(show(projectId));
}

/** Back to the full sidebar, staying on the current page. */
export function closeProject(): void {
    document.cookie = `${OPEN_PROJECT_COOKIE}=; path=/; max-age=0; samesite=lax`;
    router.reload({ only: ['openProject'] });
}

/** Whether the path is one of the project's pages: its main chat, a task, or its board. */
export function isProjectPath(path: string, projectId: number): boolean {
    const base = show(projectId).url;

    return path === base || path.startsWith(`${base}/`);
}
