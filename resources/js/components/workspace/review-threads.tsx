import {
    Check,
    CircleCheck,
    MessageSquareReply,
    Sparkles,
    Undo2,
    Wand2,
} from 'lucide-react';
import { useState } from 'react';
import type { ButtonHTMLAttributes } from 'react';
import PullRequestReviewController from '@/actions/App/Http/Controllers/PullRequestReviewController';
import Markdown from '@/components/markdown';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import PatchView from '@/components/workspace/patch-view';
import { jsonRequest } from '@/lib/json-request';
import { cn } from '@/lib/utils';

export type ReviewReply = {
    id: number;
    outcome: 'fixed' | 'declined' | 'question' | 'applied';
    body: string;
    status: 'draft' | 'posted' | 'discarded';
    commit_sha: string | null;
    error: string | null;
    posted_at: string | null;
};

export type ReviewThread = {
    id: string;
    key: string;
    resolved: boolean;
    outdated: boolean;
    path: string | null;
    line: number | null;
    start_line: number | null;
    diff_hunk: string;
    comments: {
        id: number;
        author: {
            login: string;
            avatar_url: string | null;
            bot: boolean;
        } | null;
        association: string | null;
        body: string;
        created_at: string | null;
        url: string | null;
    }[];
    /** Unresolved, and the newest comment isn't OneDrop's own reply. */
    waiting: boolean;
    /** It has a ```suggestion on lines that still exist. */
    can_apply: boolean;
    /** What the task's agent said about it (GIT-031). */
    reply: ReviewReply | null;
};

const OUTCOMES: Record<ReviewReply['outcome'], string> = {
    fixed: 'Fixed',
    declined: 'Declined',
    question: 'Needs you',
    applied: 'Suggestion applied',
};

/**
 * A pull request's review threads (GIT-031): those waiting on someone first, each with its code, its comments and the
 * agent's draft reply, and the buttons to address them, apply a suggestion, reply, and resolve.
 */
export default function ReviewThreads({
    projectId,
    number,
    threads,
    hasTask,
    canAddress,
    onAddress,
    onChanged,
}: {
    projectId: number;
    number: number;
    threads: ReviewThread[];
    /** Whether the pull request is checked out as a task, whose copy a suggestion is applied in. */
    hasTask: boolean;
    /** Whether threads can go to an agent (checked out, or it can be). */
    canAddress: boolean;
    onAddress: (threadIds: string[]) => void;
    /** Something changed on GitHub or in a draft: read the page again. */
    onChanged: () => void;
}) {
    const waiting = threads.filter((thread) => thread.waiting);
    const [chosen, setChosen] = useState<string[]>(() =>
        waiting.map((thread) => thread.id),
    );
    const [showResolved, setShowResolved] = useState(false);
    const shown = threads
        .filter((thread) => showResolved || !thread.resolved)
        .sort((a, b) => Number(b.waiting) - Number(a.waiting));
    const resolvedCount = threads.filter((thread) => thread.resolved).length;

    if (threads.length === 0) {
        return null;
    }

    return (
        <section className="space-y-2" data-test="review-threads">
            <div className="flex flex-wrap items-center gap-2">
                <h4 className="text-sm font-medium">
                    Review comments
                    {waiting.length > 0 && (
                        <span className="ml-1.5 font-normal text-muted-foreground">
                            {waiting.length} waiting
                        </span>
                    )}
                </h4>
                <span className="flex-1" />
                {resolvedCount > 0 && (
                    <button
                        type="button"
                        onClick={() => setShowResolved(!showResolved)}
                        className="text-xs text-muted-foreground hover:text-foreground"
                        data-test="review-threads-show-resolved"
                    >
                        {showResolved
                            ? 'Hide resolved'
                            : `Show ${resolvedCount} resolved`}
                    </button>
                )}
                {waiting.length > 0 && (
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={!canAddress || chosen.length === 0}
                        onClick={() => onAddress(chosen)}
                        title="Send the chosen comments to the task's agent to fix or answer"
                        data-test="review-threads-address"
                    >
                        <Sparkles />
                        {chosen.length === 1
                            ? 'Address 1 comment'
                            : `Address ${chosen.length} comments`}
                    </Button>
                )}
            </div>
            <ol className="space-y-2">
                {shown.map((thread) => (
                    <Thread
                        key={thread.id}
                        projectId={projectId}
                        number={number}
                        thread={thread}
                        hasTask={hasTask}
                        chosen={chosen.includes(thread.id)}
                        onChoose={(value) =>
                            setChosen((current) =>
                                value
                                    ? [...current, thread.id]
                                    : current.filter((id) => id !== thread.id),
                            )
                        }
                        onChanged={onChanged}
                    />
                ))}
            </ol>
        </section>
    );
}

