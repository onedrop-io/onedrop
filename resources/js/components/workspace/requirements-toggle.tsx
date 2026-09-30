import { router, usePage } from '@inertiajs/react';
import ProjectRequirementsController from '@/actions/App/Http/Controllers/ProjectRequirementsController';
import { Switch } from '@/components/ui/switch';
import type { Project } from '@/types/projects';

/**
 * Turns requirements tracking on or off for the open project (REQ-002): whether the agent keeps
 * .onedrop/REQ.md up to date. Shown in the Requirements tab and in Tools → Agent Skills.
 */
export default function RequirementsToggle({
    projectId,
    className,
}: {
    projectId: number;
    className?: string;
}) {
    const { project } = usePage<{ project: Project }>().props;
    const enabled = project.track_requirements;

    return (
        <Switch
            checked={enabled}
            onChange={(checked) =>
                router.patch(
                    ProjectRequirementsController.update.url(projectId),
                    { track_requirements: checked },
                    { preserveScroll: true, preserveState: true },
                )
            }
            label={enabled ? 'Stop keeping requirements' : 'Keep requirements'}
            testId="requirements-toggle"
            className={className}
        />
    );
}
