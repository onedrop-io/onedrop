/**
 * The workspace's panes (LAYOUT-002): each has its own tabs, and every tab is in exactly one pane.
 * Pure functions over a `Layout`, so moving, closing and splitting follow one set of rules.
 */

export type ShellTab = 'shell' | `shell-${number}`;
export type PaneTab =
    | 'tools'
    | 'preview'
    | 'file'
    | 'console'
    | 'requirements'
    | 'tests'
    | 'browser'
    | ShellTab;

export type Pane = { id: number; tabs: PaneTab[]; active: PaneTab };

export type Layout = {
    panes: Pane[];
    /** The pane last used: where tabs opened from elsewhere go. */
    focused: number;
    direction: 'row' | 'column';
    /** Each pane's share of the space, in the same order as `panes`. */
    sizes: number[];
    nextPaneId: number;
};

/** Tabs that can't be closed and stay first in their pane, in this order. */
const PINNED: PaneTab[] = ['tools', 'preview'];

export const MAX_PANES = 4;

/** The smallest share a pane can be resized to. */
const MIN_SIZE = 0.15;

export function isShell(tab: PaneTab): tab is ShellTab {
    return tab === 'shell' || tab.startsWith('shell-');
}

export function isPinned(tab: PaneTab): boolean {
    return PINNED.includes(tab);
}

/** Tools and Preview first, the rest in their order. */
function ordered(tabs: PaneTab[]): PaneTab[] {
    return [
        ...PINNED.filter((tab) => tabs.includes(tab)),
        ...tabs.filter((tab) => !isPinned(tab)),
    ];
}

export function initialLayout(
    extra: PaneTab[],
    active: PaneTab = 'preview',
): Layout {
    return {
        panes: [{ id: 1, tabs: [...PINNED, ...extra], active }],
        focused: 1,
        direction: 'row',
        sizes: [1],
        nextPaneId: 2,
    };
}

export function paneOf(layout: Layout, tab: PaneTab): Pane | undefined {
    return layout.panes.find((pane) => pane.tabs.includes(tab));
}

export function hasTab(layout: Layout, tab: PaneTab): boolean {
    return paneOf(layout, tab) !== undefined;
}

/** Whether the tab is the one showing in its pane. */
export function isShown(layout: Layout, tab: PaneTab): boolean {
    return paneOf(layout, tab)?.active === tab;
}

/** The tab showing in the pane last used. */
export function focusedTab(layout: Layout): PaneTab {
    return (
        layout.panes.find((pane) => pane.id === layout.focused) ??
        layout.panes[0]
    ).active;
}

export function focusPane(layout: Layout, paneId: number): Layout {
    return layout.focused === paneId ? layout : { ...layout, focused: paneId };
}

function withPane(
    layout: Layout,
    paneId: number,
    change: (pane: Pane) => Pane,
): Layout {
    return {
        ...layout,
        panes: layout.panes.map((pane) =>
            pane.id === paneId ? change(pane) : pane,
        ),
    };
}

/** Drop panes left without tabs, sharing their space out among the rest. */
function withoutEmptyPanes(layout: Layout): Layout {
    const kept = layout.panes
        .map((pane, index) => ({ pane, size: layout.sizes[index] }))
        .filter(({ pane }) => pane.tabs.length > 0);

    if (kept.length === layout.panes.length) {
        return layout;
    }

    const total = kept.reduce((sum, { size }) => sum + size, 0);

    return {
        ...layout,
        panes: kept.map(({ pane }) => pane),
        sizes: kept.map(({ size }) => size / total),
        focused: kept.some(({ pane }) => pane.id === layout.focused)
            ? layout.focused
            : kept[0].pane.id,
    };
}

/** The tab to show once `tab` leaves `tabs`: the one before it, or else the one after. */
function neighbour(tabs: PaneTab[], tab: PaneTab): PaneTab | undefined {
    const index = tabs.indexOf(tab);

    return tabs[index - 1] ?? tabs[index + 1];
}

/** Show a tab where it is, or add it to `paneId` (the pane last used by default); that pane becomes the one last used. */
export function showTab(
    layout: Layout,
    tab: PaneTab,
    paneId: number = layout.focused,
): Layout {
    const existing = paneOf(layout, tab);

    if (existing) {
        return {
            ...withPane(layout, existing.id, (pane) => ({
                ...pane,
                active: tab,
            })),
            focused: existing.id,
        };
    }

    const target = layout.panes.some((pane) => pane.id === paneId)
        ? paneId
        : layout.panes[0].id;

    return {
        ...withPane(layout, target, (pane) => ({
            ...pane,
            tabs: ordered([...pane.tabs, tab]),
            active: tab,
        })),
        focused: target,
    };
}

