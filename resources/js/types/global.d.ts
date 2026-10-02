import type { RealtimeConfig } from '@/lib/realtime';
import type { Auth } from '@/types/auth';
import type {
    CurrentOrganization,
    OrganizationSummary,
} from '@/types/organizations';
import type {
    OpenProject,
    PendingStart,
    SidebarProjects,
} from '@/types/projects';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            /** The install's own logo (ADMIN-001), or null for the droplet. */
            logo: string | null;
            auth: Auth;
            /** The organization the page is in (ORG-002); null when signed out. */
            currentOrganization: CurrentOrganization | null;
            /** Every organization the user belongs to, for the switcher. */
            userOrganizations: OrganizationSummary[] | null;
            /** Whether the install serves many organizations (hosted) rather than one (self-hosted). */
            multiTenant: boolean;
            /** The admin signed in as this user (USR-003); null when nobody is impersonating. */
            impersonator: { id: number; name: string } | null;
            sidebarOpen: boolean;
            sidebarWidth: number | null;
            sidebarProjects: SidebarProjects | null;
            /** The project the user opened (TASK-001); the sidebar shows just it on its pages. */
            openProject: OpenProject | null;
            /** Where the browser connects for live updates (LIVE-001); null when the app doesn't broadcast. */
            realtime: RealtimeConfig | null;
            /** What they picked on the home page, until the new-project page takes it (HOME-004). */
            pendingStart: PendingStart | null;
            [key: string]: unknown;
        };
    }
}
