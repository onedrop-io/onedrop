import { useEffect, useState } from 'react';
import { AiCreditsView } from '@/components/ai-credits-view';
import type { AiCreditsSummary } from '@/components/ai-credits-view';
import type { Catalog, Client, Screenshot } from '@/lib/client';
import { api } from './api';
import { openInBrowser } from './native';
import type { Me } from './types';

/**
 * How the components the desktop app shares with the web app (the model picker, the new-project page's ways to
 * start) reach the server: the API, with the web's pages opening in the browser.
 */
export function desktopClient(me: Me): Client {
    return {
        loadCatalog: () => api<Catalog>('agent-models'),
        saveFavorite: (change) => api('agent-models/favorites', change, 'PUT'),
        loadScreenshots: (template) =>
            api<{ screenshots: Screenshot[] }>(
                `templates/screenshots?${new URLSearchParams({ template })}`,
            ).then(({ screenshots }) => screenshots),
        CreditsBalance: ({ inUse }) => <CreditsBalance me={me} inUse={inUse} />,
        TurnOnDocker: () => <TurnOnDocker me={me} />,
    };
}

/** The organization's AI credits (CREDIT-001), checked every minute and when the window comes back, as on the web. */
function CreditsBalance({ me, inUse }: { me: Me; inUse: boolean }) {
    const [credits, setCredits] = useState<AiCreditsSummary | null>(null);

    useEffect(() => {
        let cancelled = false;
        const load = () =>
            api<{ credits: AiCreditsSummary | null }>('ai-credits')
                .then(({ credits }) => !cancelled && setCredits(credits))
                .catch(() => {});

        void load();
        const timer = window.setInterval(load, 60_000);
        window.addEventListener('focus', load);

        return () => {
            cancelled = true;
            window.clearInterval(timer);
            window.removeEventListener('focus', load);
        };
    }, []);

    return (
        <AiCreditsView
            credits={credits}
            inUse={inUse}
            link={(page, props) => (
                <button
                    type="button"
                    onClick={() =>
                        void openInBrowser(
                            page === 'settings'
                                ? me.links.ai_settings
                                : me.links.usage,
                        )
                    }
                    {...props}
                />
            )}
        />
    );
}

/** Where Docker inside sandboxes is turned on (SBX-008): Settings → Sandboxes in the browser, for an admin. */
function TurnOnDocker({ me }: { me: Me }) {
    if (!me.user.is_admin) {
        return (
            <>
                An admin can turn on Docker inside sandboxes in Settings →
                Sandboxes.
            </>
        );
    }

    return (
        <>
            Turn on Docker inside sandboxes in{' '}
            <button
                type="button"
                onClick={() => void openInBrowser(me.links.sandboxes)}
                className="text-foreground underline underline-offset-4"
            >
                Settings → Sandboxes
            </button>
            .
        </>
    );
}
