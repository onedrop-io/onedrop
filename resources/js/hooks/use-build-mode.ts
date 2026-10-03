import { router, usePage } from '@inertiajs/react';
import BuildModeController from '@/actions/App/Http/Controllers/Settings/BuildModeController';
import type { BuildMode } from '@/types';

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
        choose: (mode) =>
            router.put(
                BuildModeController.url(),
                { mode },
                { preserveScroll: true, preserveState: true },
            ),
    };
}