function Thread({
    projectId,
    number,
    thread,
    hasTask,
    chosen,
    onChoose,
    onChanged,
}: {
    projectId: number;
    number: number;
    thread: ReviewThread;
    hasTask: boolean;
    chosen: boolean;
    onChoose: (value: boolean) => void;
    onChanged: () => void;
}) {
    const ids = { project: projectId, number };
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [replying, setReplying] = useState(false);
    const [text, setText] = useState('');

    const act = (name: string, request: () => Promise<unknown>) => {
        setBusy(name);
        setError(null);
        request()
            .then(() => {
                setReplying(false);
                setText('');
                onChanged();
            })
            .catch((e: Error) => setError(e.message))
            .finally(() => setBusy(null));
    };

    const where = thread.path
        ? `${thread.path}${thread.line ? `:${thread.line}` : ''}`
        : 'The pull request';

    return (
        <li
            className={cn(
                'rounded-xl border border-sidebar-border/70 dark:border-sidebar-border',
                thread.resolved && 'opacity-70',
            )}
            data-test="review-thread"
        >
            <div className="flex items-center gap-2 border-b border-sidebar-border/70 px-3 py-1.5 text-xs dark:border-sidebar-border">
                {thread.waiting && (
                    <Checkbox
                        checked={chosen}
                        onCheckedChange={(value) => onChoose(value === true)}
                        aria-label="Address this comment"
                        data-test="review-thread-choose"
                    />
                )}
                <code className="min-w-0 flex-1 truncate">{where}</code>
                {thread.outdated && (
                    <span className="rounded-full border border-sidebar-border px-1.5 text-[11px] text-muted-foreground">
                        Outdated
                    </span>
                )}
                {thread.resolved ? (
                    <span className="flex items-center gap-1 text-green-700 dark:text-green-400">
                        <CircleCheck className="size-3.5" />
                        Resolved
                    </span>
                ) : (
                    !thread.waiting && (
                        <span className="text-muted-foreground">Replied</span>
                    )
                )}
            </div>

            {thread.diff_hunk && !thread.outdated && (
                <div className="border-b border-sidebar-border/70 dark:border-sidebar-border">
                    <PatchView
                        patch={hunkEnd(thread.diff_hunk)}
                        truncated={false}
                        path={thread.path ?? undefined}
                        className="max-h-40"
                    />
                </div>
            )}

            <ol className="divide-y divide-sidebar-border/50">
                {thread.comments.map((comment) => (
                    <li key={comment.id} className="px-3 py-2 text-sm">
                        <p className="mb-1 flex items-center gap-2 text-xs text-muted-foreground">
                            {comment.author?.avatar_url && (
                                <img
                                    src={comment.author.avatar_url}
                                    alt=""
                                    className="size-4 rounded-full"
                                />
                            )}
                            <span className="font-medium text-foreground">
                                {comment.author?.login ?? 'Someone'}
                            </span>
                            {comment.author?.bot && <span>bot</span>}
                        </p>
                        <div className="[&>*]:min-w-0">
                            <Markdown content={comment.body} />
                        </div>
                    </li>
                ))}
            </ol>

            {thread.reply && (
                <DraftReply
                    projectId={projectId}
                    reply={thread.reply}
                    onChanged={onChanged}
                />
            )}

            {replying && (
                <div className="space-y-2 border-t border-sidebar-border/70 px-3 py-2 dark:border-sidebar-border">
                    <textarea
                        value={text}
                        onChange={(event) => setText(event.target.value)}
                        rows={3}
                        autoFocus
                        placeholder="Reply on GitHub…"
                        className="w-full rounded-md border border-input bg-transparent px-2 py-1.5 text-sm"
                        data-test="review-thread-reply-text"
                    />
                    <div className="flex gap-2">
                        <Button
                            size="sm"
                            disabled={!text.trim() || busy !== null}
                            onClick={() =>
                                act('reply', () =>
                                    jsonRequest(
                                        PullRequestReviewController.reply.url(
                                            ids,
                                        ),
                                        { thread_id: thread.id, body: text },
                                    ),
                                )
                            }
                            data-test="review-thread-reply-send"
                        >
                            {busy === 'reply' ? 'Replying…' : 'Reply'}
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setReplying(false)}
                        >
                            Cancel
                        </Button>
                    </div>
                </div>
            )}

            <div className="flex flex-wrap items-center gap-1 border-t border-sidebar-border/70 px-2 py-1 text-xs dark:border-sidebar-border">
                {thread.can_apply && (
                    <ThreadButton
                        onClick={() =>
                            act('apply', () =>
                                jsonRequest(
                                    PullRequestReviewController.apply.url(ids),
                                    { thread_id: thread.id },
                                ),
                            )
                        }
                        disabled={!hasTask || busy !== null}
                        title={
                            hasTask
                                ? 'Make the suggested change in the task, push it, and resolve the comment'
                                : 'Check it out as a task first'
                        }
                        data-test="review-thread-apply"
                    >
                        <Wand2 className="size-3.5" />
                        {busy === 'apply' ? 'Applying…' : 'Apply suggestion'}
                    </ThreadButton>
                )}
                {!replying && (
                    <ThreadButton
                        onClick={() => setReplying(true)}
                        data-test="review-thread-reply"
                    >
                        <MessageSquareReply className="size-3.5" />
                        Reply
                    </ThreadButton>
                )}
                <ThreadButton
                    onClick={() =>
                        act('resolve', () =>
                            jsonRequest(
                                PullRequestReviewController.resolve.url(ids),
                                {
                                    thread_id: thread.id,
                                    resolved: !thread.resolved,
                                },
                            ),
                        )
                    }
                    disabled={busy !== null}
                    data-test="review-thread-resolve"
                >
                    {thread.resolved ? (
                        <>
                            <Undo2 className="size-3.5" />
                            Unresolve
                        </>
                    ) : (
                        <>
                            <Check className="size-3.5" />
                            Resolve
                        </>
                    )}
                </ThreadButton>
                {thread.comments[0]?.url && (
                    <a
                        href={thread.comments[0].url}
                        target="_blank"
                        rel="noreferrer"
                        className="ml-auto rounded px-1.5 py-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                    >
                        On GitHub
                    </a>
                )}
            </div>
            {error && (
                <p
                    className="px-3 pb-2 text-xs text-red-600 dark:text-red-400"
                    data-test="review-thread-error"
                >
                    {error}
                </p>
            )}
        </li>
    );
}

