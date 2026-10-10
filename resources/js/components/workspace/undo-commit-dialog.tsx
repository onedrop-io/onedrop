import { useState } from 'react';
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
import type { GitState } from '@/components/workspace/git-state';
import { jsonRequest } from '@/lib/json-request';

/** What undoing returns: the new status and the newest commits. */
export type UndoResult = Pick<GitState, 'status'> & { commits: GitCommit[] };

export type GitCommit = { sha: string; subject: string };

/**
 * Undo the last commit (GIT-010), after showing which one: its changes come back as uncommitted, and the files
 * don't change. Used by the header's git menu and Source Control.
 */
export default function UndoCommitDialog({
    projectId,
    commit,
    onClose,
    onUndone,
}: {
    projectId: number;
    commit: GitCommit;
    onClose: () => void;
    onUndone: (changed: UndoResult) => void;
}) {
    const [undoing, setUndoing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const undo = () => {
        setUndoing(true);
        setError(null);
        jsonRequest<UndoResult>(
            ProjectGitController.undoCommit.url(projectId),
            {
                sha: commit.sha,
            },
        )
            .then((changed) => {
                toast.success(`Undid “${commit.subject}”`);
                onUndone(changed);
            })
            .catch((e: Error) => setError(e.message))
            .finally(() => setUndoing(false));
    };

    return (
        <DialogContent data-test="git-undo-dialog">
            <DialogHeader>
                <DialogTitle>Undo the last commit?</DialogTitle>
                <DialogDescription>
                    Its changes come back as uncommitted, so you can change them
                    or commit them again. Your files stay as they are.
                </DialogDescription>
            </DialogHeader>
            <p
                className="rounded-lg border px-3 py-2 text-sm"
                data-test="git-undo-commit"
            >
                {commit.subject}{' '}
                <span className="font-mono text-xs text-muted-foreground">
                    {commit.sha.slice(0, 7)}
                </span>
            </p>
            {error && (
                <p className="text-sm text-red-600" data-test="git-undo-error">
                    {error}
                </p>
            )}
            <DialogFooter>
                <Button variant="ghost" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    autoFocus
                    disabled={undoing}
                    onClick={undo}
                    data-test="git-undo-confirm"
                >
                    {undoing ? 'Undoing…' : 'Undo commit'}
                </Button>
            </DialogFooter>
        </DialogContent>
    );
}
