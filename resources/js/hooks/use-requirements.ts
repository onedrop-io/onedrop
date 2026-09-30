import { useEffect, useState } from 'react';
import ProjectRequirementsController from '@/actions/App/Http/Controllers/ProjectRequirementsController';

/** A requirement's heading in .onedrop/REQ.md: `### REQ-001: Sign in with Google`. */
export const REQUIREMENT_HEADING = /^(REQ-\d+)\s*[:—–-]\s*(.*)$/;

/**
 * The requirements the agent keeps (REQ-001), from .onedrop/REQ.md: null content until it writes them.
 * Read while `enabled`, and again whenever `refreshSignal` changes.
 */
export function useRequirements(
    projectId: number,
    enabled: boolean,
    refreshSignal: string,
) {
    const [content, setContent] = useState<string | null>(null);
    const [loaded, setLoaded] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!enabled) {
            return;
        }

        let cancelled = false;

        void (async () => {
            const response = await fetch(
                ProjectRequirementsController.show.url(projectId),
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                },
            ).catch(() => null);
            const body = response
                ? await response.json().catch(() => ({}))
                : {};

            if (cancelled) {
                return;
            }

            if (!response?.ok) {
                setError(body.message ?? 'Could not read the requirements.');
            } else {
                setError(body.notice ?? null);
                setContent(body.content ?? null);
            }

            setLoaded(true);
        })();

        return () => {
            cancelled = true;
        };
    }, [projectId, enabled, refreshSignal]);

    return { content, loaded, error };
}

/** Each requirement's name by its ID, from the headings in REQ.md. */
export function requirementNames(
    content: string | null,
): Record<string, string> {
    const names: Record<string, string> = {};

    for (const line of (content ?? '').split('\n')) {
        const match = /^#{2,4}\s+(.*)$/.exec(line.trim());
        const heading = match && REQUIREMENT_HEADING.exec(match[1].trim());

        if (heading) {
            names[heading[1]] = heading[2].trim();
        }
    }

    return names;
}
