import { Bot, User } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import ProjectGitController from '@/actions/App/Http/Controllers/ProjectGitController';
import { Button } from '@/components/ui/button';
import {
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import type { GitState } from '@/components/workspace/git-state';
import { jsonRequest } from '@/lib/json-request';

type Commit = { sha: string; subject: string; author: string; agent: boolean };

/**
 * Combine the commits that aren't pushed yet into one (GIT-008), with a message the project's AI wrote from them.
 */
export default function CombineCommitsDialog({
    projectId,
    working,
    onClose,
    onCombined,
}: {
    projectId: number;
    working: boolean;
    onClose: () => void;
    onCombined: (changed: Pick<GitState, 'status'>) => void;
}) {
    const [commits, setCommits] = useState<Commit[] | null>(null);
    const [message, setMessage] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [combining, setCombining] = useState(false);
    const messageField = useRef<HTMLTextAreaElement>(null);

    useEffect(() => {
        let cancelled = false;

        jsonRequest<{ commits: Commit[]; message: string }>(
            ProjectGitController.combineDraft.url(projectId),
            {},
        )
            .then((draft) => {
                if (!cancelled) {
                    setCommits(draft.commits);
                    setMessage(draft.message);
                }
            })
            .catch((e: Error) => !cancelled && setError(e.message));

        return () => {
            cancelled = true;
        };
    }, [projectId]);

    // Written: put the cursor in the message to check it.
    useEffect(() => {
        if (commits) {
            messageField.current?.focus();
            messageField.current?.setSelectionRange(0, 0);
        }
    }, [commits]);

    const submit = (event?: FormEvent) => {
        event?.preventDefault();

        if (!commits || message.trim() === '' || combining) {
            return;
        }

        setCombining(true);
        setError(null);
        jsonRequest<Pick<GitState, 'status'>>(
            ProjectGitController.combine.url(projectId),
            { message },
        )
            .then((changed) => {
                toast.success(`Combined ${commits.length} commits`);
                onCombined(changed);
            })
            .catch((e: Error) => setError(e.message))
            .finally(() => setCombining(false));
    };

    return (
        <DialogContent className="sm:max-w-xl" data-test="git-combine-dialog">
            <form onSubmit={submit} className="grid min-w-0 gap-5">
                <DialogHeader>
                    <DialogTitle>
                        Combine {commits ? `${commits.length} ` : ''}commits
                    </DialogTitle>
                    <DialogDescription>
                        The commits that aren't pushed yet become one, so the
                        repository gets a single tidy change. Your files stay
                        exactly as they are.
                    </DialogDescription>
                </DialogHeader>

                {!commits && !error ? (
                    <div className="space-y-3" data-test="git-combine-writing">
                        <p className="text-sm text-muted-foreground">
                            Writing the message…
                        </p>
                        <Skeleton className="h-32 w-full animate-pulse" />
                        <Skeleton className="h-24 w-full animate-pulse" />
                    </div>
                ) : (
                    commits && (
                        <>
                            <ul
                                className="max-h-48 divide-y divide-border/50 overflow-y-auto rounded-lg border text-sm"
                                data-test="git-combine-commits"
                            >
                                {commits.map((commit) => (
                                    <li
                                        key={commit.sha}
                                        className="flex items-center gap-2 px-3 py-1.5"
                                    >
                                        {commit.agent ? (
                                            <Bot
                                                className="size-3.5 shrink-0 text-muted-foreground"
                                                aria-label="The agent"
                                            />
                                        ) : (
                                            <User
                                                className="size-3.5 shrink-0 text-muted-foreground"
                                                aria-label={commit.author}
                                            />
                                        )}
                                        <span className="min-w-0 flex-1 truncate">
                                            {commit.subject}
                                        </span>
                                        <span className="shrink-0 font-mono text-xs text-muted-foreground">
                                            {commit.sha.slice(0, 7)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            <div className="space-y-2">
                                <label
                                    htmlFor="git-combine-message"
                                    className="text-sm font-medium"
                                >
                                    Message for the combined commit
                                </label>
                                <textarea
                                    id="git-combine-message"
                                    ref={messageField}
                                    rows={6}
                                    value={message}
                                    onChange={(event) =>
                                        setMessage(event.target.value)
                                    }
                                    onKeyDown={(event) => {
                                        if (
                                            event.key === 'Enter' &&
                                            (event.metaKey || event.ctrlKey)
                                        ) {
                                            submit();
                                        }
                                    }}
                                    className="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 font-mono text-xs leading-5 shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                    data-test="git-combine-message"
                                />
                            </div>
                        </>
                    )
                )}

                {error && (
                    <p
                        className="text-sm text-red-600"
                        data-test="git-combine-error"
                    >
                        {error}
                    </p>
                )}

                <DialogFooter className="gap-2">
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        disabled={
                            !commits ||
                            message.trim() === '' ||
                            combining ||
                            working
                        }
                        data-test="git-combine-submit"
                    >
                        {combining
                            ? 'Combining…'
                            : `Combine${commits ? ` ${commits.length}` : ''} commits`}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}
