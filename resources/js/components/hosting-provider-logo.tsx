import { cn } from '@/lib/utils';

/** Each provider's one-colour mark in /images/logos, drawn on its brand colours. */
const LOGOS: Record<string, { tile: string; mark: string }> = {
    fly: { tile: 'bg-[#24175B]', mark: 'bg-white' },
    cloudflare: { tile: 'bg-[#F38020]', mark: 'bg-white' },
    neon: { tile: 'bg-black', mark: 'bg-[#34D59A]' },
    upstash: { tile: 'bg-black', mark: 'bg-[#00E9A3]' },
};

/** A hosting provider's logo tile (HOST-003, ADMIN-007); nothing for a provider without one. */
export default function HostingProviderLogo({
    provider,
    className,
}: {
    provider: string;
    className?: string;
}) {
    const logo = LOGOS[provider];

    if (!logo) {
        return null;
    }

    const mask = `url(/images/logos/${provider}.svg) center / contain no-repeat`;

    return (
        <span
            className={cn(
                'flex size-9 shrink-0 items-center justify-center rounded-lg shadow-sm',
                logo.tile,
                className,
            )}
            aria-hidden
            data-test={`hosting-logo-${provider}`}
        >
            <span
                className={cn('size-1/2', logo.mark)}
                style={{ mask, WebkitMask: mask }}
            />
        </span>
    );
}
