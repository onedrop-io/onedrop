import { router } from '@inertiajs/react';
import { Check, ExternalLink, LogIn, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import ClaudeLoginController from '@/actions/App/Http/Controllers/ClaudeLoginController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { jsonRequest } from '@/lib/json-request';

type Status = { signed_in: boolean | null; email: string | null };

/** How often to check again while signed out, e.g. while the user signs in in the Shell tab. */
const RECHECK_MS = 5000;

/**
 * Whether Claude Code in this chat's sandbox is signed in to the user's Claude subscription (AI-005),
 * with a button that opens a sign-in panel: it runs Claude Code's own sign-in in the sandbox, links to
 * Claude's sign-in page and takes the code that page shows (or the Shell tab does it all, `onShellSignIn`). Signing in picks the chat back
 * up: a message that failed because Claude Code wasn't signed in runs again, however the sign-in was
 * seen (the chat may have been reloaded or reopened since it failed). Once a sign-in it saw signed out
 * succeeds, `onSignedIn` runs (the workspace shows the Preview tab again).
 */
export default function ClaudeLoginStatus({
    projectId,
    taskId,
    working,
    waitingForSignIn,
    onShellSignIn,
    onSignedIn,
}: {
    projectId: number;
    /** The task whose chat this is (it may have its own copy of the app, with its own sign-in). */
    taskId: number | null;
    /** Checked again after each run, which may have found the sign-in expired. */
    working: boolean;
    /** A message failed because Claude Code wasn't signed in, and runs again once it is. */
    waitingForSignIn: boolean;
    onShellSignIn: () => void;
    onSignedIn: () => void;
}) {
    const [status, setStatus] = useState<Status | null>(null);
    const [panelOpen, setPanelOpen] = useState(false);
    // Bumped by a sign-in from the panel: check (and pick the chat up) straight away rather than at the next check.
    const [signIns, setSignIns] = useState(0);
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
    }, [projectId, taskId, working, waitingForSignIn, signIns]);

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
    if (status?.signed_in === false || waitingForSignIn) {
        if (panelOpen) {
            return (
                <ClaudeSignInPanel
                    projectId={projectId}
                    taskId={taskId}
                    onSignedIn={(signedIn) => {
                        setPanelOpen(false);
                        setStatus(signedIn);
                        setSignIns((count) => count + 1);
                    }}
                    onShellSignIn={() => {
                        setPanelOpen(false);
                        onShellSignIn();
                    }}
                    onClose={() => setPanelOpen(false)}
                />
            );
        }

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
                    onClick={() => setPanelOpen(true)}
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

/**
 * Claude Code's own sign-in, without the Shell: the sandbox starts `claude auth login` out of sight, the user
 * opens Claude's sign-in page in a new tab and pastes the code it shows here, and the sandbox types it in.
 * A code that didn't work starts a fresh sign-in, since each one works once.
 */
function ClaudeSignInPanel({
    projectId,
    taskId,
    onSignedIn,
    onShellSignIn,
    onClose,
}: {
    projectId: number;
    taskId: number | null;
    onSignedIn: (status: Status) => void;
    onShellSignIn: () => void;
    onClose: () => void;
}) {
    const [url, setUrl] = useState<string | null>(null);
    const [starts, setStarts] = useState(0);
    const [startError, setStartError] = useState<string | null>(null);
    const [code, setCode] = useState('');
    const [codeError, setCodeError] = useState<string | null>(null);
    const [sending, setSending] = useState(false);
    const codeInput = useRef<HTMLInputElement>(null);
    const query = taskId ? { task: taskId } : {};

    useEffect(() => {
        let cancelled = false;

        jsonRequest<{ url: string }>(
            ClaudeLoginController.start.url(projectId),
            query,
        ).then(
            (result) => {
                if (!cancelled) {
                    setUrl(result.url);
                }
            },
            (error: Error) => {
                if (!cancelled) {
                    setStartError(error.message);
                }
            },
        );

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps -- a new sign-in only when asked for one
    }, [projectId, taskId, starts]);

    const restart = () => {
        setUrl(null);
        setStartError(null);
        setCode('');
        setStarts((count) => count + 1);
    };

    const submit = async (event: React.FormEvent) => {
        event.preventDefault();
        setSending(true);
        setCodeError(null);

        try {
            onSignedIn(
                await jsonRequest<Status>(
                    ClaudeLoginController.code.url(projectId),
                    { ...query, code },
                ),
            );
        } catch (error) {
            const errors = (
                error as {
                    data?: { errors?: { code?: string[]; sign_in?: string[] } };
                }
            ).data?.errors;
            setCodeError(
                errors?.code?.[0] ??
                    errors?.sign_in?.[0] ??
                    (error as Error).message,
            );

            // A code Claude Code tried can't be tried again: a fresh sign-in page for the next one.
            if (!errors?.code) {
                restart();
            }
        } finally {
            setSending(false);
        }
    };

    return (
        <div
            className="mb-2 grid gap-3 rounded-lg border px-3 py-3 text-sm"
            data-test="claude-sign-in-panel"
        >
            <div className="flex items-start justify-between gap-3">
                <p className="font-medium">Sign in to Claude</p>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Close"
                    className="rounded p-0.5 text-muted-foreground hover:bg-muted"
                >
                    <X className="size-3.5" />
                </button>
            </div>
            {startError ? (
                <p
                    className="text-destructive"
                    data-test="claude-sign-in-error"
                >
                    {startError}
                </p>
            ) : (
                <>
                    <div className="grid gap-1.5">
                        <p className="text-muted-foreground">
                            1. Approve OneDrop's Claude Code on Claude's sign-in
                            page.
                        </p>
                        <div>
                            {url ? (
                                <Button asChild size="sm" variant="outline">
                                    <a
                                        href={url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        onClick={() =>
                                            codeInput.current?.focus()
                                        }
                                        data-test="claude-sign-in-page"
                                    >
                                        <ExternalLink />
                                        Open Claude's sign-in page
                                    </a>
                                </Button>
                            ) : (
                                <Button size="sm" variant="outline" disabled>
                                    <Spinner />
                                    Getting Claude's sign-in page…
                                </Button>
                            )}
                        </div>
                    </div>
                    <form onSubmit={submit} className="grid gap-1.5">
                        <label
                            htmlFor="claude-sign-in-code"
                            className="text-muted-foreground"
                        >
                            2. Paste the code it shows you.
                        </label>
                        <div className="flex gap-2">
                            <Input
                                id="claude-sign-in-code"
                                ref={codeInput}
                                value={code}
                                onChange={(event) =>
                                    setCode(event.target.value)
                                }
                                placeholder="Code from Claude"
                                autoComplete="off"
                                spellCheck={false}
                                disabled={!url || sending}
                                aria-invalid={codeError !== null}
                                data-test="claude-sign-in-code"
                            />
                            <Button
                                type="submit"
                                size="sm"
                                className="h-9"
                                disabled={!url || sending || !code.trim()}
                                data-test="claude-sign-in-submit"
                            >
                                {sending && <Spinner />}
                                Sign in
                            </Button>
                        </div>
                        {codeError && (
                            <p
                                className="text-destructive"
                                data-test="claude-sign-in-error"
                            >
                                {codeError}
                            </p>
                        )}
                    </form>
                </>
            )}
            <p className="text-xs text-muted-foreground">
                OneDrop passes the code to Claude Code in your sandbox and keeps
                nothing.{' '}
                <button
                    type="button"
                    onClick={onShellSignIn}
                    className="underline underline-offset-2 hover:text-foreground"
                    data-test="claude-sign-in-shell"
                >
                    Sign in in the Shell instead
                </button>
            </p>
        </div>
    );
}
