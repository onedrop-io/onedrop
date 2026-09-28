import { useCallback, useEffect, useState } from 'react';

export type WidthLimits = { initial: number; min: number; max: number };

/**
 * A pane width the user can drag, remembered in this browser. The saved
 * width is read after mount so server-rendered and client HTML match.
 */
export function useResizableWidth(
    storageKey: string,
    { initial, min, max }: WidthLimits,
): [number, (width: number) => void] {
    const [width, setWidth] = useState(initial);

    const clamp = useCallback(
        (value: number) => Math.round(Math.min(max, Math.max(min, value))),
        [min, max],
    );

    useEffect(() => {
        const saved = Number(localStorage.getItem(storageKey));

        if (saved > 0) {
            setWidth(clamp(saved));
        }
    }, [storageKey, clamp]);

    const resize = useCallback(
        (value: number) => {
            const next = clamp(value);
            localStorage.setItem(storageKey, String(next));
            setWidth(next);
        },
        [storageKey, clamp],
    );

    return [width, resize];
}
