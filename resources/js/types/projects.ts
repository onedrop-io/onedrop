export type ProjectStatus = 'idle' | 'working';

export type Project = {
    id: number;
    name: string;
    status: ProjectStatus;
    /** Errors the preview shows after a turn go back to the agent automatically. */
    autofix: boolean;
    /** The agent keeps what the user asks for, and why, in .onedrop/REQ.md (REQ-002). */
    track_requirements: boolean;
    /** A message that failed because Claude Code wasn't signed in waits to run again (AI-005); workspace only. */
    waiting_for_sign_in?: boolean;
    /** Its SSH name for opening it in an editor from the desktop app (DESK-008); null unless the user owns it. Workspace only. */
    editor_alias?: string | null;
    /** Set when this is the owner's computer (CMP-001): a desktop where an app's preview would be. Workspace only. */
    computer?: { organization: string } | null;
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
    /** Making the copy, merging between the copy and Main, or pushing to or pulling from its pull request. */
    sync_status:
        | 'forking'
        | 'applying'
        | 'updating'
        | 'pushing'
        | 'pulling'
        | null;
    /** Why the last merge failed. */
    sync_error: string | null;
    applied_at: string | null;
    /** A message that failed because Claude Code wasn't signed in waits to run again (AI-005). */
    waiting_for_sign_in: boolean;
    /** Checked out from a GitHub pull request (GIT-014). */
    pull_request: TaskPullRequest | null;
};

/** A task's pull request (GIT-014, GIT-015). */
export type TaskPullRequest = {
    number: number;
    branch: string;
    base: string;
    /** Its branch is in a fork, which can't be pushed to. */
    fork: boolean;
    /** Push the agent's work after each turn. */
    push: boolean;
    /** Send failed checks to the agent after each push. */
    autofix: boolean;
    checks: 'success' | 'failure' | 'pending' | null;
    head_sha: string | null;
    url: string | null;
};

/** Why an agent is waiting for the user after its turn, as Jev judged it (PRJ-011; App\Enums\TurnOutcome). */
export type WaitingFor = 'question' | 'needs_input' | 'blocked';

/** A task as listed in the sidebar. */
export type SidebarTask = Pick<Task, 'id' | 'title' | 'stage'> & {
    working: boolean;
    /** The agent replied since the owner last opened the task (PRJ-008). */
    unread: boolean;
    /** The agent's latest step in its current run. */
    activity: string | null;
    /** Its agent ended its last turn waiting for the user; null when done (or not checked). */
    waiting_for: WaitingFor | null;
};

/** A card on the board (TASK-002). */
export type BoardTask = Task & {
    activity: string | null;
    waiting_for: WaitingFor | null;
    /** Its last turn just ended and is still being checked for waiting_for. */
    checking: boolean;
    updated_at: string | null;
    /** Checked out from a pull request: its number and its checks as last seen (GIT-014). */
    pull_request: { number: number; checks: TaskPullRequest['checks'] } | null;
};

/** The project the user opened: the sidebar shows just it, with all its tasks. */
export type OpenProject = ProjectSummary & {
    working: boolean;
    /** The main chat has a reply the owner hasn't seen. */
    unread: boolean;
    /** The main chat's agent ended its last turn waiting for the user. */
    main_waiting_for: WaitingFor | null;
    /** The main chat's or a task's agent is waiting for the user (the main chat's first). */
    waiting_for: WaitingFor | null;
    /** A turn that just ended is still being checked for waiting_for. */
    checking: boolean;
    tasks: SidebarTask[];
};

/** A ready-made starting point on the new-project page (App\Enums\AppTemplate). */
export type AppTemplate = {
    value: string;
    label: string;
    description: string;
    prompt: string;
};

/** A template from App\Sandbox\Templates\TemplateCatalog (PRJ-012): built-in or from a registry. */
export type CatalogTemplate = AppTemplate & {
    /** The registry's name, e.g. "Dokploy"; null for the built-in ones. */
    registry: string | null;
    logo: string | null;
    tags: string[];
    /** A Docker Compose stack, which needs Docker inside sandboxes. */
    compose: boolean;
    /** The app's version in the registry, when it names one. */
    version: string | null;
    /** Where to read more about the app; only https links. */
    links: {
        website: string | null;
        github: string | null;
        docs: string | null;
    };
};

/** A popular free app with the one picture shown for it in the coverflow (PRJ-012). */
export type FeaturedApp = CatalogTemplate & { cover: string };

/** What they typed or picked on the home page, waiting for them through signing up (HOME-004). */
export type PendingStart = {
    prompt: string | null;
    /** A free app comes with its picture, when it has one. */
    template: (CatalogTemplate & { cover?: string | null }) | null;
};

