import {
    Head,
    Link,
    router,
    setLayoutProps,
    usePage,
    usePoll,
} from '@inertiajs/react';
import {
    Ban,
    Check,
    ChevronDown,
    Copy,
    ExternalLink,
    FileText,
    GitMerge,
    Kanban,
    Pencil,
    Monitor,
    PanelRight,
    Plus,
    RefreshCw,
    RotateCw,
    SquareTerminal,
    Terminal,
    Trash2,
    Wrench,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { CSSProperties } from 'react';
import ProjectAgentController from '@/actions/App/Http/Controllers/ProjectAgentController';
import ProjectMessageController from '@/actions/App/Http/Controllers/ProjectMessageController';
import TaskController from '@/actions/App/Http/Controllers/TaskController';
import TaskMessageController from '@/actions/App/Http/Controllers/TaskMessageController';
import AgentModelPicker from '@/components/agent-model-picker';
import ClaudeLoginStatus from '@/components/claude-login-status';
import HeaderActions from '@/components/header-actions';
import Markdown from '@/components/markdown';
import MessageAttachments from '@/components/message-attachments';
import NotificationsPrompt from '@/components/notifications-prompt';
import type { AttachmentPreview } from '@/components/message-attachments';
import PromptComposer from '@/components/prompt-composer';
import { Skeleton } from '@/components/ui/skeleton';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { TaskStatusIcon } from '@/components/task-status-icon';
import ConsoleView, { Notice } from '@/components/workspace/console-view';
import FileIcon from '@/components/workspace/file-icon';
import FileTree from '@/components/workspace/file-tree';
import FilesMenu from '@/components/workspace/files-menu';
import {
    fixRequest,
    PreviewErrorBar,
    usePreviewErrors,
} from '@/components/workspace/preview-errors';
import PublishMenu from '@/components/workspace/publish-menu';
import ShareMenu from '@/components/workspace/share-menu';
import ToolsPanel from '@/components/workspace/tools-panel';
import FileViewer from '@/components/workspace/file-viewer';
import ResizeHandle from '@/components/workspace/resize-handle';
import { isLocalHostname, useIsRemote } from '@/hooks/use-is-remote';
import { useResizableWidth } from '@/hooks/use-resizable-width';
import {
    fetchWorkspaceFile,
    useFilesVersion,
    useWorkspaceFiles,
} from '@/hooks/use-workspace-files';
import { cn } from '@/lib/utils';
import { setWorkspaceView, viewFromUrl } from '@/lib/workspace-view';
import { board, show } from '@/routes/projects';
import {
    create as createTask,
    show as showTask,
} from '@/routes/projects/tasks';
import type { RouteDefinition } from '@/wayfinder';
import type {
    AgentSelection,
    ChatMessage,
    MessageAttachment,
    QueuedMessage,
    Project,
    Publication,
    SandboxState,
    Sharing,
    TaskDetail,
    TaskStage,
    WorkspaceFile,
} from '@/types';

/**
 * Where the chat on screen sends things: the project"s main chat, a task"s (TASK-001), or,
 * for a new task, the first message creates the task.
 */
type ChatRoutes = {
    send: RouteDefinition<'post'>;
    stop: string | null;
    removeQueued: (messageId: number) => string;
};

function chatRoutes(
    project: Project,
    task: TaskDetail | null,
    newTask: boolean,
): ChatRoutes {
    if (task) {
        const ids = { project: project.id, task: task.id };

        return {
            send: TaskMessageController.store(ids),
            stop: TaskMessageController.stop.url(ids),
            removeQueued: (message) =>
                TaskMessageController.destroy.url({ ...ids, message }),
        };
    }

    if (newTask) {
        return {
            send: TaskController.store(project.id),
            stop: null,
            removeQueued: () => '',
        };
    }

    return {
        send: ProjectMessageController.store(project.id),
        stop: ProjectAgentController.stop.url(project.id),
        removeQueued: (message) =>
            ProjectMessageController.destroy.url({
                project: project.id,
                message,
            }),
    };
}

export default function ShowProject({
    project,
    task,
    newTask,
    agent,
    sandbox,
    messages,
    queued,
    publication,
    sharing,
    claudeSubscription,
}: {
    project: Project;
    /** The task whose chat this is, or null for the main chat (or a new task). */
    task: TaskDetail | null;
    /** An empty chat whose first message starts a new task. */
    newTask: boolean;
    agent: AgentSelection | null;
    sandbox: SandboxState | null;
    messages: ChatMessage[];
    queued: QueuedMessage[];
    publication: Publication;
    sharing: Sharing;
    /** Claude Code runs on the owner's own Claude sign-in in the sandbox (AI-005). */
    claudeSubscription: boolean;
}) {
    // Bumped by "Sign in to Claude": the workspace opens the Shell tab on Claude Code's sign-in.
    const [claudeSignIns, setClaudeSignIns] = useState(0);
    const working = task
        ? task.status === 'working'
        : !newTask && project.status === 'working';
    const routes = chatRoutes(project, task, newTask);
    const title = task ? task.title : newTask ? 'New task' : null;
    const busy =
        working ||
        sandbox?.status === 'creating' ||
        sandbox?.updating ||
        !!task?.sync_status ||
        publication.status === 'publishing' ||
        sharing.card_status === 'capturing';

    const [chatWidth, setChatWidth] = useResizableWidth(
        CHAT_WIDTH_KEY,
        CHAT_WIDTH,
    );

    setLayoutProps({
        breadcrumbs: [
            { title: project.name, href: show(project.id) },
            ...(task
                ? [
                      {
                          title: task.title,
                          href: showTask({
                              project: project.id,
                              task: task.id,
                          }),
                      },
                  ]
                : newTask
                  ? [{ title: 'New task', href: createTask(project.id) }]
                  : []),
        ],
    });
    const { start, stop } = usePoll(
        1000,
        {
            only: [
                'project',
                'task',
                'agent',
                'sandbox',
                'messages',
                'queued',
                'publication',
                'sharing',
            ],
        },
        { autoStart: false },
    );

    // Stream: poll for agent events and sandbox status only while something is happening.
    useEffect(() => {
        if (busy) {
            start();
        } else {
            stop();
        }

        return stop;
    }, [busy, start, stop]);

    return (
        <>
            <Head title={title ? `${title} · ${project.name}` : project.name} />
            <HeaderActions>
                <ShareMenu
                    projectId={project.id}
                    projectName={project.name}
                    sharing={sharing}
                />
                <PublishMenu projectId={project.id} publication={publication} />
            </HeaderActions>

            <div className="flex h-[calc(100svh-4rem)] min-h-0 flex-col md:h-[calc(100svh-5rem)] lg:flex-row">
                <ChatPanel
                    key={task?.id ?? (newTask ? 'new' : 'main')}
                    project={project}
                    task={task}
                    newTask={newTask}
                    routes={routes}
                    agent={agent}
                    queued={queued}
                    messages={messages}
                    working={working}
                    width={chatWidth}
                    claudeSignIn={
                        claudeSubscription && agent?.harness === 'claude_code'
                            ? () => setClaudeSignIns((count) => count + 1)
                            : null
                    }
                />
                <ResizeHandle
                    label="Resize chat"
                    side="left"
                    width={chatWidth}
                    limits={CHAT_WIDTH}
                    onResize={setChatWidth}
                    className="hidden lg:block"
                    data-test="chat-resize"
                />
                <WorkspacePanel
                    project={project}
                    sendMessage={routes.send.url}
                    publication={publication}
                    sandbox={sandbox}
                    copy={task?.own_copy ?? false}
                    working={working}
                    activity={messages.length}
                    claudeSignIns={claudeSignIns}
                />
            </div>
        </>
    );
}

function ChatPanel({
    project,
    task,
    newTask,
    routes,
    agent,
    queued,
    messages,
    working,
    width,
    claudeSignIn,
}: {
    project: Project;
    task: TaskDetail | null;
    newTask: boolean;
    routes: ChatRoutes;
    agent: AgentSelection | null;
    queued: QueuedMessage[];
    messages: ChatMessage[];
    working: boolean;
    width: number;
    /** Open Claude Code's sign-in, when the agent runs on the user's Claude subscription. */
    claudeSignIn: (() => void) | null;
}) {
    const bottom = useRef<HTMLDivElement>(null);
    const [draft, setDraft] = useState<string | undefined>(undefined);

    // Stop the agent; any queued messages come back into the box for editing.
    const stop = () =>
        routes.stop &&
        router.post(
            routes.stop,
            {},
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    const restored = (
                        page.flash as { draft?: string } | undefined
                    )?.draft;

                    if (restored) {
                        setDraft(restored);
                    }
                },
            },
        );

    useEffect(() => {
        bottom.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages.length, queued.length, working]);

    return (
        <section
            aria-label="Chat"
            className="flex min-h-0 flex-1 flex-col lg:w-(--chat-width) lg:max-w-[calc(100%-20rem)] lg:flex-none"
            style={{ '--chat-width': `${width}px` } as CSSProperties}
        >
            {(task || newTask) && (
                <TaskHeader projectId={project.id} task={task} />
            )}
            {task?.own_copy && (
                <TaskCopyBar
                    projectId={project.id}
                    task={task}
                    working={working}
                />
            )}
            <div className="flex-1 space-y-5 overflow-y-auto p-4">
                {(newTask || task) && messages.length === 0 && !working && (
                    <TaskEmptyState
                        task={task}
                        onStart={(content) =>
                            router.post(
                                routes.send.url,
                                { content },
                                { preserveScroll: true },
                            )
                        }
                    />
                )}
                {messages.map((message, index) => (
                    <MessageItem
                        key={message.id}
                        message={message}
                        live={working && index === messages.length - 1}
                    />
                ))}
                {working && (
                    <p
                        className="flex items-center gap-2 text-sm text-muted-foreground"
                        data-test="agent-working"
                    >
                        <span className="size-2 animate-pulse rounded-full bg-emerald-500" />
                        {messages.at(-1)?.role === 'user'
                            ? 'Thinking…'
                            : 'Working…'}
                    </p>
                )}
                {working && <NotificationsPrompt />}
                {queued.map((message) => (
                    <div
                        key={message.id}
                        className="flex justify-end"
                        data-test="message-queued"
                    >
                        <div className="max-w-[85%] rounded-2xl border border-dashed border-input px-4 py-2 text-sm">
                            <MessageAttachments
                                attachments={sentAttachments(
                                    message.attachments,
                                )}
                                className="mb-1 justify-end opacity-80"
                            />
                            <p className="whitespace-pre-wrap text-muted-foreground">
                                {message.content}
                            </p>
                            <div className="mt-1 flex items-center justify-end gap-2 text-xs text-muted-foreground">
                                <span>Queued</span>
                                <button
                                    type="button"
                                    onClick={() =>
                                        router.delete(
                                            routes.removeQueued(message.id),
                                            { preserveScroll: true },
                                        )
                                    }
                                    aria-label="Remove queued message"
                                    className="rounded p-0.5 hover:bg-muted"
                                    data-test="remove-queued"
                                >
                                    <X className="size-3" />
                                </button>
                            </div>
                        </div>
                    </div>
                ))}
                <div ref={bottom} />
            </div>
            <div className="p-3">
                {claudeSignIn && (
                    <ClaudeLoginStatus
                        projectId={project.id}
                        taskId={task?.id ?? null}
                        working={working}
                        onSignIn={claudeSignIn}
                    />
                )}
                <PromptComposer
                    action={routes.send}
                    field="content"
                    working={working}
                    onStop={stop}
                    attachments
                    history={messages
                        .filter(
                            (message) =>
                                message.role === 'user' &&
                                message.content !== '',
                        )
                        .map((message) => message.content)}
                    value={draft}
                    onValueChange={(next) => setDraft(next)}
                    autoFocus={newTask}
                    placeholder={
                        working
                            ? 'Queue a message, or ⌘/Ctrl+Enter to send now…'
                            : newTask
                              ? 'Describe the task…'
                              : 'Message the agent…'
                    }
                    footer={
                        <>
                            {agent && (
                                <AgentModelPicker
                                    selection={agent}
                                    harnessLocked={
                                        working
                                            ? 'Stop the agent or wait for it to finish to switch agents'
                                            : null
                                    }
                                    onChange={(next) =>
                                        router.patch(
                                            ProjectAgentController.update.url(
                                                project.id,
                                            ),
                                            {
                                                agent_harness: next.harness,
                                                agent_provider: next.provider,
                                                agent_model: next.model,
                                                agent_variant: next.variant,
                                            },
                                            { preserveScroll: true },
                                        )
                                    }
                                />
                            )}
                            <AutofixToggle project={project} />
                        </>
                    }
                />
            </div>
        </section>
    );
}

