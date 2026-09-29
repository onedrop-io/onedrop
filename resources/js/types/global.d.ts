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
            auth: Auth;
            sidebarOpen: boolean;
            sidebarWidth: number | null;
            sidebarProjects: SidebarProjects | null;
            /** The project the user opened (TASK-001); the sidebar shows just it on its pages. */
            openProject: OpenProject | null;
            [key: string]: unknown;
        };
    }
}
