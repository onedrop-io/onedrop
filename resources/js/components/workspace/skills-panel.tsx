import {
    ChevronDown,
    EllipsisVertical,
    FolderGit2,
    Github,
    LayoutGrid,
    List,
    PenLine,
    Plus,
    Sparkles,
    Upload,
    WandSparkles,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';
import ProjectSkillController from '@/actions/App/Http/Controllers/ProjectSkillController';
import SkillController from '@/actions/App/Http/Controllers/SkillController';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Switch } from '@/components/ui/switch';
import RequirementsToggle from '@/components/workspace/requirements-toggle';
import {
    CreateWithAgentDialog,
    DeleteSkillDialog,
    ImportSkillDialog,
    SkillFormDialog,
    UploadSkillDialog,
    ViewSkillDialog,
} from '@/components/workspace/skill-dialogs';
import type {
    LibrarySkill,
    ProjectSkill,
} from '@/components/workspace/skill-dialogs';
import { jsonRequest } from '@/lib/json-request';
import { SKILLS_DOCS_URL } from '@/lib/links';
import { cn } from '@/lib/utils';

const FILTERS = [
    { id: 'all', label: 'All' },
    { id: 'enabled', label: 'Enabled' },
    { id: 'mine', label: 'Created by you' },
    { id: 'shared', label: 'Shared with you' },
    { id: 'project', label: 'Project skills' },
] as const;

type Filter = (typeof FILTERS)[number]['id'];

type View = 'grid' | 'list';

const VIEW_KEY = 'onedrop.skills-view';

type Item =
    | { kind: 'library'; skill: LibrarySkill; overridden: boolean }
    | { kind: 'project'; skill: ProjectSkill };

type Open =
    | { dialog: 'write' | 'agent' | 'import' | 'upload' }
    | { dialog: 'edit' | 'delete'; skill: LibrarySkill }
    | { dialog: 'view'; item: Item }
    | null;

/**
 * Tools → Agent Skills (SKILL-001..003): the user's skills and shared ones, turned on per project, plus the
 * skills in the project's repository. The agent gets the ones that are on before each run (SKILL-004).
 */