/** The agent's reply about a thread: a draft to edit, post or discard, or what was posted. */
function DraftReply({
    projectId,
    reply,
    onChanged,
}: {
    projectId: number;
    reply: ReviewReply;
    onChanged: () => void;
}) {
    const ids = { project: projectId, reply: reply.id };
    const [text, setText] = useState(reply.body);
    const [busy, setBusy] = useState<'post' | 'discard' | null>(null);
    const [error, setError] = useState<string | null>(reply.error);
    const draft = reply.status === 'draft';
    const waitingForPush =
        draft &&
        (reply.outcome === 'fixed' || reply.outcome === 'applied') &&
        !reply.commit_sha;

    const act = (name: 'post' | 'discard') => {
        setBusy(name);
        setError(null);
        const request =
            name === 'discard'
                ? jsonRequest(
                      PullRequestReviewController.discard.url(ids),
                      {},
                      'DELETE',
                  )
                : (text !== reply.body
                      ? jsonRequest(
                            PullRequestReviewController.update.url(ids),
                            { body: text },
                            'PATCH',
                        )
                      : Promise.resolve()
                  ).then(() =>
                      jsonRequest(
                          PullRequestReviewController.post.url(ids),
                          {},
                      ),
                  );

        request
            .then(onChanged)
            .catch((e: Error) => setError(e.message))
            .finally(() => setBusy(null));
    };

    return (
        <div
            className="space-y-1.5 border-t border-sidebar-border/70 bg-muted/30 px-3 py-2 text-sm dark:border-sidebar-border"
            data-test="review-reply"
        >
            <p className="flex items-center gap-2 text-xs text-muted-foreground">
                <span
                    className={cn(
                        'rounded-full border px-1.5 text-[11px]',
                        reply.outcome === 'question'
                            ? 'border-amber-600/40 text-amber-700 dark:text-amber-400'
                            : reply.outcome === 'declined'
                              ? 'border-sidebar-border'
                              : 'border-green-600/40 text-green-700 dark:text-green-400',
                    )}
                    data-test="review-reply-outcome"
                >
                    {OUTCOMES[reply.outcome]}
                </span>
                {draft
                    ? reply.outcome === 'question'
                        ? "The agent's question for you"
                        : "The agent's reply, not posted yet"
                    : 'Posted on GitHub'}
                {reply.commit_sha && (
                    <code>{reply.commit_sha.slice(0, 7)}</code>
                )}
            </p>
            {draft ? (
                <>
                    <textarea
                        value={text}
                        onChange={(event) => setText(event.target.value)}
                        rows={2}
                        className="w-full rounded-md border border-input bg-background px-2 py-1.5 text-sm"
                        data-test="review-reply-text"
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            size="sm"
                            disabled={busy !== null || !text.trim()}
                            onClick={() => act('post')}
                            title={
                                reply.outcome === 'fixed' ||
                                reply.outcome === 'applied'
                                    ? 'Post it on the comment and resolve the comment'
                                    : 'Post it on the comment'
                            }
                            data-test="review-reply-post"
                        >
                            {busy === 'post' ? 'Posting…' : 'Post reply'}
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            disabled={busy !== null}
                            onClick={() => act('discard')}
                            data-test="review-reply-discard"
                        >
                            Discard
                        </Button>
                        {waitingForPush && (
                            <span className="text-xs text-muted-foreground">
                                The fix isn't pushed yet.
                            </span>
                        )}
                    </div>
                </>
            ) : (
                <Markdown content={reply.body} />
            )}
            {error && (
                <p
                    className="text-xs text-red-600 dark:text-red-400"
                    data-test="review-reply-error"
                >
                    {error}
                </p>
            )}
        </div>
    );
}

function ThreadButton({
    className,
    ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & {
    'data-test'?: string;
}) {
    return (
        <button
            type="button"
            className={cn(
                'flex items-center gap-1 rounded px-1.5 py-0.5 text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-50',
                className,
            )}
            {...props}
        />
    );
}

/** The lines just above the comment, like GitHub shows it, under a header that numbers them right. */
function hunkEnd(hunk: string): string {
    const [header, ...lines] = hunk.split('\n');
    const match = header.match(/^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@(.*)$/);

    if (!match || lines.length <= 8) {
        return hunk;
    }

    const skipped = lines.slice(0, -8);
    const kept = lines.slice(-8);
    const oldStart =
        Number(match[1]) +
        skipped.filter((line) => !line.startsWith('+')).length;
    const newStart =
        Number(match[2]) +
        skipped.filter((line) => !line.startsWith('-')).length;
    const oldCount = kept.filter((line) => !line.startsWith('+')).length;
    const newCount = kept.filter((line) => !line.startsWith('-')).length;

    return [
        `@@ -${oldStart},${oldCount} +${newStart},${newCount} @@${match[3]}`,
        ...kept,
    ].join('\n');
}
