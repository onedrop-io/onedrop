export type ProjectStatus = "idle" | "working";

export type Project = {
    id: number;
    name: string;
    status: ProjectStatus;
};

export type ProjectSummary = Pick<Project, "id" | "name">;

/** A project as listed in the sidebar, with what its menu needs. */
export type SidebarProject = ProjectSummary & {
    pinned: boolean;
    archived: boolean;
    /** The agent replied since the owner last opened it, or it was marked unread. */
    unread: boolean;
    /** Its AI is coming up with a new title. */
    naming: boolean;
    /** The live app's address, when it's published. */
    published_url: string | null;
};

export type SidebarProjects = {
    pinned: SidebarProject[];
    recent: SidebarProject[];
    archived: SidebarProject[];
};

export type MessageAttachment = {
    id: number;
    name: string;
    mime_type: string;
    size: number;
    /** A PNG, JPEG, GIF or WebP the browser (and a vision model) can show. */
    image: boolean;
    url: string;
};

export type ChatMessage = {
    id: number;
    role: "user" | "assistant" | "activity";
    content: string;
    attachments: MessageAttachment[];
    created_at: string | null;
};

export type SandboxState = {
    status: "creating" | "running" | "paused" | "failed";
    preview_url: string | null;
    shell_url: string | null;
    error: string | null;
    /** Moving to the current sandbox image (files kept). */
    updating: boolean;
};

export type WorkspaceEntry = {
    path: string;
    type: "file" | "dir";
};

export type WorkspaceFile = {
    path: string;
    content: string | null;
    notice: string | null;
};

export type Publication = {
    status: "publishing" | "live" | "failed" | null;
    visibility: "private" | "public" | null;
    url: string | null;
    published_at: string | null;
    published_by: string | null;
    error: string | null;
    /** Tailscale sign-in link to approve this project (no auth key configured). */
    login_url: string | null;
    /** Why publishing can't be used here (e.g. Tailscale not set up). */
    unavailable: string | null;
};

export type QueuedMessage = {
    id: number;
    content: string;
    attachments: MessageAttachment[];
};
