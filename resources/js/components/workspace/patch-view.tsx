import { cn } from '@/lib/utils';

/**
 * A unified diff's hunks, lines colored by what happened to them (git's header lines are left out).
 * Used for a commit's files (GIT-001) and for uncommitted changes before committing them (GIT-006).
 */
export default function PatchView({
    patch,
    truncated,
    className,
}: {
    patch: string;
    truncated: boolean;
    className?: string;
}) {
    // Skip git's header lines (diff --git, index, ---, +++); the hunks start at the first @@.
    const lines = patch.split('\n');
    const firstHunk = lines.findIndex((line) => line.startsWith('@@'));
    const body = (firstHunk === -1 ? lines : lines.slice(firstHunk)).filter(
        (line, index, all) => index < all.length - 1 || line !== '',
    );

    return (
        <>
            <pre
                className={cn(
                    'overflow-auto bg-muted/30 py-1 font-mono text-xs leading-5',
                    className,
                )}
                data-test="git-diff"
            >
                {body.length === 0 ? (
                    <span className="px-3 text-muted-foreground">
                        No text changes (e.g. only permissions changed).
                    </span>
                ) : (
                    body.map((line, index) => (
                        <div
                            key={index}
                            className={cn(
                                'px-3 whitespace-pre',
                                line.startsWith('@@')
                                    ? 'text-sky-600 dark:text-sky-400'
                                    : line.startsWith('+')
                                      ? 'bg-green-500/10 text-green-700 dark:text-green-400'
                                      : line.startsWith('-')
                                        ? 'bg-red-500/10 text-red-700 dark:text-red-400'
                                        : 'text-muted-foreground',
                            )}
                        >
                            {line || ' '}
                        </div>
                    ))
                )}
            </pre>
            {truncated && (
                <p className="px-3 py-1.5 text-xs text-muted-foreground">
                    This diff is too large to show in full.
                </p>
            )}
        </>
    );
}