/** A project as listed in the sidebar, with what its menu needs. */
export type SidebarProject = ProjectSummary & {
    pinned: boolean;
    archived: boolean;
    /** The agent replied in its main chat or one of its open tasks since the owner last opened it, or it was marked unread. */
    unread: boolean;
    /** Its AI is coming up with a new title. */
    naming: boolean;
    /** Its agent is working on a request. */
    working: boolean;
    /** The agent's latest step in its current run, e.g. "Editing routes/web.php". */
    activity: string | null;
    /** Its main chat's or a task's agent ended its last turn waiting for the user (the main chat's first). */
    waiting_for: WaitingFor | null;
    /** A turn that just ended there is still being checked for waiting_for, so its notification waits. */
    checking: boolean;
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

/** How the sidebar orders pinned and recent projects (PRJ-010). */
export type ProjectSort = 'updated' | 'created' | 'manual';

export type SidebarProjects = {
    pinned: SidebarProject[];
    recent: SidebarProject[];
    archived: SidebarProject[];
    sort: ProjectSort;
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
    /** An activity step in plain words, for Simple mode (PRJ-013). */
    plain: string | null;
    attachments: MessageAttachment[];
    created_at: string | null;
};

export type SandboxState = {
    status: 'creating' | 'running' | 'paused' | 'failed';
    preview_url: string | null;
    /** The preview's own address, for the bar above it (preview_url may be a link that redirects there). */
    preview_address: string | null;
    shell_url: string | null;
    /** shell_url is the gateway's address, which takes the shell's own address as its `path` (FILE-005). */
    shell_via_gateway: boolean;
    /** The Shell tab opened on Claude Code's own sign-in (AI-005). */
    claude_login_url: string | null;
    error: string | null;
    /** Moving to the current sandbox image (files kept). */
    updating: boolean;
    /** A computer's desktop viewer (CMP-001); null for an app. */
    desktop_url?: string | null;
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
    /** 'review': held for a platform admin before going public on the hosted install (PUB-003). */
    status: 'publishing' | 'live' | 'failed' | 'review' | null;
    visibility: 'private' | 'public' | null;
    url: string | null;
    published_at: string | null;
    published_by: string | null;
    error: string | null;
    /** While publishing, the Tailscale link for what it's waiting on (see waiting_for). */
    login_url: string | null;
    /** Approving the node (no auth key configured), or turning on Funnel or HTTPS certificates for the tailnet. */
    waiting_for: 'login' | 'funnel' | 'https' | null;
    /** Why publishing can't be used here at all; each target's own reason is in targets. */
    unavailable: string | null;
    /** Who can open it where it's published, e.g. "People signed in to OneDrop". */
    audience: string | null;
    /** Where it's (last) published; null before it ever was. */
    target: PublishTarget | null;
    /** Where it can be published, and who Private and Public mean there. */
    targets: PublishTargetOption[];
    /** Its latest deploy to hosting and what it has there (HOST-001, HOST-002); null when it never had any. */
    hosting: Hosting | null;
    /** Whether the organization's Apps page lists it (APPS-003); null where it can't be (a private Tailscale app). */
    apps_listed: boolean | null;
};

export type PublishTarget = 'domain' | 'tailscale' | 'hosting';

export type PublishTargetOption = {
    target: PublishTarget;
    label: string;
    /** Null when it can't be private there (hosted apps are public for now). */
    private: string | null;
    public: string;
    unavailable: string | null;
};

export type Hosting = {
    deployment: {
        number: number;
        kind: 'server' | 'static' | null;
        status: 'running' | 'live' | 'failed';
        step: string;
        url: string | null;
        log: string | null;
        error: string | null;
        created_at: string | null;
    } | null;
    services: {
        id: number;
        kind: string;
        label: string;
        provider: string;
        /** Whose account it's in (HOST-003). */
        owner: 'organization' | 'platform';
        holds_data: boolean;
    }[];
    /** What the sandbox has that the hosted app doesn't yet (HOST-004). */
    changes: {
        count: number;
        commits: { sha: string; message: string }[];
    } | null;
    /** Recent deploys of an app with a server, the live one marked, to put one back (HOST-005). */
    history: {
        id: number;
        number: number;
        commit: string | null;
        finished_at: string | null;
        live: boolean;
    }[];
    /** Update the hosted app by itself after a turn that went well (HOST-006). */
    auto_deploy: boolean;
    /** The hosted SQLite file Move to Postgres would move, when it can (HOST-009). */
    sqlite: string | null;
    /** A Move to Postgres is waiting for the next deploy. */
    moving_to_postgres: boolean;
    /** The machine size it runs on, and the sizes on offer (HOST-010). */
    size: string;
    sizes: { key: string; label: string }[];
    /** Its hosted data can be deleted (it isn't published there). */
    can_delete: boolean;
};

/** The Share panel: the project's share page, or what sharing would start from (SHARE-001). */
export type Sharing = {
    shared: boolean;
    prompt: string;
    page_path: string;
    url: string | null;
    card_url: string | null;
    card_status: 'capturing' | 'ready' | 'failed' | null;
    card_error: string | null;
    views: number;
    remixes: number;
    /** Held for a platform admin's review, or taken down after one, on the hosted install (PUB-003). */
    review: 'held' | 'taken_down' | null;
};

/** A shared project's public page (SHARE-001). */
export type SharePage = {
    slug: string;
    name: string;
    author: string | null;
    prompt: string;
    screenshot_url: string | null;
    /** How many prompts the project took. */
    prompts: number;
    /** The agent that built it, e.g. "Claude Code". */
    agent: string | null;
    /** The running app, only when it's published as Public and live. */
    app_url: string | null;
    url: string;
    shared_at: string | null;
};

export type QueuedMessage = {
    id: number;
    content: string;
    attachments: MessageAttachment[];
};
