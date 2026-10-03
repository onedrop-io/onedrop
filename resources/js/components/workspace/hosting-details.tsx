import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import ProjectHostingController from '@/actions/App/Http/Controllers/ProjectHostingController';
import { highlightLog } from '@/components/log-highlight';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import type { Hosting } from '@/types';

/** A deploy log line's color: failures red, going live green, the builder's own output dimmed. */
export function deployLineClass(line: string): string | undefined {
    if (/^\S+ Failed: /.test(line)) {
        return 'text-red-400';
    }

    if (
        /^\S+ (Live at |Built the image|Put the last good version back)/.test(
            line,
        )
    ) {
        return 'text-green-400';
    }

    return /^\d{2}:\d{2}:\d{2} /.test(line) ? undefined : 'text-neutral-500';
}

/** What a deploy to hosting is doing, by its step (HOST-001). */
export const DEPLOY_STEPS: Record<string, string> = {
    inspect: 'Reading the app',
    package: 'Building and packing',
    provision: 'Setting up its services',
    upload: 'Uploading the site',
    build: 'Building the image',
    building: 'Building the image',
    release: 'Starting the app',
    verify: 'Waiting for it to answer',
};

/** The latest deploy to hosting, its log, and what the app has there, with whose account (HOST-001..003). */
/** What the sandbox has that the hosted app doesn't yet (HOST-004). */
export function HostingChanges({ changes }: { changes: Hosting['changes'] }) {
    if (!changes || changes.count === 0) {
        return null;
    }

    return (
        <div
            className="space-y-1 rounded-lg border border-amber-500/40 bg-amber-500/10 p-2 text-sm"
            data-test="hosting-changes"
        >
            <p className="font-medium">
                {changes.count === 1
                    ? '1 change since you published'
                    : `${changes.count} changes since you published`}
            </p>
            <ul className="space-y-0.5 text-muted-foreground">
                {changes.commits.slice(0, 5).map((commit) => (
                    <li key={commit.sha} className="truncate">
                        {commit.message}
                    </li>
                ))}
                {changes.count > 5 && <li>and {changes.count - 5} more</li>}
            </ul>
        </div>
    );
}

/** The latest deploy, with its colored log, newest lines in view (HOST-001). */
export function DeployLog({
    deployment,
    open = false,
}: {
    deployment: NonNullable<Hosting['deployment']>;
    open?: boolean;
}) {
    const log = useRef<HTMLPreElement>(null);
    const lines = deployment.log?.split('\n') ?? [];

    // The newest lines (what it's doing now, or where it went live) are at the end.
    useEffect(() => {
        log.current?.scrollTo({ top: log.current.scrollHeight });
    }, [deployment.log]);

    return (
        <details
            className="rounded-lg border p-2 text-sm"
            open={open}
            onToggle={() =>
                log.current?.scrollTo({ top: log.current.scrollHeight })
            }
        >
            <summary className="cursor-pointer text-muted-foreground">
                Deploy #{deployment.number}
                {deployment.status === 'live' && ' · live'}
                {deployment.status === 'failed' && ' · failed'}
                {deployment.status === 'running' &&
                    ` · ${(DEPLOY_STEPS[deployment.step] ?? 'Deploying').toLowerCase()}…`}
            </summary>
            <pre
                ref={log}
                className="mt-2 max-h-64 overflow-auto rounded-md bg-neutral-950 p-2 text-xs whitespace-pre-wrap text-neutral-300"
                data-test="deploy-log"
            >
                {deployment.log
                    ? highlightLog(deployment.log).map((line) => (
                          <div
                              key={line.index}
                              className={deployLineClass(
                                  lines[line.index] ?? '',
                              )}
                          >
                              {line.nodes}
                          </div>
                      ))
                    : 'Starting…'}
            </pre>
        </details>
    );
}

/**
 * Everything about a hosted app, for Tools → Publishing: its latest deploy, earlier ones to roll back to, updating
 * automatically, its machine size, what it has at each provider, moving to Postgres, and deleting its data
 * (HOST-001..010).
 */
