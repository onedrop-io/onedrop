import { usePage } from '@inertiajs/react';
import { ArrowUp } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Skeleton } from '@/components/ui/skeleton';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { Spinner } from '@/components/ui/spinner';
import { loadGlimpse, sendOnLoad } from '@/lib/chat-glimpse';
import type { GlimpseMessage } from '@/lib/chat-glimpse';
import { onPageVisit } from '@/lib/page-visits';
import { cn } from '@/lib/utils';
import { WAITING_LABELS } from '@/lib/waiting-for';
import { loadChat, saveChat } from '@/lib/workspace-state';
import type { SidebarProject, SidebarTask } from '@/types';

/** How long a visit runs before the page it's going to shows as loading: quick ones don't flash. */
const DELAY_MS = 150;
/** How long before it says it's still loading. */
const SLOW_MS = 8000;

/** A project's chat page, or one of its tasks': what the sketch can show of it. */
type ChatTarget = { projectId: number; taskId: number | null; path: string };

/**
 * While another page loads, a sketch of it over the one being left, so a click shows at once (PRJ-002). A
 * project's shows its name, what its agent is doing, the chat's last messages as this browser last saw them, and a
 * chat box that can be typed in already: the draft carries over, and sending sends once the chat is there.
 */
export function PageLoading() {
    const { sidebarProjects, openProject, auth } = usePage().props;
    const [target, setTarget] = useState<URL | null>(null);
    const [shown, setShown] = useState(false);
    const [slow, setSlow] = useState(false);

    useEffect(
        () =>
            onPageVisit(
                (url) => {
                    setTarget(url);
                    setShown(false);
                    setSlow(false);
                },
                () => {
                    // Typing in the sketch: carry on in the real chat box.
                    if (
                        document.activeElement?.id === 'page-loading-composer'
                    ) {
                        requestAnimationFrame(() => {
                            const box = document.getElementById(
                                'composer-content',
                            ) as HTMLTextAreaElement | null;

                            box?.focus();
                            box?.setSelectionRange(
                                box.value.length,
                                box.value.length,
                            );
                        });
                    }

                    setTarget(null);
                },
            ),
        [],
    );

    useEffect(() => {
        if (!target) {
            return;
        }

        const show = setTimeout(() => setShown(true), DELAY_MS);
        const later = setTimeout(() => setSlow(true), SLOW_MS);

        return () => {
            clearTimeout(show);
            clearTimeout(later);
        };
    }, [target]);

    if (!target || !shown) {
        return null;
    }

    const match = target.pathname.match(
        /^\/projects\/(\d+)(?:\/tasks\/(\d+))?(\/.*)?$/,
    );
    const projectId = match ? Number(match[1]) : null;
    const chat: ChatTarget | null =
        match && !match[3]
            ? {
                  projectId: Number(match[1]),
                  taskId: match[2] ? Number(match[2]) : null,
                  path: target.pathname,
              }
            : null;
    const listed: SidebarProject | undefined = [
        ...(sidebarProjects?.pinned ?? []),
        ...(sidebarProjects?.recent ?? []),
        ...(sidebarProjects?.archived ?? []),
    ].find((candidate) => candidate.id === projectId);
    const name =
        listed?.name ??
        (openProject?.id === projectId ? openProject.name : null);
    const task = chat?.taskId
        ? listed?.tasks.find((candidate) => candidate.id === chat.taskId)
        : undefined;
    const status = chat ? statusOf(chat.taskId ? task : listed) : null;

    return (
        <div
            className="absolute inset-0 z-30 flex flex-col overflow-hidden bg-background md:rounded-xl"
            aria-busy="true"
            data-test="page-loading"
        >
            <div className="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-6 md:px-4">
                <SidebarTrigger className="-ml-1" />
                {projectId !== null && name ? (
                    <span className="truncate" data-test="page-loading-name">
                        {task ? `${name} · ${task.title}` : name}
                    </span>
                ) : (
                    <Skeleton className="h-4 w-32" />
                )}
                <Spinner className="shrink-0 text-muted-foreground" />
                {slow && (
                    <span className="truncate text-sm text-muted-foreground">
                        Still loading…
                    </span>
                )}
                <Skeleton className="ml-auto h-8 w-20 max-sm:hidden" />
            </div>
            {projectId !== null ? (
                <>
                    <WorkspaceSketch
                        chat={chat}
                        user={auth.user.id}
                        status={status}
                    />
                    {/* The phone's tab bar (LAYOUT-007). */}
                    <div className="h-14 shrink-0 border-t border-sidebar-border/50 bg-toolbar md:hidden" />
                </>
            ) : (
                <PageSketch />
            )}
        </div>
    );
}

type Status = { text: string; tone: 'working' | 'attention' | 'failed' };

/** What the sidebar already knows the chat's agent is up to (PRJ-008, PRJ-011). */
function statusOf(
    item: (Partial<SidebarProject> & Partial<SidebarTask>) | undefined,
): Status | null {
    if (!item) {
        return null;
    }

    if (item.failed) {
        return { text: 'The sandbox failed to start', tone: 'failed' };
    }

    if (item.working) {
        return {
            text: item.activity ? `Working: ${item.activity}` : 'Working…',
            tone: 'working',
        };
    }

    if (item.waiting_for) {
        return { text: WAITING_LABELS[item.waiting_for], tone: 'attention' };
    }

    return item.unread ? { text: 'New reply', tone: 'attention' } : null;
}

