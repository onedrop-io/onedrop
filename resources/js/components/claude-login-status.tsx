import { router } from '@inertiajs/react';
import { Check, LogIn } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import ClaudeLoginController from '@/actions/App/Http/Controllers/ClaudeLoginController';
import { jsonRequest } from '@/lib/json-request';

type Status = { signed_in: boolean | null; email: string | null };

/** How often to check again while signed out, e.g. while the user signs in in the Shell tab. */
const RECHECK_MS = 5000;

/**
 * Whether Claude Code in this chat's sandbox is signed in to the user's Claude subscription (AI-005),
 * with a button that opens Claude Code's own sign-in in the Shell tab. Signing in picks the chat back
 * up: a message that failed because Claude Code wasn't signed in runs again, however the sign-in was
 * seen (the chat may have been reloaded or reopened since it failed). Once a sign-in it saw signed out
 * succeeds, `onSignedIn` runs (the workspace shows the Preview tab again).
 */
export default function ClaudeLoginStatus({
    projectId,
    taskId,
    working,
    waitingForSignIn,
    onSignIn,
    onSignedIn,
}: {
    projectId: number;
    /** The task whose chat this is (it may have its own copy of the app, with its own sign-in). */
    taskId: number | null;
    /** Checked again after each run, which may have found the sign-in expired. */
    working: boolean;
    /** A message failed because Claude Code wasn't signed in, and runs again once it is. */
    waitingForSignIn: boolean;
    onSignIn: () => void;
    onSignedIn: () => void;
}) {
    const [status, setStatus] = useState<Status | null>(null);
    const seenSignedOut = useRef(false);

    useEffect(() => {
        if (status?.signed_in === true) {
            if (seenSignedOut.current) {
                seenSignedOut.current = false;
                onSignedIn();
            }
        } else if (status?.signed_in === false || waitingForSignIn) {
            seenSignedOut.current = true;
        }
    }, [status, waitingForSignIn, onSignedIn]);

    useEffect(() => {
        if (working) {
            return;
        }

        let timer: ReturnType<typeof setTimeout>;
        let cancelled = false;

        const check = async () => {
            try {
                const response = await fetch(
                    ClaudeLoginController.show.url(projectId, {
                        query: taskId ? { task: taskId } : {},
                    }),
                    {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    },
                );

                if (cancelled) {
                    return;
                }

                // A failed check (e.g. throttled) says nothing about the sign-in: keep what was known and ask again.
                if (!response.ok) {
                    timer = setTimeout(check, RECHECK_MS);

                    return;
                }

                const next = (await response.json()) as Status;

                if (cancelled) {
                    return;
                }

                setStatus(next);

                if (next.signed_in !== true) {
                    timer = setTimeout(check, RECHECK_MS);
                } else if (waitingForSignIn) {
                    void resume();
                }
            } catch {
                if (!cancelled) {
                    timer = setTimeout(check, RECHECK_MS);
                }
            }
        };

        // A message still waiting after a failed try (e.g. the sandbox or database was busy) is tried again.
        const resume = async () => {
            let waiting = true;

            try {
                const result = await jsonRequest<{
                    resumed: boolean;
                    waiting: boolean;
                }>(
                    ClaudeLoginController.resume.url(projectId),
                    taskId ? { task: taskId } : {},
                );

                if (cancelled) {
                    return;
                }

                if (result.resumed || !result.waiting) {
                    router.reload();
                }

                waiting = result.waiting;
            } catch {
                // Asked again below.
            }

            if (waiting && !cancelled) {
                timer = setTimeout(check, RECHECK_MS);
            }
        };

        void check();

        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
    }, [projectId, taskId, working, waitingForSignIn]);

    if (status?.signed_in === true) {
        return (
            <p
                className="mb-2 flex items-center gap-1 px-1 text-xs text-muted-foreground"
                title="Claude Code is signed in to your Claude subscription"
                data-test="claude-login-status"
            >
                <Check className="size-3 shrink-0" />
                <span className="truncate">
                    Claude Code is signed in
                    {status.email ? ` as ${status.email}` : ''}
                </span>
            </p>
        );
    }

    // A message waiting for a sign-in has told the user to click this button, so it's there even when the check can't tell.
    if (
        status?.signed_in === false ||
        (waitingForSignIn && status?.signed_in !== true)
    ) {
        return (
            <div
                className="mb-2 flex items-center justify-between gap-3 rounded-lg border px-3 py-2 text-sm"
                data-test="claude-login-status"
            >
                <span className="text-muted-foreground">
                    Sign in to Claude to build on your subscription.
                </span>
                <button
                    type="button"
                    onClick={onSignIn}
                    className="inline-flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-xs font-medium whitespace-nowrap hover:bg-muted"
                    data-test="claude-sign-in"
                >
                    <LogIn className="size-3.5" />
                    Sign in to Claude
                </button>
            </div>
        );
    }

    return null;
}
