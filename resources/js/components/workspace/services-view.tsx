import {
    ChevronRight,
    Maximize2,
    Play,
    Radio,
    RotateCw,
    Search,
    Square,
} from 'lucide-react';
import {
    Fragment,
    useCallback,
    useDeferredValue,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import ProjectServiceController from '@/actions/App/Http/Controllers/ProjectServiceController';
import { highlightLog } from '@/components/log-highlight';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import TurnOnDocker from '@/components/turn-on-docker';
import { jsonRequest } from '@/lib/json-request';
import { parseLogSearch } from '@/lib/log-search';
import { cn } from '@/lib/utils';

type Container = {
    name: string;
    project: string | null;
    service: string | null;
    image: string;
    state: string;
    status: string;
    exit_code: number | null;
    ports: { published: number; target: number }[];
};

type Port = {
    address: string;
    port: number;
    process: string | null;
    role: 'app' | 'proxy' | 'shell' | 'ssh' | null;
    service: string | null;
};

type Services = {
    preview: { command: string | null; running: boolean; port: number };
    docker: 'off' | 'down' | 'up';
    containers: Container[];
    ports: Port[];
};

/** How often the tab reads the sandbox again while it's shown. */
const POLL_MS = 3000;

const ROLES: Record<NonNullable<Port['role']>, string> = {
    app: 'Preview',
    proxy: 'Host proxy',
    shell: 'Shell tab',
    ssh: 'SSH',
};

/**
 * What runs in the project's sandbox (SVC-001): the preview server, the containers its own Docker runs, and the
 * ports open in it, read again every few seconds while shown.
 */
export default function ServicesView({
    projectId,
    active,
    running,
}: {
    projectId: number;
    active: boolean;
    running: boolean;
}) {
    const [services, setServices] = useState<Services | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState<string | null>(null);
    const [logsFor, setLogsFor] = useState<string | null>(null);
    const toggleLogs = (name: string) =>
        setLogsFor((current) => (current === name ? null : name));

    const load = useCallback(async () => {
        try {
            setServices(
                await jsonRequest<Services>(
                    ProjectServiceController.index.url(projectId),
                ),
            );
            setError(null);
        } catch (e) {
            setError((e as Error).message);
        }
    }, [projectId]);

    useEffect(() => {
        if (!active || !running) {
            return;
        }

        void load();
        const timer = window.setInterval(() => void load(), POLL_MS);

        return () => window.clearInterval(timer);
    }, [active, running, load]);

    const act = async (key: string, request: () => Promise<unknown>) => {
        setBusy(key);

        try {
            await request();
            setError(null);
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(null);
            void load();
        }
    };

    if (!running) {
        return (
            <Message>
                The sandbox isn't running, so there's nothing to show.
            </Message>
        );
    }

    if (!services) {
        return error ? (
            <Message>{error}</Message>
        ) : (
            <div className="space-y-3 p-4" aria-busy>
                {[0, 1, 2].map((row) => (
                    <div
                        key={row}
                        className="h-10 animate-pulse rounded-lg bg-muted"
                    />
                ))}
            </div>
        );
    }

    const projects = Object.entries(
        services.containers.reduce<Record<string, Container[]>>(
            (groups, container) => {
                const project = container.project ?? 'Other containers';
                (groups[project] ??= []).push(container);

                return groups;
            },
            {},
        ),
    );
    const ports = [
        ...new Map(services.ports.map((port) => [port.port, port])).values(),
    ];

    return (
        <div
            className="min-h-0 flex-1 space-y-6 overflow-y-auto p-4 text-sm"
            data-test="services-view"
        >
            {error && (
                <p className="text-destructive" role="alert">
                    {error}
                </p>
            )}

            <Section
                title="Preview server"
                description={`What the preview shows, on port ${services.preview.port}.`}
            >
                <div
                    className="flex items-start gap-3 rounded-lg border p-3"
                    data-test="services-preview"
                >
                    <State
                        state={services.preview.running ? 'running' : 'exited'}
                    />
                    <div className="min-w-0 flex-1">
                        {services.preview.command ? (
                            <pre className="overflow-x-auto font-mono text-xs whitespace-pre-wrap text-muted-foreground">
                                {services.preview.command}
                            </pre>
                        ) : (
                            <p className="text-muted-foreground">
                                The placeholder page: the project has no{' '}
                                <code>.onedrop/dev</code> yet.
                            </p>
                        )}
                    </div>
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={busy === 'preview'}
                        onClick={() =>
                            act('preview', () =>
                                jsonRequest(
                                    ProjectServiceController.restartPreview.url(
                                        projectId,
                                    ),
                                    {},
                                ),
                            )
                        }
                        data-test="services-preview-restart"
                    >
                        <RotateCw className="size-3.5" />
                        Restart
                    </Button>
                </div>
            </Section>

            <Section
                title="Docker"
                description="Containers the sandbox's own Docker runs, such as the project's Docker Compose stack."
            >
                {services.docker === 'off' && (
                    <p className="text-muted-foreground">
                        This sandbox has no Docker. <TurnOnDocker />
                    </p>
                )}
                {services.docker === 'down' && (
                    <p className="text-muted-foreground">
                        Docker isn't answering yet. Its log is{' '}
                        <code>/tmp/onedrop-dockerd.log</code>.
                    </p>
                )}
                {services.docker === 'up' &&
                    services.containers.length === 0 && (
                        <p className="text-muted-foreground">
                            No containers. For a project with a compose file,
                            run <code>/opt/onedrop/compose init</code> in the
                            Shell tab.
                        </p>
                    )}
                {projects.map(([project, containers]) => (
                    <div key={project} className="space-y-1">
                        <h4 className="text-xs font-medium text-muted-foreground">
                            {project}
                        </h4>
                        <div className="divide-y rounded-lg border">
                            {containers.map((container) => (
                                <Fragment key={container.name}>
                                    <div
                                        className="flex cursor-pointer flex-wrap items-center gap-x-3 gap-y-1 p-2.5 hover:bg-muted/40"
                                        onClick={(event) => {
                                            // Buttons in the row do their own thing, and dragging to select text isn't a click.
                                            if (
                                                (
                                                    event.target as HTMLElement
                                                ).closest('button') ||
                                                window
                                                    .getSelection()
                                                    ?.toString()
                                            ) {
                                                return;
                                            }

                                            toggleLogs(container.name);
                                        }}
                                        data-test={`service-${container.service ?? container.name}`}
                                    >
                                        <button
                                            type="button"
                                            aria-expanded={
                                                logsFor === container.name
                                            }
                                            aria-label={`Logs of ${container.name}`}
                                            title="Show logs"
                                            onClick={() =>
                                                toggleLogs(container.name)
                                            }
                                            className="-mx-1 rounded p-0.5 text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                            data-test="service-logs"
                                        >
                                            <ChevronRight
                                                className={cn(
                                                    'size-3.5 transition-transform',
                                                    logsFor ===
                                                        container.name &&
                                                        'rotate-90',
                                                )}
                                            />
                                        </button>
                                        <State state={container.state} />
                                        <div className="min-w-0 flex-1">
                                            <p
                                                className="truncate font-medium"
                                                title={`${container.name} · ${container.image}`}
                                            >
                                                {container.service ??
                                                    container.name}
                                            </p>
                                            <p
                                                className={cn(
                                                    'text-xs text-muted-foreground',
                                                    container.state ===
                                                        'restarting' &&
                                                        'text-amber-600 dark:text-amber-400',
                                                )}
                                            >
                                                {container.status}
                                            </p>
                                        </div>
                                        <span className="font-mono text-xs text-muted-foreground">
                                            {container.ports
                                                .map((port) =>
                                                    port.published ===
                                                    port.target
                                                        ? port.published
                                                        : `${port.published}→${port.target}`,
                                                )
                                                .join(' ')}
                                        </span>
                                        <div className="flex gap-1">
                                            <IconButton
                                                label={`Restart ${container.name}`}
                                                disabled={
                                                    busy === container.name
                                                }
                                                onClick={() =>
                                                    act(container.name, () =>
                                                        containerAction(
                                                            projectId,
                                                            container.name,
                                                            'restart',
                                                        ),
                                                    )
                                                }
                                                testId="service-restart"
                                            >
                                                <RotateCw className="size-3.5" />
                                            </IconButton>
                                            {container.state === 'exited' ||
                                            container.state === 'created' ? (
                                                <IconButton
                                                    label={`Start ${container.name}`}
                                                    disabled={
                                                        busy === container.name
                                                    }
                                                    onClick={() =>
                                                        act(
                                                            container.name,
                                                            () =>
                                                                containerAction(
                                                                    projectId,
                                                                    container.name,
                                                                    'start',
                                                                ),
                                                        )
                                                    }
                                                    testId="service-start"
                                                >
                                                    <Play className="size-3.5" />
                                                </IconButton>
                                            ) : (
                                                <IconButton
                                                    label={`Stop ${container.name}`}
                                                    disabled={
                                                        busy === container.name
                                                    }
                                                    onClick={() =>
                                                        act(
                                                            container.name,
                                                            () =>
                                                                containerAction(
                                                                    projectId,
                                                                    container.name,
                                                                    'stop',
                                                                ),
                                                        )
                                                    }
                                                    testId="service-stop"
                                                >
                                                    <Square className="size-3.5" />
                                                </IconButton>
                                            )}
                                        </div>
                                    </div>
                                    {logsFor === container.name && (
                                        <ContainerLogs
                                            projectId={projectId}
                                            name={container.name}
                                        />
                                    )}
                                </Fragment>
                            ))}
                        </div>
                    </div>
                ))}
            </Section>

            <Section
                title="Ports"
                description="Programs listening in the sandbox. Only the preview port is reachable from outside."
            >
                <div
                    className="divide-y rounded-lg border"
                    data-test="services-ports"
                >
                    {ports.map((port) => (
                        <div
                            key={port.port}
                            className="flex items-center gap-3 px-2.5 py-1.5"
                        >
                            <span className="w-14 font-mono text-xs">
                                {port.port}
                            </span>
                            <span className="text-muted-foreground">
                                {port.role
                                    ? ROLES[port.role]
                                    : port.service
                                      ? `${port.service} (Docker)`
                                      : (port.process ?? 'Unknown')}
                            </span>
                        </div>
                    ))}
                </div>
            </Section>
        </div>
    );
}

function containerAction(
    projectId: number,
    name: string,
    action: 'restart' | 'stop' | 'start',
) {
    return jsonRequest(
        ProjectServiceController.update.url({ project: projectId, name }),
        { action },
        'PUT',
    );
}

/** A container's latest log lines, searchable, with a bigger view that loads more of them. */
function ContainerLogs({
    projectId,
    name,
}: {
    projectId: number;
    name: string;
}) {
    const [query, setQuery] = useState('');
    const [expanded, setExpanded] = useState(false);

    return (
        <div className="bg-muted/40 p-2.5" data-test="service-log">
            <LogViewer
                projectId={projectId}
                name={name}
                query={query}
                onQueryChange={setQuery}
                onExpand={() => setExpanded(true)}
                className="max-h-80"
            />
            <Dialog open={expanded} onOpenChange={setExpanded}>
                <DialogContent
                    className="flex h-[90vh] flex-col sm:max-w-[95vw]"
                    data-test="service-log-expanded"
                >
                    <DialogHeader>
                        <DialogTitle>Logs of {name}</DialogTitle>
                        <DialogDescription>
                            The latest {EXPANDED_LOG_LINES.toLocaleString()}{' '}
                            lines.
                        </DialogDescription>
                    </DialogHeader>
                    <LogViewer
                        projectId={projectId}
                        name={name}
                        lines={EXPANDED_LOG_LINES}
                        query={query}
                        onQueryChange={setQuery}
                        className="min-h-0 flex-1"
                        autoFocus
                    />
                </DialogContent>
            </Dialog>
        </div>
    );
}

/** Lines the expanded log view loads (SandboxServices::MAX_LOG_LINES). */
const EXPANDED_LOG_LINES = 2000;

/**
 * Log lines loaded when shown, on Refresh, and every few seconds while Live is on, narrowed to the lines
 * passing the search (lib/log-search.ts). Clicking a matching line clears the search and shows it among its
 * neighbours. It stays scrolled to the newest line unless the user scrolls up.
 */
function LogViewer({
    projectId,
    name,
    lines,
    query,
    onQueryChange,
    onExpand,
    className,
    autoFocus = false,
}: {
    projectId: number;
    name: string;
    lines?: number;
    query: string;
    onQueryChange: (query: string) => void;
    onExpand?: () => void;
    className: string;
    autoFocus?: boolean;
}) {
    const [logs, setLogs] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [live, setLive] = useState(false);
    const [contextLine, setContextLine] = useState<number | null>(null);
    const scroller = useRef<HTMLPreElement>(null);
    const atBottom = useRef(true);
    const deferredQuery = useDeferredValue(query);
    const search = useMemo(
        () => parseLogSearch(deferredQuery),
        [deferredQuery],
    );
    const shown = useMemo(
        () => (logs ? highlightLog(logs, search) : []),
        [logs, search],
    );

    const load = useCallback(async () => {
        try {
            const body = await jsonRequest<{ logs: string }>(
                ProjectServiceController.logs.url(
                    { project: projectId, name },
                    lines ? { query: { lines } } : undefined,
                ),
            );
            setLogs(body.logs);
            setError(null);
        } catch (e) {
            setError((e as Error).message);
        }
    }, [projectId, name, lines]);

    useEffect(() => {
        void load();
    }, [load]);

    useEffect(() => {
        if (!live) {
            return;
        }

        const timer = window.setInterval(() => void load(), POLL_MS);

        return () => window.clearInterval(timer);
    }, [live, load]);

    useLayoutEffect(() => {
        const element = scroller.current;

        if (!element) {
            return;
        }

        if (contextLine !== null) {
            element
                .querySelector(`[data-line="${contextLine}"]`)
                ?.scrollIntoView({ block: 'center' });
        } else if (atBottom.current) {
            element.scrollTop = element.scrollHeight;
        }
    }, [shown, contextLine]);

    const showInContext = (index: number) => {
        setLive(false);
        setContextLine(index);
        onQueryChange('');
    };

    const changeQuery = (next: string) => {
        setContextLine(null);
        atBottom.current = true;
        onQueryChange(next);
    };

    return (
        <div className="flex min-h-0 flex-1 flex-col gap-2">
            <div className="flex items-center gap-2">
                <div className="relative flex-1">
                    <Search className="pointer-events-none absolute top-1/2 left-2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        type="search"
                        value={query}
                        onChange={(event) => changeQuery(event.target.value)}
                        placeholder='Search: words, "a phrase", -exclude, a OR b'
                        aria-label="Search logs"
                        className="h-7 pl-7 text-xs"
                        autoFocus={autoFocus}
                        data-test="service-log-search"
                    />
                </div>
                {search && logs !== null && (
                    <span
                        className="shrink-0 text-xs text-muted-foreground"
                        data-test="service-log-matches"
                    >
                        {shown.length === 1
                            ? '1 line'
                            : `${shown.length} lines`}
                    </span>
                )}
                <Button
                    size="sm"
                    variant={live ? 'secondary' : 'ghost'}
                    onClick={() => {
                        setLive(!live);
                        setContextLine(null);
                        atBottom.current = true;
                    }}
                    aria-pressed={live}
                    title="Load new lines every few seconds"
                    data-test="service-log-live"
                >
                    <Radio
                        className={cn('size-3.5', live && 'text-emerald-500')}
                    />
                    Live
                </Button>
                {onExpand && (
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={onExpand}
                        data-test="service-log-expand"
                    >
                        <Maximize2 className="size-3.5" />
                        Expand
                    </Button>
                )}
                <Button size="sm" variant="ghost" onClick={() => void load()}>
                    <RotateCw className="size-3.5" />
                    Refresh
                </Button>
            </div>
            {error ? (
                <p className="text-destructive">{error}</p>
            ) : (
                <pre
                    ref={scroller}
                    onScroll={(event) => {
                        const element = event.currentTarget;
                        atBottom.current =
                            element.scrollHeight -
                                element.scrollTop -
                                element.clientHeight <
                            24;
                    }}
                    className={cn(
                        'overflow-auto rounded bg-neutral-950 p-2 font-mono text-xs whitespace-pre-wrap text-neutral-200',
                        className,
                    )}
                    data-test="service-log-lines"
                >
                    {logs === null
                        ? 'Loading…'
                        : !logs
                          ? 'No output yet.'
                          : shown.length
                            ? shown.map((line) => (
                                  <div
                                      key={line.index}
                                      data-line={line.index}
                                      onClick={
                                          search
                                              ? () => showInContext(line.index)
                                              : undefined
                                      }
                                      title={
                                          search
                                              ? 'Show among the lines around it'
                                              : undefined
                                      }
                                      className={cn(
                                          'min-h-lh',
                                          search &&
                                              'cursor-pointer hover:bg-white/5',
                                          contextLine === line.index &&
                                              'bg-amber-400/15',
                                      )}
                                  >
                                      {line.nodes}
                                  </div>
                              ))
                            : 'No lines match.'}
                </pre>
            )}
        </div>
    );
}

function Section({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: React.ReactNode;
}) {
    return (
        <section className="space-y-2">
            <div>
                <h3 className="font-medium">{title}</h3>
                <p className="text-xs text-muted-foreground">{description}</p>
            </div>
            {children}
        </section>
    );
}

/** A dot for a process or container: green running, amber restarting or paused, grey otherwise. */
function State({ state }: { state: string }) {
    return (
        <span
            className={cn(
                'mt-1 size-2 shrink-0 self-start rounded-full',
                state === 'running'
                    ? 'bg-emerald-500'
                    : state === 'restarting' || state === 'paused'
                      ? 'bg-amber-500'
                      : 'bg-muted-foreground/40',
            )}
            role="img"
            aria-label={state}
        />
    );
}

function IconButton({
    label,
    onClick,
    disabled,
    active,
    testId,
    children,
}: {
    label: string;
    onClick: () => void;
    disabled?: boolean;
    active?: boolean;
    testId?: string;
    children: React.ReactNode;
}) {
    return (
        <Button
            size="icon"
            variant={active ? 'secondary' : 'ghost'}
            className="size-7"
            aria-label={label}
            title={label}
            disabled={disabled}
            onClick={onClick}
            data-test={testId}
        >
            {children}
        </Button>
    );
}

function Message({ children }: { children: React.ReactNode }) {
    return (
        <div className="flex flex-1 items-center justify-center p-6 text-sm text-muted-foreground">
            {children}
        </div>
    );
}