/** The chat beside the workspace; on a phone, the chat above the bottom tab bar (LAYOUT-007). */
function WorkspaceSketch({
    chat,
    user,
    status,
}: {
    chat: ChatTarget | null;
    user: number;
    status: Status | null;
}) {
    const glimpse = chat ? loadGlimpse(user, chat.path) : [];

    return (
        <div className="flex min-h-0 flex-1">
            <div className="flex min-h-0 w-full flex-col p-4 lg:w-[28rem] lg:shrink-0 lg:border-r lg:border-sidebar-border/50">
                {glimpse.length > 0 ? (
                    <Glimpse messages={glimpse} />
                ) : (
                    <div className="flex-1 space-y-4">
                        <Bubble className="ml-auto w-2/3" />
                        <Bubble className="w-5/6" />
                        <Bubble className="w-1/2" />
                    </div>
                )}
                {status && (
                    <p
                        className={cn(
                            'mb-2 flex min-w-0 items-center gap-2 text-sm',
                            status.tone === 'failed'
                                ? 'text-destructive'
                                : 'text-muted-foreground',
                        )}
                        data-test="page-loading-status"
                    >
                        <span
                            className={cn(
                                'size-2 shrink-0 rounded-full',
                                status.tone === 'working' &&
                                    'animate-pulse bg-primary',
                                status.tone === 'attention' && 'bg-blue-500',
                                status.tone === 'failed' && 'bg-destructive',
                            )}
                        />
                        <span className="truncate">{status.text}</span>
                    </p>
                )}
                {chat ? (
                    <EarlyComposer key={chat.path} chat={chat} />
                ) : (
                    <Skeleton className="h-24 w-full rounded-2xl" />
                )}
            </div>
            <div className="hidden min-w-0 flex-1 flex-col lg:flex">
                <div className="flex h-11 items-center gap-2 border-b border-sidebar-border/50 px-3">
                    <Skeleton className="h-5 w-16" />
                    <Skeleton className="h-5 w-20" />
                    <Skeleton className="h-5 w-14" />
                </div>
                <Skeleton className="m-4 flex-1 rounded-lg" />
            </div>
        </div>
    );
}

/** The chat's last messages as this browser last showed them, dimmed until the real ones arrive. */
function Glimpse({ messages }: { messages: GlimpseMessage[] }) {
    return (
        <div
            className="flex flex-1 flex-col justify-end gap-3 overflow-hidden pb-3 text-sm opacity-60"
            data-test="page-loading-glimpse"
        >
            {messages.map((message, index) => (
                <p
                    key={index}
                    className={cn(
                        'line-clamp-4 break-words',
                        message.role === 'user' &&
                            'ml-auto max-w-[85%] rounded-2xl bg-muted px-3 py-2',
                    )}
                >
                    {message.text}
                </p>
            ))}
        </div>
    );
}

/**
 * A chat box to type in while the chat loads: it writes the chat's own saved draft, which the real box picks up,
 * and sending marks it to be sent once the chat is there.
 */
function EarlyComposer({ chat }: { chat: ChatTarget }) {
    const name = chat.taskId ? `task-${chat.taskId}` : 'main';
    const [text, setText] = useState(
        () => loadChat(chat.projectId, name).draft,
    );
    const [sending, setSending] = useState(false);

    const send = () => {
        if (text.trim()) {
            sendOnLoad(chat.path);
            setSending(true);
        }
    };

    return (
        <div className="relative rounded-2xl border border-input bg-card shadow-xs">
            <textarea
                id="page-loading-composer"
                value={text}
                readOnly={sending}
                onChange={(event) => {
                    setText(event.target.value);
                    saveChat(chat.projectId, name, {
                        draft: event.target.value,
                        scrollTop: null,
                    });
                }}
                onKeyDown={(event) => {
                    if (event.key === 'Enter' && !event.shiftKey) {
                        event.preventDefault();
                        send();
                    }
                }}
                rows={2}
                placeholder="Message the agent…"
                aria-label="Message the agent"
                className="block w-full resize-none bg-transparent px-4 pt-3 pb-12 text-sm outline-none placeholder:text-muted-foreground"
                data-test="page-loading-composer"
            />
            <div className="absolute inset-x-3 bottom-2 flex items-center justify-end gap-2">
                {sending && (
                    <span
                        className="text-xs text-muted-foreground"
                        data-test="page-loading-sending"
                    >
                        Sends once the chat is open
                    </span>
                )}
                <button
                    type="button"
                    onClick={send}
                    disabled={!text.trim() || sending}
                    aria-label="Send"
                    className="flex size-8 items-center justify-center rounded-full bg-primary text-primary-foreground disabled:opacity-40"
                >
                    <ArrowUp className="size-4" />
                </button>
            </div>
        </div>
    );
}

function Bubble({ className }: { className?: string }) {
    return (
        <div className={cn('space-y-2', className)}>
            <Skeleton className="h-4 w-full" />
            <Skeleton className="h-4 w-4/5" />
        </div>
    );
}

function PageSketch() {
    return (
        <div className="space-y-4 p-6">
            <Skeleton className="h-6 w-48" />
            <Skeleton className="h-4 w-full max-w-xl" />
            <Skeleton className="h-4 w-full max-w-md" />
            <div className="grid gap-4 pt-2 sm:grid-cols-2 lg:grid-cols-3">
                <Skeleton className="h-32" />
                <Skeleton className="h-32" />
                <Skeleton className="h-32" />
            </div>
        </div>
    );
}
