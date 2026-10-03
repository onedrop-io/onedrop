import {
    AppWindow,
    ExternalLink,
    FileText,
    Globe,
    MessageSquare,
    RotateCw,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import AgentModelPicker from '@/components/agent-model-picker';
import Markdown from '@/components/markdown';
import { Spinner } from '@/components/ui/spinner';
import ResizeHandle from '@/components/workspace/resize-handle';
import { useResizableWidth } from '@/hooks/use-resizable-width';
import type { RealtimeConfig } from '@/lib/realtime';
import { cn } from '@/lib/utils';
import type { AgentSelection, ChatMessage, MessageAttachment } from '@/types';
import { api, ApiError, serverUrl } from '../lib/api';
import { useBlobUrl, useCoalesced, useInterval } from '../lib/hooks';
import { isLooking, openInBrowser, openInWindow } from '../lib/native';
import { useProjectChannel } from '../lib/realtime';
import type { QueuedMessage, Workspace } from '../lib/types';
import ClaudeLogin from './claude-login';
import Composer from './composer';
import TitleBar from './title-bar';

const CHAT_OPEN_KEY = 'onedrop.chat-open';
const CHAT_WIDTH_KEY = 'onedrop.chat-width';
const CHAT_WIDTH = { initial: 448, min: 300, max: 900 };

/** How often the sandbox hears the workspace is open, so it isn't suspended for sitting idle (SBX-007). */
const ACTIVITY_MS = 20_000;

/**
 * A project's workspace (DESK-003): its chat with the agent, and its running app's preview. Kept current like the
 * web workspace: pushed updates when the server broadcasts (LIVE-001), polling while something happens otherwise.
 */
export default function WorkspaceView({
    projectId,
    realtime,
    onChanged,
    onMissing,
}: {
    projectId: number;
    realtime: RealtimeConfig | null;
    /** Something the sidebar shows may have changed. */
    onChanged: () => void;
    /** The project is gone (deleted elsewhere) or isn't the user's. */
    onMissing: () => void;
}) {
    const [workspace, setWorkspace] = useState<Workspace | null>(null);
    const [chatOpen, setChatOpen] = useState(
        () => localStorage.getItem(CHAT_OPEN_KEY) !== 'false',
    );
    const [chatWidth, setChatWidth] = useResizableWidth(
        CHAT_WIDTH_KEY,
        CHAT_WIDTH,
    );
    const latest = useRef<Workspace | null>(null);
    // The Shell's address while it shows instead of the preview (Claude Code's own sign-in, AI-005); null for the preview.
    const [shell, setShell] = useState<string | null>(null);

    const load = useCallback(async () => {
        try {
            const next = await api<Workspace>(
                `projects/${projectId}`,
                undefined,
                'GET',
                // Like a background tab on the web: replies stay unread until the user looks (PRJ-008).
                isLooking() ? {} : { 'X-Onedrop-Unseen': '1' },
            );

            latest.current = next;
            setWorkspace(next);
        } catch (error) {
            if (
                error instanceof ApiError &&
                [403, 404].includes(error.status)
            ) {
                onMissing();
            }

            throw error;
        }
    }, [projectId, onMissing]);

    const reload = useCoalesced(load);
    const reloadAll = () => {
        reload();
        onChanged();
    };

    useEffect(reload, [reload]);

    const live = useProjectChannel(realtime, projectId, {
        ProjectUpdated: reloadAll,
    });

    // Catch up on anything missed while connecting (or reconnecting).
    useEffect(() => {
        if (live) {
            reload();
        }
    }, [live, reload]);

    const working = workspace?.project.status === 'working';
    const busy =
        working ||
        workspace?.sandbox?.status === 'creating' ||
        !!workspace?.sandbox?.updating ||
        workspace?.publication.status === 'publishing';

    // Without live updates, poll while something is happening; and now and then anyway, to notice other changes.
    useInterval(reloadAll, busy && !live ? 1_000 : 30_000);

    // Opening the project in the app reads it, as on the web: catch up when the window comes back.
    useEffect(() => {
        const onFocus = () => reload();

        window.addEventListener('focus', onFocus);

        return () => window.removeEventListener('focus', onFocus);
    }, [reload]);

    const [wakes, setWakes] = useState(0);

    useInterval(() => {
        if (document.visibilityState !== 'visible') {
            return;
        }

        api<{ woke: boolean }>(`projects/${projectId}/sandbox/activity`, {})
            .then(({ woke }) => {
                if (woke) {
                    setWakes((count) => count + 1);
                    reload();
                }
            })
            .catch(() => {});
    }, ACTIVITY_MS);

    // Claude Code's own sign-in (AI-005) runs in the Shell, in place of the preview; its sign-in link opens in the
    // browser (see open_in_browser in src-tauri). A fresh address, since on servers it's good for a minute.
    const signInToClaude = async () => {
        await load().catch(() => {});

        setShell(latest.current?.sandbox?.claude_login_url ?? null);
    };

    const toggleChat = () => {
        localStorage.setItem(CHAT_OPEN_KEY, String(!chatOpen));
        setChatOpen(!chatOpen);
    };

    if (!workspace) {
        return (
            <div className="flex flex-1 flex-col">
                <TitleBar />
                <div className="flex flex-1 items-center justify-center">
                    <Spinner className="size-5 text-muted-foreground" />
                </div>
            </div>
        );
    }

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <TitleBar className="border-b">
                <button
                    type="button"
                    onClick={toggleChat}
                    title={chatOpen ? 'Hide the chat' : 'Show the chat'}
                    aria-pressed={chatOpen}
                    className="flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                >
                    <MessageSquare className="size-4" />
                </button>
                <h1
                    className="truncate text-sm font-medium"
                    data-tauri-drag-region
                >
                    {workspace.project.name}
                </h1>
                <div className="flex-1" data-tauri-drag-region />
                {workspace.publication.status === 'live' &&
                    workspace.publication.url && (
                        <button
                            type="button"
                            onClick={() =>
                                void openInBrowser(workspace.publication.url!)
                            }
                            className="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs hover:bg-muted"
                            title={workspace.publication.url}
                        >
                            <span className="size-1.5 rounded-full bg-emerald-500" />
                            Live
                            <ExternalLink className="size-3" />
                        </button>
                    )}
                <button
                    type="button"
                    onClick={() =>
                        void openInBrowser(serverUrl(`/projects/${projectId}`))
                    }
                    title="Open in browser, for files, git, publishing and the other tools"
                    className="flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                >
                    <Globe className="size-4" />
                </button>
            </TitleBar>

            <div className="flex min-h-0 flex-1">
                <Chat
                    projectId={projectId}
                    workspace={workspace}
                    working={working}
                    width={chatWidth}
                    hidden={!chatOpen}
                    onChanged={reloadAll}
                    onClaudeSignIn={() => void signInToClaude()}
                    onClaudeSignedIn={() => setShell(null)}
                />
                {chatOpen && (
                    <ResizeHandle
                        label="Resize chat"
                        side="left"
                        width={chatWidth}
                        limits={CHAT_WIDTH}
                        onResize={setChatWidth}
                    />
                )}
                <Preview
                    workspace={workspace}
                    wakes={wakes}
                    shell={shell}
                    onShell={setShell}
                    refresh={async () => {
                        await load();

                        return latest.current?.sandbox?.preview_url ?? null;
                    }}
                />
            </div>
        </div>
    );
}

