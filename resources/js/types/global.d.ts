import type { RealtimeConfig } from '@/lib/realtime';
import type { Auth } from '@/types/auth';
import type { OpenProject, SidebarProjects } from '@/types/projects';

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
            sidebarOpen: boolean;
            sidebarWidth: number | null;
            sidebarProjects: SidebarProjects | null;
            /** The project the user opened (TASK-001); the sidebar shows just it on its pages. */
            openProject: OpenProject | null;
            /** Where the browser connects for live updates (LIVE-001); null when the app doesn't broadcast. */
            realtime: RealtimeConfig | null;
            [key: string]: unknown;
        };
    }
}
