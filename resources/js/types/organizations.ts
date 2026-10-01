export type OrganizationRole = 'owner' | 'admin' | 'member';

/** An organization the user can switch to (ORG-002). */
export type OrganizationSummary = {
    id: number;
    name: string;
    slug: string;
};

/** The organization the page is in. */
export type CurrentOrganization = OrganizationSummary & {
    role: OrganizationRole | null;
    /** Whether the user runs it: an owner or admin (ORG-005). */
    manages: boolean;
};