function sentAttachments(
    attachments: MessageAttachment[],
): AttachmentPreview[] {
    return attachments.map((attachment) => ({
        key: attachment.id,
        name: attachment.name,
        imageUrl: attachment.image ? attachment.url : null,
        href: attachment.url,
    }));
}

function MessageItem({
    message,
    live = false,
}: {
    message: ChatMessage;
    /** The agent's current step: its dot pulses green. */
    live?: boolean;
}) {
    if (message.role === 'user') {
        return (
            <div
                className="flex flex-col items-end gap-2"
                data-test="message-user"
            >
                <MessageAttachments
                    attachments={sentAttachments(message.attachments)}
                    className="max-w-[85%] justify-end"
                />
                {message.content !== '' && (
                    <p className="max-w-[85%] rounded-2xl bg-muted px-4 py-2 text-sm whitespace-pre-wrap">
                        {message.content}
                    </p>
                )}
            </div>
        );
    }

    if (message.role === 'activity') {
        return (
            <p
                className="flex items-center gap-2 text-sm text-muted-foreground"
                data-test="message-activity"
            >
                <span
                    className={cn(
                        'size-1.5 rounded-full',
                        live ? 'animate-pulse bg-emerald-500' : 'bg-current',
                    )}
                />
                {message.content}
            </p>
        );
    }

    return (
        <Markdown
            content={message.content}
            className="text-sm leading-relaxed"
            data-test="message-assistant"
        />
    );
}

