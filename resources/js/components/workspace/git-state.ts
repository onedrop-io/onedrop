/** What the header's git menu and its dialogs know about the repository (from Tools → Git's index). */
export type Change = {
    path: string;
    status: string;
    /** Lines added and removed; null for binary files and new folders (and sandboxes that don't count them yet). */
    additions?: number | null;
    deletions?: number | null;
    binary?: boolean;
};

export type GitState = {
    status: {
        branch: string | null;
        head: string | null;
        changes: Change[];
        more_changes: boolean;
        branches: string[];
        tracking: { ahead: number; behind: number } | null;
        /** Commits no remote branch has yet; null without a remote (and in sandboxes that don't count them yet). */
        unpushed?: number | null;
        state: 'merging' | 'rebasing' | null;
    };
    /** The newest commits, newest first. */
    commits: { sha: string; subject: string }[];
    remote: {
        url: string;
        host: string | null;
        sync_status: 'pushing' | 'pulling' | 'failed' | null;
        sync_error: string | null;
    } | null;
};

/** Branches that are usually a repository's default: pull requests go into them, and committing to them deserves a note. */
export const DEFAULT_BRANCHES = ['main', 'master'];
