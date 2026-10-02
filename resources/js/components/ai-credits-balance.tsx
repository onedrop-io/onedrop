import { Link, usePage } from '@inertiajs/react';
import { Coins } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { index as aiSettings } from '@/routes/agent-connections';
import { aiCredits } from '@/routes/organizations';
import { index as usage } from '@/routes/usage';

/** What an organization has left of its AI credits (CREDIT-001), in dollars. */
export type AiCreditsSummary = {
    left: number;
    monthly: { left: number; granted: number };
    welcome: { left: number; granted: number };
    /** When the next monthly credits arrive (ISO 8601). */
    resets_at: string | null;
};

/** Below this many dollars the balance turns amber. */
export const LOW = 0.5;

export function formatCredits(dollars: number): string {
    return `$${dollars.toFixed(2)}`;
}

export function formatRefill(iso: string | null): string | null {
    return iso
        ? new Date(iso).toLocaleDateString(undefined, {
              month: 'long',
              day: 'numeric',
          })
        : null;
}

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

    if (credits === null) {
        return null;
    }

    const empty = credits.left < 0.01;
    const refill = formatRefill(credits.resets_at);

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Link
                    href={inUse && empty ? aiSettings() : usage()}
                    data-test="ai-credits-balance"
                    className={cn(
                        'inline-flex shrink-0 items-center gap-1 rounded-lg px-2 py-1 text-xs tabular-nums hover:bg-muted',
                        !inUse
                            ? 'text-muted-foreground/70'
                            : empty
                              ? 'text-red-600 dark:text-red-400'
                              : credits.left < LOW
                                ? 'text-amber-600 dark:text-amber-400'
                                : 'text-muted-foreground',
                    )}
                >
                    <Coins className="size-3.5 shrink-0" />
                    {empty ? (
                        <span>
                            Out of credits
                            {refill && (
                                <span className="hidden sm:inline">
                                    {' '}
                                    · more on {refill}
                                </span>
                            )}
                        </span>
                    ) : (
                        <span>
                            {formatCredits(credits.left)}
                            <span className="hidden sm:inline"> left</span>
                        </span>
                    )}
                </Link>
            </TooltipTrigger>
            <TooltipContent className="max-w-64 space-y-1 text-left">
                <p className="font-medium">
                    AI credits: {formatCredits(credits.left)} left
                </p>
                {credits.monthly.granted > 0 && (
                    <p>
                        {formatCredits(credits.monthly.left)} of this month's{' '}
                        {formatCredits(credits.monthly.granted)}
                        {refill && `, more on ${refill}`}
                    </p>
                )}
                {credits.welcome.granted > 0 && (
                    <p>
                        {formatCredits(credits.welcome.left)} of the welcome{' '}
                        {formatCredits(credits.welcome.granted)}, which never
                        expires
                    </p>
                )}
                <p className="opacity-80">
                    {!inUse
                        ? "This project uses your own AI, which doesn't use credits. Pick AI credits in the model picker to build on them."
                        : empty
                          ? 'Connect your own AI in Settings → AI to keep building.'
                          : 'Building with your own AI never uses credits.'}
                </p>
            </TooltipContent>
        </Tooltip>
    );
}