function Chat({
    projectId,
    workspace,
    working,
    width,
    hidden,
    onChanged,
    onClaudeSignIn,
    onClaudeSignedIn,
}: {
    projectId: number;
    workspace: Workspace;
    working: boolean;
    width: number;
    /** Hidden by the user (LAYOUT-001); stays mounted so a draft survives. */
    hidden: boolean;
    onChanged: () => void;
    /** Open Claude Code's own sign-in (AI-005). */
    onClaudeSignIn: () => void;
    onClaudeSignedIn: () => void;
}) {
    const { messages, queued, agent } = workspace;
    const bottom = useRef<HTMLDivElement>(null);
    const [draft, setDraft] = useState<string | undefined>(undefined);

    useEffect(() => {
        bottom.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages.length, queued.length, working]);

    const send = async (text: string, files: File[], mode: 'queue' | 'now') => {
        const body = new FormData();

        body.append('content', text);
        body.append('mode', mode);
        files.forEach((file) => body.append('attachments[]', file));

        await api(`projects/${projectId}/messages`, body);
        onChanged();
    };

    // Stop the agent; any queued messages come back into the box for editing.
    const stop = () =>
        api<{ draft: string | null; dropped_attachments: number }>(
            `projects/${projectId}/agent/stop`,
            {},
        )
            .then(({ draft: restored, dropped_attachments }) => {
                if (restored) {
                    setDraft(restored);
                }

                if (dropped_attachments > 0) {
                    toast.info(
                        'Queued attachments were removed; attach them again to send them.',
                    );
                }

                onChanged();
            })
            .catch((error: Error) => toast.error(error.message));

    const changeAgent = (next: AgentSelection) =>
        api(
            `projects/${projectId}/agent`,
            {
                agent_harness: next.harness,
                agent_provider: next.provider,
                agent_model: next.model,
                agent_variant: next.variant,
            },
            'PATCH',
        )
            .then(onChanged)
            .catch((error: Error) =>
                toast.error(
                    error instanceof ApiError
                        ? (Object.values(error.errors)[0] ?? error.message)
                        : error.message,
                ),
            );

    const removeQueued = (message: QueuedMessage) =>
        api(`projects/${projectId}/messages/${message.id}`, undefined, 'DELETE')
            .then(onChanged)
            .catch((error: Error) => toast.error(error.message));

    return (
        <section
            aria-label="Chat"
            className={cn('flex min-h-0 shrink-0 flex-col', hidden && 'hidden')}
            style={{ width }}
        >
            <div className="selectable flex-1 space-y-5 overflow-y-auto p-4">
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
                {queued.map((message) => (
                    <div
                        key={message.id}
                        className="flex justify-end"
                        data-test="message-queued"
                    >
                        <div className="max-w-[85%] rounded-2xl border border-dashed border-input px-4 py-2 text-sm">
                            <Attachments
                                attachments={message.attachments}
                                className="mb-1 justify-end opacity-80"
                            />
                            <p className="whitespace-pre-wrap text-muted-foreground">
                                {message.content}
                            </p>
                            <div className="mt-1 flex items-center justify-end gap-2 text-xs text-muted-foreground">
                                <span>Queued</span>
                                <button
                                    type="button"
                                    onClick={() => void removeQueued(message)}
                                    className="rounded px-1 hover:bg-muted"
                                >
                                    Remove
                                </button>
                            </div>
                        </div>
                    </div>
                ))}
                <div ref={bottom} />
            </div>
            <div className="p-3">
                {workspace.claudeSubscription &&
                    agent?.harness === 'claude_code' && (
                        <ClaudeLogin
                            projectId={projectId}
                            working={working}
                            waitingForSignIn={
                                workspace.project.waiting_for_sign_in ?? false
                            }
                            onSignIn={onClaudeSignIn}
                            onSignedIn={onClaudeSignedIn}
                            onResumed={onChanged}
                        />
                    )}
                <Composer
                    onSend={send}
                    field="content"
                    working={working}
                    onStop={() => void stop()}
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
                    autoFocus
                    placeholder={
                        working
                            ? 'Queue a message, or ⌘/Ctrl+Enter to send now…'
                            : 'Message the agent…'
                    }
                    footer={
                        agent && (
                            <AgentModelPicker
                                selection={agent}
                                harnessLocked={
                                    working
                                        ? 'Stop the agent or wait for it to finish to switch agents'
                                        : null
                                }
                                onChange={(next) => void changeAgent(next)}
                            />
                        )
                    }
                />
            </div>
        </section>
    );
}

