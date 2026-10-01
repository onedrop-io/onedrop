import { usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { cn } from '@/lib/utils';
import type { OrganizationSummary } from '@/types';

/**
 * An organization's logo (ORG-005). Without one: on a self-hosted install the install's own logo or the droplet
 * (ADMIN-001), so it looks as it always did; on the hosted install its initial, so organizations look different.
 */
export function OrganizationMark({
    organization,
    className,
}: {
    organization: Pick<OrganizationSummary, 'name' | 'logo_url'>;
    className?: string;
}) {
    const { logo, multiTenant } = usePage().props;

    if (organization.logo_url) {
        return (
            <span
                className={cn(
                    'flex size-8 shrink-0 overflow-hidden rounded-md',
                    className,
                )}
            >
                <img
                    src={organization.logo_url}
                    alt=""
                    className="size-full object-contain"
                />
            </span>
        );
    }

    if (!multiTenant) {
        return logo ? (
            <AppLogoIcon
                className={cn('size-8 shrink-0 rounded-md', className)}
            />
        ) : (
            <span
                className={cn(
                    'flex size-8 shrink-0 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground',
                    className,
                )}
            >
                <AppLogoIcon className="size-5 fill-current text-white dark:text-black" />
            </span>
        );
    }

    return (
        <span
            className={cn(
                'flex size-8 shrink-0 items-center justify-center rounded-md border bg-sidebar-accent text-sm font-medium',
                className,
            )}
        >
            {organization.name.trim().charAt(0).toUpperCase()}
        </span>
    );
}
