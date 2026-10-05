import { useEffect, useState } from 'react';
import type { OnedropDesktop } from '@/types/desktop';

/**
 * The desktop app's bridge (DESK-006), or null in a browser, with a counter that goes up whenever anything it reports
 * changes (a forward connects, Docker starts), for effects that read its state again.
 */
export function useOnedropDesktop(): {
    desktop: OnedropDesktop | null;
    changes: number;
} {
    const [desktop] = useState(() =>
        typeof window === 'undefined' ? null : (window.onedropDesktop ?? null),
    );
    const [changes, setChanges] = useState(0);

    useEffect(
        () => desktop?.onChange(() => setChanges((count) => count + 1)),
        [desktop],
    );

    return { desktop, changes };
}
