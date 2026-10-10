/** A staged or unstaged change to one file (SCM-001). */
export type Change = {
    path: string;
    /** M modified, A added, D deleted, R renamed, U conflicted, ? new. */
    status: string;
    /** Lines added and removed; null for binary files and new folders (and sandboxes that don't count them yet). */
    additions?: number | null;
    deletions?: number | null;
    binary?: boolean;
    /** Where a staged rename came from. */
    from?: string;
};

export type GitStatus = {
    initialized?: boolean;
    branch: string | null;
    head: string | null;
    /** What the next commit takes; empty commits every change. */
    staged: Change[];
    /** Unstaged changes, conflicted and new files included. */
    changes: Change[];
    more_changes: boolean;
    branches: string[];
    tracking: { ahead: number; behind: number } | null;
    /** Commits no remote branch has yet; null without a remote (and in sandboxes that don't count them yet). */
    unpushed?: number | null;
    state: 'merging' | 'rebasing' | null;
};

export type GitRemote = {
    url: string;
    host: string | null;
    username?: string | null;
    /** Connected through the GitHub App (no token stored). */
    github_app?: boolean;
    sync_status: 'pushing' | 'pulling' | 'failed' | null;
    sync_error: string | null;
    synced_at?: string | null;
};

/** What the header's git menu and Source Control know about the repository (from the git index endpoint). */
export type GitState = {
    status: GitStatus;
    /** The newest commits, newest first. */
    commits: { sha: string; subject: string }[];
    remote: GitRemote | null;
};

/** One of the agent's private checkpoints (SCM-002): a turn, edits made outside it, a restore or an applied task. */
export type Checkpoint = {
    sha: string;
    subject: string;
    date: string;
    /** turn, edits (made outside the agent), restore or apply (an applied task). */
    kind: string;
    files: number;
    additions: number;
    deletions: number;
    /** It has a version before it to go back to. */
    restorable_before: boolean;
};

/** Every change once per file: what committing with nothing staged commits. */
export function allChanges(status: GitStatus): Change[] {
    const byPath = new Map<string, Change>();

    [...status.staged, ...status.changes].forEach((change) =>
        byPath.set(change.path, change),
    );

    return [...byPath.values()];
}

/** Branches that are usually a repository's default: pull requests go into them, and committing to them deserves a note. */
export const DEFAULT_BRANCHES = ['main', 'master'];

const GIT_CHANGED_EVENT = 'workspace:git-changed';

/** Tell the header's git menu and Source Control that the repository changed, with what's known of it now. */
export function gitChanged(changed: Partial<GitState> = {}): void {
    window.dispatchEvent(
        new CustomEvent<Partial<GitState>>(GIT_CHANGED_EVENT, {
            detail: changed,
        }),
    );
}

/** Calls `changed` whenever the repository changed from elsewhere in the workspace. */
export function onGitChanged(
    changed: (state: Partial<GitState>) => void,
): () => void {
    const listener = (event: Event) =>
        changed((event as CustomEvent<Partial<GitState>>).detail);

    window.addEventListener(GIT_CHANGED_EVENT, listener);

    return () => window.removeEventListener(GIT_CHANGED_EVENT, listener);
}