export default function SkillsPanel({
    projectId,
    running,
    working,
}: {
    projectId: number;
    running: boolean;
    /** The agent is running a task. */
    working: boolean;
}) {
    const [skills, setSkills] = useState<LibrarySkill[] | null>(null);
    const [projectSkills, setProjectSkills] = useState<ProjectSkill[] | null>(
        null,
    );
    const [projectError, setProjectError] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [filter, setFilter] = useState<Filter>('all');
    const [view, setView] = useState<View>(() =>
        localStorage.getItem(VIEW_KEY) === 'list' ? 'list' : 'grid',
    );
    const [open, setOpen] = useState<Open>(null);

    const load = useCallback(
        () =>
            jsonRequest<{ skills: LibrarySkill[] }>(
                ProjectSkillController.index.url(projectId),
            )
                .then(({ skills }) => {
                    setSkills(skills);
                    setError(null);
                })
                .catch((e: Error) => setError(e.message)),
        [projectId],
    );

    useEffect(() => {
        void load();
    }, [load]);

    useEffect(() => {
        if (!running) {
            setProjectSkills(null);

            return;
        }

        let cancelled = false;

        jsonRequest<{ skills: ProjectSkill[] }>(
            ProjectSkillController.projectIndex.url(projectId),
        )
            .then(({ skills }) => {
                if (!cancelled) {
                    setProjectSkills(skills);
                    setProjectError(null);
                }
            })
            .catch((e: Error) => !cancelled && setProjectError(e.message));

        return () => {
            cancelled = true;
        };
        // Reload when the agent finishes, e.g. after it wrote a skill.
    }, [projectId, running, working]);

    const chooseView = (next: View) => {
        setView(next);
        localStorage.setItem(VIEW_KEY, next);
    };

    const close = () => setOpen(null);

    const added = (changed: { skills: LibrarySkill[] } | null) => {
        if (changed) {
            setSkills(changed.skills);
        } else {
            void load();
        }

        close();
    };

    const toggle = (skill: LibrarySkill, enabled: boolean) => {
        setSkills((current) =>
            (current ?? []).map((s) =>
                s.id === skill.id ? { ...s, enabled } : s,
            ),
        );

        jsonRequest<{ skills: LibrarySkill[] }>(
            ProjectSkillController.toggle.url({
                project: projectId,
                skill: skill.id,
            }),
            { enabled },
            'PUT',
        )
            .then(({ skills }) => setSkills(skills))
            .catch((e: Error) => {
                void load();
                toast.error(e.message);
            });
    };

    const share = (skill: LibrarySkill, shared: boolean) =>
        jsonRequest(SkillController.update.url(skill.id), { shared }, 'PATCH')
            .then(() => {
                void load();
                toast.success(
                    shared
                        ? `Shared ${skill.name} with everyone`
                        : `Stopped sharing ${skill.name}`,
                );
            })
            .catch((e: Error) => toast.error(e.message));

    const saveProjectSkill = (skill: ProjectSkill) =>
        jsonRequest<{ skills: LibrarySkill[] }>(
            ProjectSkillController.saveProject.url(projectId),
            { path: skill.path },
        )
            .then(({ skills }) => {
                setSkills(skills);
                close();
                toast.success(`Saved ${skill.name} to your skills`);
            })
            .catch((e: Error) => toast.error(e.message));

    const projectNames = new Set((projectSkills ?? []).map((s) => s.name));
    const library: Item[] = (skills ?? []).map((skill) => ({
        kind: 'library',
        skill,
        overridden: skill.enabled && projectNames.has(skill.name),
    }));
    const project: Item[] = (projectSkills ?? []).map((skill) => ({
        kind: 'project',
        skill,
    }));

    const items = {
        all: [...library, ...project],
        enabled: [
            ...library.filter((i) => i.kind === 'library' && i.skill.enabled),
            ...project,
        ],
        mine: library.filter((i) => i.kind === 'library' && i.skill.mine),
        shared: library.filter((i) => i.kind === 'library' && !i.skill.mine),
        project,
    }[filter];

    return (
        <div
            className="@container max-w-5xl space-y-6"
            data-test="skills-panel"
        >
            <p className="text-sm text-muted-foreground">
                Skills are instructions the agent loads when a task calls for
                them. Turn yours on for this project, or keep skills in the
                project itself.{' '}
                <a
                    href={SKILLS_DOCS_URL}
                    target="_blank"
                    rel="noreferrer"
                    className="text-blue-500 hover:underline"
                >
                    Learn more about skills
                </a>
            </p>

            <section
                className="flex items-center gap-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                data-test="skills-built-in"
            >
                <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium">Requirements</p>
                    <p className="text-sm text-muted-foreground">
                        The agent writes down what you ask for (“User should be
                        able to…”) and the decisions behind it, and writes and
                        runs a browser test for each, with a recording. See them
                        in the Requirements and Tests tabs (+ menu).
                    </p>
                </div>
                <RequirementsToggle projectId={projectId} />
            </section>

            <div className="flex flex-wrap items-center gap-2">
                <div className="flex flex-wrap gap-1" role="tablist">
                    {FILTERS.map(({ id, label }) => (
                        <button
                            key={id}
                            type="button"
                            role="tab"
                            aria-selected={filter === id}
                            onClick={() => setFilter(id)}
                            data-test={`skills-filter-${id}`}
                            className={cn(
                                'rounded-md px-3 py-1.5 text-sm text-muted-foreground hover:text-foreground',
                                filter === id &&
                                    'bg-muted font-medium text-foreground',
                            )}
                        >
                            {label}
                        </button>
                    ))}
                </div>

                <div className="ml-auto flex items-center gap-2">
                    <div className="flex rounded-md bg-muted/60 p-0.5">
                        {(
                            [
                                ['grid', LayoutGrid, 'Grid'],
                                ['list', List, 'List'],
                            ] as const
                        ).map(([id, Icon, label]) => (
                            <button
                                key={id}
                                type="button"
                                aria-label={`${label} view`}
                                aria-pressed={view === id}
                                onClick={() => chooseView(id)}
                                data-test={`skills-view-${id}`}
                                className={cn(
                                    'rounded p-1.5 text-muted-foreground',
                                    view === id &&
                                        'bg-background text-foreground shadow-sm',
                                )}
                            >
                                <Icon className="size-4" />
                            </button>
                        ))}
                    </div>

                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="outline"
                                size="sm"
                                data-test="skills-add"
                            >
                                <Plus /> Add <ChevronDown />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                onSelect={() => setOpen({ dialog: 'write' })}
                                data-test="skills-add-write"
                            >
                                <PenLine /> Write a skill
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                onSelect={() => setOpen({ dialog: 'agent' })}
                                data-test="skills-add-agent"
                            >
                                <WandSparkles /> Create with agent
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                onSelect={() => setOpen({ dialog: 'import' })}
                                data-test="skills-add-import"
                            >
                                <Github /> Import from GitHub
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                onSelect={() => setOpen({ dialog: 'upload' })}
                                data-test="skills-add-upload"
                            >
                                <Upload /> Upload
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            {error ? (
                <Empty tone="error">{error}</Empty>
            ) : skills === null ? (
                <Empty>Loading…</Empty>
            ) : items.length === 0 ? (
                <EmptyState
                    filter={filter}
                    running={running}
                    projectError={projectError}
                />
            ) : (
                <>
                    <ul
                        className={cn(
                            view === 'grid'
                                ? 'grid gap-3 @xl:grid-cols-2 @4xl:grid-cols-3'
                                : 'divide-y rounded-xl border border-sidebar-border/70 dark:border-sidebar-border',
                        )}
                        data-test="skills-list"
                    >
                        {items.map((item) => (
                            <SkillCard
                                key={
                                    item.kind === 'library'
                                        ? `skill-${item.skill.id}`
                                        : `project-${item.skill.path}`
                                }
                                item={item}
                                view={view}
                                onOpen={() => setOpen({ dialog: 'view', item })}
                                onToggle={toggle}
                                onEdit={(skill) =>
                                    setOpen({ dialog: 'edit', skill })
                                }
                                onShare={share}
                                onDelete={(skill) =>
                                    setOpen({ dialog: 'delete', skill })
                                }
                                onSave={saveProjectSkill}
                            />
                        ))}
                    </ul>
                    {filter !== 'project' &&
                        filter !== 'mine' &&
                        filter !== 'shared' &&
                        !running && (
                            <p className="text-xs text-muted-foreground">
                                Project skills show when the sandbox is running.
                            </p>
                        )}
                </>
            )}

            <Dialog open={open !== null} onOpenChange={(o) => !o && close()}>
                {open?.dialog === 'write' && (
                    <SkillFormDialog projectId={projectId} onSaved={added} />
                )}
                {open?.dialog === 'edit' && (
                    <SkillFormDialog
                        projectId={projectId}
                        skill={open.skill}
                        onSaved={added}
                    />
                )}
                {open?.dialog === 'agent' && (
                    <CreateWithAgentDialog
                        projectId={projectId}
                        onSent={(queued) => {
                            close();
                            toast.success(
                                queued
                                    ? 'Asked the agent. It runs after the current task; follow along in the chat.'
                                    : 'The agent is writing the skill. It shows up under Project skills when it’s done.',
                            );
                        }}
                    />
                )}
                {open?.dialog === 'import' && (
                    <ImportSkillDialog projectId={projectId} onAdded={added} />
                )}
                {open?.dialog === 'upload' && (
                    <UploadSkillDialog projectId={projectId} onAdded={added} />
                )}
                {open?.dialog === 'delete' && (
                    <DeleteSkillDialog
                        skill={open.skill}
                        onClose={close}
                        onDeleted={() => {
                            toast.success(`Deleted ${open.skill.name}`);
                            added(null);
                        }}
                    />
                )}
                {open?.dialog === 'view' &&
                    (open.item.kind === 'library' ? (
                        <ViewSkillDialog
                            projectId={projectId}
                            title={open.item.skill.name}
                            subtitle={open.item.skill.description}
                            source={{ id: open.item.skill.id }}
                            actions={
                                open.item.skill.can_edit && (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            open.item.kind === 'library' &&
                                            setOpen({
                                                dialog: 'edit',
                                                skill: open.item.skill,
                                            })
                                        }
                                    >
                                        Edit
                                    </Button>
                                )
                            }
                        />
                    ) : (
                        <ViewSkillDialog
                            projectId={projectId}
                            title={open.item.skill.name}
                            subtitle={
                                <>
                                    {open.item.skill.description ??
                                        'No description.'}{' '}
                                    <span className="font-mono text-xs">
                                        {open.item.skill.path}
                                    </span>
                                </>
                            }
                            source={{ path: open.item.skill.path }}
                            actions={
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        open.item.kind === 'project' &&
                                        saveProjectSkill(open.item.skill)
                                    }
                                    data-test="skill-save-project"
                                >
                                    Save to your skills
                                </Button>
                            }
                        />
                    ))}
            </Dialog>
        </div>
    );
}

