import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { AiCreditsView } from '@/components/ai-credits-view';
import type { AiCreditsSummary } from '@/components/ai-credits-view';
import { index as aiSettings } from '@/routes/agent-connections';
import { aiCredits } from '@/routes/organizations';
import { index as usage } from '@/routes/usage';

/**
 * Fetches the current organization's AI credits, again every minute and when the window regains focus, since runs
 * are charged shortly after they end.
 */
function useAiCredits(): AiCreditsSummary | null {
    const organization = usePage().props.currentOrganization;
    const [credits, setCredits] = useState<AiCreditsSummary | null>(null);

    useEffect(() => {
        if (!organization) {
            return;
        }

        let cancelled = false;
        let offered = false;
        const load = () =>
            fetch(aiCredits.url(organization.slug), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((body) => {
                    if (!cancelled && body) {
                        setCredits(body.credits);
                    }

                    // No credits on this install: nothing to keep checking.
                    if (body && body.credits === null && !offered) {
                        window.clearInterval(timer);
                        window.removeEventListener('focus', load);
                    }

                    offered ||= Boolean(body?.credits);
                })
                .catch(() => {});

        void load();
        const timer = window.setInterval(load, 60_000);
        window.addEventListener('focus', load);

        return () => {
            cancelled = true;
            window.clearInterval(timer);
            window.removeEventListener('focus', load);
        };
    }, [organization]);

    return credits;
}

/**
 * The balance beside the model picker wherever the install offers AI credits. While the agent runs on them it's
 * amber when low and red once used up; on the user's own AI it's dimmed. The details are on hover.
 */
export default function AiCreditsBalance({ inUse }: { inUse: boolean }) {
    const credits = useAiCredits();

    return (
        <AiCreditsView
            credits={credits}
            inUse={inUse}
            link={(page, props) => (
                <Link
                    href={page === 'settings' ? aiSettings() : usage()}
                    {...props}
                />
            )}
        />
    );
}
