import type { ComponentType } from 'react';
import type { CatalogHarness, CatalogProvider } from '@/types';

/** What the model picker offers (AGT-002). */
export type Catalog = {
    /** The agents the user can run, OpenCode first. */
    harnesses: CatalogHarness[];
    providers: CatalogProvider[];
    favorites: string[];
    /** Recently chosen models ("provider:model"), newest first. */
    recent: string[];
};

/** A free app's picture (PRJ-012). */
export type Screenshot = { url: string; source: string };

/**
 * How the components the web app and the desktop app share (the model picker, the new-project page's ways to
 * start) reach the server and the rest of their app: the web app's session routes and pages, or the desktop app's
 * API (DESK-004). Each app configures it once, on start.
 */
export type Client = {
    loadCatalog: () => Promise<Catalog>;
    saveFavorite: (change: {
        provider: string;
        model: string;
        favorite: boolean;
    }) => Promise<unknown>;
    loadScreenshots: (template: string) => Promise<Screenshot[]>;
    /** What the organization has left of its AI credits, beside the model picker (CREDIT-001). */
    CreditsBalance?: ComponentType<{ inUse: boolean }>;
    /** Where Docker inside sandboxes is turned on (SBX-008), for apps that need it. */
    TurnOnDocker?: ComponentType;
};

let client: Client | null = null;
let catalog: Promise<Catalog> | null = null;

export function configureClient(next: Client): void {
    client = next;
    catalog = null;
}

/** The model catalog, fetched once until it's forgotten. */
export function loadCatalog(): Promise<Catalog> {
    if (!client) {
        return Promise.reject(new Error('Could not load models'));
    }

    catalog ??= client.loadCatalog().catch((error: unknown) => {
        catalog = null;
        throw error;
    });

    return catalog;
}

/** Keep a catalog changed here (a model chosen or starred) for the next open. */
export function rememberCatalog(next: Catalog): void {
    catalog = Promise.resolve(next);
}

/** Fetch the catalog again next time, e.g. after an AI was connected or removed. */
export function forgetCatalog(): void {
    catalog = null;
}

export function saveFavorite(
    provider: string,
    model: string,
    favorite: boolean,
): void {
    void client?.saveFavorite({ provider, model, favorite }).catch(() => {});
}

export function loadScreenshots(template: string): Promise<Screenshot[]> {
    return client ? client.loadScreenshots(template) : Promise.resolve([]);
}

/** The app's AI credits balance, or nothing where it has none. */
export function CreditsBalance({ inUse }: { inUse: boolean }) {
    const Balance = client?.CreditsBalance;

    return Balance ? <Balance inUse={inUse} /> : null;
}

/** The app's pointer to turning on Docker inside sandboxes. */
export function TurnOnDocker() {
    const Hint = client?.TurnOnDocker;

    return Hint ? <Hint /> : null;
}
