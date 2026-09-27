export type ProjectStatus = 'idle' | 'working';

export type Project = {
    id: number;
    name: string;
    status: ProjectStatus;
};

export type ProjectSummary = Pick<Project, 'id' | 'name'>;

export type ChatMessage = {
    id: number;
    role: 'user' | 'assistant' | 'activity';
    content: string;
    created_at: string | null;
};

export type SandboxState = {
    status: 'creating' | 'running' | 'paused' | 'failed';
    preview_url: string | null;
    shell_url: string | null;
    error: string | null;
};

export type WorkspaceEntry = {
    path: string;
    type: 'file' | 'dir';
};

export type WorkspaceFile = {
    path: string;
    content: string | null;
    notice: string | null;
};

export type Publication = {
    status: 'publishing' | 'live' | 'failed' | null;
    visibility: 'private' | 'public' | null;
    url: string | null;
    published_at: string | null;
    published_by: string | null;
    error: string | null;
    /** Tailscale sign-in link to approve this project (no auth key configured). */
    login_url: string | null;
    /** Why publishing can't be used here (e.g. Tailscale not set up). */
    unavailable: string | null;
};
