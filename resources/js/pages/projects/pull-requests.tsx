import { Head, router, setLayoutProps } from '@inertiajs/react';
import PullRequestsPanel from '@/components/workspace/pull-requests-panel';
import { pullRequests, show } from '@/routes/projects';
import type { Project } from '@/types';

/**
 * A project's pull requests on a page of their own (GIT-022), beside Board in the opened project's sidebar: the same
 * list and pull request pages as Tools → Pull requests, full width.
 */
export default function PullRequests({
    project,
}: {
    project: Pick<Project, 'id' | 'name'>;
}) {
    setLayoutProps({
        breadcrumbs: [
            { title: project.name, href: show(project.id) },
            { title: 'Pull requests', href: pullRequests(project.id) },
        ],
    });

    return (
        <>
            <Head title={`Pull requests · ${project.name}`} />
            <div className="mx-auto w-full max-w-3xl px-4 py-3">
                <h1 className="mb-3 text-lg font-semibold">Pull requests</h1>
                <PullRequestsPanel
                    projectId={project.id}
                    onConnect={() =>
                        router.visit(
                            show(project.id, {
                                query: { tab: 'tools', tool: 'git' },
                            }).url,
                        )
                    }
                />
            </div>
        </>
    );
}
