import type { Auth } from '@/types/auth';
import type { ProjectSummary } from '@/types/projects';

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
            recentProjects: ProjectSummary[];
            [key: string]: unknown;
        };
    }
}