function MessageItem({
    message,
    live = false,
}: {
    message: ChatMessage;
    live?: boolean;
}) {
    if (message.role === 'user') {
        return (
            <div
                className="flex flex-col items-end gap-2"
                data-test="message-user"
            >
                <Attachments
                    attachments={message.attachments}
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
                        'size-1.5 shrink-0 rounded-full',
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

/** A message's files, like the web chat's: images shown (fetched with the token), others as chips. */
function Attachments({
    attachments,
    className,
}: {
    attachments: MessageAttachment[];
    className?: string;
}) {
    if (attachments.length === 0) {
        return null;
    }

    return (
        <ul className={cn('flex flex-wrap gap-2', className)}>
            {attachments.map((attachment) => (
                <li
                    key={attachment.id}
                    title={attachment.name}
                    data-test="attachment"
                >
                    {attachment.image ? (
                        <AuthedImage
                            url={attachment.url}
                            name={attachment.name}
                        />
                    ) : (
                        <span className="flex h-16 max-w-44 items-center gap-2 rounded-lg border bg-card px-3 text-xs">
                            <FileText className="size-4 shrink-0 text-muted-foreground" />
                            <span className="truncate">{attachment.name}</span>
                        </span>
                    )}
                </li>
            ))}
        </ul>
    );
}

function AuthedImage({ url, name }: { url: string; name: string }) {
    const src = useBlobUrl(url);

    return src ? (
        <img
            src={src}
            alt={name}
            className="size-16 rounded-lg border object-cover"
        />
    ) : (
        <span className="block size-16 animate-pulse rounded-lg border bg-muted" />
    );
}

/**
 * The running app. The frame keeps its address while the workspace refreshes (a reload would lose the app's
 * state); it moves to a fresh one when the user reloads it or the sandbox comes back up.
 */
function Preview({
    workspace,
    wakes,
    shell,
    onShell,
    refresh,
}: {
    workspace: Workspace;
    wakes: number;
    /** The Shell's address while it shows instead of the preview, or null. */
    shell: string | null;
    onShell: (url: string | null) => void;
    /** Fetch the workspace again and give back the preview's current address. */
    refresh: () => Promise<string | null>;
}) {
    const sandbox = workspace.sandbox;
    const running = sandbox?.status === 'running' && !!sandbox.preview_url;
    const [src, setSrc] = useState<string | null>(
        running ? sandbox.preview_url : null,
    );
    const [loads, setLoads] = useState(0);

    const reloadFrame = async () => {
        setSrc(await refresh().catch(() => src));
        setLoads((count) => count + 1);
    };

    // First shown, or back after the sandbox was made, failed or updated.
    useEffect(() => {
        if (running && src === null) {
            setSrc(sandbox.preview_url);
        }

        if (!running && src !== null) {
            setSrc(null);
        }
    }, [running, sandbox, src]);

    // The sandbox had been asleep: its preview is stale.
    useEffect(() => {
        if (wakes > 0) {
            void reloadFrame();
        }
        // Only on wakes.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [wakes]);

    const popOut = async () => {
        const url = await refresh().catch(() => null);

        if (url) {
            openInWindow(url, `${workspace.project.name} preview`);
        }
    };

    return (
        <section
            aria-label="Preview"
            className="flex min-w-0 flex-1 flex-col border-l bg-muted/30"
        >
            <div className="flex h-9 shrink-0 items-center gap-1 border-b px-2 text-xs text-muted-foreground">
                {sandbox?.status === 'running' && sandbox.shell_url && (
                    <div
                        className="mr-1 flex items-center gap-0.5"
                        role="tablist"
                    >
                        <ViewTab
                            active={shell === null}
                            onClick={() => onShell(null)}
                        >
                            Preview
                        </ViewTab>
                        <ViewTab
                            active={shell !== null}
                            onClick={() => onShell(shell ?? sandbox.shell_url)}
                            data-test="shell-tab"
                        >
                            Shell
                        </ViewTab>
                    </div>
                )}
                <span className="flex-1 truncate">
                    {sandbox?.status === 'creating' && 'Starting the sandbox…'}
                    {sandbox?.status === 'paused' && 'Waking the sandbox…'}
                    {sandbox?.status === 'failed' &&
                        'The sandbox failed to start.'}
                    {sandbox?.updating && ' Updating the sandbox…'}
                </span>
                <button
                    type="button"
                    onClick={() => void reloadFrame()}
                    disabled={!running}
                    title="Reload the preview"
                    className="flex size-6 items-center justify-center rounded hover:bg-muted hover:text-foreground disabled:opacity-40"
                    data-test="preview-reload"
                >
                    <RotateCw className="size-3.5" />
                </button>
                <button
                    type="button"
                    onClick={() => void popOut()}
                    disabled={!running}
                    title="Open the preview in its own window"
                    className="flex size-6 items-center justify-center rounded hover:bg-muted hover:text-foreground disabled:opacity-40"
                >
                    <AppWindow className="size-3.5" />
                </button>
            </div>
            {shell && (
                <iframe
                    src={shell}
                    title={`${workspace.project.name} shell`}
                    className="min-h-0 flex-1 bg-black"
                    data-test="shell"
                />
            )}
            {src ? (
                // Kept while the Shell shows, so the app's state survives.
                <iframe
                    key={loads}
                    src={src}
                    title={`${workspace.project.name} preview`}
                    className={cn('min-h-0 flex-1 bg-white', shell && 'hidden')}
                    data-test="preview"
                />
            ) : shell ? null : (
                <div className="flex flex-1 items-center justify-center p-8 text-center text-sm text-muted-foreground">
                    {sandbox?.status === 'failed' ? (
                        <p className="max-w-sm">
                            {sandbox.error ?? 'The sandbox failed to start.'}
                        </p>
                    ) : (
                        <Spinner className="size-5" />
                    )}
                </div>
            )}
        </section>
    );
}

function ViewTab({
    active,
    onClick,
    children,
    ...props
}: {
    active: boolean;
    onClick: () => void;
    children: ReactNode;
    'data-test'?: string;
}) {
    return (
        <button
            type="button"
            role="tab"
            aria-selected={active}
            onClick={onClick}
            className={cn(
                'rounded px-2 py-1 hover:bg-muted hover:text-foreground',
                active && 'bg-muted font-medium text-foreground',
            )}
            {...props}
        >
            {children}
        </button>
    );
}
