import {
    Head,
    Link,
    router,
    setLayoutProps,
    useForm,
    usePoll,
} from '@inertiajs/react';
import { EllipsisVertical, Play, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { DragEvent } from 'react';
import TaskController from '@/actions/App/Http/Controllers/TaskController';
import TaskMessageController from '@/actions/App/Http/Controllers/TaskMessageController';
import InputError from '@/components/input-error';
import { TaskStatusIcon } from '@/components/task-status-icon';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { useWorkspaceLinks } from '@/lib/workspace-view';
import { board, show } from '@/routes/projects';
import {
    create as createTask,
    show as showTask,
} from '@/routes/projects/tasks';
import type { BoardTask, Project, TaskStage } from '@/types';

type Stage = { value: TaskStage; label: string };

/** Carries the dragged card's id between columns. */
const DRAG_TYPE = 'application/x-onedrop-task';

/**
 * A project's tasks as a kanban board (TASK-002): To do, In progress, Review and Done. Cards move
 * by dragging or from their menu; To do cards start a fresh agent with their title and notes.
 */
export default function Board({
    project,
    stages,
    tasks,
}: {
    project: Pick<Project, 'id' | 'name' | 'status'>;
    stages: Stage[];
    tasks: BoardTask[];
}) {
    const working = tasks.some((task) => task.status === 'working');
    const { start, stop } = usePoll(
        1500,
        { only: ['tasks'] },
        { autoStart: false },
    );

    setLayoutProps({
        breadcrumbs: [
            { title: project.name, href: show(project.id) },
            { title: 'Board', href: board(project.id) },
        ],
    });

    // Cards move to Review, and their activity changes, while agents work.
    useEffect(() => {
        if (working) {
            start();
        } else {
            stop();
        }

        return stop;
    }, [working, start, stop]);

    const move = (taskId: number, stage: TaskStage) => {
        const task = tasks.find((candidate) => candidate.id === taskId);

        if (!task || task.stage === stage) {
            return;
        }

        router.patch(
            TaskController.update.url({ project: project.id, task: taskId }),
            { stage },
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <>
            <Head title={`Board · ${project.name}`} />
            <div className="flex h-[calc(100svh-4rem)] min-h-0 flex-col md:h-[calc(100svh-5rem)]">
                <div className="flex items-center justify-between gap-2 px-4 py-3">
                    <h1 className="text-lg font-semibold">Board</h1>
                    <Button asChild size="sm" data-test="board-new-task">
                        <Link href={createTask(project.id)}>
                            <Plus />
                            New task
                        </Link>
                    </Button>
                </div>
                <div className="flex min-h-0 flex-1 gap-3 overflow-x-auto px-4 pb-4">
                    {stages.map((stage) => (
                        <Column
                            key={stage.value}
                            projectId={project.id}
                            stage={stage}
                            stages={stages}
                            tasks={tasks.filter(
                                (task) => task.stage === stage.value,
                            )}
                            onMove={move}
                        />
                    ))}
                </div>
            </div>
        </>
    );
}

function Column({
    projectId,
    stage,
    stages,
    tasks,
    onMove,
}: {
    projectId: number;
    stage: Stage;
    stages: Stage[];
    tasks: BoardTask[];
    onMove: (taskId: number, stage: TaskStage) => void;
}) {
    const [dragOver, setDragOver] = useState(false);

    const accepts = (event: DragEvent) =>
        event.dataTransfer.types.includes(DRAG_TYPE);

    return (
        <section
            aria-label={stage.label}
            className={cn(
                'flex w-72 shrink-0 flex-col rounded-xl bg-muted/50 p-2 transition-colors',
                dragOver && 'bg-muted ring-2 ring-sky-500/50',
            )}
            onDragOver={(event) => {
                if (accepts(event)) {
                    event.preventDefault();
                    event.dataTransfer.dropEffect = 'move';
                    setDragOver(true);
                }
            }}
            onDragLeave={(event) => {
                if (
                    !event.currentTarget.contains(
                        event.relatedTarget as Node | null,
                    )
                ) {
                    setDragOver(false);
                }
            }}
            onDrop={(event) => {
                event.preventDefault();
                setDragOver(false);

                const taskId = Number(event.dataTransfer.getData(DRAG_TYPE));

                if (taskId) {
                    onMove(taskId, stage.value);
                }
            }}
            data-test={`board-column-${stage.value}`}
        >
            <h2 className="flex items-center gap-2 px-2 py-1.5 text-sm font-medium">
                <TaskStatusIcon stage={stage.value} working={false} />
                {stage.label}
                <span className="text-muted-foreground">{tasks.length}</span>
            </h2>
            <div className="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto p-0.5">
                {stage.value === 'todo' && <AddCard projectId={projectId} />}
                {tasks.map((task) => (
                    <Card
                        key={task.id}
                        projectId={projectId}
                        task={task}
                        stages={stages}
                        onMove={onMove}
                    />
                ))}
            </div>
        </section>
    );
}

function Card({
    projectId,
    task,
    stages,
    onMove,
}: {
    projectId: number;
    task: BoardTask;
    stages: Stage[];
    onMove: (taskId: number, stage: TaskStage) => void;
}) {
    const ids = { project: projectId, task: task.id };
    const working = task.status === 'working';
    // Opens on the tab and tool the user last had open in this project (TASK-004).
    const link = useWorkspaceLinks();

    const startTask = () =>
        router.post(
            TaskMessageController.store.url(ids),
            {
                content: [task.title, task.description]
                    .filter(Boolean)
                    .join('\n\n'),
                stay: true,
            },
            { preserveScroll: true },
        );

    return (
        <article
            draggable
            onDragStart={(event) => {
                event.dataTransfer.setData(DRAG_TYPE, String(task.id));
                event.dataTransfer.effectAllowed = 'move';
            }}
            className="group relative rounded-lg border border-sidebar-border/70 bg-background shadow-xs hover:border-input dark:border-sidebar-border"
            data-test="board-card"
        >
            <Link
                href={link(projectId, showTask(ids).url)}
                className="block p-3 pr-8"
                data-test="board-card-open"
            >
                <p className="flex items-start gap-2 text-sm font-medium">
                    <TaskStatusIcon
                        stage={task.stage}
                        working={working}
                        className="mt-0.5"
                    />
                    <span className="min-w-0 break-words">{task.title}</span>
                </p>
                {task.description && (
                    <p className="mt-1 line-clamp-2 pl-6 text-xs text-muted-foreground">
                        {task.description}
                    </p>
                )}
                {working && (
                    <p
                        className="mt-1 truncate pl-6 text-xs text-muted-foreground"
                        data-test="board-card-activity"
                    >
                        {task.activity ?? 'Working…'}
                    </p>
                )}
            </Link>
            {task.stage === 'todo' && !working && (
                <div className="px-3 pb-3">
                    <Button
                        size="sm"
                        variant="secondary"
                        className="h-7 w-full"
                        onClick={startTask}
                        data-test="board-card-start"
                    >
                        <Play />
                        Start
                    </Button>
                </div>
            )}
            <DropdownMenu modal={false}>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        aria-label={`Actions for ${task.title}`}
                        className="absolute top-2.5 right-2 rounded p-0.5 text-muted-foreground opacity-0 group-hover:opacity-100 hover:bg-muted focus-visible:opacity-100 data-[state=open]:opacity-100"
                        data-test="board-card-menu"
                    >
                        <EllipsisVertical className="size-4" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-48">
                    {stages
                        .filter((stage) => stage.value !== task.stage)
                        .map((stage) => (
                            <DropdownMenuItem
                                key={stage.value}
                                onSelect={() => onMove(task.id, stage.value)}
                                data-test={`board-card-move-${stage.value}`}
                            >
                                <TaskStatusIcon
                                    stage={stage.value}
                                    working={false}
                                />
                                Move to {stage.label}
                            </DropdownMenuItem>
                        ))}
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        onSelect={() => {
                            if (
                                window.confirm(
                                    `Delete “${task.title}” and its chat?`,
                                )
                            ) {
                                router.delete(TaskController.destroy.url(ids), {
                                    preserveScroll: true,
                                });
                            }
                        }}
                        data-test="board-card-delete"
                    >
                        <Trash2 />
                        Delete
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </article>
    );
}

/** Add a card to To do: a title and optional notes, without starting an agent. */
function AddCard({ projectId }: { projectId: number }) {
    const [adding, setAdding] = useState(false);
    const form = useForm({ title: '', description: '' });

    if (!adding) {
        return (
            <button
                type="button"
                onClick={() => setAdding(true)}
                className="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-muted-foreground hover:bg-background"
                data-test="board-add-card"
            >
                <Plus className="size-4" />
                Add a card
            </button>
        );
    }

    return (
        <form
            className="space-y-2 rounded-lg border border-input bg-background p-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(TaskController.store.url(projectId), {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <Input
                value={form.data.title}
                onChange={(event) => form.setData('title', event.target.value)}
                onKeyDown={(event) => {
                    if (event.key === 'Escape') {
                        setAdding(false);
                    }
                }}
                placeholder="What needs doing?"
                aria-label="Card title"
                maxLength={80}
                autoFocus
                data-test="board-card-title"
            />
            <textarea
                value={form.data.description}
                onChange={(event) =>
                    form.setData('description', event.target.value)
                }
                placeholder="Notes for the agent (optional)"
                aria-label="Card notes"
                rows={2}
                className="w-full resize-none rounded-md border border-input bg-transparent px-3 py-1.5 text-sm placeholder:text-muted-foreground"
                data-test="board-card-notes"
            />
            <InputError message={form.errors.title} />
            <div className="flex justify-end gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() => {
                        form.reset();
                        form.clearErrors();
                        setAdding(false);
                    }}
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    size="sm"
                    disabled={form.processing}
                    data-test="board-card-save"
                >
                    Add
                </Button>
            </div>
        </form>
    );
}