function WorkspacePanel({
    project,
    sendMessage,
    publication,
    sandbox,
    copy,
    working,
    activity,
    claudeSignIns,
}: {
    project: Project;
    /** Where the chat on screen sends messages. */
    sendMessage: string;
    publication: Publication;
    sandbox: SandboxState | null;
    /** The sandbox is a task's own copy of the app (TASK-003). */
    copy: boolean;
    working: boolean;
    /** Changes whenever the agent does something (message count). */
    activity: number;
    /** Changes when the user asks to sign in to Claude: open the Shell tab on Claude Code's sign-in. */
    claudeSignIns: number;
}) {
    const running = sandbox?.status === 'running';
    const isRemoteBrowser = useIsRemote();
    // A 127.0.0.1 preview (laptop) can't be reached from another machine; servers use gateway URLs instead.
    const remote =
        isRemoteBrowser &&
        !!sandbox?.preview_url &&
        isLocalHostname(new URL(sandbox.preview_url).hostname);
    const shellUnreachable =
        isRemoteBrowser &&
        !!sandbox?.shell_url &&
        isLocalHostname(new URL(sandbox.shell_url).hostname);
    const url = !running
        ? null
        : !remote
          ? sandbox.preview_url
          : publication.status === 'live'
            ? publication.url
            : null;
    const [reloadKey, setReloadKey] = useState(0);
    const [wasWorking, setWasWorking] = useState(working);
    const previewFrame = useRef<HTMLIFrameElement>(null);
    const previewErrors = usePreviewErrors(previewFrame);
    const shellFrame = useRef<HTMLIFrameElement>(null);
    // The URL says what to show (TASK-004): `?tab=tools&tool=database`, `?tab=file&file=…`, `?tab=console`.
    // `?tool=git` alone (e.g. back from connecting GitHub) opens that Tools section.
    const { url: pageUrl } = usePage();
    const [initialView] = useState(() => viewFromUrl(pageUrl));
    const [tool, setTool] = useState<string | null>(initialView.tool);
    const [tab, setTab] = useState<ActiveTab>(() => {
        const asked = initialView.tab ?? (initialView.tool ? 'tools' : null);

        return asked === 'file' && !initialView.file
            ? 'preview'
            : ACTIVE_TABS.includes(asked as ActiveTab)
              ? (asked as ActiveTab)
              : 'preview';
    });
    const [extraTabs, setExtraTabs] = useState<MovableTab[]>(() =>
        tab === 'console' || tab === 'shell' || tab === 'file' ? [tab] : [],
    );
    const [draggedTab, setDraggedTab] = useState<MovableTab | null>(null);

    // Put the cursor in the terminal whenever the Shell tab is shown.
    useEffect(() => {
        if (tab === 'shell') {
            shellFrame.current?.focus();
        }
    }, [tab]);
    const [consoleClears, setConsoleClears] = useState(0);
    const [filesOpen, setFilesOpen] = useState(false);
    const [hideHidden, setHideHidden] = useState(false);
    const [openPath, setOpenPath] = useState<string | null>(
        tab === 'file' ? initialView.file : null,
    );

    // Keep the address bar (and links to the project's other pages) on what's showing.
    useEffect(() => {
        setWorkspaceView(project.id, { tab, tool, file: openPath });
    }, [project.id, tab, tool, openPath]);
    const [file, setFile] = useState<WorkspaceFile | null>(null);
    const [fileError, setFileError] = useState<string | null>(null);
    const [fileDirty, setFileDirty] = useState(false);
    const files = useWorkspaceFiles(project.id);
    const filesVersion = useFilesVersion(project.id, running && filesOpen);
    // The sandbox has a file watcher (FILE-004), so the tree reloads only when files come or go.
    const watchingFiles = filesVersion > 0;
    const seenFilesVersion = useRef(0);
    const [filesWidth, setFilesWidth] = useResizableWidth(
        FILES_WIDTH_KEY,
        FILES_WIDTH,
    );

    // Use the last choice saved in this browser; otherwise open by default
    // only where there's room for chat, preview and files. Decided after
    // mount so server-rendered and client HTML match.
    useEffect(() => {
        setHideHidden(localStorage.getItem(HIDE_HIDDEN_KEY) === 'true');

        const saved = localStorage.getItem(FILES_OPEN_KEY);

        if (saved !== null) {
            setFilesOpen(saved === 'true');
        } else if (window.matchMedia('(min-width: 1440px)').matches) {
            setFilesOpen(true);
        }
    }, []);

    const toggleFiles = () => {
        localStorage.setItem(FILES_OPEN_KEY, String(!filesOpen));
        setFilesOpen(!filesOpen);
    };

    const toggleHidden = () => {
        localStorage.setItem(HIDE_HIDDEN_KEY, String(!hideHidden));
        setHideHidden(!hideHidden);
    };

    const visibleEntries = hideHidden
        ? files.entries.filter(
              (entry) =>
                  !entry.path.split('/').some((part) => part.startsWith('.')),
          )
        : files.entries;

    const reloadPreview = () => {
        previewErrors.clear();
        setReloadKey((key) => key + 1);
    };

    // Reload once the agent finishes so a newly started dev server shows up.
    if (wasWorking !== working) {
        setWasWorking(working);

        if (!working) {
            reloadPreview();
        }
    }

    // Ask the agent to fix what the preview reported (queued if it's busy).
    const fixPreviewErrors = () => {
        router.post(
            sendMessage,
            { content: fixRequest(previewErrors.errors) },
            { preserveScroll: true },
        );
        previewErrors.clear();
    };

    const loadFile = useCallback(
        (path: string) => {
            fetchWorkspaceFile(project.id, path)
                .then((loaded) => {
                    setFile(loaded);
                    setFileError(null);
                })
                .catch((e: Error) => setFileError(e.message));
        },
        [project.id],
    );

    // Keep the open file (and, without a file watcher, the tree) current as the agent works.
    useEffect(() => {
        if (!running) {
            return;
        }

        if (filesOpen && !watchingFiles) {
            void files.refresh();
        }

        if (openPath) {
            loadFile(openPath);
        }
    }, [
        running,
        filesOpen,
        watchingFiles,
        activity,
        working,
        openPath,
        files.refresh,
        loadFile,
    ]);

    // Reload the tree when files were added, removed or renamed by anything: the agent, the Shell, the app.
    useEffect(() => {
        if (
            seenFilesVersion.current !== 0 &&
            filesVersion !== seenFilesVersion.current
        ) {
            void files.refresh();
        }

        seenFilesVersion.current = filesVersion;
    }, [filesVersion, files.refresh]);

    const openFile = (path: string) => {
        if (
            path !== openPath &&
            fileDirty &&
            !window.confirm('Discard unsaved changes?')
        ) {
            return;
        }

        if (path !== openPath) {
            setFile(null);
            setFileError(null);
        }

        setOpenPath(path);
        setExtraTabs((tabs) =>
            tabs.includes('file') ? tabs : [...tabs, 'file'],
        );
        setTab('file');
    };

    const addTab = (kind: ToolTab) => {
        setExtraTabs((tabs) => (tabs.includes(kind) ? tabs : [...tabs, kind]));
        setTab(kind);
    };

    useEffect(() => {
        if (claudeSignIns > 0) {
            setExtraTabs((tabs) =>
                tabs.includes('shell') ? tabs : [...tabs, 'shell'],
            );
            setTab('shell');
        }
    }, [claudeSignIns]);

    const closeTab = (kind: MovableTab) => {
        setExtraTabs((tabs) => tabs.filter((t) => t !== kind));

        if (tab === kind) {
            setTab('preview');
        }
    };

    const closeFile = () => {
        if (fileDirty && !window.confirm('Discard unsaved changes?')) {
            return;
        }

        setOpenPath(null);
        closeTab('file');
    };

    /**
     * Drag-to-reorder props for a closable tab. The dragged tab takes a
     * neighbour's place once the pointer passes that neighbour's middle,
     * or when it's dropped on it.
     */
    const sortable = (kind: MovableTab) => {
        const moveOnto = (
            event: React.DragEvent<HTMLElement>,
            always: boolean,
        ) => {
            if (!draggedTab) {
                return;
            }

            event.preventDefault();

            if (draggedTab === kind) {
                return;
            }

            const rect = event.currentTarget.getBoundingClientRect();
            const middle = rect.left + rect.width / 2;

            setExtraTabs((tabs) => {
                const from = tabs.indexOf(draggedTab);
                const to = tabs.indexOf(kind);
                const passed =
                    from < to
                        ? event.clientX >= middle
                        : event.clientX <= middle;

                if (from < 0 || to < 0 || !(passed || always)) {
                    return tabs;
                }

                const next = tabs.filter((t) => t !== draggedTab);
                next.splice(to, 0, draggedTab);

                return next;
            });
        };

        return {
            draggable: true,
            dragging: draggedTab === kind,
            onDragStart: (event: React.DragEvent) => {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', kind);
                setDraggedTab(kind);
            },
            onDragOver: (event: React.DragEvent<HTMLElement>) =>
                moveOnto(event, false),
            onDrop: (event: React.DragEvent<HTMLElement>) =>
                moveOnto(event, true),
            onDragEnd: () => setDraggedTab(null),
        };
    };

    const statusText = sandbox?.updating
        ? 'Updating sandbox…'
        : {
              creating: copy ? 'Copying the app…' : 'Starting sandbox…',
              running: url ?? 'Running',
              paused: 'Paused',
              failed: 'Sandbox failed to start',
          }[sandbox?.status ?? 'creating'];

    return (
        <div className="flex min-h-80 min-w-0 flex-1 border-t border-sidebar-border/70 lg:border-t-0 dark:border-sidebar-border">
            <section
                aria-label="Preview"
                className="flex min-w-0 flex-1 flex-col"
            >
                <div className="flex items-center gap-1 border-b border-sidebar-border/70 px-2 py-1.5 text-sm dark:border-sidebar-border">
                    <TabButton
                        active={tab === 'tools'}
                        onClick={() => setTab('tools')}
                        testId="tab-tools"
                    >
                        <Wrench className="size-4" />
                        Tools
                    </TabButton>
                    <TabButton
                        active={tab === 'preview'}
                        onClick={() => setTab('preview')}
                        testId="tab-preview"
                    >
                        <Monitor className="size-4" />
                        Preview
                    </TabButton>
                    {extraTabs.map((kind) =>
                        kind === 'file' ? (
                            openPath && (
                                <TabButton
                                    key={kind}
                                    active={tab === 'file'}
                                    onClick={() => setTab('file')}
                                    onClose={closeFile}
                                    testId="tab-file"
                                    {...sortable(kind)}
                                >
                                    <FileIcon
                                        name={openPath.split('/').pop() ?? ''}
                                    />
                                    <span className="max-w-48 truncate">
                                        {openPath.split('/').pop()}
                                    </span>
                                    {fileDirty && (
                                        <span
                                            className="size-1.5 rounded-full bg-current"
                                            aria-label="Unsaved changes"
                                            data-test="file-dirty"
                                        />
                                    )}
                                </TabButton>
                            )
                        ) : (
                            <TabButton
                                key={kind}
                                active={tab === kind}
                                onClick={() => setTab(kind)}
                                onClose={() => closeTab(kind)}
                                testId={`tab-${kind}`}
                                {...sortable(kind)}
                            >
                                {TOOL_TABS[kind].icon}
                                {TOOL_TABS[kind].label}
                            </TabButton>
                        ),
                    )}
                    <DropdownMenu modal={false}>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                aria-label="Add tab"
                                title="Add tab"
                                data-test="add-tab"
                                className="rounded p-1 text-muted-foreground hover:bg-muted"
                            >
                                <Plus className="size-4" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="start"
                            onFocusOutside={(event) => event.preventDefault()}
                            onCloseAutoFocus={(event) => {
                                // The menu hands focus back to "+" as it closes; keep it in the shell instead.
                                if (tab === 'shell') {
                                    event.preventDefault();
                                    shellFrame.current?.focus();
                                }
                            }}
                        >
                            {(Object.keys(TOOL_TABS) as ToolTab[]).map(
                                (kind) => (
                                    <DropdownMenuItem
                                        key={kind}
                                        onSelect={() => addTab(kind)}
                                        data-test={`add-tab-${kind}`}
                                    >
                                        {TOOL_TABS[kind].icon}
                                        {TOOL_TABS[kind].label}
                                    </DropdownMenuItem>
                                ),
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <span
                        className="ml-2 min-w-0 flex-1 truncate text-muted-foreground"
                        data-test="sandbox-status"
                    >
                        {
                            {
                                preview: statusText,
                                tools: '',
                                file: openPath,
                                console: 'App output',
                                shell: '~/workspace',
                            }[tab]
                        }
                    </span>
                    {tab === 'preview' && url && (
                        <>
                            <IconButton
                                label="Reload preview"
                                onClick={reloadPreview}
                            >
                                <RotateCw className="size-4" />
                            </IconButton>
                            <a
                                href={url}
                                target="_blank"
                                rel="noreferrer"
                                aria-label="Open preview in a new tab"
                                className="rounded p-1 hover:bg-muted"
                            >
                                <ExternalLink className="size-4" />
                            </a>
                        </>
                    )}
                    {tab === 'console' && (
                        <IconButton
                            label="Clear console"
                            onClick={() => setConsoleClears((n) => n + 1)}
                        >
                            <Ban className="size-4" />
                        </IconButton>
                    )}
                    <IconButton
                        label={filesOpen ? 'Hide files' : 'Show files'}
                        onClick={toggleFiles}
                        testId="toggle-files"
                    >
                        <PanelRight className="size-4" />
                    </IconButton>
                </div>

                {url ? (
                    <div
                        className={cn(
                            'relative flex flex-1 flex-col',
                            tab !== 'preview' && 'hidden',
                        )}
                    >
                        <iframe
                            ref={previewFrame}
                            key={reloadKey}
                            src={url}
                            title="App preview"
                            className="flex-1 bg-white"
                        />
                        {!working && previewErrors.errors.length > 0 && (
                            <PreviewErrorBar
                                errors={previewErrors.errors}
                                onFix={fixPreviewErrors}
                                onDismiss={previewErrors.clear}
                            />
                        )}
                    </div>
                ) : (
                    tab === 'preview' && (
                        <PreviewPlaceholder sandbox={sandbox} copy={copy} />
                    )
                )}
                {tab === 'tools' && (
                    <ToolsPanel
                        projectId={project.id}
                        running={running}
                        working={working}
                        publication={publication}
                        initialSection={tool}
                        onSectionChange={setTool}
                    />
                )}
                {tab === 'file' && openPath && (
                    <FileViewer
                        key={openPath}
                        projectId={project.id}
                        file={file}
                        error={fileError}
                        onDirtyChange={setFileDirty}
                    />
                )}
                {extraTabs.includes('console') && tab === 'console' && (
                    <ConsoleView
                        projectId={project.id}
                        active
                        running={running}
                        clearSignal={consoleClears}
                    />
                )}
                {extraTabs.includes('shell') &&
                    (running && sandbox.shell_url && !shellUnreachable ? (
                        // Stays mounted while the tab is open so the session survives tab switches.
                        <iframe
                            // A new sign-in restarts the terminal on Claude Code's own `claude auth login`.
                            key={claudeSignIns}
                            ref={shellFrame}
                            src={
                                claudeSignIns > 0 && sandbox.claude_login_url
                                    ? sandbox.claude_login_url
                                    : sandbox.shell_url
                            }
                            title="Shell"
                            onLoad={() =>
                                tab === 'shell' && shellFrame.current?.focus()
                            }
                            className={cn(
                                'flex-1 bg-neutral-950',
                                tab !== 'shell' && 'hidden',
                            )}
                            data-test="shell-frame"
                        />
                    ) : (
                        tab === 'shell' && (
                            <Notice>
                                {shellUnreachable
                                    ? 'The shell only works on the machine running this app builder.'
                                    : running
                                      ? "This sandbox doesn't have a shell. It was created before shells were added; recreate it to get one."
                                      : 'The shell starts when the sandbox is running.'}
                            </Notice>
                        )
                    ))}
            </section>

            {filesOpen && (
                <ResizeHandle
                    label="Resize files"
                    side="right"
                    width={filesWidth}
                    limits={FILES_WIDTH}
                    onResize={setFilesWidth}
                    className="hidden md:block"
                    data-test="files-resize"
                />
            )}
            {filesOpen && (
                <aside
                    aria-label="Files"
                    className="hidden shrink-0 flex-col md:flex"
                    style={{ width: filesWidth }}
                    data-test="files-panel"
                >
                    <div className="flex items-center justify-between border-b border-sidebar-border/70 px-3 py-2 text-sm dark:border-sidebar-border">
                        <span className="font-medium">Files</span>
                        <div className="flex items-center">
                            <IconButton
                                label="Refresh files"
                                onClick={() => void files.refresh()}
                            >
                                <RefreshCw
                                    className={cn(
                                        'size-3.5',
                                        files.loading && 'animate-spin',
                                    )}
                                />
                            </IconButton>
                            <FilesMenu
                                projectId={project.id}
                                disabled={!running}
                                hideHidden={hideHidden}
                                onToggleHidden={toggleHidden}
                                onClose={toggleFiles}
                                onChanged={() => void files.refresh()}
                                onCreatedFile={openFile}
                            />
                        </div>
                    </div>
                    <div className="flex-1 overflow-y-auto px-1">
                        {!running ? (
                            <p className="p-3 text-sm text-muted-foreground">
                                Files appear once the sandbox is running.
                            </p>
                        ) : files.error ? (
                            <p className="p-3 text-sm text-red-600">
                                {files.error}
                            </p>
                        ) : visibleEntries.length === 0 ? (
                            <p className="p-3 text-sm text-muted-foreground">
                                {files.loading
                                    ? 'Loading…'
                                    : files.entries.length > 0
                                      ? 'Only hidden files so far.'
                                      : 'No files yet. The agent will create them.'}
                            </p>
                        ) : (
                            <FileTree
                                entries={visibleEntries}
                                selected={tab === 'file' ? openPath : null}
                                onSelect={openFile}
                            />
                        )}
                    </div>
                </aside>
            )}
        </div>
    );
}

type ToolTab = 'console' | 'shell';
/** Tabs that can be closed and dragged into a different order; Tools and Preview stay put. */
type MovableTab = ToolTab | 'file';
type ActiveTab = 'tools' | 'preview' | MovableTab;

const ACTIVE_TABS: ActiveTab[] = [
    'tools',
    'preview',
    'console',
    'shell',
    'file',
];

/** localStorage key for whether the files panel was last left open. */
const FILES_OPEN_KEY = 'zap.files-open';

/** localStorage keys and limits for the chat and files panel widths, in px. */
const CHAT_WIDTH_KEY = 'zap.chat-width';
const CHAT_WIDTH = { initial: 448, min: 280, max: 960 };
const FILES_WIDTH_KEY = 'zap.files-width';
const FILES_WIDTH = { initial: 224, min: 160, max: 480 };

/** localStorage key for whether dotfiles are hidden in the files panel. */
const HIDE_HIDDEN_KEY = 'zap.files-hide-hidden';

const TOOL_TABS: Record<ToolTab, { label: string; icon: React.ReactNode }> = {
    console: { label: 'Console', icon: <Terminal className="size-4" /> },
    shell: { label: 'Shell', icon: <SquareTerminal className="size-4" /> },
};

function TabButton({
    active,
    onClick,
    onClose,
    testId,
    dragging = false,
    children,
    ...dragProps
}: {
    active: boolean;
    onClick: () => void;
    onClose?: () => void;
    testId?: string;
    dragging?: boolean;
    children: React.ReactNode;
} & Pick<
    React.HTMLAttributes<HTMLDivElement>,
    'draggable' | 'onDragStart' | 'onDragOver' | 'onDrop' | 'onDragEnd'
>) {
    return (
        <div
            {...dragProps}
            className={cn(
                'flex items-center rounded-md transition-colors',
                active
                    ? 'bg-muted font-medium'
                    : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground',
                dragging && 'opacity-50',
            )}
        >
            <button
                type="button"
                onClick={onClick}
                className="flex items-center gap-1.5 px-2 py-1"
                data-test={testId}
            >
                {children}
            </button>
            {onClose && (
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Close tab"
                    className="mr-1 rounded p-0.5 hover:bg-background"
                >
                    <X className="size-3" />
                </button>
            )}
        </div>
    );
}

/** Whether errors the preview shows after a turn go back to the agent automatically (ERR-001). */
function AutofixToggle({ project }: { project: Project }) {
    return (
        <button
            type="button"
            aria-pressed={project.autofix}
            onClick={() =>
                router.patch(
                    ProjectAgentController.autofix.url(project.id),
                    { autofix: !project.autofix },
                    { preserveScroll: true },
                )
            }
            title={
                project.autofix
                    ? 'Autofix is on: errors the preview shows after a turn go back to the agent. Click to turn off.'
                    : 'Autofix is off. Click to send errors the preview shows after a turn back to the agent.'
            }
            data-test="composer-autofix"
            className={cn(
                'flex h-7 shrink-0 items-center gap-1 rounded-full px-2 text-xs hover:bg-muted',
                project.autofix
                    ? 'bg-muted text-foreground'
                    : 'text-muted-foreground',
            )}
        >
            <Wrench className="size-3.5" />
            Autofix
        </button>
    );
}

function IconButton({
    label,
    onClick,
    testId,
    children,
}: {
    label: string;
    onClick: () => void;
    testId?: string;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={label}
            title={label}
            data-test={testId}
            className="rounded p-1 hover:bg-muted"
        >
            {children}
        </button>
    );
}

function PreviewPlaceholder({
    sandbox,
    copy,
}: {
    sandbox: SandboxState | null;
    /** A task's own copy of the app (TASK-003). */
    copy: boolean;
}) {
    const failed = sandbox?.status === 'failed';
    const title = failed
        ? "Sandbox didn't start"
        : copy && !sandbox
          ? 'No copy of the app yet'
          : copy
            ? 'Copying the app…'
            : sandbox?.updating
              ? 'Updating sandbox…'
              : 'Starting sandbox…';
    const detail = failed
        ? sandbox.error
        : copy && !sandbox
          ? "This task gets its own copy of Main's app when its agent starts, so its changes don't touch Main until you apply them."
          : copy
            ? 'Its files, packages and data, as Main has them now. Main keeps running.'
            : sandbox?.updating
              ? 'Getting the latest tools. Your files are kept, and your app will be back in a moment.'
              : 'Your app will appear here in a moment.';

    return (
        <div
            className="relative flex-1 overflow-hidden p-4"
            data-test="preview-placeholder"
        >
            <div className="grid h-full grid-cols-[1fr_3fr] grid-rows-[auto_1fr] gap-3 opacity-60">
                <Skeleton className="col-span-2 h-10 rounded-full" />
                <Skeleton className="rounded-xl" />
                <div className="grid grid-rows-[auto_1fr_auto] gap-3">
                    <div className="grid grid-cols-3 gap-3">
                        {[0, 1, 2].map((i) => (
                            <Skeleton key={i} className="h-8 rounded-full" />
                        ))}
                    </div>
                    <Skeleton className="rounded-xl" />
                    <Skeleton className="h-16 rounded-xl" />
                </div>
            </div>
            <div className="absolute inset-0 flex items-center justify-center p-6">
                <div className="max-w-xs rounded-xl border border-sidebar-border/70 bg-background/95 p-5 text-center text-sm shadow-sm dark:border-sidebar-border">
                    <p className="font-medium">{title}</p>
                    <p
                        className={cn(
                            'mt-1',
                            failed ? 'text-red-600' : 'text-muted-foreground',
                        )}
                        data-test="sandbox-message"
                    >
                        {detail}
                    </p>
                </div>
            </div>
        </div>
    );
}

const STAGE_LABELS: Record<TaskStage, string> = {
    todo: 'To do',
    in_progress: 'In progress',
    review: 'Review',
    done: 'Done',
};

/** Above a task's chat: its title and column, with rename, move and delete (TASK-001). */
function TaskHeader({
    projectId,
    task,
}: {
    projectId: number;
    task: TaskDetail | null;
}) {
    const [renaming, setRenaming] = useState(false);
    const [name, setName] = useState(task?.title ?? '');
    const ids = task ? { project: projectId, task: task.id } : null;

    const save = () => {
        setRenaming(false);

        if (ids && name.trim() !== '' && name !== task?.title) {
            router.patch(
                TaskController.update.url(ids),
                { title: name },
                { preserveScroll: true },
            );
        }
    };

    return (
        <div
            className="flex h-11 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-4 text-sm dark:border-sidebar-border"
            data-test="task-header"
        >
            {task && (
                <TaskStatusIcon
                    stage={task.stage}
                    working={task.status === 'working'}
                />
            )}
            {renaming && task ? (
                <input
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                    onBlur={save}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            save();
                        } else if (event.key === 'Escape') {
                            setName(task.title);
                            setRenaming(false);
                        }
                    }}
                    onFocus={(event) => event.target.select()}
                    maxLength={80}
                    aria-label="Task title"
                    autoFocus
                    className="min-w-0 flex-1 rounded border border-input bg-transparent px-2 py-0.5"
                    data-test="task-rename-input"
                />
            ) : (
                <span
                    className="min-w-0 truncate font-medium"
                    data-test="task-title"
                >
                    {task?.title ?? 'New task'}
                </span>
            )}
            {task && ids && !renaming && (
                <DropdownMenu modal={false}>
                    <DropdownMenuTrigger asChild>
                        <button
                            type="button"
                            aria-label="Task actions"
                            className="rounded p-0.5 text-muted-foreground hover:bg-muted"
                            data-test="task-menu"
                        >
                            <ChevronDown className="size-4" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" className="w-48">
                        <DropdownMenuItem
                            onSelect={() => {
                                setName(task.title);
                                setRenaming(true);
                            }}
                            data-test="task-menu-rename"
                        >
                            <Pencil />
                            Rename
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        {(Object.keys(STAGE_LABELS) as TaskStage[]).map(
                            (stage) => (
                                <DropdownMenuItem
                                    key={stage}
                                    disabled={stage === task.stage}
                                    onSelect={() =>
                                        router.patch(
                                            TaskController.update.url(ids),
                                            { stage },
                                            { preserveScroll: true },
                                        )
                                    }
                                    data-test={`task-menu-stage-${stage}`}
                                >
                                    {stage === task.stage ? (
                                        <Check />
                                    ) : (
                                        <TaskStatusIcon
                                            stage={stage}
                                            working={false}
                                        />
                                    )}
                                    {STAGE_LABELS[stage]}
                                </DropdownMenuItem>
                            ),
                        )}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={() => {
                                if (
                                    window.confirm(
                                        `Delete “${task.title}” and its chat?`,
                                    )
                                ) {
                                    router.delete(
                                        TaskController.destroy.url(ids),
                                    );
                                }
                            }}
                            data-test="task-menu-delete"
                        >
                            <Trash2 />
                            Delete
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
            {task && (
                <span
                    className="ml-auto shrink-0 text-xs text-muted-foreground"
                    data-test="task-stage"
                >
                    {STAGE_LABELS[task.stage]}
                </span>
            )}
            <Link
                href={board(projectId)}
                className={cn(
                    'flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 text-xs text-muted-foreground hover:bg-muted',
                    !task && 'ml-auto',
                )}
                data-test="task-board-link"
            >
                <Kanban className="size-3.5" />
                Board
            </Link>
        </div>
    );
}

/**
 * Under a task's title when it has its own copy of the app (TASK-003): what the preview shows, and
 * buttons to apply its work to Main or bring Main's newer work in.
 */
function TaskCopyBar({
    projectId,
    task,
    working,
}: {
    projectId: number;
    task: TaskDetail;
    working: boolean;
}) {
    const ids = { project: projectId, task: task.id };
    const syncing = task.sync_status !== null;
    const disabled = working || syncing;

    const apply = () => {
        if (
            window.confirm(
                `Apply “${task.title}” to Main? Its work is merged into Main, and Main's agent finishes the job. This task's copy of the app is removed.`,
            )
        ) {
            router.post(
                TaskController.apply.url(ids),
                {},
                { preserveScroll: true },
            );
        }
    };

    return (
        <div
            className="flex min-h-9 shrink-0 flex-wrap items-center gap-2 border-b border-sidebar-border/70 px-4 py-1.5 text-xs text-muted-foreground dark:border-sidebar-border"
            data-test="task-copy-bar"
        >
            <Copy className="size-3.5 shrink-0" />
            <span
                className="min-w-0 flex-1 truncate"
                data-test="task-copy-status"
            >
                {task.sync_status === 'applying'
                    ? 'Applying to Main…'
                    : task.sync_status === 'updating'
                      ? 'Bringing in the latest from Main…'
                      : task.has_copy
                        ? 'Working in its own copy of the app'
                        : task.applied_at
                          ? 'Applied to Main'
                          : 'Gets its own copy of the app when it starts'}
            </span>
            {task.sync_error && (
                <span
                    className="basis-full text-red-600 dark:text-red-400"
                    data-test="task-copy-error"
                >
                    {task.sync_error}
                </span>
            )}
            {task.has_copy && (
                <>
                    <button
                        type="button"
                        onClick={() =>
                            router.post(
                                TaskController.updateFromMain.url(ids),
                                {},
                                { preserveScroll: true },
                            )
                        }
                        disabled={disabled}
                        title="Merge Main's newer work into this task's copy"
                        className="flex items-center gap-1 rounded px-1.5 py-0.5 hover:bg-muted disabled:opacity-50"
                        data-test="task-update-from-main"
                    >
                        <RefreshCw
                            className={cn(
                                'size-3.5',
                                task.sync_status === 'updating' &&
                                    'animate-spin',
                            )}
                        />
                        Update from Main
                    </button>
                    <button
                        type="button"
                        onClick={apply}
                        disabled={disabled}
                        title="Merge this task's work into Main"
                        className="flex items-center gap-1 rounded bg-primary px-2 py-0.5 font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                        data-test="task-apply"
                    >
                        <GitMerge className="size-3.5" />
                        Apply to Main
                    </button>
                </>
            )}
        </div>
    );
}

/** A task with nothing in its chat yet: what to do for a new one, or a board card's notes and Start. */
function TaskEmptyState({
    task,
    onStart,
}: {
    task: TaskDetail | null;
    onStart: (content: string) => void;
}) {
    if (!task) {
        return (
            <div
                className="mx-auto flex max-w-sm flex-col items-center gap-2 pt-24 text-center"
                data-test="task-empty"
            >
                <FileText className="size-10 text-muted-foreground" />
                <p className="text-lg font-medium">Plan a new task</p>
                <p className="text-sm text-muted-foreground">
                    Describe what you want done. A fresh agent works on it
                    alongside your other tasks, in the same app, so you can get
                    more done at once.
                </p>
            </div>
        );
    }

    const content = [task.title, task.description].filter(Boolean).join('\n\n');

    return (
        <div
            className="mx-auto flex max-w-sm flex-col items-center gap-3 pt-24 text-center"
            data-test="task-empty"
        >
            <p className="text-lg font-medium">{task.title}</p>
            {task.description && (
                <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                    {task.description}
                </p>
            )}
            <button
                type="button"
                onClick={() => onStart(content)}
                className="rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                data-test="task-start"
            >
                Start task
            </button>
            <p className="text-xs text-muted-foreground">
                Or write your own first message below.
            </p>
        </div>
    );
}