function SkillCard({
    item,
    view,
    onOpen,
    onToggle,
    onEdit,
    onShare,
    onDelete,
    onSave,
}: {
    item: Item;
    view: View;
    onOpen: () => void;
    onToggle: (skill: LibrarySkill, enabled: boolean) => void;
    onEdit: (skill: LibrarySkill) => void;
    onShare: (skill: LibrarySkill, shared: boolean) => void;
    onDelete: (skill: LibrarySkill) => void;
    onSave: (skill: ProjectSkill) => void;
}) {
    const { skill } = item;
    const Icon = item.kind === 'project' ? FolderGit2 : Sparkles;
    const origin =
        item.kind === 'project'
            ? `In the project · ${item.skill.path}`
            : item.skill.mine
              ? item.skill.shared
                  ? 'Yours · shared with everyone'
                  : 'Yours'
              : `Shared by ${item.skill.owner ?? 'someone'}`;

    const menu = (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    size="icon"
                    variant="ghost"
                    className="size-8 shrink-0"
                    aria-label={`More options for ${skill.name}`}
                    data-test={`skill-menu-${skill.name}`}
                >
                    <EllipsisVertical />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem onSelect={onOpen}>View</DropdownMenuItem>
                {item.kind === 'project' ? (
                    <DropdownMenuItem
                        onSelect={() => onSave(item.skill)}
                        data-test={`skill-save-${skill.name}`}
                    >
                        Save to your skills
                    </DropdownMenuItem>
                ) : (
                    item.skill.can_edit && (
                        <>
                            <DropdownMenuItem
                                onSelect={() => onEdit(item.skill)}
                                data-test={`skill-edit-${skill.name}`}
                            >
                                Edit
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                onSelect={() =>
                                    onShare(item.skill, !item.skill.shared)
                                }
                                data-test={`skill-share-${skill.name}`}
                            >
                                {item.skill.shared
                                    ? 'Stop sharing'
                                    : 'Share with everyone'}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                onSelect={() => onDelete(item.skill)}
                                className="text-red-600"
                                data-test={`skill-delete-${skill.name}`}
                            >
                                Delete
                            </DropdownMenuItem>
                        </>
                    )
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );

    const control =
        item.kind === 'library' ? (
            <Switch
                checked={item.skill.enabled}
                onChange={(enabled) => onToggle(item.skill, enabled)}
                label={`Turn ${skill.name} ${item.skill.enabled ? 'off' : 'on'} in this project`}
                testId={`skill-switch-${skill.name}`}
            />
        ) : (
            <span className="text-xs text-muted-foreground">On</span>
        );

    const note =
        item.kind === 'library' && item.overridden ? (
            <p className="text-xs text-amber-600">
                The project’s own {skill.name} is used instead.
            </p>
        ) : null;

    if (view === 'list') {
        return (
            <li
                className="flex items-center gap-3 p-3"
                data-test={`skill-${skill.name}`}
            >
                <Icon className="size-4 shrink-0 text-muted-foreground" />
                <button
                    type="button"
                    onClick={onOpen}
                    className="min-w-0 flex-1 text-left"
                >
                    <p className="truncate font-mono text-sm font-medium">
                        {skill.name}
                    </p>
                    <p className="truncate text-sm text-muted-foreground">
                        {skill.description ?? 'No description.'}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                        {origin}
                    </p>
                    {note}
                </button>
                {control}
                {menu}
            </li>
        );
    }

    return (
        <li
            className="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
            data-test={`skill-${skill.name}`}
        >
            <div className="flex items-start gap-2">
                <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                <button
                    type="button"
                    onClick={onOpen}
                    className="min-w-0 flex-1 truncate text-left font-mono text-sm font-medium hover:underline"
                >
                    {skill.name}
                </button>
                {menu}
            </div>
            <p className="line-clamp-3 flex-1 text-sm text-muted-foreground">
                {skill.description ?? 'No description.'}
            </p>
            {note}
            <div className="flex items-center justify-between gap-2">
                <span className="truncate text-xs text-muted-foreground">
                    {origin}
                </span>
                {control}
            </div>
        </li>
    );
}

function EmptyState({
    filter,
    running,
    projectError,
}: {
    filter: Filter;
    running: boolean;
    projectError: string | null;
}) {
    if (filter === 'project') {
        return (
            <Empty tone={projectError ? 'error' : undefined}>
                {projectError ??
                    (running
                        ? 'This project has no skills of its own yet. Use Add → Create with agent to write one.'
                        : 'Project skills show when the sandbox is running.')}
            </Empty>
        );
    }

    const text = {
        all: 'Skills you create or that are shared with you will appear here.',
        enabled: 'No skills are on in this project yet.',
        mine: 'You haven’t created any skills yet.',
        shared: 'Nobody has shared a skill yet.',
    }[filter];

    return (
        <div
            className="rounded-xl border border-sidebar-border/70 px-6 py-14 text-center dark:border-sidebar-border"
            data-test="skills-empty"
        >
            <p className="text-lg font-medium">No skills yet</p>
            <p className="mt-2 text-sm text-muted-foreground">{text}</p>
        </div>
    );
}

function Empty({
    children,
    tone,
}: {
    children: React.ReactNode;
    tone?: 'error';
}) {
    return (
        <div
            className={cn(
                'rounded-xl border border-dashed border-sidebar-border p-6 text-sm',
                tone === 'error' ? 'text-red-600' : 'text-muted-foreground',
            )}
            data-test="skills-message"
        >
            {children}
        </div>
    );
}
