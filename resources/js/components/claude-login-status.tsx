import { router } from '@inertiajs/react';
import { Check, LogIn } from 'lucide-react';
import { useEffect, useState } from 'react';
import ClaudeLoginController from '@/actions/App/Http/Controllers/ClaudeLoginController';
import { jsonRequest } from '@/lib/json-request';

type Status = { signed_in: boolean | null; email: string | null };

/** How often to check again while signed out, e.g. while the user signs in in the Shell tab. */
const RECHECK_MS = 5000;

/**
 * Whether Claude Code in this chat's sandbox is signed in to the user's Claude subscription (AI-005),
 * with a button that opens Claude Code's own sign-in in the Shell tab. Signing in picks the chat back
 * up: a message that failed because Claude Code wasn't signed in runs again, however the sign-in was
 * seen (the chat may have been reloaded or reopened since it failed).
 */
export default function ClaudeLoginStatus({
    projectId,
    taskId,
    working,
    waitingForSignIn,
    onSignIn,
}: {
    projectId: number;
    /** The task whose chat this is (it may have its own copy of the app, with its own sign-in). */
    taskId: number | null;
    /** Checked again after each run, which may have found the sign-in expired. */
    working: boolean;
    /** A message failed because Claude Code wasn't signed in, and runs again once it is. */
    waitingForSignIn: boolean;
    onSignIn: () => void;
}) {
    const [status, setStatus] = useState<Status | null>(null);

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

        const resume = async () => {
            try {
                const { resumed } = await jsonRequest<{ resumed: boolean }>(
                    ClaudeLoginController.resume.url(projectId),
                    taskId ? { task: taskId } : {},
                );

                if (resumed && !cancelled) {
                    router.reload();
                }
            } catch {
                // The user can still send the message again themselves.
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

    if (status?.signed_in === false) {
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
