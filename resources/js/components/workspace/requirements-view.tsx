import { usePage } from '@inertiajs/react';
import { Check, CircleDashed, X } from 'lucide-react';
import { Children, isValidElement, useMemo } from 'react';
import type { ReactNode } from 'react';
import type { Components } from 'react-markdown';
import Markdown from '@/components/markdown';
import { Notice } from '@/components/workspace/console-view';
import RequirementsToggle from '@/components/workspace/requirements-toggle';
import { REQUIREMENT_HEADING, useRequirements } from '@/hooks/use-requirements';
import {
    requirementTests,
    useWorkspaceTests,
} from '@/hooks/use-workspace-tests';
import type { WorkspaceTest } from '@/hooks/use-workspace-tests';
import { cn } from '@/lib/utils';
import type { Project } from '@/types/projects';

/**
 * The requirements the agent keeps for the project (REQ-001): what the user asked the app to do, and the
 * decisions behind it, from .onedrop/REQ.md. Each requirement shows how its tests did (TEST-003); clicking
 * that opens them in the Tests tab. Both are read again whenever the agent may have changed them.
 */
export default function RequirementsView({
    projectId,
    running,
    refreshSignal,
    onShowTests,
}: {
    projectId: number;
    running: boolean;
    /** Changes when the agent did something or files changed: read the file again. */
    refreshSignal: string;
    /** Open the Tests tab on a requirement's tests. */
    onShowTests: (requirement: string) => void;
}) {
    const { project } = usePage<{ project: Project }>().props;
    const { content, loaded, error } = useRequirements(
        projectId,
        running,
        refreshSignal,
    );
    const { state: tests } = useWorkspaceTests(
        projectId,
        running,
        refreshSignal,
    );
    const testList = tests?.tests;

    const components = useMemo<Components>(
        () => ({
            h3: ({ children }) => {
                const heading = REQUIREMENT_HEADING.exec(textOf(children));

                return (
                    <h3 className="mt-3 mb-1 flex flex-wrap items-center gap-2 font-semibold first:mt-0">
                        {children}
                        {heading && testList && (
                            <TestsBadge
                                id={heading[1]}
                                tests={testList}
                                onClick={() => onShowTests(heading[1])}
                            />
                        )}
                    </h3>
                );
            },
        }),
        [testList, onShowTests],
    );

    return (
        <div
            className="flex min-h-0 flex-1 flex-col"
            data-test="requirements-view"
        >
            <div className="flex items-center gap-3 border-b border-sidebar-border/70 px-4 py-2 text-sm dark:border-sidebar-border">
                <RequirementsToggle projectId={projectId} />
                <p className="min-w-0 flex-1 text-muted-foreground">
                    {project.track_requirements
                        ? 'The agent writes down what you ask for, and why, and tests it as it works.'
                        : "Off: the agent won't add to these or write tests for them."}
                </p>
            </div>
            {!running ? (
                <Notice>
                    The requirements show when the sandbox is running.
                </Notice>
            ) : error ? (
                <Notice>{error}</Notice>
            ) : !loaded ? null : content === null || content.trim() === '' ? (
                <div
                    className="max-w-prose p-4 text-sm text-muted-foreground"
                    data-test="requirements-empty"
                >
                    <p className="font-medium text-foreground">
                        No requirements yet
                    </p>
                    <p className="mt-1">
                        As you ask for changes, the agent lists what the app
                        should let people do ("User should be able to…") and the
                        decisions made along the way, with the reasons. Read
                        them here to check it understood you.
                    </p>
                </div>
            ) : (
                <div className="flex-1 overflow-y-auto p-4">
                    <Markdown
                        content={content}
                        components={components}
                        className="max-w-3xl text-sm"
                        data-test="requirements-content"
                    />
                </div>
            )}
        </div>
    );
}

/** How a requirement's tests did; nothing when it has none. */
function TestsBadge({
    id,
    tests,
    onClick,
}: {
    id: string;
    tests: WorkspaceTest[];
    onClick: () => void;
}) {
    const {
        tests: checking,
        passed,
        failed,
        notRun,
    } = requirementTests(tests, id);

    if (checking.length === 0) {
        return null;
    }

    const count = `${checking.length} ${checking.length === 1 ? 'test' : 'tests'}`;
    const [label, Icon, tone] =
        failed > 0
            ? [
                  checking.length === 1
                      ? 'Test failing'
                      : `${failed} of ${count} failing`,
                  X,
                  'bg-red-500/15 text-red-600 dark:text-red-400',
              ]
            : notRun > 0
              ? [
                    `${count}, ${notRun} not run`,
                    CircleDashed,
                    'bg-muted text-muted-foreground',
                ]
              : [
                    `${passed === 1 ? 'Test passes' : `${count} pass`}`,
                    Check,
                    'bg-green-500/15 text-green-700 dark:text-green-400',
                ];

    return (
        <button
            type="button"
            onClick={onClick}
            title={`See ${id}'s tests`}
            data-test={`requirement-tests-${id}`}
            className={cn(
                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium hover:opacity-80',
                tone,
            )}
        >
            <Icon className="size-3" />
            {label}
        </button>
    );
}

/** The plain text of rendered markdown children. */
function textOf(children: ReactNode): string {
    return Children.toArray(children)
        .map((child) =>
            typeof child === 'string' || typeof child === 'number'
                ? String(child)
                : isValidElement<{ children?: ReactNode }>(child)
                  ? textOf(child.props.children)
                  : '',
        )
        .join('');
}
