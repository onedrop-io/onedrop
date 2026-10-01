import { usePage } from '@inertiajs/react';
import type { CurrentOrganization } from '@/types';

/**
 * The organization the page is in (ORG-002). Only for signed-in pages, where the server always shares one.
 */
export function useOrganization(): CurrentOrganization {
    const { currentOrganization: organization } = usePage().props;

    if (!organization) {
        throw new Error('useOrganization() is only for signed-in pages.');
    }

    return organization;
}
