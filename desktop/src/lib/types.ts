import type { ImportGitHub } from '@/components/repository-picker';
import type { RealtimeConfig } from '@/lib/realtime';
import type {
    AgentSelection,
    AppTemplate,
    ChatMessage,
    Project,
    Publication,
    SandboxState,
    Sharing,
} from '@/types';

/** Where the app is signed in (DESK-001). Kept in the system keychain. */
export type Session = {
    server: string;
    token: string;
};

export type Organization = { id: number; name: string; slug: string };

/** `GET /api/v1/user`. */
export type Me = {
    user: {
        id: number;
        name: string;
        email: string;
        avatar: string | null;
        is_admin: boolean;
        /** Without an AI connection the projects API answers 409: set one up on the web (AI-001). */
        ai_connected: boolean;
    };
    app: { name: string; url: string; logo: string | null };
    /** The organization the app works in, and the others it can switch to (ORG-002). */
    organization: Organization;
    organizations: Organization[];
    realtime: RealtimeConfig | null;
};

export type QueuedMessage = Pick<ChatMessage, 'id' | 'content' | 'attachments'>;

/** `GET /api/v1/projects/{project}`: what the web workspace's page gets. */
export type Workspace = {
    project: Project;
    agent: AgentSelection | null;
    claudeSubscription: boolean;
    publication: Publication;
    sharing: Sharing;
    sandbox: SandboxState | null;
    queued: QueuedMessage[];
    messages: ChatMessage[];
};

/** `GET /api/v1/projects/new`: what the web's new-project page gets. */
export type NewProjectOptions = {
    defaultAi: string | null;
    agent: AgentSelection | null;
    templates: AppTemplate[];
    /** Importing a repository instead (PRJ-009). */
    github: ImportGitHub;
};
