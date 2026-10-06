export type OrganizationRole = 'owner' | 'admin' | 'member';

/** An organization the user can switch to (ORG-002). */
export type OrganizationSummary = {
    id: number;
    name: string;
    slug: string;
    /** Its logo (ORG-005), or null to show its initial. */
    logo_url: string | null;
};

/** The organization the page is in. */
export type CurrentOrganization = OrganizationSummary & {
    role: OrganizationRole | null;
    /** Whether the user runs it: an owner or admin (ORG-005). */
    manages: boolean;
    /** Whether its people get their own computer (CMP-003). */
    computers: boolean;
};
