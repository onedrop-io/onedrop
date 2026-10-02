import type { Page, VisitOptions } from '@inertiajs/core';
import { router } from '@inertiajs/react';

/** The page component on screen, so a visit's response can be told apart from it. */
let currentComponent: string | null = null;

/**
 * Inertia only clears layout props (the header's breadcrumbs) on visits that don't preserve state, and form
 * visits (post, put, patch, delete) and reloads do. So a visit that lands on a different page, like the
 * new-project page after deleting the open project, would keep the old page's title in the header.
 * State is only worth keeping on the same page, so it never is across pages.
 */
export function preserveStateOnSamePageOnly(
    _href: string,
    options: VisitOptions,
): VisitOptions {
    const preserveState = options.preserveState;

    return {
        preserveState: (page: Page) => {
            if (page.component !== currentComponent) {
                return false;
            }

            if (typeof preserveState === 'function') {
                return preserveState(page);
            }

            if (preserveState === 'errors') {
                return Object.keys(page.props.errors ?? {}).length > 0;
            }

            return preserveState ?? false;
        },
    };
}

/** Keep track of the page on screen (set after a visit decides whether to preserve state). */
export function trackCurrentComponent(): void {
    router.on('navigate', (event) => {
        currentComponent = event.detail.page.component;
    });
    router.on('beforeUpdate', (event) => {
        currentComponent = event.detail.page.component;
    });
}
