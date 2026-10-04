import { router, usePage } from '@inertiajs/react';
import BuildModeController from '@/actions/App/Http/Controllers/Settings/BuildModeController';
import type { BuildMode } from '@/types';

/** localStorage key for whether the chat was last left showing (LAYOUT-001). */
export const CHAT_OPEN_KEY = 'onedrop.chat-open';

/** localStorage key for whether the files panel was last left open. */
export const FILES_OPEN_KEY = 'onedrop.files-open';

/**
 * The signed-in user's Simple or Advanced mode (PRJ-013). `chosen` is null until they pick one; until then
 * everything shows, as in Advanced, so `simple` is only true once they've chosen it.
 */
export function useBuildMode(): {
    chosen: BuildMode | null;
    simple: boolean;
    choose: (mode: BuildMode) => void;
} {
    const chosen = usePage().props.auth.user?.build_mode ?? null;

    return {
        chosen,
        simple: chosen === 'simple',
        choose: (mode) => {
            // Switching to Simple starts its layout over once: chat showing, files panel closed. What they open or
            // hide after that is remembered, as in Advanced.
            if (mode === 'simple' && chosen !== 'simple') {
                localStorage.setItem(CHAT_OPEN_KEY, 'true');
                localStorage.setItem(FILES_OPEN_KEY, 'false');
            }

            router.put(
                BuildModeController.url(),
                { mode },
                { preserveScroll: true, preserveState: true },
            );
        },
    };
}
