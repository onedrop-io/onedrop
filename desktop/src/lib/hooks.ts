import { useEffect, useRef, useState } from 'react';
import { blobUrl } from './api';

/**
 * A file the server only gives out with the token (a project icon, an attachment) as an address `<img>` can show.
 * Null while it loads, or when there's no file.
 */
export function useBlobUrl(url: string | null): string | null {
    const [loaded, setLoaded] = useState<{ url: string; local: string } | null>(
        null,
    );

    useEffect(() => {
        if (!url) {
            return;
        }

        let local: string | null = null;
        let cancelled = false;

        blobUrl(url)
            .then((address) => {
                local = address;

                if (cancelled) {
                    URL.revokeObjectURL(address);
                } else {
                    setLoaded({ url, local: address });
                }
            })
            .catch(() => {});

        return () => {
            cancelled = true;

            if (local?.startsWith('blob:')) {
                URL.revokeObjectURL(local);
            }
        };
    }, [url]);

    return url && loaded?.url === url ? loaded.local : null;
}

/** Run `callback` every `ms` while `active`, and when the window comes back into view. */
export function useInterval(
    callback: () => void,
    ms: number,
    active = true,
): void {
    const latest = useRef(callback);

    useEffect(() => {
        latest.current = callback;
    });

    useEffect(() => {
        if (!active) {
            return;
        }

        const timer = window.setInterval(() => latest.current(), ms);
        const onFocus = () => latest.current();

        window.addEventListener('focus', onFocus);

        return () => {
            window.clearInterval(timer);
            window.removeEventListener('focus', onFocus);
        };
    }, [ms, active]);
}

/**
 * Reload with `load`; calls arriving during a reload bring one more reload after it, never a pile of them
 * (like the web workspace's live reloads).
 */
export function useCoalesced(load: () => Promise<void>): () => void {
    const state = useRef<'idle' | 'loading' | 'again'>('idle');
    const latest = useRef(load);

    useEffect(() => {
        latest.current = load;
    });

    const run = useRef(() => {
        if (state.current !== 'idle') {
            state.current = 'again';

            return;
        }

        const go = () => {
            state.current = 'loading';
            latest
                .current()
                .catch(() => {})
                .finally(() => {
                    const again = state.current === 'again';
                    state.current = 'idle';

                    if (again) {
                        go();
                    }
                });
        };

        go();
    });

    return run.current;
}
