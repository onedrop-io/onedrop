import { Head, setLayoutProps, usePoll } from '@inertiajs/react';
import {
    Ban,
    ExternalLink,
    FileText,
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
import ProjectMessageController from '@/actions/App/Http/Controllers/ProjectMessageController';
import HeaderActions from '@/components/header-actions';
import PromptComposer from '@/components/prompt-composer';
import { Skeleton } from '@/components/ui/skeleton';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import ConsoleView, { Notice } from '@/components/workspace/console-view';
import FileTree from '@/components/workspace/file-tree';
import PublishMenu from '@/components/workspace/publish-menu';
import ToolsPanel from '@/components/workspace/tools-panel';
import FileViewer from '@/components/workspace/file-viewer';
import { useIsRemote } from '@/hooks/use-is-remote';
import {
    fetchWorkspaceFile,
    useWorkspaceFiles,
} from '@/hooks/use-workspace-files';
import { cn } from '@/lib/utils';
import { show } from '@/routes/projects';
import type {
    ChatMessage,
    Project,
    Publication,
    SandboxState,
    WorkspaceFile,
} from '@/types';

export default function ShowProject({
    project,
    sandbox,
    messages,
    publication,
}: {
    project: Project;
    sandbox: SandboxState | null;
    messages: ChatMessage[];
    publication: Publication;
}) {
    const working = project.status === 'working';
    const busy =
        working ||
        sandbox?.status === 'creating' ||
        publication.status === 'publishing';

    setLayoutProps({
        breadcrumbs: [{ title: project.name, href: show(project.id) }],
    });
    const { start, stop } = usePoll(
        1000,
        { only: ['project', 'sandbox', 'messages', 'publication'] },
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
    messages,
    working,
}: {
    project: Project;
    messages: ChatMessage[];
    working: boolean;
}) {
    const bottom = useRef<HTMLDivElement>(null);

    useEffect(() => {
        bottom.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages.length, working]);

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
                <div ref={bottom} />
            </div>
            <div className="p-3">
                <PromptComposer
                    action={ProjectMessageController.store(project.id)}
                    field="content"
                    placeholder="Message the agent…"
                    disabled={working}
                    disabledPlaceholder="The agent is working… you can type your next message"
                />
            </div>
        </section>
    );
}

function MessageItem({ message }: { message: ChatMessage }) {
    if (message.role === 'user') {
        return (
            <div className="flex justify-end" data-test="message-user">
                <p className="max-w-[85%] rounded-2xl bg-muted px-4 py-2 text-sm whitespace-pre-wrap">
                    {message.content}
                </p>
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
        <p
            className="text-sm leading-relaxed whitespace-pre-wrap"
            data-test="message-assistant"
        >
            {message.content}
        </p>
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
    const remote = useIsRemote();
    // This machine's 127.0.0.1 preview can't be reached from elsewhere; use the published URL instead.
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
    const [openPath, setOpenPath] = useState<string | null>(null);
    const [file, setFile] = useState<WorkspaceFile | null>(null);
    const [fileError, setFileError] = useState<string | null>(null);
    const files = useWorkspaceFiles(project.id);

    // Open by default only where there's room for chat, preview and files.
    // Decided after mount so server-rendered and client HTML match.
    useEffect(() => {
        if (window.matchMedia('(min-width: 1440px)').matches) {
            setFilesOpen(true);
        }
    }, []);

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

    const statusText = {
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
                                setOpenPath(null);
                                setTab('preview');
                            }}
                        >
                            <FileText className="size-4" />
                            <span className="max-w-48 truncate">
                                {openPath.split('/').pop()}
                            </span>
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
                        onClick={() => setFilesOpen(!filesOpen)}
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
                {tab === 'tools' && <ToolsPanel publication={publication} />}
                {tab === 'file' && <FileViewer file={file} error={fileError} />}
                {extraTabs.includes('console') && tab === 'console' && (
                    <ConsoleView
                        projectId={project.id}
                        active
                        running={running}
                        clearSignal={consoleClears}
                    />
                )}
                {extraTabs.includes('shell') &&
                    (running && sandbox.shell_url && !remote ? (
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
                                {remote
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
                        ) : files.entries.length === 0 ? (
                            <p className="p-3 text-sm text-muted-foreground">
                                {files.loading
                                    ? 'Loading…'
                                    : 'No files yet. The agent will create them.'}
                            </p>
                        ) : (
                            <FileTree
                                entries={files.entries}
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
                'flex items-center rounded-md',
                active ? 'bg-muted font-medium' : 'text-muted-foreground',
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
                        {failed ? "Sandbox didn't start" : 'Starting sandbox…'}
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
                            : 'Your app will appear here in a moment.'}
                    </p>
                </div>
            </div>
        </div>
    );
}
