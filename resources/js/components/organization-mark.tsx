import { cn } from '@/lib/utils';
import type { OrganizationSummary } from '@/types';

/** An organization's logo, or its initial when it has none (ORG-002, ORG-005). */
export function OrganizationMark({
    organization,
    className,
}: {
    organization: Pick<OrganizationSummary, 'name' | 'logo_url'>;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-sidebar-accent text-sm font-medium',
                className,
            )}
        >
            {organization.logo_url ? (
                <img
                    src={organization.logo_url}
                    alt=""
                    className="size-full object-contain"
                />
            ) : (
                organization.name.trim().charAt(0).toUpperCase()
            )}
        </span>
    );
}