export default function HostingManager({
    projectId,
    hosting,
}: {
    projectId: number;
    hosting: Hosting;
}) {
    const { deployment, services, history } = hosting;

    const rollBack = (id: number, number: number) => {
        if (
            window.confirm(
                `Put deploy #${number} back? Visitors get that version again; the app's data stays as it is now.`,
            )
        ) {
            router.post(
                ProjectHostingController.rollBack.url({
                    project: projectId,
                    deployment: id,
                }),
                {},
                { preserveScroll: true },
            );
        }
    };

    const setAutoDeploy = (on: boolean) =>
        router.patch(
            ProjectHostingController.update.url(projectId),
            { auto_deploy: on },
            { preserveScroll: true },
        );

    const resize = (size: string) => {
        if (
            window.confirm(
                'Restart the hosted app on this machine size? It takes a few seconds, with no rebuild.',
            )
        ) {
            router.patch(
                ProjectHostingController.update.url(projectId),
                { size },
                { preserveScroll: true },
            );
        }
    };

    const moveToPostgres = () => {
        if (
            window.confirm(
                'Move this app to Postgres? The agent switches it over, then the next update makes a Postgres database and copies the hosted data into it. The SQLite file is kept.',
            )
        ) {
            router.post(
                ProjectHostingController.moveToPostgres.url(projectId),
                {},
                { preserveScroll: true },
            );
        }
    };

    const deleteData = () => {
        if (
            window.confirm(
                "Delete everything this app has at its hosting providers, its databases and files included? This can't be undone.",
            )
        ) {
            router.delete(ProjectHostingController.destroy.url(projectId), {
                preserveScroll: true,
            });
        }
    };

    const row = 'flex items-center justify-between gap-4 py-3';

    return (
        <div className="space-y-4 text-sm" data-test="hosting-details">
            <h3 className="font-medium">Hosting</h3>
            <HostingChanges changes={hosting.changes} />
            {deployment && <DeployLog deployment={deployment} open />}

            <div className="divide-y divide-sidebar-border/70 rounded-xl border border-sidebar-border/70 px-4 dark:divide-sidebar-border dark:border-sidebar-border">
                {history.length > 0 && (
                    <div className={row}>
                        <span>
                            Update automatically
                            <span className="block text-xs text-muted-foreground">
                                After each turn that finishes without errors.
                            </span>
                        </span>
                        <Switch
                            checked={hosting.auto_deploy}
                            onChange={setAutoDeploy}
                            label="Update the hosted app automatically"
                            testId="auto-deploy"
                        />
                    </div>
                )}
                {history.length > 0 && (
                    <label className={row}>
                        <span>
                            Machine size
                            <span className="block text-xs text-muted-foreground">
                                Larger ones wake a little slower after sleeping.
                            </span>
                        </span>
                        <select
                            value={hosting.size}
                            onChange={(event) => resize(event.target.value)}
                            className="h-8 rounded-md border border-input bg-transparent px-2 text-xs"
                            aria-label="Machine size"
                            data-test="machine-size"
                        >
                            {hosting.sizes.map((size) => (
                                <option key={size.key} value={size.key}>
                                    {size.label}
                                </option>
                            ))}
                        </select>
                    </label>
                )}
                {hosting.moving_to_postgres ? (
                    <p
                        className="py-3 text-sky-700 dark:text-sky-400"
                        data-test="moving-to-postgres"
                    >
                        Moving to Postgres. Once the agent has switched the app
                        over, click Update: the hosted data is copied in then.
                    </p>
                ) : (
                    hosting.sqlite && (
                        <div className={row}>
                            <span>
                                SQLite ({hosting.sqlite})
                                <span className="block text-xs text-muted-foreground">
                                    To run on more than one machine, move it to
                                    Postgres.
                                </span>
                            </span>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={moveToPostgres}
                                data-test="move-to-postgres"
                            >
                                Move to Postgres
                            </Button>
                        </div>
                    )
                )}
                {services.length > 0 && (
                    <div className="py-3">
                        <p className="mb-2 text-muted-foreground">
                            What it has
                        </p>
                        <ul className="space-y-1" data-test="hosted-services">
                            {services.map((service) => (
                                <li
                                    key={service.id}
                                    className="flex justify-between gap-2"
                                >
                                    <span>{service.label}</span>
                                    <span className="text-muted-foreground">
                                        {service.provider}
                                        {service.owner === 'organization'
                                            ? ', your account'
                                            : ''}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
                {history.length > 1 && (
                    <div className="py-3">
                        <p className="mb-2 text-muted-foreground">
                            Earlier deploys
                        </p>
                        <ul className="space-y-1" data-test="deploy-history">
                            {history.map((past) => (
                                <li
                                    key={past.id}
                                    className="flex items-center justify-between gap-2"
                                >
                                    <span>
                                        #{past.number}
                                        {past.commit && (
                                            <span className="ml-2 font-mono text-xs text-muted-foreground">
                                                {past.commit}
                                            </span>
                                        )}
                                    </span>
                                    {past.live ? (
                                        <span className="text-muted-foreground">
                                            Live
                                        </span>
                                    ) : (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                rollBack(past.id, past.number)
                                            }
                                            data-test={`roll-back-${past.number}`}
                                        >
                                            Roll back
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>

            {hosting.can_delete && (
                <Button
                    variant="outline"
                    size="sm"
                    className="text-red-600"
                    onClick={deleteData}
                    data-test="delete-hosted-data"
                >
                    Delete hosted data
                </Button>
            )}
        </div>
    );
}
