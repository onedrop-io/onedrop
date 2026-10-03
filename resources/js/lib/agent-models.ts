import type { ComponentType } from 'react';
import type { CatalogHarness, CatalogProvider } from '@/types';

/** What the model picker offers (AGT-002): `GET agent-models` on the web app and the desktop app's API. */
export type Catalog = {
    /** The agents the user can run, OpenCode first. */
    harnesses: CatalogHarness[];
    providers: CatalogProvider[];
    favorites: string[];
    /** Recently chosen models ("provider:model"), newest first. */
    recent: string[];
};

/** How the picker reaches the server: the web app's session routes, or the desktop app's API (DESK-003). */
export type AgentModelsTransport = {
    load: () => Promise<Catalog>;
    favorite: (change: {
        provider: string;
        model: string;
        favorite: boolean;
    }) => Promise<unknown>;
    /** What the organization has left of its AI credits, beside the picker (CREDIT-001); `inUse` while the agent runs on them. */
    CreditsBalance?: ComponentType<{ inUse: boolean }>;
};

let transport: AgentModelsTransport | null = null;
let cached: Promise<Catalog> | null = null;

/** Tell the picker how to reach the server. Each app does this once, on start. */
export function configureAgentModels(next: AgentModelsTransport): void {
    transport = next;
    cached = null;
}

/** The AI credits balance the app shows beside the picker, if any. */
export function creditsBalance(): ComponentType<{ inUse: boolean }> | null {
    return transport?.CreditsBalance ?? null;
}

/** The catalog, fetched once until it's forgotten. */
export function loadCatalog(): Promise<Catalog> {
    if (!transport) {
        return Promise.reject(new Error('Could not load models'));
    }

    cached ??= transport.load().catch((error: unknown) => {
        cached = null;
        throw error;
    });

    return cached;
}

/** Keep a catalog changed here (a model chosen or starred) for the next open. */
export function rememberCatalog(catalog: Catalog): void {
    cached = Promise.resolve(catalog);
}

/** Fetch the catalog again next time, e.g. after an AI was connected or removed. */
export function forgetCatalog(): void {
    cached = null;
}

export function saveFavorite(
    provider: string,
    model: string,
    favorite: boolean,
): void {
    void transport?.favorite({ provider, model, favorite }).catch(() => {});
}