/** Close a tab (not Tools or Preview); its pane closes if that was its last tab. */
export function closeTab(layout: Layout, tab: PaneTab): Layout {
    const pane = paneOf(layout, tab);

    if (!pane || isPinned(tab)) {
        return layout;
    }

    return withoutEmptyPanes(
        withPane(layout, pane.id, (current) => ({
            ...current,
            tabs: current.tabs.filter((other) => other !== tab),
            active:
                current.active === tab
                    ? (neighbour(current.tabs, tab) ?? current.active)
                    : current.active,
        })),
    );
}

/**
 * Move a tab next to `target` in pane `paneId` (after it when `after`), or to the end with no target.
 * Tools and Preview stay first, so a tab dropped on them in another pane lands right after them. The pane it left closes if that was its last tab.
 */
export function moveTab(
    layout: Layout,
    tab: PaneTab,
    paneId: number,
    target: PaneTab | null,
    after: boolean,
): Layout {
    const from = paneOf(layout, tab);

    if (
        !from ||
        target === tab ||
        !layout.panes.some((pane) => pane.id === paneId)
    ) {
        return layout;
    }

    // Within a pane, Tools and Preview stay put: dropping a tab on them does nothing.
    if (from.id === paneId && target && isPinned(target) !== isPinned(tab)) {
        return layout;
    }

    const removed = withPane(layout, from.id, (pane) => ({
        ...pane,
        tabs: pane.tabs.filter((other) => other !== tab),
        active:
            pane.active === tab && pane.id !== paneId
                ? (neighbour(pane.tabs, tab) ?? pane.active)
                : pane.active,
    }));

    const placed = withPane(removed, paneId, (pane) => {
        const tabs = [...pane.tabs];
        const index = target ? tabs.indexOf(target) : -1;
        tabs.splice(
            index === -1 ? tabs.length : index + (after ? 1 : 0),
            0,
            tab,
        );

        return { ...pane, tabs: ordered(tabs), active: tab };
    });

    return withoutEmptyPanes({ ...placed, focused: paneId });
}

/** Open a new pane after `paneId` holding `tab` (a tab not in any pane yet); every pane gets an equal share. */
export function splitPane(
    layout: Layout,
    paneId: number,
    direction: Layout['direction'],
    tab: PaneTab,
): Layout {
    if (layout.panes.length >= MAX_PANES || hasTab(layout, tab)) {
        return layout;
    }

    const index = layout.panes.findIndex((pane) => pane.id === paneId);
    const panes = [...layout.panes];
    panes.splice(index === -1 ? panes.length : index + 1, 0, {
        id: layout.nextPaneId,
        tabs: [tab],
        active: tab,
    });

    return {
        panes,
        focused: layout.nextPaneId,
        direction,
        sizes: panes.map(() => 1 / panes.length),
        nextPaneId: layout.nextPaneId + 1,
    };
}

/** Close a pane, moving its tabs to the end of the pane before it (or after it, for the first). */
export function closePane(layout: Layout, paneId: number): Layout {
    const index = layout.panes.findIndex((pane) => pane.id === paneId);

    if (index === -1 || layout.panes.length === 1) {
        return layout;
    }

    const closing = layout.panes[index];
    const into = layout.panes[index === 0 ? 1 : index - 1];

    return withoutEmptyPanes({
        ...withPane(
            withPane(layout, closing.id, (pane) => ({ ...pane, tabs: [] })),
            into.id,
            (pane) => ({
                ...pane,
                tabs: ordered([...pane.tabs, ...closing.tabs]),
            }),
        ),
        focused: into.id,
    });
}

/** Move the line after pane `index` by `delta` (a share of the whole space), keeping both panes usable. */
export function resizePanes(
    layout: Layout,
    index: number,
    delta: number,
): Layout {
    const sizes = [...layout.sizes];
    const pair = sizes[index] + sizes[index + 1];

    if (Number.isNaN(pair)) {
        return layout;
    }

    sizes[index] = Math.min(
        Math.max(sizes[index] + delta, MIN_SIZE),
        pair - MIN_SIZE,
    );
    sizes[index + 1] = pair - sizes[index];

    return { ...layout, sizes };
}

export function equalPanes(layout: Layout): Layout {
    return {
        ...layout,
        sizes: layout.panes.map(() => 1 / layout.panes.length),
    };
}

/** The next Shell's tab, the lowest number not open: `shell`, then `shell-2`, `shell-3`… */
export function nextShellTab(layout: Layout): ShellTab {
    for (let number = 1; ; number++) {
        const tab: ShellTab = number === 1 ? 'shell' : `shell-${number}`;

        if (!hasTab(layout, tab)) {
            return tab;
        }
    }
}

/** A Shell tab's name: Shell, Shell 2, Shell 3… */
export function shellLabel(tab: ShellTab): string {
    return tab === 'shell' ? 'Shell' : `Shell ${tab.slice('shell-'.length)}`;
}
