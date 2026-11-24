import {
    Check,
    ChevronDown,
    Cloud,
    GitBranch,
    Plus,
    Search,
} from 'lucide-react';
import { useState } from 'react';
import type { KeyboardEvent } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { GitStatus } from '@/components/workspace/git-state';
import { cn } from '@/lib/utils';

/** The branch names the sandbox's git tool accepts (BRANCH_PATTERN in docker/sandbox/git.php). */
const BRANCH_NAME =
    /^(?!-)(?!.*\.\.)(?!.*\/\/)(?!.*@\{)[A-Za-z0-9._/-]{1,100}(?<!\.lock)(?<![/.])$/;

/**
 * The current branch, and a searchable menu to switch to another (the remote's included) or create one from the
 * current commit by typing its name (GIT-002, GIT-025). Enter picks the only match, or creates the typed name.
 */
export default function BranchPicker({
    status,
    disabled,
    disabledReason,
    onSwitch,
    className,
    testId,
}: {
    status: GitStatus;
    disabled: boolean;
    /** Why it's disabled, as its tooltip. */
    disabledReason?: string;
    /** Switch to a branch, or create it; resolves to whether it worked. */
    onSwitch: (branch: string, create: boolean) => Promise<boolean>;
    className?: string;
    testId: string;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');

    const current = status.branch;
    const needle = query.trim().toLowerCase();
    const matches = (name: string) => name.toLowerCase().includes(needle);
    const local = status.branches.filter(matches);
    const remote = (status.remote_branches ?? []).filter(matches);
    const name = query.trim();
    const exists =
        status.branches.includes(name) ||
        (status.remote_branches ?? []).includes(name);
    const creatable = name !== '' && !exists && BRANCH_NAME.test(name);

    const pick = (branch: string, create = false) => {
        setOpen(false);
        setQuery('');

        if (create || branch !== current) {
            void onSwitch(branch, create);
        }
    };

    const keyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        // Let the menu move to its items and close; everything else types.
        if (event.key === 'Escape' || event.key === 'ArrowDown') {
            return;
        }

        event.stopPropagation();

        if (event.key === 'Enter') {
            event.preventDefault();
            const only = [...local, ...remote];

            if (only.length === 1) {
                pick(only[0]);
            } else if (creatable) {
                pick(name, true);
            }
        }
    };

    const label = current ?? (status.head ? 'Detached' : 'main');

    return (
        <DropdownMenu
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (!next) {
                    setQuery('');
                }
            }}
        >
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    className={cn(
                        'h-7 min-w-0 gap-1 px-2 font-medium',
                        className,
                    )}
                    disabled={disabled || !status.head}
                    title={
                        !status.head
                            ? 'Branches work once the project has its first commit'
                            : disabled && disabledReason
                              ? disabledReason
                              : `On ${label}. Switch branch`
                    }
                    data-test={testId}
                >
                    <GitBranch className="size-4 shrink-0 text-muted-foreground" />
                    <span className="truncate">{label}</span>
                    <ChevronDown className="size-3.5 shrink-0 text-muted-foreground" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                className="w-72"
                data-test={`${testId}-menu`}
            >
                <div className="flex items-center gap-2 px-2 pb-1">
                    <Search className="size-4 shrink-0 text-muted-foreground" />
                    <input
                        autoFocus
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        onKeyDown={keyDown}
                        placeholder="Find or create a branch"
                        className="h-8 min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                        data-test={`${testId}-search`}
                    />
                </div>
                <DropdownMenuSeparator />
                <div className="max-h-72 overflow-y-auto">
                    {local.map((branch) => (
                        <DropdownMenuItem
                            key={branch}
                            onSelect={() => pick(branch)}
                            data-test={`${testId}-${branch}`}
                        >
                            <Check
                                className={cn(
                                    'size-4',
                                    branch !== current && 'invisible',
                                )}
                            />
                            <span className="truncate">{branch}</span>
                        </DropdownMenuItem>
                    ))}
                    {remote.length > 0 && (
                        <>
                            <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
                                On the remote
                            </DropdownMenuLabel>
                            {remote.map((branch) => (
                                <DropdownMenuItem
                                    key={branch}
                                    onSelect={() => pick(branch)}
                                    data-test={`${testId}-${branch}`}
                                >
                                    <Cloud className="size-4 text-muted-foreground" />
                                    <span className="truncate">{branch}</span>
                                </DropdownMenuItem>
                            ))}
                        </>
                    )}
                    {local.length === 0 &&
                        remote.length === 0 &&
                        !creatable && (
                            <p className="px-2 py-1.5 text-sm text-muted-foreground">
                                {name !== '' && !exists
                                    ? "That isn't a valid branch name."
                                    : 'No branches match.'}
                            </p>
                        )}
                </div>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    disabled={!creatable}
                    onSelect={() => pick(name, true)}
                    data-test={`${testId}-create`}
                >
                    <Plus className="size-4" />
                    <span className="truncate">
                        {creatable
                            ? `Create branch “${name}”`
                            : 'Type a name to create a branch'}
                    </span>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
