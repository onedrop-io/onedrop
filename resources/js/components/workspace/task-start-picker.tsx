import { Check, ChevronDown, Copy, GitBranch, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { KeyboardEvent } from 'react';
import TaskController from '@/actions/App/Http/Controllers/TaskController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { jsonRequest } from '@/lib/json-request';
import { cn } from '@/lib/utils';

/** Where a new task's copy of the app starts (TASK-005); null is the server's default (the remote's default branch, else Main). */
export type TaskStart =
    | { start_from: 'branch'; start_branch: string }
    | { start_from: 'main' }
    | { start_from: 'task'; start_task_id: number };

type StartPoints = {
    copies: boolean;
    remote: boolean;
    default_branch: string | null;
    branches: { name: string; task: { id: number; title: string } | null }[];
    error: string | null;
    tasks: { id: number; title: string }[];
};

/** What the new task's form sends for a choice. */
export function taskStartData(
    start: TaskStart | null,
): Record<string, string> | undefined {
    return start
        ? Object.fromEntries(
              Object.entries(start).map(([key, value]) => [key, String(value)]),
          )
        : undefined;
}

/**
 * "Start from" in a new task's chat box (TASK-005): a branch of the remote (a fresh clone; its default branch unless
 * picked), Main's copy, or another task's copy. A branch another open task works on is marked, and the task then gets
 * a branch of its own off it, which the line under the picker says. Check out (TASK-006) starts the task there
 * with no message: its copy is made and its app started (.onedrop/dev), and the agent waits for one.
 */
export default function TaskStartPicker({
    projectId,
    value,
    onChange,
    onCheckOut,
}: {
    projectId: number;
    value: TaskStart | null;
    onChange: (start: TaskStart | null) => void;
    /** Start the task with no message; gets the branch's name, for its title. */
    onCheckOut: (branch: string | null) => void;
}) {
    const [checkingOut, setCheckingOut] = useState(false);
    const [points, setPoints] = useState<StartPoints | null>(null);
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');

    useEffect(() => {
        let current = true;

        jsonRequest<StartPoints>(TaskController.startPoints.url(projectId))
            .then((loaded) => current && setPoints(loaded))
            .catch(() => current && setPoints(null));

        return () => {
            current = false;
        };
    }, [projectId]);

    if (!points?.copies) {
        return null;
    }

    const branch =
        value?.start_from === 'branch'
            ? value.start_branch
            : value === null && points.remote
              ? points.default_branch
              : null;
    const task =
        value?.start_from === 'task'
            ? points.tasks.find((other) => other.id === value.start_task_id)
            : undefined;
    const label = branch
        ? branch
        : task
          ? task.title
          : value?.start_from === 'main' || !points.remote
            ? 'Main'
            : 'Default branch';
    const busy = branch
        ? points.branches.find((other) => other.name === branch)?.task
        : null;

    const needle = query.trim().toLowerCase();
    const branches = points.branches.filter((other) =>
        other.name.toLowerCase().includes(needle),
    );
    const tasks = points.tasks.filter((other) =>
        other.title.toLowerCase().includes(needle),
    );

    const pick = (start: TaskStart | null) => {
        setOpen(false);
        setQuery('');
        onChange(start);
    };

    const keyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Escape' || event.key === 'ArrowDown') {
            return;
        }

        event.stopPropagation();

        if (event.key === 'Enter' && branches.length === 1) {
            event.preventDefault();
            pick({ start_from: 'branch', start_branch: branches[0].name });
        }
    };

    return (
        <div className="mt-3 flex max-w-full flex-col items-center gap-1">
            <div className="flex max-w-full items-center gap-1 text-sm text-muted-foreground">
                <span className="shrink-0">Start from</span>
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
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-7 min-w-0 gap-1 px-2 font-medium"
                            title="Where this task's copy of the app starts"
                            data-test="task-start"
                        >
                            {branch ? (
                                <GitBranch className="size-4 shrink-0" />
                            ) : (
                                <Copy className="size-4 shrink-0" />
                            )}
                            <span className="max-w-56 truncate">{label}</span>
                            <ChevronDown className="size-3.5 shrink-0" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="center"
                        className="w-80"
                        data-test="task-start-menu"
                    >
                        {points.remote && (
                            <>
                                <div className="flex items-center gap-2 px-2 pb-1">
                                    <Search className="size-4 shrink-0 text-muted-foreground" />
                                    <input
                                        autoFocus
                                        value={query}
                                        onChange={(event) =>
                                            setQuery(event.target.value)
                                        }
                                        onKeyDown={keyDown}
                                        placeholder="Find a branch or task"
                                        className="h-8 min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                                        data-test="task-start-search"
                                    />
                                </div>
                                <DropdownMenuSeparator />
                            </>
                        )}
                        <div className="max-h-80 overflow-y-auto">
                            {points.remote && (
                                <>
                                    <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
                                        Fresh from a branch
                                    </DropdownMenuLabel>
                                    {points.error && (
                                        <p className="px-2 py-1.5 text-sm text-destructive">
                                            {points.error}
                                        </p>
                                    )}
                                    {branches.map((other) => (
                                        <DropdownMenuItem
                                            key={other.name}
                                            onSelect={() =>
                                                pick(
                                                    other.name ===
                                                        points.default_branch
                                                        ? null
                                                        : {
                                                              start_from:
                                                                  'branch',
                                                              start_branch:
                                                                  other.name,
                                                          },
                                                )
                                            }
                                            data-test={`task-start-branch-${other.name}`}
                                        >
                                            <Check
                                                className={cn(
                                                    'size-4 shrink-0',
                                                    other.name !== branch &&
                                                        'invisible',
                                                )}
                                            />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate">
                                                    {other.name}
                                                    {other.name ===
                                                        points.default_branch && (
                                                        <span className="ml-1.5 text-xs text-muted-foreground">
                                                            default
                                                        </span>
                                                    )}
                                                </span>
                                                {other.task && (
                                                    <span className="block truncate text-xs text-muted-foreground">
                                                        In use by “
                                                        {other.task.title}”
                                                    </span>
                                                )}
                                            </span>
                                        </DropdownMenuItem>
                                    ))}
                                    {branches.length === 0 && !points.error && (
                                        <p className="px-2 py-1.5 text-sm text-muted-foreground">
                                            No branches match.
                                        </p>
                                    )}
                                    <DropdownMenuSeparator />
                                </>
                            )}
                            <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
                                A copy, with its files, changes and data
                            </DropdownMenuLabel>
                            <DropdownMenuItem
                                onSelect={() => pick({ start_from: 'main' })}
                                data-test="task-start-main"
                            >
                                <Check
                                    className={cn(
                                        'size-4 shrink-0',
                                        label !== 'Main' && 'invisible',
                                    )}
                                />
                                <span>Main</span>
                            </DropdownMenuItem>
                            {tasks.map((other) => (
                                <DropdownMenuItem
                                    key={other.id}
                                    onSelect={() =>
                                        pick({
                                            start_from: 'task',
                                            start_task_id: other.id,
                                        })
                                    }
                                    data-test={`task-start-task-${other.id}`}
                                >
                                    <Check
                                        className={cn(
                                            'size-4 shrink-0',
                                            task?.id !== other.id &&
                                                'invisible',
                                        )}
                                    />
                                    <span className="truncate">
                                        {other.title}
                                    </span>
                                </DropdownMenuItem>
                            ))}
                        </div>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
            {(busy || (branch && branch === points.default_branch)) && (
                <p
                    className="text-xs text-muted-foreground"
                    data-test="task-start-note"
                >
                    {busy
                        ? `“${busy.title}” works on ${branch}, so this task gets its own branch off it.`
                        : 'A fresh clone, on its own branch off it.'}
                </p>
            )}
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="mt-2 max-w-full gap-1.5"
                disabled={checkingOut}
                onClick={() => {
                    setCheckingOut(true);
                    onCheckOut(branch);
                }}
                title="Make this task's copy and start its app, without the agent"
                data-test="task-check-out"
            >
                {branch ? (
                    <GitBranch className="size-4 shrink-0" />
                ) : (
                    <Copy className="size-4 shrink-0" />
                )}
                <span className="truncate">
                    {branch ? `Check out ${branch}` : `Copy ${label}`}
                </span>
            </Button>
            <p className="text-xs text-muted-foreground">
                Or describe the task below and the agent starts on it.
            </p>
        </div>
    );
}
