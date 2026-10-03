import { Coins } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

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

/** Where the balance leads: Settings → AI once the credits ran out while in use, else the Usage page. */
export type AiCreditsPage = 'settings' | 'usage';

/**
 * The balance itself, for the web app (Inertia links) and the desktop app (which opens those pages in the browser):
 * `link` renders the element that goes to the page.
 */
export function AiCreditsView({
    credits,
    inUse,
    link,
}: {
    credits: AiCreditsSummary | null;
    inUse: boolean;
    link: (
        page: AiCreditsPage,
        props: {
            className: string;
            children: ReactNode;
            'data-test': string;
        },
    ) => ReactNode;
}) {
    if (credits === null) {
        return null;
    }

    const empty = credits.left < 0.01;
    const refill = formatRefill(credits.resets_at);

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                {link(inUse && empty ? 'settings' : 'usage', {
                    'data-test': 'ai-credits-balance',
                    className: cn(
                        'inline-flex shrink-0 items-center gap-1 rounded-lg px-2 py-1 text-xs tabular-nums hover:bg-muted',
                        !inUse
                            ? 'text-muted-foreground/70'
                            : empty
                              ? 'text-red-600 dark:text-red-400'
                              : credits.left < LOW
                                ? 'text-amber-600 dark:text-amber-400'
                                : 'text-muted-foreground',
                    ),
                    children: (
                        <>
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
                                    <span className="hidden sm:inline">
                                        {' '}
                                        left
                                    </span>
                                </span>
                            )}
                        </>
                    ),
                })}
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
