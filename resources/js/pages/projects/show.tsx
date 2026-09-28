import { Head, router, setLayoutProps, usePoll } from '@inertiajs/react';
import {
    Ban,
    ExternalLink,
    Monitor,
    PanelRight,
    Plus,
    RefreshCw,
    RotateCw,
    SquareTerminal,
    Terminal,
    Wrench,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import ProjectAgentController from '@/actions/App/Http/Controllers/ProjectAgentController';
import ProjectMessageController from '@/actions/App/Http/Controllers/ProjectMessageController';
import AgentModelPicker from '@/components/agent-model-picker';
import HeaderActions from '@/components/header-actions';
import Markdown from '@/components/markdown';
import MessageAttachments from '@/components/message-attachments';
import type { AttachmentPreview } from '@/components/message-attachments';
import PromptComposer from '@/components/prompt-composer';
import { Skeleton } from '@/components/ui/skeleton';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import ConsoleView, { Notice } from '@/components/workspace/console-view';
import FileIcon from '@/components/workspace/file-icon';
import FileTree from '@/components/workspace/file-tree';
import FilesMenu from '@/components/workspace/files-menu';
import PublishMenu from '@/components/workspace/publish-menu';
import ToolsPanel from '@/components/workspace/tools-panel';
import FileViewer from '@/components/workspace/file-viewer';
import { isLocalHostname, useIsRemote } from '@/hooks/use-is-remote';
import {
    fetchWorkspaceFile,
    useWorkspaceFiles,
} from '@/hooks/use-workspace-files';
import { cn } from '@/lib/utils';
import { show } from '@/routes/projects';
import type {
    AgentSelection,
    ChatMessage,
    MessageAttachment,
    QueuedMessage,
    Project,
    Publication,
    SandboxState,
    WorkspaceFile,
} from '@/types';

export default function ShowProject({
    project,
    agent,
    sandbox,
    messages,
    queued,
    publication,
}: {
    project: Project;
    agent: AgentSelection | null;
    sandbox: SandboxState | null;
    messages: ChatMessage[];
    queued: QueuedMessage[];
    publication: Publication;
}) {
    const working = project.status === 'working';
    const busy =
        working ||
        sandbox?.status === 'creating' ||
        sandbox?.updating ||
        publication.status === 'publishing';

    setLayoutProps({
        breadcrumbs: [{ title: project.name, href: show(project.id) }],
    });
    const { start, stop } = usePoll(
        1000,
        {
            only: [
                'project',
                'agent',
                'sandbox',
                'messages',
                'queued',
                'publication',
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
            <Head title={project.name} />
            <HeaderActions>
                <PublishMenu projectId={project.id} publication={publication} />
            </HeaderActions>

            <div className="flex h-[calc(100svh-4rem)] min-h-0 flex-col md:h-[calc(100svh-5rem)] lg:flex-row">
                <ChatPanel
                    project={project}
                    agent={agent}
                    queued={queued}
                    messages={messages}
                    working={working}
                />
                <WorkspacePanel
                    project={project}
                    publication={publication}
                    sandbox={sandbox}
                    working={working}
                    activity={messages.length}
                />
            </div>
        </>
    );
}

function ChatPanel({
    project,
    agent,
    queued,
    messages,
    working,
}: {
    project: Project;
    agent: AgentSelection | null;
    queued: QueuedMessage[];
    messages: ChatMessage[];
    working: boolean;
}) {
    const bottom = useRef<HTMLDivElement>(null);
    const [draft, setDraft] = useState<string | undefined>(undefined);

    // Stop the agent; any queued messages come back into the box for editing.
    const stop = () =>
        router.post(
            ProjectAgentController.stop.url(project.id),
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
            className="flex min-h-0 flex-1 flex-col border-sidebar-border/70 lg:max-w-md lg:border-r dark:border-sidebar-border"
        >
            <div className="flex-1 space-y-5 overflow-y-auto p-4">
                {messages.map((message) => (
                    <MessageItem key={message.id} message={message} />
                ))}
                {working && (
                    <p
                        className="flex items-center gap-2 text-sm text-muted-foreground"
                        data-test="agent-working"
                    >
                        <span className="size-2 animate-pulse rounded-full bg-current" />
                        {messages.at(-1)?.role === 'user'
                            ? 'Thinking…'
                            : 'Working…'}
                    </p>
                )}
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
                                            ProjectMessageController.destroy.url(
                                                {
                                                    project: project.id,
                                                    message: message.id,
                                                },
                                            ),
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
                <PromptComposer
                    action={ProjectMessageController.store(project.id)}
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
                    placeholder={
                        working
                            ? 'Queue a message, or ⌘/Ctrl+Enter to send now…'
                            : 'Message the agent…'
                    }
                    footer={
                        agent && (
                            <AgentModelPicker
                                selection={agent}
                                onChange={(next) =>
                                    router.patch(
                                        ProjectAgentController.update.url(
                                            project.id,
                                        ),
                                        {
                                            agent_provider: next.provider,
                                            agent_model: next.model,
                                            agent_variant: next.variant,
                                        },
                                        { preserveScroll: true },
                                    )
                                }
                            />
                        )
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

function MessageItem({ message }: { message: ChatMessage }) {
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
                <span className="size-1.5 rounded-full bg-current" />
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
    publication,
    sandbox,
    working,
    activity,
}: {
    project: Project;
    publication: Publication;
    sandbox: SandboxState | null;
    working: boolean;
    /** Changes whenever the agent does something (message count). */
    activity: number;
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
    const [tab, setTab] = useState<ActiveTab>('preview');
    const [extraTabs, setExtraTabs] = useState<ToolTab[]>([]);
    const [consoleClears, setConsoleClears] = useState(0);
    const [filesOpen, setFilesOpen] = useState(false);
    const [hideHidden, setHideHidden] = useState(false);
    const [openPath, setOpenPath] = useState<string | null>(null);
    const [file, setFile] = useState<WorkspaceFile | null>(null);
    const [fileError, setFileError] = useState<string | null>(null);
    const [fileDirty, setFileDirty] = useState(false);
    const files = useWorkspaceFiles(project.id);

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

    // Reload once the agent finishes so a newly started dev server shows up.
    if (wasWorking !== working) {
        setWasWorking(working);

        if (!working) {
            setReloadKey((key) => key + 1);
        }
    }

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

    // Keep the tree (and the open file) current as the agent works.
    useEffect(() => {
        if (!running) {
            return;
        }

        if (filesOpen) {
            void files.refresh();
        }

        if (openPath) {
            loadFile(openPath);
        }
    }, [
        running,
        filesOpen,
        activity,
        working,
        openPath,
        files.refresh,
        loadFile,
    ]);

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
        setTab('file');
    };

    const addTab = (kind: ToolTab) => {
        setExtraTabs((tabs) => (tabs.includes(kind) ? tabs : [...tabs, kind]));
        setTab(kind);
    };

    const closeTab = (kind: ToolTab) => {
        setExtraTabs((tabs) => tabs.filter((t) => t !== kind));

        if (tab === kind) {
            setTab('preview');
        }
    };

    const statusText = sandbox?.updating
        ? 'Updating sandbox…'
        : {
              creating: 'Starting sandbox…',
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
                    {extraTabs.map((kind) => (
                        <TabButton
                            key={kind}
                            active={tab === kind}
                            onClick={() => setTab(kind)}
                            onClose={() => closeTab(kind)}
                            testId={`tab-${kind}`}
                        >
                            {TOOL_TABS[kind].icon}
                            {TOOL_TABS[kind].label}
                        </TabButton>
                    ))}
                    {openPath && (
                        <TabButton
                            active={tab === 'file'}
                            onClick={() => setTab('file')}
                            onClose={() => {
                                if (
                                    fileDirty &&
                                    !window.confirm('Discard unsaved changes?')
                                ) {
                                    return;
                                }

                                setOpenPath(null);
                                setTab('preview');
                            }}
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
                                onClick={() => setReloadKey((key) => key + 1)}
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
                    <iframe
                        key={reloadKey}
                        src={url}
                        title="App preview"
                        className={cn(
                            'flex-1 bg-white',
                            tab !== 'preview' && 'hidden',
                        )}
                    />
                ) : (
                    tab === 'preview' && (
                        <PreviewPlaceholder sandbox={sandbox} />
                    )
                )}
                {tab === 'tools' && (
                    <ToolsPanel
                        projectId={project.id}
                        running={running}
                        working={working}
                        publication={publication}
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
                            src={sandbox.shell_url}
                            title="Shell"
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
                <aside
                    aria-label="Files"
                    className="hidden w-56 shrink-0 flex-col border-l border-sidebar-border/70 md:flex dark:border-sidebar-border"
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
type ActiveTab = 'tools' | 'preview' | 'file' | ToolTab;

/** localStorage key for whether the files panel was last left open. */
const FILES_OPEN_KEY = 'zap.files-open';

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
    children,
}: {
    active: boolean;
    onClick: () => void;
    onClose?: () => void;
    testId?: string;
    children: React.ReactNode;
}) {
    return (
        <div
            className={cn(
                'flex items-center rounded-md transition-colors',
                active
                    ? 'bg-muted font-medium'
                    : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground',
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

function PreviewPlaceholder({ sandbox }: { sandbox: SandboxState | null }) {
    const failed = sandbox?.status === 'failed';

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
                    <p className="font-medium">
                        {failed
                            ? "Sandbox didn't start"
                            : sandbox?.updating
                              ? 'Updating sandbox…'
                              : 'Starting sandbox…'}
                    </p>
                    <p
                        className={cn(
                            'mt-1',
                            failed ? 'text-red-600' : 'text-muted-foreground',
                        )}
                        data-test="sandbox-message"
                    >
                        {failed
                            ? sandbox.error
                            : sandbox?.updating
                              ? 'Getting the latest tools. Your files are kept, and your app will be back in a moment.'
                              : 'Your app will appear here in a moment.'}
                    </p>
                </div>
            </div>
        </div>
    );
}
