export type ProjectStatus = 'idle' | 'working';

export type Project = {
    id: number;
    name: string;
    status: ProjectStatus;
    /** Errors the preview shows after a turn go back to the agent automatically. */
    autofix: boolean;
};

export type ProjectSummary = Pick<Project, 'id' | 'name'>;

/** A board column (App\Enums\TaskStage). */
export type TaskStage = 'todo' | 'in_progress' | 'review' | 'done';

/** A piece of work in a project with its own agent and chat (TASK-001). */
export type Task = {
    id: number;
    title: string;
    /** Notes from its board card, sent along when it's started. */
    description: string | null;
    stage: TaskStage;
    status: ProjectStatus;
};

/** A task on its own page, with its copy of the app (TASK-003). */
export type TaskDetail = Task & {
    /** Tasks get their own copy of the app (otherwise they share Main's sandbox). */
    own_copy: boolean;
    /** It has that copy now (it's made with the first message, and removed once applied). */
    has_copy: boolean;
    /** Merging between the copy and Main. */
    sync_status: 'forking' | 'applying' | 'updating' | null;
    /** Why the last merge failed. */
    sync_error: string | null;
    applied_at: string | null;
};

/** A task as listed in the sidebar. */
export type SidebarTask = Pick<Task, 'id' | 'title' | 'stage'> & {
    working: boolean;
    /** The agent's latest step in its current run. */
    activity: string | null;
};

/** A card on the board (TASK-002). */
export type BoardTask = Task & {
    activity: string | null;
    updated_at: string | null;
};

/** The project the user opened: the sidebar shows just it, with all its tasks. */
export type OpenProject = ProjectSummary & {
    working: boolean;
    tasks: SidebarTask[];
};

/** A ready-made starting point on the new-project page (App\Enums\AppTemplate). */
export type AppTemplate = {
    value: string;
    label: string;
    description: string;
    prompt: string;
};

/** A project as listed in the sidebar, with what its menu needs. */
export type SidebarProject = ProjectSummary & {
    pinned: boolean;
    archived: boolean;
    /** The agent replied since the owner last opened it, or it was marked unread. */
    unread: boolean;
    /** Its AI is coming up with a new title. */
    naming: boolean;
    /** Its agent is working on a request. */
    working: boolean;
    /** The agent's latest step in its current run, e.g. "Editing routes/web.php". */
    activity: string | null;
    /** Its sandbox failed to start or crashed. */
    failed: boolean;
    /** The app's icon (its favicon, or one the AI drew). */
    icon_url: string | null;
    /** The AI is drawing an icon for it. */
    drawing_icon: boolean;
    /** The live app's address, when it's published. */
    published_url: string | null;
    /** Its tasks that aren't done yet. */
    tasks: SidebarTask[];
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
    role: 'user' | 'assistant' | 'activity';
    content: string;
    attachments: MessageAttachment[];
    created_at: string | null;
};

export type SandboxState = {
    status: 'creating' | 'running' | 'paused' | 'failed';
    preview_url: string | null;
    shell_url: string | null;
    error: string | null;
    /** Moving to the current sandbox image (files kept). */
    updating: boolean;
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

export type QueuedMessage = {
    id: number;
    content: string;
    attachments: MessageAttachment[];
};
