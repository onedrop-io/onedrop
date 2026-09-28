import type { Auth } from '@/types/auth';
import type { SidebarProjects } from '@/types/projects';

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
            [key: string]: unknown;
        };
    }
}
