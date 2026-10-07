import {
    Head,
    Link,
    router,
    setLayoutProps,
    usePage,
    usePoll,
} from '@inertiajs/react';
import {
    ArrowLeft,
    AppWindow,
    ArrowRight,
    Ban,
    Brush,
    Check,
    ChevronDown,
    Boxes,
    ClipboardList,
    Copy,
    ExternalLink,
    FlaskConical,
    Globe,
    FileText,
    CloudUpload,
    GitMerge,
    GitPullRequest,
    Kanban,
    LoaderCircle,
    SquareMousePointer,
    Pencil,
    MessageSquare,
    TextSearch,
    Monitor,
    MoreHorizontal,
    Columns2,
    PanelLeft,
    PanelRight,
    Plus,
    Rows2,
    RefreshCw,
    RotateCw,
    Search,
    Smartphone,
    SquareTerminal,
    Tablet,
    Terminal,
    Trash2,
    Wrench,
    X,
} from 'lucide-react';
import {
    Fragment,
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import type { CSSProperties } from 'react';
import ProjectAgentController from '@/actions/App/Http/Controllers/ProjectAgentController';
import ProjectMessageController from '@/actions/App/Http/Controllers/ProjectMessageController';
import TaskController from '@/actions/App/Http/Controllers/TaskController';
import TaskMessageController from '@/actions/App/Http/Controllers/TaskMessageController';
import AgentModelPicker from '@/components/agent-model-picker';
import HeldMessagePrompt from '@/components/held-message-prompt';
import ClaudeLoginStatus from '@/components/claude-login-status';
import HeaderActions from '@/components/header-actions';
import Markdown from '@/components/markdown';
import MessageAttachments from '@/components/message-attachments';
import NotificationsPrompt from '@/components/notifications-prompt';
import type { AttachmentPreview } from '@/components/message-attachments';
import PromptComposer from '@/components/prompt-composer';
import type { AttachedFile, ContextFile } from '@/components/prompt-composer';
import PreviewAnnotator, {
    annotationText,
    capturePreview,
    captureTab,
    inspectPreview,
} from '@/components/workspace/preview-annotator';
import type { PreviewCapture } from '@/components/workspace/preview-annotator';
import PreviewInspectorPanel, {
    inspectionImage,
    inspectionText,
    inspectorAction,
    inspectorRects,
    usePreviewInspector,
} from '@/components/workspace/preview-inspector';
import { Checkbox } from '@/components/ui/checkbox';
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
import ServicesView from '@/components/workspace/services-view';
import RequirementsView from '@/components/workspace/requirements-view';
import BrowserView, { closeBrowser } from '@/components/workspace/browser-view';
import type { BrowserSession } from '@/components/workspace/browser-view';
import TestsView from '@/components/workspace/tests-view';
import FileIcon from '@/components/workspace/file-icon';
import FileTree, {
    isWithin,
    searchEntries,
} from '@/components/workspace/file-tree';
import FilesMenu from '@/components/workspace/files-menu';
import QuickOpen from '@/components/workspace/quick-open';
import MobileTabSwitcher from '@/components/workspace/mobile-tab-switcher';
import * as panes from '@/components/workspace/panes';
import type { Layout, PaneTab, ShellTab } from '@/components/workspace/panes';
import {
    fixRequest,
    PreviewErrorBar,
    usePreviewErrors,
} from '@/components/workspace/preview-errors';
import EditorMenu from '@/components/workspace/editor-menu';
import GitActionsMenu from '@/components/workspace/git-actions-menu';
import PublishMenu from '@/components/workspace/publish-menu';
import { ComputerMenu, DesktopView } from '@/components/workspace/desktop-view';
import ShareMenu from '@/components/workspace/share-menu';
import ToolsPanel from '@/components/workspace/tools-panel';
import {
    ChecksIcon,
    openPullRequest,
} from '@/components/workspace/pull-requests-panel';
import FileViewer from '@/components/workspace/file-viewer';
import type { FileLocation } from '@/components/workspace/file-viewer';
import {
    ContentSearchResults,
    ContentSearchToggles,
    EMPTY_CONTENT_QUERY,
    useContentSearch,
} from '@/components/workspace/content-search';
import type { ContentQuery } from '@/components/workspace/content-search';
import ResizeHandle from '@/components/workspace/resize-handle';
import { isLocalHostname, useIsRemote } from '@/hooks/use-is-remote';
import {
    CHAT_OPEN_KEY,
    FILES_OPEN_KEY,
    useBuildMode,
} from '@/hooks/use-build-mode';
import { useClipboard } from '@/hooks/use-clipboard';
import { useResizableWidth } from '@/hooks/use-resizable-width';
import { useSandboxActivity } from '@/hooks/use-sandbox-activity';
import {
    fetchWorkspaceFile,
    useFilesVersion,
    useWorkspaceFiles,
} from '@/hooks/use-workspace-files';
import { useLiveReload, useProjectChannel } from '@/lib/realtime';
import { cn } from '@/lib/utils';
import {
    isPagePath,
    pageFromAddress,
    previewAddress,
    loadChat,
    loadWorkspace,
    newShellSession,
    previewUrlAt,
    saveChat,
    saveWorkspace,
} from '@/lib/workspace-state';
import type { SavedShell } from '@/lib/workspace-state';
import {
    askAgent,
    onAskAgent,
    onOpenWorkspaceTool,
    setWorkspaceView,
    viewFromUrl,
} from '@/lib/workspace-view';
import { show as computerRoute } from '@/routes/computers';
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
    TaskPullRequest,
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
    const { simple } = useBuildMode();
    // On a phone or small tablet the chat and the workspace are tabs, one at a time (LAYOUT-006).
    const [mobileView, setMobileView] = useState<'chat' | 'workspace'>('chat');

    // Bumped by "Sign in to Claude": the workspace opens the Shell tab on Claude Code's sign-in.
    const [claudeSignIns, setClaudeSignIns] = useState(0);
    // Bumped when that sign-in succeeds: the workspace goes back to the Preview tab,
    // and a phone back to the chat, where the waiting message picks up (LAYOUT-006).
    const [claudeSignInsDone, setClaudeSignInsDone] = useState(0);
    const claudeSignedIn = useCallback(() => {
        setClaudeSignInsDone((count) => count + 1);
        setMobileView('chat');
    }, []);
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
    // Hidden or shown, as last left in this browser (LAYOUT-001), in either mode; read after mount so server and
    // client HTML match. Switching to Simple shows it again (PRJ-013).
    const [chatOpen, setChatOpen] = useState(true);

    useEffect(() => {
        setChatOpen(localStorage.getItem(CHAT_OPEN_KEY) !== 'false');
    }, [simple]);

    const toggleChat = () => {
        localStorage.setItem(CHAT_OPEN_KEY, String(!chatOpen));
        setChatOpen(!chatOpen);
    };

    // A Tools section was asked for from outside the workspace (Manage hosting, the git menu): on a phone or a narrow
    // window, where the chat and the workspace are tabs, show the workspace too.
    useEffect(() => onOpenWorkspaceTool(() => setMobileView('workspace')), []);

    // Something was sent to the chat from the workspace: make sure it's on screen.
    const showChat = () => {
        if (!chatOpen) {
            toggleChat();
        }

        setMobileView('chat');
    };

    const computer = project.computer ?? null;

    setLayoutProps({
        breadcrumbs: [
            computer
                ? {
                      title: 'Computer',
                      href: computerRoute(computer.organization),
                  }
                : { title: project.name, href: show(project.id) },
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
    const reloadLive = useLiveReload(LIVE_PROPS);
    // Bumped when the tab comes back to a sandbox that had been asleep: the preview reloads (SBX-007).
    const [wakes, setWakes] = useState(0);
    // Keeps the sandbox awake while the workspace is open, and wakes it when the tab comes back (SBX-007). Coming
    // back also catches up on anything missed meanwhile, without moving the chat.
    useSandboxActivity(project.id, task?.own_copy ? task.id : null, (woke) => {
        reloadLive();

        if (woke) {
            setWakes((count) => count + 1);
        }
    });
    // Bumped when the sandbox's files come or go (FILE-004): the Files panel checks whether they're its own.
    const [filesChanges, setFilesChanges] = useState(0);
    // The file open in the editor, which the chat sends along for the agent (AGT-015).
    const [openFile, setOpenFile] = useState<ContextFile | null>(null);
    // Pushed updates (LIVE-001): the chat, the agent's progress and the sandbox's status, as they change.
    const live = useProjectChannel(project.id, {
        ProjectUpdated: reloadLive,
        ProjectFilesChanged: () => setFilesChanges((count) => count + 1),
    });
    const { start, stop } = usePoll(
        1000,
        { only: LIVE_PROPS },
        { autoStart: false },
    );

    // Catch up on anything missed while connecting (or reconnecting).
    useEffect(() => {
        if (live) {
            reloadLive();
        }
    }, [live, reloadLive]);

    // Without live updates, poll for agent events and sandbox status while something is happening.
    useEffect(() => {
        if (busy && !live) {
            start();
        } else {
            stop();
        }

        return stop;
    }, [busy, live, start, stop]);

    return (
        <>
            <Head
                title={
                    computer
                        ? 'Computer'
                        : title
                          ? `${title} · ${project.name}`
                          : project.name
                }
            />
            {computer ? (
                <HeaderActions>
                    <ComputerMenu
                        organization={computer.organization}
                        running={sandbox?.status === 'running'}
                    />
                </HeaderActions>
            ) : (
                <HeaderActions>
                    <GitActionsMenu
                        projectId={project.id}
                        running={sandbox?.status === 'running'}
                        working={working}
                    />
                    <ShareMenu
                        projectId={project.id}
                        projectName={project.name}
                        sharing={sharing}
                    />
                    <EditorMenu
                        projectId={project.id}
                        alias={project.editor_alias ?? null}
                        running={sandbox?.status === 'running'}
                    />
                    <PublishMenu
                        projectId={project.id}
                        publication={publication}
                    />
                </HeaderActions>
            )}

            <div className="flex h-[calc(100svh-4rem)] min-h-0 flex-col md:h-[calc(100svh-5rem)] lg:flex-row">
                <MobileViewTabs
                    view={mobileView}
                    onChange={setMobileView}
                    working={working}
                />
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
                    hidden={!chatOpen}
                    mobileHidden={mobileView !== 'chat'}
                    claudeSignIn={
                        claudeSubscription && agent?.harness === 'claude_code'
                            ? () => {
                                  setClaudeSignIns((count) => count + 1);
                                  setMobileView('workspace');
                              }
                            : null
                    }
                    onClaudeSignedIn={claudeSignedIn}
                    openFile={openFile}
                />
                {chatOpen && (
                    <ResizeHandle
                        label="Resize chat"
                        side="left"
                        width={chatWidth}
                        limits={CHAT_WIDTH}
                        onResize={setChatWidth}
                        className="hidden lg:block"
                        data-test="chat-resize"
                    />
                )}
                <WorkspacePanel
                    project={project}
                    sendMessage={routes.send.url}
                    publication={publication}
                    sandbox={sandbox}
                    copy={task?.own_copy ?? false}
                    working={working}
                    activity={messages.length}
                    claudeSignIns={claudeSignIns}
                    claudeSignInsDone={claudeSignInsDone}
                    live={live}
                    filesChanges={filesChanges}
                    wakes={wakes}
                    chatOpen={chatOpen}
                    onToggleChat={toggleChat}
                    onShowChat={showChat}
                    mobileHidden={mobileView !== 'workspace'}
                    onOpenFileChange={setOpenFile}
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
    hidden,
    mobileHidden,
    claudeSignIn,
    onClaudeSignedIn,
    openFile,
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
    /** Hidden by the user (LAYOUT-001); stays mounted so a draft survives. */
    hidden: boolean;
    /** The workspace tab is showing on a small screen (LAYOUT-006). */
    mobileHidden: boolean;
    /** Open Claude Code's sign-in, when the agent runs on the user's Claude subscription. */
    claudeSignIn: (() => void) | null;
    /** That sign-in succeeded. */
    onClaudeSignedIn: () => void;
    /** The file open in the editor (AGT-015). */
    openFile: ContextFile | null;
}) {
    const { simple } = useBuildMode();
    // In Simple mode a step repeated in a row ("Building the app" three times) shows once (PRJ-013).
    const shownMessages = simple
        ? messages.filter((message, index) => {
              const previous = messages[index - 1];

              return !(
                  message.role === 'activity' &&
                  previous?.role === 'activity' &&
                  (previous.plain ?? previous.content) ===
                      (message.plain ?? message.content)
              );
          })
        : messages;
    const bottom = useRef<HTMLDivElement>(null);
    const scroller = useRef<HTMLDivElement>(null);
    const [draft, setDraft] = useState<string | undefined>(undefined);
    const chat = task ? `task-${task.id}` : newTask ? 'new' : 'main';
    // A reload brings back the half-typed message and, if it was scrolled up, where the chat was (LAYOUT-005).
    const keptScroll = useRef(false);
    const draftRef = useRef(draft);

    useEffect(() => {
        draftRef.current = draft;
    }, [draft]);

    useLayoutEffect(() => {
        const saved = loadChat(project.id, chat);

        if (saved.draft) {
            setDraft(saved.draft);
        }

        if (saved.scrollTop !== null && scroller.current) {
            scroller.current.scrollTop = saved.scrollTop;
            keptScroll.current = true;
        }

        const save = () => {
            const box = scroller.current;
            const atBottom =
                !box ||
                box.scrollHeight - box.scrollTop - box.clientHeight < 40;

            saveChat(project.id, chat, {
                draft: draftRef.current ?? '',
                scrollTop: atBottom ? null : box.scrollTop,
            });
        };

        window.addEventListener('pagehide', save);

        return () => {
            window.removeEventListener('pagehide', save);
            save();
        };
    }, [project.id, chat]);

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

    // The open file goes with each message unless the user removed it; opening another file brings the chip back (AGT-015).
    const [removedFile, setRemovedFile] = useState<string | null>(null);
    const contextFile =
        openFile && openFile.path !== removedFile ? openFile : null;

    // Files from outside the chat box (a marked-up picture of the preview, AGT-013), until it has them.
    const [incomingFiles, setIncomingFiles] = useState<AttachedFile[]>([]);

    // "Ask" on a hunk of a diff (GIT-011), or a marked-up preview (AGT-013): the text goes in the chat box, with the
    // cursor after it for the question.
    useEffect(
        () =>
            onAskAgent((text, files) => {
                if (text) {
                    setDraft((current) =>
                        current?.trim()
                            ? `${current.trimEnd()}\n\n${text}`
                            : text,
                    );
                }

                if (files.length > 0) {
                    setIncomingFiles(files);
                }

                requestAnimationFrame(() => {
                    const box = document.getElementById(
                        'composer-content',
                    ) as HTMLTextAreaElement | null;

                    box?.focus();
                    box?.setSelectionRange(box.value.length, box.value.length);
                });
            }),
        [],
    );

    useEffect(() => {
        if (keptScroll.current) {
            keptScroll.current = false;

            return;
        }

        bottom.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages.length, queued.length, working]);

    return (
        <section
            aria-label="Chat"
            className={cn(
                'flex min-h-0 flex-1 flex-col lg:w-(--chat-width) lg:max-w-[calc(100%-20rem)] lg:flex-none',
                hidden && 'lg:hidden',
                mobileHidden && 'max-lg:hidden',
            )}
            style={{ '--chat-width': `${width}px` } as CSSProperties}
        >
            {(task || newTask) && (
                <TaskHeader projectId={project.id} task={task} />
            )}
            {task?.pull_request ? (
                <PullRequestBar
                    projectId={project.id}
                    task={task}
                    pullRequest={task.pull_request}
                    working={working}
                />
            ) : (
                task?.own_copy && (
                    <TaskCopyBar
                        projectId={project.id}
                        task={task}
                        working={working}
                    />
                )
            )}
            <div
                ref={scroller}
                className="flex-1 space-y-5 overflow-y-auto p-4"
            >
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
                {shownMessages.map((message, index) => (
                    <MessageItem
                        key={message.id}
                        message={message}
                        simple={simple}
                        live={working && index === shownMessages.length - 1}
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
                        waitingForSignIn={
                            (task ?? project).waiting_for_sign_in ?? false
                        }
                        onSignIn={claudeSignIn}
                        onSignedIn={onClaudeSignedIn}
                    />
                )}
                <PromptComposer
                    action={routes.send}
                    field="content"
                    working={working}
                    onStop={stop}
                    attachments
                    incomingFiles={incomingFiles}
                    onIncomingFilesAdded={() => setIncomingFiles([])}
                    contextFile={contextFile}
                    onRemoveContextFile={() =>
                        setRemovedFile(contextFile?.path ?? null)
                    }
                    history={messages
                        .filter(
                            (message) =>
                                message.role === 'user' &&
                                message.content !== '',
                        )
                        .map((message) => message.content)}
                    value={draft}
                    onValueChange={(next) => setDraft(next)}
                    // Jev's checks as it's sent: a secret, an earlier decision, queue or send now (SECRET-002, REQ-003, AGT-012).
                    extraData={newTask ? undefined : { checks: '1' }}
                    renderHeld={(held, actions) => (
                        <HeldMessagePrompt held={held} actions={actions} />
                    )}
                    autoFocus={newTask}
                    placeholder={
                        working
                            ? 'Queue a message, or ⌘/Ctrl+Enter to send now…'
                            : newTask
                              ? 'Describe the task…'
                              : project.computer
                                ? 'Ask the AI to do something on your computer…'
                                : 'Message the agent…'
                    }
                    footer={
                        <>
                            {agent && (
                                <AgentModelPicker
                                    selection={agent}
                                    allowAuto
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
                                                agent_auto: next.auto ?? false,
                                            },
                                            { preserveScroll: true },
                                        )
                                    }
                                />
                            )}
                            {!project.computer && (
                                <AutofixToggle project={project} />
                            )}
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
    simple = false,
}: {
    message: ChatMessage;
    /** The agent's current step: its dot pulses green. */
    live?: boolean;
    /** Steps in plain words (PRJ-013). */
    simple?: boolean;
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
                {simple ? (message.plain ?? message.content) : message.content}
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
    claudeSignInsDone,
    live,
    filesChanges,
    wakes,
    chatOpen,
    onToggleChat,
    onShowChat,
    mobileHidden,
    onOpenFileChange,
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
    /** Changes when that sign-in succeeds: show the Preview tab again. */
    claudeSignInsDone: number;
    /** Live updates are flowing (LIVE-001), so there's no need to poll. */
    live: boolean;
    /** Changes when a sandbox of this project saw files come or go (FILE-004). */
    filesChanges: number;
    /** Changes when the tab came back to the sandbox asleep: the preview reloads (SBX-007). */
    wakes: number;
    /** The chat is showing next to the workspace (LAYOUT-001). */
    chatOpen: boolean;
    onToggleChat: () => void;
    /** Shows the chat (opening it, or switching to its tab on a small screen) when something is sent to it. */
    onShowChat: () => void;
    /** The chat tab is showing on a small screen (LAYOUT-006). */
    mobileHidden: boolean;
    /** Reports the file open in the editor, for the chat to send along (AGT-015). */
    onOpenFileChange: (file: ContextFile | null) => void;
}) {
    const running = sandbox?.status === 'running';
    // The owner's computer (CMP-001): its desktop is the pinned tab, and it has no app tools.
    const computer = project.computer ?? null;
    const toolTabs = (Object.keys(TOOL_TABS) as ToolTab[]).filter(
        (kind) => !computer || kind === 'shell',
    );
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
    // Mid-update the old sandbox's address no longer answers: show the placeholder, not the browser's error page.
    const url =
        !running || sandbox.updating
            ? null
            : !remote
              ? sandbox.preview_url
              : publication.status === 'live'
                ? publication.url
                : null;
    const [reloadKey, setReloadKey] = useState(0);
    /** Whether the preview's page is loading, for the bar under the address, as a browser shows it. */
    const [previewLoading, setPreviewLoading] = useState(true);
    const [wasWorking, setWasWorking] = useState(working);
    const previewFrame = useRef<HTMLIFrameElement>(null);
    const previewErrors = usePreviewErrors(previewFrame);
    const shellFrames = useRef<Partial<Record<ShellTab, HTMLIFrameElement>>>(
        {},
    );
    // The URL says what to show (TASK-004): `?tab=tools&tool=database`, `?tab=file&file=…`, `?tab=console`.
    // `?tool=git` alone (e.g. back from connecting GitHub) opens that Tools section.
    const { url: pageUrl } = usePage();
    const { simple } = useBuildMode();
    const [initialView] = useState(() => viewFromUrl(pageUrl));
    const [tool, setTool] = useState<string | null>(initialView.tool);
    /** The panes and their tabs (LAYOUT-002); the URL follows the tab showing in the pane last used. */
    const [layout, setLayout] = useState<Layout>(() => {
        const asked = initialView.tab ?? (initialView.tool ? 'tools' : null);
        const first: PaneTab =
            (asked === 'file' && !initialView.file) ||
            (computer && asked === 'tools')
                ? 'preview'
                : ACTIVE_TABS.includes(asked as PaneTab)
                  ? (asked as PaneTab)
                  : 'preview';

        // Simple mode opens with just Tools and Preview; the rest are still in "+" (PRJ-013).
        return panes.initialLayout(first, simple || computer ? [] : undefined);
    });
    const tab = panes.focusedTab(layout);
    const showTab = (kind: PaneTab, paneId?: number) =>
        setLayout((current) => panes.showTab(current, kind, paneId));

    // A computer has no Tools tab to show (a layout saved in this tab, a link): its desktop instead.
    useEffect(() => {
        if (computer && layout.panes.some((pane) => pane.active === 'tools')) {
            setLayout((current) => panes.showTab(current, 'preview'));
        }
    }, [computer, layout]);
    const [draggedTab, setDraggedTab] = useState<PaneTab | null>(null);
    /**
     * Each open Shell's tmux session, so a reload reattaches to it (LAYOUT-005), and how it started: in a
     * folder (FILE-005) or on Claude Code's sign-in.
     */
    const [shellStarts, setShellStarts] = useState<
        Partial<Record<ShellTab, ShellStart>>
    >({});
    const panesArea = useRef<HTMLElement>(null);

    // Put the cursor in the terminal whenever a Shell tab is shown; not when only the pane last used changes,
    // which would take focus from a menu opened in that pane.
    const shownTabs = layout.panes.map((pane) => pane.active).join();

    useEffect(() => {
        if (panes.isShell(tab)) {
            shellFrames.current[tab]?.focus();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [shownTabs]);

    // Clicking into a pane's preview or Shell (iframes, whose clicks the page never sees) makes it the pane last used.
    useEffect(() => {
        const onBlur = () =>
            window.setTimeout(() => {
                const pane =
                    document.activeElement?.closest<HTMLElement>('[data-pane]')
                        ?.dataset.pane;

                if (pane) {
                    setLayout((current) =>
                        panes.focusPane(current, Number(pane)),
                    );
                }
            });

        window.addEventListener('blur', onBlur);

        return () => window.removeEventListener('blur', onBlur);
    }, []);
    const [consoleClears, setConsoleClears] = useState(0);
    const [filesOpen, setFilesOpen] = useState(false);
    const [hideHidden, setHideHidden] = useState(false);
    /** The files panel's name search, in the whole project ('') or a folder picked from its menu (FILE-005). */
    const [fileSearch, setFileSearch] = useState({ folder: '', query: '' });
    const fileSearchInput = useRef<HTMLInputElement>(null);
    /** The search inside files below it (FILE-008), in the same folder, and where the open file should show. */
    const [contentSearch, setContentSearch] =
        useState<ContentQuery>(EMPTY_CONTENT_QUERY);
    const contentSearchInput = useRef<HTMLInputElement>(null);
    const [contentFocus, setContentFocus] = useState(0);
    const [fileLocation, setFileLocation] = useState<FileLocation | null>(null);
    const [openPath, setOpenPath] = useState<string | null>(
        tab === 'file' ? initialView.file : null,
    );
    /** Cmd/Ctrl+P's "Go to file" palette, and the files opened lately, newest first (FILE-006). */
    const [quickOpen, setQuickOpen] = useState(false);
    const [recentFiles, setRecentFiles] = useState<string[]>([]);

    // The header's git menu asks for Tools → Git to connect a repository (GIT-006).
    useEffect(
        () =>
            onOpenWorkspaceTool((section) => {
                setLayout((current) => panes.showTab(current, 'tools'));
                setTool(section);
            }),
        [],
    );

    // Keep the address bar (and links to the project's other pages) on what's showing.
    useEffect(() => {
        setWorkspaceView(project.id, {
            tab: panes.isShell(tab) ? 'shell' : tab,
            tool,
            file: openPath,
        });
    }, [project.id, tab, tool, openPath]);
    const [file, setFile] = useState<WorkspaceFile | null>(null);
    const [fileError, setFileError] = useState<string | null>(null);
    const [fileDirty, setFileDirty] = useState(false);

    useEffect(() => {
        onOpenFileChange(
            openPath ? { path: openPath, dirty: fileDirty } : null,
        );
    }, [openPath, fileDirty, onOpenFileChange]);
    const files = useWorkspaceFiles(project.id);
    const filesVersion = useFilesVersion(project.id, running && filesOpen, {
        live,
        changes: filesChanges,
    });
    // The sandbox has a file watcher (FILE-004), so the tree reloads only when files come or go.
    const watchingFiles = filesVersion > 0;
    const seenFilesVersion = useRef(0);
    const [filesWidth, setFilesWidth] = useResizableWidth(
        FILES_WIDTH_KEY,
        FILES_WIDTH,
    );

    // Use the last choice saved in this browser, in either mode; otherwise open by default only where there's room
    // for chat, preview and files, and not in Simple mode (PRJ-013), whose switch closes it again. On a phone the
    // panel covers the workspace, so it always starts closed there. Decided after mount so server-rendered and
    // client HTML match.
    useEffect(() => {
        setHideHidden(localStorage.getItem(HIDE_HIDDEN_KEY) === 'true');
    }, []);

    useEffect(() => {
        const saved = localStorage.getItem(FILES_OPEN_KEY);

        if (isPhone()) {
            return;
        }

        if (saved !== null) {
            setFilesOpen(saved === 'true');
        } else if (
            !simple &&
            window.matchMedia('(min-width: 1440px)').matches
        ) {
            setFilesOpen(true);
        }
    }, [simple]);

    const toggleFiles = () => {
        if (!isPhone()) {
            localStorage.setItem(FILES_OPEN_KEY, String(!filesOpen));
        }

        setFilesOpen(!filesOpen);
    };

    const toggleHidden = () => {
        localStorage.setItem(HIDE_HIDDEN_KEY, String(!hideHidden));
        setHideHidden(!hideHidden);
    };

    const unhiddenEntries = hideHidden
        ? files.entries.filter(
              (entry) =>
                  !entry.path.split('/').some((part) => part.startsWith('.')),
          )
        : files.entries;
    const searching = fileSearch.query.trim() !== '';
    const visibleEntries = searching
        ? searchEntries(unhiddenEntries, fileSearch.folder, fileSearch.query)
        : unhiddenEntries;
    const searchingContent = contentSearch.query.trim() !== '';
    const contentResults = useContentSearch(
        project.id,
        contentSearch,
        fileSearch.folder,
        running && filesOpen && searchingContent,
        filesVersion,
    );

    // Cmd/Ctrl+Shift+F opens the files panel with the cursor in "Search in files" (FILE-008).
    useEffect(() => {
        if (contentFocus > 0 && filesOpen) {
            contentSearchInput.current?.focus();
            contentSearchInput.current?.select();
        }
    }, [contentFocus, filesOpen]);

    const [previewSize, setPreviewSize] = useState<PreviewSize>('desktop');

    useEffect(() => {
        const saved = localStorage.getItem(PREVIEW_SIZE_KEY);

        if (saved && saved in PREVIEW_SIZES) {
            setPreviewSize(saved as PreviewSize);
        }
    }, []);

    const choosePreviewSize = (size: PreviewSize) => {
        localStorage.setItem(PREVIEW_SIZE_KEY, size);
        setPreviewSize(size);
    };

    /** The app's page the preview is on, as the page reports it, and the one the preview (re)loads on. */
    const [previewPage, setPreviewPage] = useState<string | null>(null);
    const [previewStart, setPreviewStart] = useState<string | null>(null);

    /** Whether the preview's page can go back or forward, as it reports it. */
    const [previewHistory, setPreviewHistory] = useState({
        back: false,
        forward: false,
    });

    /** Load the preview on one of the app's pages, like typing an address in a browser. */
    const openPreviewPage = (page: string | null) => {
        previewErrors.clear();
        setPreviewStart(page);
        setPreviewLoading(true);
        setReloadKey((key) => key + 1);
    };

    // Like a browser's reload, the preview stays on the app's page it was on.
    const reloadPreview = () => openPreviewPage(previewPage);

    /** Back or forward in the preview's own history; the page does it, so the workspace page never moves. */
    const goInPreview = (delta: -1 | 1) =>
        previewFrame.current?.contentWindow?.postMessage(
            { onedrop: 'go', delta },
            '*',
        );

    // Reload once the agent finishes so a newly started dev server shows up.
    if (wasWorking !== working) {
        setWasWorking(working);

        if (!working) {
            reloadPreview();
        }
    }

    // Coming back to a sandbox that was asleep: its dev server's connection to the page was cut.
    const [seenWakes, setSeenWakes] = useState(wakes);

    if (seenWakes !== wakes) {
        setSeenWakes(wakes);
        reloadPreview();
    }

    // Annotate (AGT-013): a picture of the preview to draw on, then into the chat box with what the marks point at.
    const [annotation, setAnnotation] = useState<PreviewCapture | null>(null);
    const [capturing, setCapturing] = useState(false);
    // Which picture failed: the page's own (the tab can be shared instead), the shared tab's, or Inspect's.
    const [annotateError, setAnnotateError] = useState<
        'page' | 'tab' | 'inspect' | null
    >(null);

    // Inspect (AGT-014): pick and move elements in the live preview, then into the chat box with a picture of it.
    const [inspecting, setInspecting] = useState(false);
    const [inspectNotes, setInspectNotes] = useState<Record<number, string>>(
        {},
    );
    const [inspectBusy, setInspectBusy] = useState(false);
    const inspectItems = usePreviewInspector(previewFrame, inspecting, () =>
        setInspecting(false),
    );

    const toggleInspecting = () => {
        setInspectNotes({});
        setAnnotateError(null);
        setInspecting(!inspecting);
    };

    const addInspection = async () => {
        const frame = previewFrame.current;

        if (!frame || inspectBusy) {
            return;
        }

        setInspectBusy(true);

        try {
            const rects = await inspectorRects(frame);
            const capture = await capturePreview(frame);
            const image = await inspectionImage(capture, inspectItems, rects);

            if (!image) {
                throw new Error("Couldn't make the picture.");
            }

            const context = inspectionText(
                capture,
                inspectItems,
                inspectNotes,
                image.name,
            );

            setInspecting(false);

            onShowChat();

            // Only the picture shows in the chat; what was picked and moved goes to the agent with it.
            askAgent('', [{ file: image, context }]);
        } catch {
            setAnnotateError('inspect');
        } finally {
            setInspectBusy(false);
        }
    };

    const annotate = async (fromTab = false) => {
        const frame = previewFrame.current;

        if (!frame || capturing) {
            return;
        }

        setInspecting(false);

        setCapturing(true);
        setAnnotateError(null);

        try {
            setAnnotation(
                await (fromTab ? captureTab(frame) : capturePreview(frame)),
            );
        } catch {
            setAnnotateError(fromTab ? 'tab' : 'page');
        } finally {
            setCapturing(false);
        }
    };

    const addAnnotation = async (
        image: File,
        marks: Parameters<
            React.ComponentProps<typeof PreviewAnnotator>['onDone']
        >[1],
    ) => {
        const capture = annotation;
        const elements = previewFrame.current
            ? await inspectPreview(
                  previewFrame.current,
                  marks.map((mark) => mark.region),
              )
            : [];

        setAnnotation(null);

        if (!capture) {
            return;
        }

        onShowChat();

        // Only the picture shows in the chat; what the marks point at goes to the agent with it.
        askAgent('', [
            {
                file: image,
                context: annotationText(capture, marks, elements, image.name),
            },
        ]);
    };

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

    const openFile = (path: string, location: FileLocation | null = null) => {
        if (
            path !== openPath &&
            fileDirty &&
            !window.confirm('Discard unsaved changes?')
        ) {
            return;
        }

        setRecentFiles((recent) => [
            path,
            ...recent.filter((other) => other !== path).slice(0, 19),
        ]);

        if (path !== openPath) {
            setFile(null);
            setFileError(null);
        }

        setOpenPath(path);
        setFileLocation(location);
        showTab('file');

        // On a phone the panel covers the workspace: get it out of the way of the file.
        if (isPhone()) {
            setFilesOpen(false);
        }
    };

    // Cmd/Ctrl+P opens "Go to file" from anywhere in the workspace, even the editor (FILE-006).
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (
                (event.metaKey || event.ctrlKey) &&
                !event.shiftKey &&
                !event.altKey &&
                event.key.toLowerCase() === 'p'
            ) {
                event.preventDefault();
                event.stopPropagation();
                setQuickOpen(true);
            }

            if (
                (event.metaKey || event.ctrlKey) &&
                event.shiftKey &&
                !event.altKey &&
                event.key.toLowerCase() === 'f'
            ) {
                event.preventDefault();
                event.stopPropagation();
                setFilesOpen(true);
                setContentFocus((count) => count + 1);
            }
        };

        // A Shell (another origin) sends its Cmd/Ctrl+P here instead (docker/sandbox/shell-keys.js).
        const onMessage = (event: MessageEvent) => {
            if (
                (event.data as { onedrop?: string })?.onedrop ===
                    'quick-open' &&
                Object.values(shellFrames.current).some(
                    (frame) => frame?.contentWindow === event.source,
                )
            ) {
                setQuickOpen(true);
            }
        };

        window.addEventListener('keydown', onKeyDown, true);
        window.addEventListener('message', onMessage);

        return () => {
            window.removeEventListener('keydown', onKeyDown, true);
            window.removeEventListener('message', onMessage);
        };
    }, []);

    // The tree may be stale or not loaded when the Files panel is closed.
    useEffect(() => {
        if (quickOpen && running) {
            void files.refresh();
        }
    }, [quickOpen, running, files.refresh]);

    /** Open a new Shell (LAYOUT-002), in `paneId` or the pane last used, or in a new pane split off `split.paneId`. */
    const openShell = ({
        folder = null,
        claudeLogin = false,
        paneId,
        split,
    }: {
        folder?: string | null;
        claudeLogin?: boolean;
        paneId?: number;
        split?: { paneId: number; direction: Layout['direction'] };
    } = {}) => {
        const shell = panes.nextShellTab(layout);

        setShellStarts((starts) => ({
            ...starts,
            [shell]: { session: newShellSession(), folder, claudeLogin },
        }));
        setLayout((current) =>
            split
                ? panes.splitPane(current, split.paneId, split.direction, shell)
                : panes.showTab(current, shell, paneId),
        );
    };

    /** A tab from the "+" menu: a new Shell every time, the others where they already are. */
    const addTab = (kind: ToolTab, paneId?: number) =>
        kind === 'shell' ? openShell({ paneId }) : showTab(kind, paneId);

    /** The test page the user took over at a step, shown in the Browser tab (TEST-005). */
    const [browserSession, setBrowserSession] = useState<BrowserSession | null>(
        null,
    );
    const showBrowser = useCallback((session: BrowserSession) => {
        setBrowserSession(session);
        setLayout((current) => panes.showTab(current, 'browser'));
    }, []);

    /** The requirement the Tests tab was last opened on from the Requirements tab (TEST-003). */
    const [testsFocus, setTestsFocus] = useState<string | null>(null);
    const showRequirementTests = useCallback((requirement: string) => {
        setTestsFocus(requirement);
        setLayout((current) => panes.showTab(current, 'tests'));
    }, []);

    const openShellIn = (folder: string) => openShell({ folder });

    useEffect(() => {
        const onMessage = (event: MessageEvent) => {
            const data = event.data as {
                onedrop?: string;
                page?: unknown;
                back?: unknown;
                forward?: unknown;
            };

            if (
                event.source === previewFrame.current?.contentWindow &&
                data?.onedrop === 'location' &&
                isPagePath(data.page)
            ) {
                setPreviewPage(data.page);
                setPreviewHistory({
                    back: data.back === true,
                    forward: data.forward === true,
                });
            }
        };

        window.addEventListener('message', onMessage);

        return () => window.removeEventListener('message', onMessage);
    }, []);

    // Reloading the page comes back to the workspace as it was in this browser tab (LAYOUT-005): read before the
    // first paint (not in the server's HTML, so it matches), then saved on every change. A tab the URL asks for
    // (a link from another of the project's pages) is shown on top of it.
    const [restored, setRestored] = useState(false);

    useLayoutEffect(() => {
        const saved = loadWorkspace(project.id);
        const savedLayout = panes.restoreLayout(saved?.layout);
        let next = savedLayout ?? layout;
        const asked = panes.focusedTab(layout);
        const savedFocus = panes.focusedTab(next);
        const shownFromUrl = initialView.tab !== null || initialView.tool;

        if (
            savedLayout &&
            shownFromUrl &&
            (panes.isShell(asked)
                ? !panes.isShell(savedFocus)
                : asked !== savedFocus)
        ) {
            next = panes.isShell(asked)
                ? panes.showTab(next, panes.nextShellTab(next))
                : panes.showTab(next, asked);
        }

        const path =
            initialView.file ??
            (typeof saved?.openPath === 'string' ? saved.openPath : null);

        if (!path) {
            next = panes.closeTab(next, 'file');
        }

        const browser = saved?.browserSession;
        const keptBrowser =
            browser &&
            typeof browser.url === 'string' &&
            typeof browser.test === 'string'
                ? browser
                : null;

        if (!keptBrowser) {
            next = panes.closeTab(next, 'browser');
        }

        const savedShells = saved?.shells ?? {};
        const starts: Partial<Record<ShellTab, ShellStart>> = {};

        next.panes
            .flatMap((pane) => pane.tabs)
            .filter(panes.isShell)
            .forEach((shell) => {
                const kept = savedShells[shell];

                starts[shell] = {
                    session:
                        typeof kept?.session === 'string'
                            ? kept.session
                            : newShellSession(),
                    folder:
                        typeof kept?.folder === 'string' ? kept.folder : null,
                    claudeLogin: false,
                };
            });

        setLayout(next);
        setShellStarts(starts);
        setOpenPath(path);
        setBrowserSession(keptBrowser);

        if (!initialView.tool && typeof saved?.tool === 'string') {
            setTool(saved.tool);
        }

        if (Array.isArray(saved?.recentFiles)) {
            setRecentFiles(
                saved.recentFiles
                    .filter((file) => typeof file === 'string')
                    .slice(0, 20),
            );
        }

        if (
            typeof saved?.fileSearch?.folder === 'string' &&
            typeof saved.fileSearch.query === 'string'
        ) {
            setFileSearch(saved.fileSearch);
        }

        if (typeof saved?.contentSearch?.query === 'string') {
            setContentSearch({
                ...EMPTY_CONTENT_QUERY,
                ...saved.contentSearch,
            });
        }

        if (typeof saved?.testsFocus === 'string') {
            setTestsFocus(saved.testsFocus);
        }

        if (isPagePath(saved?.previewPage)) {
            setPreviewPage(saved.previewPage);
            setPreviewStart(saved.previewPage);
        }

        setRestored(true);
        // Once, on opening the page.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (!restored) {
            return;
        }

        saveWorkspace(project.id, {
            layout,
            shells: shellStarts,
            openPath,
            tool,
            recentFiles,
            fileSearch,
            contentSearch,
            testsFocus,
            browserSession,
            previewPage,
        });
    }, [
        restored,
        project.id,
        layout,
        shellStarts,
        openPath,
        tool,
        recentFiles,
        fileSearch,
        contentSearch,
        testsFocus,
        browserSession,
        previewPage,
    ]);

    // The open file (or a folder it's in) was renamed: keep it open under its new name.
    const followRename = (from: string, to: string) => {
        if (openPath && isWithin(openPath, from)) {
            setOpenPath(to + openPath.slice(from.length));
        }
    };

    // The open file (or a folder it's in) was deleted: close it.
    const followDelete = (path: string) => {
        if (openPath && isWithin(openPath, path)) {
            setFileDirty(false);
            setOpenPath(null);
            closeTab('file');
        }
    };

    // "Sign in to Claude" opens a new Shell on Claude Code's own sign-in (AI-005).
    const [seenClaudeSignIns, setSeenClaudeSignIns] = useState(claudeSignIns);

    if (seenClaudeSignIns !== claudeSignIns) {
        setSeenClaudeSignIns(claudeSignIns);
        openShell({ claudeLogin: true });
    }

    const [seenClaudeSignInsDone, setSeenClaudeSignInsDone] =
        useState(claudeSignInsDone);

    if (seenClaudeSignInsDone !== claudeSignInsDone) {
        setSeenClaudeSignInsDone(claudeSignInsDone);
        showTab('preview');
    }

    const closeTab = (kind: PaneTab) => {
        setLayout((current) => panes.closeTab(current, kind));

        // Closing the Browser tab closes the sandbox's browser too.
        if (kind === 'browser' && browserSession) {
            void closeBrowser(project.id);
            setBrowserSession(null);
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
     * Drag props for a tab in pane `paneId`. Within a pane, the dragged tab takes a neighbour's place once
     * the pointer passes that neighbour's middle, or when it's dropped on it; dropped on a tab in another
     * pane, it moves there, before or after that tab by which half it's dropped on (LAYOUT-002).
     */
    const draggableTab = (kind: PaneTab, paneId: number) => {
        const moveOnto = (
            event: React.DragEvent<HTMLElement>,
            dropped: boolean,
        ) => {
            if (!draggedTab) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            if (draggedTab === kind) {
                return;
            }

            const rect = event.currentTarget.getBoundingClientRect();
            const after = event.clientX >= rect.left + rect.width / 2;
            const from = panes.paneOf(layout, draggedTab);

            if (from?.id === paneId) {
                const forward =
                    from.tabs.indexOf(draggedTab) < from.tabs.indexOf(kind);

                if (forward === after || dropped) {
                    setLayout((current) =>
                        panes.moveTab(
                            current,
                            draggedTab,
                            paneId,
                            kind,
                            forward,
                        ),
                    );
                }
            } else if (dropped) {
                setLayout((current) =>
                    panes.moveTab(current, draggedTab, paneId, kind, after),
                );
            }
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

    /** Drop props for a pane's tab bar: a tab dropped past the tabs goes to the end of that pane. */
    const tabBarDrop = (paneId: number) => ({
        onDragOver: (event: React.DragEvent) =>
            draggedTab && event.preventDefault(),
        onDrop: (event: React.DragEvent) => {
            if (draggedTab) {
                event.preventDefault();
                setLayout((current) =>
                    panes.moveTab(current, draggedTab, paneId, null, false),
                );
            }
        },
    });

    const statusText = sandbox?.updating
        ? 'Updating sandbox…'
        : {
              creating: copy ? 'Copying the app…' : 'Starting sandbox…',
              running: url ?? 'Running',
              paused: 'Paused',
              failed: 'Sandbox failed to start',
          }[sandbox?.status ?? 'creating'];

    // A running preview shows its address in the bar above it, with the preview's tools; a phone has no room for the address (LAYOUT-006).
    const statusIsUrl =
        sandbox?.status === 'running' && !sandbox.updating && !!url;

    const statusFor = (kind: PaneTab): string | null => {
        if (panes.isShell(kind)) {
            const folder = shellStarts[kind]?.folder;

            return folder ? `~/workspace/${folder}` : '~/workspace';
        }

        return {
            // A running preview's address has its own bar above the page; a computer's desktop says how it's doing.
            preview: statusIsUrl || computer ? '' : statusText,
            tools: '',
            file: openPath,
            console: '',
            services: '',
            requirements: '',
            tests: '',
            browser: '',
        }[kind];
    };

    const shellSrc = (start: ShellStart, sandbox: SandboxState): string =>
        shellUrlWith(
            start.claudeLogin && sandbox.claude_login_url
                ? sandbox.claude_login_url
                : sandbox.shell_url!,
            sandbox.shell_via_gateway,
            [
                ...(start.folder ? ['cd', start.folder] : []),
                'session',
                start.session,
            ],
        );

    // Panes sit in one grid, side by side or stacked, with a 1px line between each. Every tab's content is a
    // child of the grid in a fixed order, placed in its pane's cell, so moving a tab to another pane never
    // moves it in the page: a Shell keeps its session and the preview doesn't reload.
    const rowLayout = layout.direction === 'row';
    const gridStyle: CSSProperties = rowLayout
        ? {
              gridTemplateColumns: layout.sizes
                  .map((size) => `minmax(0, ${size}fr)`)
                  .join(' 1px '),
              gridTemplateRows: 'auto minmax(0, 1fr)',
          }
        : {
              gridTemplateColumns: 'minmax(0, 1fr)',
              gridTemplateRows: layout.sizes
                  .map((size) => `auto minmax(0, ${size}fr)`)
                  .join(' 1px '),
          };
    const cell = (index: number, part: 'bar' | 'content'): CSSProperties =>
        rowLayout
            ? { gridColumn: 2 * index + 1, gridRow: part === 'bar' ? 1 : 2 }
            : { gridColumn: 1, gridRow: 3 * index + (part === 'bar' ? 1 : 2) };
    const paneIndex = (kind: PaneTab) =>
        layout.panes.findIndex((pane) => pane.tabs.includes(kind));
    const shown = (kind: PaneTab) => panes.isShown(layout, kind);

    /** The cell a tab's content goes in: its pane's, and only visible while it's the tab showing there. */
    const content = (kind: PaneTab, children: React.ReactNode) => {
        const index = paneIndex(kind);

        return (
            index !== -1 && (
                <div
                    key={kind}
                    data-pane={layout.panes[index].id}
                    style={cell(index, 'content')}
                    onPointerDownCapture={() =>
                        setLayout((current) =>
                            panes.focusPane(current, layout.panes[index].id),
                        )
                    }
                    className={cn(
                        'flex min-h-0 min-w-0 flex-col',
                        !shown(kind) && 'hidden',
                    )}
                >
                    {children}
                </div>
            )
        );
    };

    const shells = layout.panes
        .flatMap((pane) => pane.tabs)
        .filter(panes.isShell)
        .sort(
            (a, b) =>
                Number(a.split('-')[1] ?? 1) - Number(b.split('-')[1] ?? 1),
        );
    const shellReady = running && !!sandbox.shell_url && !shellUnreachable;
    const filesTogglePane = rowLayout ? layout.panes.length - 1 : 0;

    const tabButton = (kind: PaneTab, paneId: number) => {
        const active = panes.paneOf(layout, kind)?.active === kind;

        if (kind === 'tools' && computer) {
            return null;
        }

        if (kind === 'tools' || kind === 'preview') {
            return (
                <TabButton
                    key={kind}
                    active={active}
                    onClick={() => showTab(kind)}
                    testId={`tab-${kind}`}
                    {...draggableTab(kind, paneId)}
                >
                    {kind === 'tools' ? (
                        <Wrench className="size-4" />
                    ) : (
                        <Monitor className="size-4" />
                    )}
                    {kind === 'tools'
                        ? 'Tools'
                        : computer
                          ? 'Desktop'
                          : 'Preview'}
                </TabButton>
            );
        }

        if (kind === 'file') {
            return (
                openPath && (
                    <TabButton
                        key={kind}
                        active={active}
                        onClick={() => showTab('file')}
                        onClose={closeFile}
                        testId="tab-file"
                        {...draggableTab(kind, paneId)}
                    >
                        <FileIcon name={openPath.split('/').pop() ?? ''} />
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
            );
        }

        return (
            <TabButton
                key={kind}
                active={active}
                onClick={() => {
                    showTab(kind);

                    if (panes.isShell(kind)) {
                        shellFrames.current[kind]?.focus();
                    }
                }}
                onClose={() => closeTab(kind)}
                testId={`tab-${kind}`}
                {...draggableTab(kind, paneId)}
            >
                {panes.isShell(kind) ? (
                    <>
                        {TOOL_TABS.shell.icon}
                        {panes.shellLabel(kind)}
                    </>
                ) : (
                    <>
                        {TOOL_TABS[kind].icon}
                        {TOOL_TABS[kind].label}
                    </>
                )}
            </TabButton>
        );
    };

    /** A pane's tabs for the phone's tab switcher (LAYOUT-007). */
    const switcherTabs = (pane: Layout['panes'][number]) =>
        pane.tabs
            .filter((kind) => kind !== 'file' || openPath)
            .filter((kind) => kind !== 'tools' || !computer)
            .map((kind) => {
                const name = openPath?.split('/').pop() ?? '';

                return {
                    id: kind,
                    label:
                        kind === 'tools'
                            ? 'Tools'
                            : kind === 'preview'
                              ? computer
                                  ? 'Desktop'
                                  : 'Preview'
                              : kind === 'file'
                                ? name
                                : panes.isShell(kind)
                                  ? panes.shellLabel(kind)
                                  : TOOL_TABS[kind].label,
                    icon:
                        kind === 'tools' ? (
                            <Wrench className="size-4" />
                        ) : kind === 'preview' ? (
                            <Monitor className="size-4" />
                        ) : kind === 'file' ? (
                            <FileIcon name={name} />
                        ) : panes.isShell(kind) ? (
                            TOOL_TABS.shell.icon
                        ) : (
                            TOOL_TABS[kind].icon
                        ),
                    active: pane.active === kind,
                    dirty: kind === 'file' && fileDirty,
                    onSelect: () => showTab(kind),
                    onClose:
                        kind === 'tools' || kind === 'preview'
                            ? undefined
                            : kind === 'file'
                              ? closeFile
                              : () => closeTab(kind),
                };
            });

    return (
        <div
            className={cn(
                'relative flex min-h-0 min-w-0 flex-1',
                mobileHidden && 'max-lg:hidden',
            )}
        >
            <section
                ref={panesArea}
                aria-label="Preview"
                className="grid min-w-0 flex-1"
                style={gridStyle}
            >
                {layout.panes.map((pane, index) => (
                    <Fragment key={pane.id}>
                        {index > 0 && (
                            <PaneDivider
                                direction={layout.direction}
                                style={
                                    rowLayout
                                        ? {
                                              gridColumn: 2 * index,
                                              gridRow: '1 / span 2',
                                          }
                                        : { gridColumn: 1, gridRow: 3 * index }
                                }
                                area={panesArea}
                                onResize={(delta) =>
                                    setLayout((current) =>
                                        panes.resizePanes(
                                            current,
                                            index - 1,
                                            delta,
                                        ),
                                    )
                                }
                                onReset={() =>
                                    setLayout((current) =>
                                        panes.equalPanes(current),
                                    )
                                }
                            />
                        )}
                        <div
                            style={cell(index, 'bar')}
                            data-pane={pane.id}
                            data-test={`pane-${index + 1}`}
                            onPointerDownCapture={() =>
                                setLayout((current) =>
                                    panes.focusPane(current, pane.id),
                                )
                            }
                            {...tabBarDrop(pane.id)}
                            className={cn(
                                'flex min-w-0 [scrollbar-width:none] items-center gap-1 overflow-x-auto border-b border-sidebar-border/70 bg-toolbar px-2 py-1.5 text-sm dark:border-sidebar-border',
                                !rowLayout && index > 0 && 'border-t-0',
                            )}
                        >
                            {index === 0 && (
                                <IconButton
                                    label={chatOpen ? 'Hide chat' : 'Show chat'}
                                    onClick={onToggleChat}
                                    testId="toggle-chat"
                                    className="max-lg:hidden"
                                >
                                    <PanelLeft className="size-4" />
                                </IconButton>
                            )}
                            <MobileTabSwitcher
                                className="md:hidden"
                                tabs={switcherTabs(pane)}
                                newTabs={toolTabs
                                    .filter(
                                        (kind) =>
                                            kind === 'shell' ||
                                            !panes.paneOf(layout, kind),
                                    )
                                    .map((kind) => ({
                                        id: kind,
                                        label: TOOL_TABS[kind].label,
                                        icon: TOOL_TABS[kind].icon,
                                        onSelect: () => addTab(kind, pane.id),
                                    }))}
                            />
                            <div className="flex min-w-0 items-center gap-1 max-md:hidden">
                                {/* The tabs scroll when they don't fit, so the status and the tab's buttons stay in view. */}
                                <div className="flex min-w-0 [scrollbar-width:none] items-center gap-1 overflow-x-auto">
                                    {pane.tabs.map((kind) =>
                                        tabButton(kind, pane.id),
                                    )}
                                </div>
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
                                        onFocusOutside={(event) =>
                                            event.preventDefault()
                                        }
                                        onCloseAutoFocus={(event) => {
                                            // The menu hands focus back to "+" as it closes; keep it in the shell instead.
                                            if (panes.isShell(tab)) {
                                                event.preventDefault();
                                                shellFrames.current[
                                                    tab
                                                ]?.focus();
                                            }
                                        }}
                                    >
                                        {toolTabs.map((kind) => (
                                            <DropdownMenuItem
                                                key={kind}
                                                onSelect={() =>
                                                    addTab(kind, pane.id)
                                                }
                                                data-test={`add-tab-${kind}`}
                                            >
                                                {TOOL_TABS[kind].icon}
                                                {TOOL_TABS[kind].label}
                                            </DropdownMenuItem>
                                        ))}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                            <span
                                className="ml-2 min-w-0 flex-[1_1_8rem] truncate text-muted-foreground"
                                data-test="sandbox-status"
                            >
                                {statusFor(pane.active)}
                            </span>
                            {pane.active === 'console' && (
                                <IconButton
                                    label="Clear console"
                                    onClick={() =>
                                        setConsoleClears((n) => n + 1)
                                    }
                                >
                                    <Ban className="size-4" />
                                </IconButton>
                            )}
                            {layout.panes.length < panes.MAX_PANES && (
                                <DropdownMenu modal={false}>
                                    <DropdownMenuTrigger asChild>
                                        <button
                                            type="button"
                                            aria-label="Split pane"
                                            title="Split pane"
                                            data-test="split-menu"
                                            className="rounded p-1 hover:bg-muted max-md:hidden"
                                        >
                                            {rowLayout ? (
                                                <Columns2 className="size-4" />
                                            ) : (
                                                <Rows2 className="size-4" />
                                            )}
                                        </button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        onFocusOutside={(event) =>
                                            event.preventDefault()
                                        }
                                        onCloseAutoFocus={(event) => {
                                            if (panes.isShell(tab)) {
                                                event.preventDefault();
                                                shellFrames.current[
                                                    tab
                                                ]?.focus();
                                            }
                                        }}
                                    >
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                openShell({
                                                    split: {
                                                        paneId: pane.id,
                                                        direction: 'row',
                                                    },
                                                })
                                            }
                                            data-test="split-right"
                                        >
                                            <Columns2 />
                                            Split right
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                openShell({
                                                    split: {
                                                        paneId: pane.id,
                                                        direction: 'column',
                                                    },
                                                })
                                            }
                                            data-test="split-down"
                                        >
                                            <Rows2 />
                                            Split down
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            )}
                            {layout.panes.length > 1 && (
                                <IconButton
                                    label="Close pane (its tabs move to the pane next to it)"
                                    onClick={() =>
                                        setLayout((current) =>
                                            panes.closePane(current, pane.id),
                                        )
                                    }
                                    testId="close-pane"
                                >
                                    <X className="size-4" />
                                </IconButton>
                            )}
                            {index === filesTogglePane && (
                                <IconButton
                                    label={
                                        filesOpen ? 'Hide files' : 'Show files'
                                    }
                                    onClick={toggleFiles}
                                    testId="toggle-files"
                                    className="max-md:hidden"
                                >
                                    <PanelRight className="size-4" />
                                </IconButton>
                            )}
                            {((pane.active === 'preview' && url && !computer) ||
                                index === filesTogglePane) && (
                                <DropdownMenu modal={false}>
                                    <DropdownMenuTrigger asChild>
                                        <button
                                            type="button"
                                            aria-label="More"
                                            title="More"
                                            data-test="pane-more"
                                            className="rounded p-1 hover:bg-muted md:hidden"
                                        >
                                            <MoreHorizontal className="size-4" />
                                        </button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        {pane.active === 'preview' &&
                                            url &&
                                            !computer && (
                                                <DropdownMenuItem asChild>
                                                    <a
                                                        href={previewUrlAt(
                                                            url,
                                                            previewPage,
                                                            sandbox?.shell_via_gateway ??
                                                                false,
                                                        )}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                    >
                                                        <ExternalLink />
                                                        Open in a new tab
                                                    </a>
                                                </DropdownMenuItem>
                                            )}
                                        {pane.active === 'preview' &&
                                            url &&
                                            !computer && (
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        openPreviewWindow(
                                                            previewUrlAt(
                                                                url,
                                                                previewPage,
                                                                sandbox?.shell_via_gateway ??
                                                                    false,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <AppWindow />
                                                    Open in a new window
                                                </DropdownMenuItem>
                                            )}
                                        {index === filesTogglePane && (
                                            <DropdownMenuItem
                                                onSelect={toggleFiles}
                                                data-test="pane-more-files"
                                            >
                                                <PanelRight />
                                                {filesOpen
                                                    ? 'Hide files'
                                                    : 'Show files'}
                                            </DropdownMenuItem>
                                        )}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            )}
                        </div>
                    </Fragment>
                ))}

                {content(
                    'preview',
                    computer ? (
                        <DesktopView
                            organization={computer.organization}
                            sandbox={sandbox}
                            url={
                                running && !sandbox.updating && restored
                                    ? (sandbox.desktop_url ?? null)
                                    : null
                            }
                            wakes={wakes}
                        />
                    ) : url && restored ? (
                        <>
                            <div
                                className="relative flex min-w-0 items-center gap-1 border-b border-sidebar-border/70 bg-toolbar px-2 py-1.5 text-sm dark:border-sidebar-border"
                                data-test="preview-address-bar"
                            >
                                <IconButton
                                    label="Back"
                                    onClick={() => goInPreview(-1)}
                                    disabled={!previewHistory.back}
                                    testId="preview-back"
                                >
                                    <ArrowLeft className="size-4" />
                                </IconButton>
                                <IconButton
                                    label="Forward"
                                    onClick={() => goInPreview(1)}
                                    disabled={!previewHistory.forward}
                                    testId="preview-forward"
                                >
                                    <ArrowRight className="size-4" />
                                </IconButton>
                                <IconButton
                                    label="Reload preview"
                                    onClick={reloadPreview}
                                    testId="preview-reload"
                                >
                                    <RotateCw className="size-4" />
                                </IconButton>
                                <div className="mx-1 min-w-0 flex-1 max-md:invisible">
                                    {statusIsUrl && (
                                        <PreviewAddress
                                            address={previewAddress(
                                                url,
                                                previewPage,
                                            )}
                                            link={previewUrlAt(
                                                url,
                                                previewPage,
                                                sandbox?.shell_via_gateway ??
                                                    false,
                                            )}
                                            onGo={(address) => {
                                                const page = pageFromAddress(
                                                    address,
                                                    url,
                                                );

                                                if (page) {
                                                    openPreviewPage(page);
                                                }

                                                return page !== null;
                                            }}
                                        />
                                    )}
                                </div>
                                <div
                                    aria-hidden
                                    className="mx-1 h-4 w-px bg-border max-md:hidden"
                                />
                                <DropdownMenu modal={false}>
                                    <DropdownMenuTrigger asChild>
                                        <button
                                            type="button"
                                            aria-label="Preview size"
                                            title={`Preview size: ${PREVIEW_SIZES[previewSize].label}`}
                                            data-test="preview-size"
                                            className="rounded p-1 hover:bg-muted max-md:hidden"
                                        >
                                            {PREVIEW_SIZES[previewSize].icon}
                                        </button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        {(
                                            Object.keys(
                                                PREVIEW_SIZES,
                                            ) as PreviewSize[]
                                        ).map((size) => (
                                            <DropdownMenuItem
                                                key={size}
                                                onSelect={() =>
                                                    choosePreviewSize(size)
                                                }
                                                data-test={`preview-size-${size}`}
                                            >
                                                {PREVIEW_SIZES[size].icon}
                                                <span className="flex-1">
                                                    {PREVIEW_SIZES[size].label}
                                                </span>
                                                {PREVIEW_SIZES[size].width && (
                                                    <span className="text-xs text-muted-foreground">
                                                        {
                                                            PREVIEW_SIZES[size]
                                                                .width
                                                        }
                                                        px
                                                    </span>
                                                )}
                                                {size === previewSize && (
                                                    <Check className="size-4" />
                                                )}
                                            </DropdownMenuItem>
                                        ))}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                                <button
                                    type="button"
                                    aria-label="Inspect"
                                    title="Inspect"
                                    aria-pressed={inspecting}
                                    onClick={toggleInspecting}
                                    data-test="preview-inspect"
                                    className={cn(
                                        'rounded p-1 hover:bg-muted',
                                        inspecting &&
                                            'bg-muted text-foreground',
                                    )}
                                >
                                    <SquareMousePointer className="size-4" />
                                </button>
                                <IconButton
                                    label="Annotate"
                                    onClick={() => annotate()}
                                    testId="preview-annotate"
                                >
                                    {capturing ? (
                                        <LoaderCircle className="size-4 animate-spin" />
                                    ) : (
                                        <Brush className="size-4" />
                                    )}
                                </IconButton>
                                {previewLoading && (
                                    <div
                                        aria-hidden
                                        className="absolute inset-x-0 -bottom-px h-0.5 overflow-hidden"
                                        data-test="preview-loading"
                                    >
                                        <div className="h-full w-1/3 animate-preview-loading bg-primary/70" />
                                    </div>
                                )}
                            </div>
                            <div
                                className={cn(
                                    'relative flex flex-1 flex-col',
                                    PREVIEW_SIZES[previewSize].width &&
                                        'overflow-auto bg-muted p-4',
                                )}
                            >
                                <iframe
                                    ref={previewFrame}
                                    key={reloadKey}
                                    // A page that (re)loads has lost the inspector and what was picked (AGT-014).
                                    onLoad={() => {
                                        setInspecting(false);
                                        setPreviewLoading(false);
                                    }}
                                    src={previewUrlAt(
                                        url,
                                        previewStart,
                                        sandbox?.shell_via_gateway ?? false,
                                    )}
                                    title="App preview"
                                    data-test="preview-frame"
                                    style={{
                                        width:
                                            PREVIEW_SIZES[previewSize].width ??
                                            undefined,
                                    }}
                                    className={cn(
                                        'flex-1 bg-white',
                                        PREVIEW_SIZES[previewSize].width &&
                                            'mx-auto shrink-0 rounded-md border shadow-sm',
                                    )}
                                />
                                {!working &&
                                    previewErrors.errors.length > 0 && (
                                        <PreviewErrorBar
                                            errors={previewErrors.errors}
                                            onFix={fixPreviewErrors}
                                            onDismiss={previewErrors.clear}
                                        />
                                    )}
                                {annotateError && (
                                    <div
                                        className="absolute inset-x-3 top-3 z-10 flex items-center gap-2 rounded-md border bg-background px-3 py-2 text-sm shadow"
                                        data-test="annotate-error"
                                    >
                                        <span className="flex-1">
                                            {annotateError === 'page'
                                                ? "The preview couldn't take a picture of itself."
                                                : annotateError === 'inspect'
                                                  ? "The preview couldn't take a picture of itself, so nothing was added to the chat."
                                                  : "Couldn't take a picture of the preview."}
                                        </span>
                                        {annotateError === 'page' && (
                                            <button
                                                type="button"
                                                onClick={() => annotate(true)}
                                                className="font-medium underline-offset-4 hover:underline"
                                                data-test="annotate-share-tab"
                                            >
                                                Share the tab instead
                                            </button>
                                        )}
                                        <IconButton
                                            label="Dismiss"
                                            onClick={() =>
                                                setAnnotateError(null)
                                            }
                                        >
                                            <X className="size-4" />
                                        </IconButton>
                                    </div>
                                )}
                                {inspecting && (
                                    <PreviewInspectorPanel
                                        items={inspectItems}
                                        notes={inspectNotes}
                                        busy={inspectBusy}
                                        onNote={(item, note) =>
                                            setInspectNotes((current) => ({
                                                ...current,
                                                [item]: note,
                                            }))
                                        }
                                        onParent={(item) =>
                                            inspectorAction(
                                                previewFrame.current,
                                                'parent',
                                                item,
                                            )
                                        }
                                        onRemove={(item) =>
                                            inspectorAction(
                                                previewFrame.current,
                                                'remove',
                                                item,
                                            )
                                        }
                                        onCancel={() => setInspecting(false)}
                                        onDone={addInspection}
                                    />
                                )}
                                {annotation && (
                                    <PreviewAnnotator
                                        capture={annotation}
                                        onCancel={() => setAnnotation(null)}
                                        onDone={addAnnotation}
                                    />
                                )}
                            </div>
                        </>
                    ) : (
                        <PreviewPlaceholder sandbox={sandbox} copy={copy} />
                    ),
                )}
                {content(
                    'tools',
                    shown('tools') && (
                        <ToolsPanel
                            projectId={project.id}
                            running={running}
                            working={working}
                            publication={publication}
                            initialSection={tool}
                            onSectionChange={setTool}
                        />
                    ),
                )}
                {content(
                    'file',
                    shown('file') && openPath && (
                        <FileViewer
                            key={openPath}
                            projectId={project.id}
                            file={file}
                            error={fileError}
                            location={fileLocation}
                            onDirtyChange={setFileDirty}
                            mediaUrl={
                                running && sandbox.preview_url && !remote
                                    ? (page) =>
                                          previewUrlAt(
                                              sandbox.preview_url!,
                                              page,
                                              sandbox.shell_via_gateway,
                                          )
                                    : null
                            }
                        />
                    ),
                )}
                {content(
                    'console',
                    shown('console') && (
                        <ConsoleView
                            projectId={project.id}
                            active
                            running={running}
                            clearSignal={consoleClears}
                        />
                    ),
                )}
                {content(
                    'services',
                    shown('services') && (
                        <ServicesView
                            projectId={project.id}
                            active
                            running={running}
                        />
                    ),
                )}
                {content(
                    'requirements',
                    shown('requirements') && (
                        <RequirementsView
                            projectId={project.id}
                            running={running}
                            refreshSignal={`${activity}-${working}-${filesChanges}`}
                            onShowTests={showRequirementTests}
                        />
                    ),
                )}
                {content(
                    'tests',
                    shown('tests') && (
                        <TestsView
                            projectId={project.id}
                            running={running}
                            refreshSignal={`${activity}-${working}-${filesChanges}`}
                            focus={testsFocus}
                            runnerReachable={!remote}
                            onTakeOver={showBrowser}
                        />
                    ),
                )}
                {content(
                    'browser',
                    // Stays mounted while the tab is open, so its stream doesn't reconnect on every switch or move.
                    <BrowserView
                        projectId={project.id}
                        session={browserSession}
                        onClose={() => closeTab('browser')}
                    />,
                )}
                {shells.map((shell) =>
                    content(
                        shell,
                        shellReady && shellStarts[shell] ? (
                            // Stays mounted while the tab is open so the session survives tab switches and moves.
                            <iframe
                                ref={(frame) => {
                                    if (frame) {
                                        shellFrames.current[shell] = frame;
                                    } else {
                                        delete shellFrames.current[shell];
                                    }
                                }}
                                src={shellSrc(shellStarts[shell], sandbox)}
                                title={panes.shellLabel(shell)}
                                onLoad={() =>
                                    tab === shell &&
                                    shellFrames.current[shell]?.focus()
                                }
                                className="flex-1 bg-neutral-950"
                                data-test={
                                    shell === 'shell'
                                        ? 'shell-frame'
                                        : `shell-frame-${shell.slice('shell-'.length)}`
                                }
                            />
                        ) : (
                            <Notice>
                                {shellUnreachable
                                    ? 'The shell only works on the machine running this app builder.'
                                    : running
                                      ? "This sandbox doesn't have a shell. It was created before shells were added; recreate it to get one."
                                      : 'The shell starts when the sandbox is running.'}
                            </Notice>
                        ),
                    ),
                )}
            </section>

            <QuickOpen
                open={quickOpen}
                onOpenChange={setQuickOpen}
                entries={files.entries}
                recent={recentFiles}
                loading={files.loading}
                notice={
                    !running
                        ? 'Files appear once the sandbox is running.'
                        : files.error
                }
                onOpenFile={openFile}
            />
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
                    className="flex shrink-0 flex-col bg-background max-md:absolute max-md:inset-0 max-md:z-20 max-md:w-full!"
                    style={{ width: filesWidth }}
                    data-test="files-panel"
                >
                    <div className="flex flex-col gap-1.5 border-b border-sidebar-border/70 bg-toolbar px-2 py-2 text-sm dark:border-sidebar-border">
                        <div className="flex items-center gap-1">
                            <div className="flex h-8 min-w-0 flex-1 items-center gap-1.5 rounded-md border border-input bg-transparent px-2 focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50">
                                <Search className="size-3.5 shrink-0 text-muted-foreground" />
                                {fileSearch.folder && (
                                    <button
                                        type="button"
                                        title="Search the whole project"
                                        onClick={() => {
                                            setFileSearch({
                                                ...fileSearch,
                                                folder: '',
                                            });
                                            fileSearchInput.current?.focus();
                                        }}
                                        className="flex max-w-[50%] shrink-0 items-center gap-0.5 rounded bg-muted px-1 text-xs text-muted-foreground hover:text-foreground"
                                        data-test="files-search-folder"
                                    >
                                        <span className="truncate">
                                            {fileSearch.folder}
                                        </span>
                                        <X className="size-3 shrink-0" />
                                    </button>
                                )}
                                <input
                                    ref={fileSearchInput}
                                    value={fileSearch.query}
                                    disabled={!running}
                                    onChange={(event) =>
                                        setFileSearch({
                                            ...fileSearch,
                                            query: event.target.value,
                                        })
                                    }
                                    onKeyDown={(event) =>
                                        event.key === 'Escape' &&
                                        setFileSearch({ folder: '', query: '' })
                                    }
                                    placeholder="Search files"
                                    title="Filter the tree by name; ⌘/Ctrl+P goes to any file"
                                    aria-label={`Search file names in ${fileSearch.folder || 'the project'}`}
                                    className="min-w-0 flex-1 bg-transparent outline-none placeholder:text-muted-foreground"
                                    data-test="files-search"
                                />
                            </div>
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
                                onGoToFile={() => setQuickOpen(true)}
                            />
                            <IconButton
                                label="Close files"
                                onClick={toggleFiles}
                                testId="files-panel-close"
                                className="md:hidden"
                            >
                                <X className="size-4" />
                            </IconButton>
                        </div>
                        <div className="flex h-8 min-w-0 items-center gap-1 rounded-md border border-input bg-transparent pr-1 pl-2 focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50">
                            <TextSearch className="size-3.5 shrink-0 text-muted-foreground" />
                            <input
                                ref={contentSearchInput}
                                value={contentSearch.query}
                                disabled={!running}
                                onChange={(event) =>
                                    setContentSearch({
                                        ...contentSearch,
                                        query: event.target.value,
                                    })
                                }
                                onKeyDown={(event) =>
                                    event.key === 'Escape' &&
                                    setContentSearch({
                                        ...contentSearch,
                                        query: '',
                                    })
                                }
                                placeholder="Search in files"
                                title="Search inside files (⌘/Ctrl+Shift+F)"
                                aria-label={`Search inside files in ${fileSearch.folder || 'the project'}`}
                                className="min-w-0 flex-1 bg-transparent pl-0.5 outline-none placeholder:text-muted-foreground"
                                data-test="content-search"
                            />
                            <ContentSearchToggles
                                search={contentSearch}
                                onChange={(next) => {
                                    setContentSearch(next);
                                    contentSearchInput.current?.focus();
                                }}
                                disabled={!running}
                            />
                        </div>
                    </div>
                    <div className="flex-1 overflow-y-auto px-1">
                        {running && searchingContent ? (
                            <ContentSearchResults
                                {...contentResults}
                                onOpen={(path, match) =>
                                    openFile(path, {
                                        line: match.line,
                                        from: match.column[0],
                                        to: match.column[1],
                                        key: Date.now(),
                                    })
                                }
                            />
                        ) : !running ? (
                            <p className="p-3 text-sm text-muted-foreground">
                                Files appear once the sandbox is running.
                            </p>
                        ) : files.error ? (
                            <p className="p-3 text-sm text-red-600">
                                {files.error}
                            </p>
                        ) : visibleEntries.length === 0 ? (
                            <p className="p-3 text-sm text-muted-foreground">
                                {searching
                                    ? 'No file names match.'
                                    : files.loading
                                      ? 'Loading…'
                                      : files.entries.length > 0
                                        ? 'Only hidden files so far.'
                                        : 'No files yet. The agent will create them.'}
                            </p>
                        ) : (
                            <FileTree
                                projectId={project.id}
                                entries={visibleEntries}
                                selected={shown('file') ? openPath : null}
                                onSelect={openFile}
                                expandAll={searching}
                                onChanged={() => void files.refresh()}
                                onRenamed={followRename}
                                onDeleted={followDelete}
                                onSearch={(folder) => {
                                    setFileSearch({ ...fileSearch, folder });
                                    fileSearchInput.current?.focus();
                                }}
                                onOpenShell={
                                    sandbox?.shell_url && !shellUnreachable
                                        ? openShellIn
                                        : undefined
                                }
                            />
                        )}
                    </div>
                </aside>
            )}
        </div>
    );
}

type ShellStart = SavedShell & { claudeLogin: boolean };

/**
 * The Shell's address with more docker/sandbox/shell-entry arguments: start in a folder (`cd`; FILE-005),
 * in a tmux session (`session`; LAYOUT-005). Through the gateway, the shell's own address is in its `path`.
 */
function shellUrlWith(
    shellUrl: string,
    viaGateway: boolean,
    args: string[],
): string {
    const url = new URL(shellUrl, window.location.origin);
    const append = (params: URLSearchParams) =>
        args.forEach((arg) => params.append('arg', arg));

    if (viaGateway) {
        const path = new URL(
            url.searchParams.get('path') ?? '/',
            'http://shell',
        );
        append(path.searchParams);
        url.searchParams.set('path', `${path.pathname}${path.search}`);
    } else {
        append(url.searchParams);
    }

    return url.href;
}

/** Tabs the "+" menu adds. */
type ToolTab =
    | 'console'
    | 'services'
    | 'shell'
    | 'requirements'
    | 'tests'
    | 'browser';

/** Tabs the URL can ask for. */
const ACTIVE_TABS: PaneTab[] = [
    'tools',
    'preview',
    'console',
    'services',
    'shell',
    'requirements',
    'tests',
    'browser',
    'file',
];

/** What the page reloads when the project changes (LIVE-001). */
const LIVE_PROPS = [
    'project',
    'task',
    'agent',
    'sandbox',
    'messages',
    'queued',
    'publication',
    'sharing',
];

/** Below `md`, where the files panel covers the workspace instead of sitting beside it. */
function isPhone(): boolean {
    return !window.matchMedia('(min-width: 768px)').matches;
}

/** localStorage keys and limits for the chat and files panel widths, in px. */
const CHAT_WIDTH_KEY = 'onedrop.chat-width';
const CHAT_WIDTH = { initial: 448, min: 280, max: 960 };
const FILES_WIDTH_KEY = 'onedrop.files-width';
const FILES_WIDTH = { initial: 224, min: 160, max: 480 };

/** localStorage key for the size the preview was last shown at (LAYOUT-004). */
const PREVIEW_SIZE_KEY = 'onedrop.preview-size';

type PreviewSize = 'desktop' | 'tablet' | 'mobile';

/** Widths the preview can be shown at; desktop fills the pane (LAYOUT-004). */
const PREVIEW_SIZES: Record<
    PreviewSize,
    { label: string; width: number | null; icon: React.ReactNode }
> = {
    desktop: {
        label: 'Desktop',
        width: null,
        icon: <Monitor className="size-4" />,
    },
    tablet: {
        label: 'Tablet',
        width: 768,
        icon: <Tablet className="size-4" />,
    },
    mobile: {
        label: 'Mobile',
        width: 390,
        icon: <Smartphone className="size-4" />,
    },
};

/** localStorage key for whether dotfiles are hidden in the files panel. */
const HIDE_HIDDEN_KEY = 'onedrop.files-hide-hidden';

const TOOL_TABS: Record<ToolTab, { label: string; icon: React.ReactNode }> = {
    console: { label: 'Console', icon: <Terminal className="size-4" /> },
    services: { label: 'Services', icon: <Boxes className="size-4" /> },
    shell: { label: 'Shell', icon: <SquareTerminal className="size-4" /> },
    requirements: {
        label: 'Requirements',
        icon: <ClipboardList className="size-4" />,
    },
    tests: { label: 'Tests', icon: <FlaskConical className="size-4" /> },
    browser: { label: 'Browser', icon: <Globe className="size-4" /> },
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
                'flex shrink-0 items-center rounded-md whitespace-nowrap transition-colors',
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
            aria-label="Autofix"
            data-test="composer-autofix"
            className={cn(
                'flex h-7 shrink-0 items-center gap-1 rounded-full px-2 text-xs hover:bg-muted',
                project.autofix
                    ? 'bg-muted text-foreground'
                    : 'text-muted-foreground',
            )}
        >
            <Wrench className="size-3.5" />
            <span className="hidden @lg:inline">Autofix</span>
        </button>
    );
}

/** Pixels moved per arrow key press on a pane divider. */
const PANE_KEYBOARD_STEP = 16;

/**
 * The line between two panes (LAYOUT-002): dragging it moves space from one to the other, as a share of
 * the whole `area`; double-clicking makes every pane the same size.
 */
function PaneDivider({
    direction,
    area,
    style,
    onResize,
    onReset,
}: {
    direction: 'row' | 'column';
    area: React.RefObject<HTMLElement | null>;
    style: CSSProperties;
    /** Move the line by this share of the whole area. */
    onResize: (delta: number) => void;
    onReset: () => void;
}) {
    const last = useRef<number | null>(null);
    const [dragging, setDragging] = useState(false);
    const row = direction === 'row';
    const length = () => {
        const rect = area.current?.getBoundingClientRect();

        return (row ? rect?.width : rect?.height) || 1;
    };

    return (
        <div
            role="separator"
            aria-label="Resize panes"
            aria-orientation={row ? 'vertical' : 'horizontal'}
            tabIndex={0}
            data-test="pane-divider"
            style={style}
            onPointerDown={(event) => {
                event.preventDefault();
                event.currentTarget.setPointerCapture(event.pointerId);
                last.current = row ? event.clientX : event.clientY;
                setDragging(true);
            }}
            onPointerMove={(event) => {
                if (last.current !== null) {
                    const position = row ? event.clientX : event.clientY;
                    onResize((position - last.current) / length());
                    last.current = position;
                }
            }}
            onPointerUp={() => {
                last.current = null;
                setDragging(false);
            }}
            onPointerCancel={() => {
                last.current = null;
                setDragging(false);
            }}
            onDoubleClick={onReset}
            onKeyDown={(event) => {
                const step = (
                    row
                        ? { ArrowRight: 1, ArrowLeft: -1 }
                        : { ArrowDown: 1, ArrowUp: -1 }
                )[event.key as 'ArrowRight'];

                if (step) {
                    event.preventDefault();
                    onResize((step * PANE_KEYBOARD_STEP) / length());
                }
            }}
            className={cn(
                'group relative z-10 touch-none bg-sidebar-border/70 outline-none dark:bg-sidebar-border',
                row ? 'cursor-col-resize' : 'cursor-row-resize',
            )}
        >
            <span
                className={cn(
                    'absolute transition-colors group-hover:bg-primary/20 group-focus-visible:bg-primary/40',
                    row
                        ? 'inset-y-0 -right-1 -left-1'
                        : 'inset-x-0 -top-1 -bottom-1',
                    dragging && 'bg-primary/40',
                )}
            />
            {/* Keeps previews and Shells (iframes) from swallowing the drag. */}
            {dragging && (
                <span
                    className={cn(
                        'fixed inset-0 select-none',
                        row ? 'cursor-col-resize' : 'cursor-row-resize',
                    )}
                />
            )}
        </div>
    );
}

function IconButton({
    label,
    onClick,
    testId,
    className,
    disabled,
    children,
}: {
    label: string;
    onClick: () => void;
    testId?: string;
    className?: string;
    disabled?: boolean;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={label}
            title={label}
            data-test={testId}
            disabled={disabled}
            className={cn(
                'rounded p-1 hover:bg-muted disabled:pointer-events-none disabled:opacity-40',
                className,
            )}
        >
            {children}
        </button>
    );
}

/** Chat and Workspace as tabs on a phone or small tablet, instead of one above the other (LAYOUT-006). */
function MobileViewTabs({
    view,
    onChange,
    working,
}: {
    view: 'chat' | 'workspace';
    onChange: (view: 'chat' | 'workspace') => void;
    /** The agent is working: the Chat tab shows it while the workspace is on screen. */
    working: boolean;
}) {
    const tab = (
        kind: 'chat' | 'workspace',
        icon: React.ReactNode,
        label: string,
    ) => (
        <button
            type="button"
            role="tab"
            aria-selected={view === kind}
            onClick={() => onChange(kind)}
            data-test={`mobile-tab-${kind}`}
            className={cn(
                'flex flex-1 items-center justify-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors',
                view === kind && 'bg-background text-foreground shadow-sm',
            )}
        >
            {icon}
            {label}
            {kind === 'chat' && working && view !== 'chat' && (
                <span
                    className="size-1.5 animate-pulse rounded-full bg-primary"
                    aria-label="Agent is working"
                    data-test="mobile-chat-working"
                />
            )}
        </button>
    );

    return (
        <div
            role="tablist"
            aria-label="Chat or workspace"
            className="flex shrink-0 gap-1 border-b border-sidebar-border/70 p-2 lg:hidden dark:border-sidebar-border"
        >
            <div className="flex flex-1 gap-1 rounded-lg bg-muted p-1">
                {tab('chat', <MessageSquare className="size-4" />, 'Chat')}
                {tab('workspace', <Monitor className="size-4" />, 'Workspace')}
            </div>
        </div>
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

/**
 * Under a pull request's task's title (GIT-014): the pull request, its checks, Push and Pull, and whether the agent's
 * work is pushed after each turn and failed checks are fixed automatically (GIT-015).
 */
function PullRequestBar({
    projectId,
    task,
    pullRequest,
    working,
}: {
    projectId: number;
    task: TaskDetail;
    pullRequest: TaskPullRequest;
    working: boolean;
}) {
    const ids = { project: projectId, task: task.id };
    const busy = task.sync_status !== null || !task.has_copy;
    const post = (url: string) =>
        router.post(url, {}, { preserveScroll: true });
    const set =
        (field: 'pull_request_push' | 'pull_request_autofix') =>
        (value: boolean) =>
            router.patch(
                TaskController.update.url(ids),
                { [field]: value },
                { preserveScroll: true },
            );

    return (
        <div
            className="flex shrink-0 flex-col gap-1 border-b border-sidebar-border/70 px-4 py-1.5 text-xs text-muted-foreground dark:border-sidebar-border"
            data-test="task-pull-request-bar"
        >
            <div className="flex min-h-6 flex-wrap items-center gap-2">
                <GitPullRequest className="size-3.5 shrink-0" />
                <button
                    type="button"
                    onClick={() => openPullRequest(pullRequest.number)}
                    title="Open its page in Tools"
                    className="font-medium text-foreground hover:underline"
                    data-test="task-pull-request-open"
                >
                    #{pullRequest.number}
                </button>
                <span className="min-w-0 truncate">
                    <code>{pullRequest.branch}</code> into{' '}
                    <code>{pullRequest.base}</code>
                </span>
                <ChecksIcon state={pullRequest.checks} className="size-3.5" />
                <span
                    className="min-w-0 flex-1 truncate"
                    data-test="task-pull-request-status"
                >
                    {task.sync_status === 'forking'
                        ? 'Checking out…'
                        : task.sync_status === 'pushing'
                          ? 'Pushing…'
                          : task.sync_status === 'pulling'
                            ? 'Pulling…'
                            : pullRequest.checks === 'failure'
                              ? 'Checks failed'
                              : pullRequest.checks === 'pending'
                                ? 'Checks running'
                                : pullRequest.checks === 'success'
                                  ? 'Checks passed'
                                  : ''}
                </span>
                <button
                    type="button"
                    onClick={() =>
                        post(TaskController.pullPullRequest.url(ids))
                    }
                    disabled={busy || working}
                    title="Bring in commits pushed to the pull request since"
                    className="flex items-center gap-1 rounded px-1.5 py-0.5 hover:bg-muted disabled:opacity-50"
                    data-test="task-pull-request-pull"
                >
                    <RefreshCw
                        className={cn(
                            'size-3.5',
                            task.sync_status === 'pulling' && 'animate-spin',
                        )}
                    />
                    Pull
                </button>
                <button
                    type="button"
                    onClick={() =>
                        post(TaskController.pushPullRequest.url(ids))
                    }
                    disabled={busy || pullRequest.fork}
                    title={
                        pullRequest.fork
                            ? "It comes from a fork, which OneDrop can't push to"
                            : 'Commit what changed and push it to the pull request'
                    }
                    className="flex items-center gap-1 rounded bg-primary px-2 py-0.5 font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                    data-test="task-pull-request-push"
                >
                    <CloudUpload className="size-3.5" />
                    Push
                </button>
                {pullRequest.url && (
                    <a
                        href={pullRequest.url}
                        target="_blank"
                        rel="noreferrer"
                        title="Open on GitHub"
                        className="rounded p-0.5 hover:bg-muted"
                    >
                        <ExternalLink className="size-3.5" />
                    </a>
                )}
            </div>
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                {pullRequest.fork ? (
                    <span>
                        From a fork: it runs here, but can't be pushed to.
                    </span>
                ) : (
                    <label className="flex items-center gap-1.5">
                        <Checkbox
                            checked={pullRequest.push}
                            onCheckedChange={(value) =>
                                set('pull_request_push')(value === true)
                            }
                            data-test="task-pull-request-push-after-turn"
                        />
                        Push after each turn
                    </label>
                )}
                <label className="flex items-center gap-1.5">
                    <Checkbox
                        checked={pullRequest.autofix}
                        onCheckedChange={(value) =>
                            set('pull_request_autofix')(value === true)
                        }
                        data-test="task-pull-request-autofix"
                    />
                    Fix failing checks automatically
                </label>
            </div>
            {task.sync_error && (
                <span
                    className="text-red-600 dark:text-red-400"
                    data-test="task-copy-error"
                >
                    {task.sync_error}
                </span>
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

/**
 * The address of the preview's page, in the bar above it. It follows the page as the app moves around, and typing
 * one of the app's paths (or its full address) then Enter goes there; Esc puts it back. The copy button that shows
 * on hover copies a link that opens the same page, the arrow opens it in a new tab and the window button in a
 * full-size pop-up window; clicking or selecting the address never touches the clipboard. Until it is focused, the
 * path stands out and the host is dimmed.
 */
function PreviewAddress({
    address,
    link,
    onGo,
}: {
    address: string;
    link: string;
    /** Go to what was typed; false when it isn't one of the app's pages, so the typing stays to fix. */
    onGo: (address: string) => boolean;
}) {
    const [, copy] = useClipboard();
    const [copied, setCopied] = useState(false);
    const [draft, setDraft] = useState<string | null>(null);

    useEffect(() => {
        if (!copied) {
            return;
        }

        const timer = setTimeout(() => setCopied(false), 1500);

        return () => clearTimeout(timer);
    }, [copied]);

    const [focused, setFocused] = useState(false);
    const [, host, path] = address.match(/^(?:\w+:\/\/)?([^/?#]*)(.*)$/) ?? [
        '',
        address,
        '',
    ];

    return (
        <div className="group flex h-7 min-w-0 items-center gap-1.5 rounded-full border border-transparent bg-black/[0.04] pr-1 pl-2.5 focus-within:border-ring focus-within:bg-background dark:bg-black/30 dark:focus-within:bg-black/40">
            <Globe className="size-3.5 shrink-0 text-muted-foreground" />
            <div className="relative flex min-w-0 flex-1">
                <input
                    value={draft ?? address}
                    onChange={(event) => setDraft(event.target.value)}
                    onFocus={(event) => {
                        setFocused(true);
                        event.target.select();
                    }}
                    onBlur={() => {
                        setFocused(false);
                        setDraft(null);
                    }}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter' && draft !== null) {
                            if (onGo(draft)) {
                                setDraft(null);
                                event.currentTarget.blur();
                            }
                        } else if (event.key === 'Escape') {
                            setDraft(null);
                            event.currentTarget.blur();
                        }
                    }}
                    aria-label="Preview address"
                    spellCheck={false}
                    autoComplete="off"
                    className={cn(
                        'h-6 min-w-0 flex-1 truncate bg-transparent text-xs outline-none',
                        !focused && 'text-transparent caret-transparent',
                    )}
                    data-test="preview-address"
                />
                {!focused && (
                    // Like a browser's address bar: the page's path stands out, the host behind it is dimmed.
                    <span
                        aria-hidden
                        className="pointer-events-none absolute inset-0 flex items-center truncate text-xs"
                    >
                        <span className="text-muted-foreground">{host}</span>
                        <span className="truncate text-foreground">{path}</span>
                    </span>
                )}
            </div>
            <button
                type="button"
                onClick={() => void copy(link).then(setCopied)}
                aria-label={copied ? 'Copied' : 'Copy address'}
                title={copied ? 'Copied' : 'Copy address'}
                className={cn(
                    'shrink-0 rounded-full p-1 text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:opacity-100',
                    copied
                        ? 'opacity-100'
                        : 'opacity-0 transition-opacity group-hover:opacity-100',
                )}
                data-test="preview-address-copy"
            >
                {copied ? (
                    <Check className="size-3 text-emerald-500" />
                ) : (
                    <Copy className="size-3" />
                )}
            </button>
            <a
                href={link}
                target="_blank"
                rel="noreferrer"
                aria-label="Open preview in a new tab"
                title="Open preview in a new tab"
                className="shrink-0 rounded-full p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
            >
                <ExternalLink className="size-3.5" />
            </a>
            <button
                type="button"
                onClick={() => openPreviewWindow(link)}
                aria-label="Open preview in a new window"
                title="Open preview in a new window"
                className="shrink-0 rounded-full p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                data-test="preview-open-window"
            >
                <AppWindow className="size-3.5" />
            </button>
        </div>
    );
}

/** Open the preview in its own pop-up window (no tabs or toolbars) as big as the screen. */
function openPreviewWindow(link: string): void {
    const { availWidth, availHeight } = window.screen;

    window.open(
        link,
        '_blank',
        `popup,noopener,left=0,top=0,width=${availWidth},height=${availHeight}`,
    );
}
