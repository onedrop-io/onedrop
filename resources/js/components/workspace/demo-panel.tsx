import { usePage } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    Clapperboard,
    Download,
    Plus,
    Sparkles,
    Wand2,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import ProjectDemoController from '@/actions/App/Http/Controllers/ProjectDemoController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useWorkspaceTests } from '@/hooks/use-workspace-tests';
import type { WorkspaceTest } from '@/hooks/use-workspace-tests';
import { askAgent } from '@/lib/ask-agent';
import { jsonRequest } from '@/lib/json-request';
import { cn } from '@/lib/utils';
import type { Project } from '@/types/projects';

/** One scene: a test, by file and title, with its caption (DEMO-001). */
type Scene = { file: string; title: string; caption: string };

/** The storyboard, kept in .onedrop/demo.json. */
type Storyboard = {
    title: string;
    tagline: string;
    accent: string | null;
    url: string | null;
    scenes: Scene[];
};

type DemoState = {
    running: boolean;
    phase: 'recording' | 'rendering' | null;
    progress: number;
    error: string | null;
    video: {
        path: string;
        size: number;
        rendered_at?: string;
        duration_ms?: number;
        scenes?: number;
    } | null;
    storyboard: Storyboard | null;
    storyboard_error: string | null;
};

const POLL_MS = 2000;
const DEFAULT_ACCENT = '#6366f1';
const QUEUED =
    'Asked the agent. It runs after the current task; follow along in the chat.';

/** "Todos › User should be able to add a todo" → "Add a todo". */
export function captionFor(title: string): string {
    const caption = (title.split(' › ').at(-1) ?? title)
        .replace(/^(the )?user should (be able to )?/i, '')
        .replace(/\.$/, '')
        .trim();

    return caption.charAt(0).toUpperCase() + caption.slice(1);
}

/** One scene per requirement, in order: its first passing test (else its first), captioned with the requirement. */
function scenesFromTests(tests: WorkspaceTest[]): Scene[] {
    const requirement = (test: WorkspaceTest) =>
        test.tags.find((tag) => /^REQ-\d+$/.test(tag)) ?? null;
    const tagged = tests.filter(requirement);
    const picked = tagged.length
        ? [...new Set(tagged.map(requirement))]
              .sort((a, b) =>
                  (a ?? '').localeCompare(b ?? '', undefined, {
                      numeric: true,
                  }),
              )
              .map((id) => {
                  const ofIt = tagged.filter((t) => requirement(t) === id);

                  return (
                      ofIt.find((t) => t.result?.status === 'passed') ?? ofIt[0]
                  );
              })
        : tests;

    return picked.map((test) => ({
        file: test.file,
        title: test.title,
        caption: captionFor(test.title),
    }));
}

function duration(ms: number) {
    const seconds = Math.round(ms / 1000);

    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}

/**
 * Tools → Demo (DEMO-001..002): a demo video made from the app's browser tests. The user (or the agent) picks the
 * tests that tell the app's story and captions them; Render re-runs them slowly in the sandbox and composes the
 * recording into an MP4.
 */
export default function DemoPanel({
    projectId,
    running,
    working,
}: {
    projectId: number;
    running: boolean;
    /** The agent is running a task. */
    working: boolean;
}) {
    const { project } = usePage<{ project: Project }>().props;
    const [demo, setDemo] = useState<DemoState | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [draft, setDraft] = useState<Storyboard | null>(null);
    const [dirty, setDirty] = useState(false);
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState<{
        text: string;
        error?: boolean;
    } | null>(null);
    const [checks, setChecks] = useState(0);
    const { state: tests } = useWorkspaceTests(
        projectId,
        running,
        String(working),
    );

    const load = useCallback(
        () =>
            jsonRequest<DemoState>(ProjectDemoController.show.url(projectId))
                .then((state) => {
                    setDemo(state);
                    setError(null);

                    return state;
                })
                .catch((e: Error) => {
                    setError(e.message);

                    return null;
                }),
        [projectId],
    );

    // Read it again when the agent finishes: it may have written the storyboard or rendered.
    useEffect(() => {
        if (running) {
            void load();
        }
    }, [running, working, load]);

    // While a render goes, check on it.
    useEffect(() => {
        if (!running || !demo?.running) {
            return;
        }

        const timer = window.setTimeout(
            () => void load().then(() => setChecks((n) => n + 1)),
            POLL_MS,
        );

        return () => window.clearTimeout(timer);
    }, [running, demo?.running, load, checks]);

    // The saved storyboard, unless the user is editing it.
    useEffect(() => {
        if (demo && !dirty) {
            setDraft(
                demo.storyboard ?? {
                    title: project?.name ?? '',
                    tagline: '',
                    accent: null,
                    url: null,
                    scenes: [],
                },
            );
        }
    }, [demo, dirty, project?.name]);

    if (!running) {
        return <Empty>The demo works when the sandbox is running.</Empty>;
    }

    if (error && !demo) {
        return <Empty tone="error">{error}</Empty>;
    }

    if (!demo || !draft) {
        return <Empty>Loading…</Empty>;
    }

    const testList = tests?.tests ?? [];
    const change = (changes: Partial<Storyboard>) => {
        setDraft({ ...draft, ...changes });
        setDirty(true);
    };
    const changeScene = (index: number, changes: Partial<Scene> | null) =>
        change({
            scenes: draft.scenes.flatMap((scene, i) =>
                i !== index
                    ? [scene]
                    : changes
                      ? [{ ...scene, ...changes }]
                      : [],
            ),
        });
    const move = (index: number, by: number) => {
        const scenes = [...draft.scenes];
        const [scene] = scenes.splice(index, 1);
        scenes.splice(index + by, 0, scene);
        change({ scenes });
    };

    const save = () => {
        setBusy(true);
        setNotice(null);

        jsonRequest(ProjectDemoController.update.url(projectId), draft, 'PUT')
            .then(() => {
                setDirty(false);
                setNotice({ text: 'Saved the storyboard.' });

                return load();
            })
            .catch((e: Error) => setNotice({ text: e.message, error: true }))
            .finally(() => setBusy(false));
    };

    const render = () => {
        setBusy(true);
        setNotice(null);

        jsonRequest(ProjectDemoController.render.url(projectId), {
            storyboard: draft,
        })
            .then(() => {
                setDirty(false);
                setDemo({
                    ...demo,
                    storyboard: draft,
                    running: true,
                    phase: 'recording',
                    progress: 0,
                    error: null,
                });
            })
            .catch((e: Error) => setNotice({ text: e.message, error: true }))
            .finally(() => setBusy(false));
    };

    const write = () => {
        askAgent(ProjectDemoController.write.url(projectId))
            .then(({ queued }) =>
                setNotice({
                    text: queued
                        ? QUEUED
                        : 'The agent is writing the storyboard and rendering it. Follow along in the chat.',
                }),
            )
            .catch((e: Error) => setNotice({ text: e.message, error: true }));
    };

    const unused = testList.filter(
        (test) =>
            !draft.scenes.some(
                (s) => s.file === test.file && s.title === test.title,
            ),
    );

    return (
        <div className="max-w-3xl space-y-6" data-test="demo-panel">
            <VideoCard projectId={projectId} demo={demo} />

            {demo.storyboard_error && (
                <p className="text-sm text-red-600">{demo.storyboard_error}</p>
            )}

            {tests && testList.length === 0 ? (
                <Empty>
                    <span data-test="demo-no-tests">
                        The demo is made from the app's browser tests, and there
                        are none yet. Ask the agent to write them (see the Tests
                        tab), then come back to pick the ones that show off the
                        app.
                    </span>
                </Empty>
            ) : (
                <section className="space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h3 className="text-sm font-medium">Storyboard</h3>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    change({
                                        scenes: scenesFromTests(testList),
                                    })
                                }
                                disabled={testList.length === 0}
                                data-test="demo-from-tests"
                            >
                                <Wand2 />
                                Start from tests
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={write}
                                data-test="demo-write"
                            >
                                <Sparkles />
                                Write it with agent
                            </Button>
                        </div>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-[1fr_2fr_auto]">
                        <label className="space-y-1 text-xs text-muted-foreground">
                            Title
                            <Input
                                value={draft.title}
                                maxLength={80}
                                onChange={(e) =>
                                    change({ title: e.target.value })
                                }
                                data-test="demo-title"
                            />
                        </label>
                        <label className="space-y-1 text-xs text-muted-foreground">
                            Tagline
                            <Input
                                value={draft.tagline}
                                maxLength={160}
                                placeholder="What the app does, in a line"
                                onChange={(e) =>
                                    change({ tagline: e.target.value })
                                }
                                data-test="demo-tagline"
                            />
                        </label>
                        <label className="space-y-1 text-xs text-muted-foreground">
                            Color
                            <input
                                type="color"
                                value={draft.accent ?? DEFAULT_ACCENT}
                                onChange={(e) =>
                                    change({ accent: e.target.value })
                                }
                                className="block h-9 w-12 cursor-pointer rounded-md border border-input bg-transparent p-1"
                                data-test="demo-accent"
                            />
                        </label>
                    </div>

                    {draft.scenes.length === 0 ? (
                        <p
                            className="rounded-md border border-dashed p-4 text-sm text-muted-foreground"
                            data-test="demo-no-scenes"
                        >
                            No scenes yet. Start from the tests (one per
                            requirement), add them one by one, or have the agent
                            write the storyboard.
                        </p>
                    ) : (
                        <ol className="space-y-2" data-test="demo-scenes">
                            {draft.scenes.map((scene, index) => {
                                const test = testList.find(
                                    (t) =>
                                        t.file === scene.file &&
                                        t.title === scene.title,
                                );

                                return (
                                    <li
                                        key={`${scene.file}:${scene.title}:${index}`}
                                        className="flex items-start gap-3 rounded-md border p-2"
                                        data-test={`demo-scene-${index}`}
                                    >
                                        <span className="mt-2 w-5 shrink-0 text-right text-xs text-muted-foreground">
                                            {index + 1}
                                        </span>
                                        <div className="min-w-0 flex-1 space-y-1">
                                            <Input
                                                value={scene.caption}
                                                maxLength={140}
                                                placeholder="Caption"
                                                aria-label={`Caption of scene ${index + 1}`}
                                                onChange={(e) =>
                                                    changeScene(index, {
                                                        caption: e.target.value,
                                                    })
                                                }
                                                data-test={`demo-caption-${index}`}
                                            />
                                            <p className="truncate text-xs text-muted-foreground">
                                                {scene.title}
                                                {tests && !test && (
                                                    <span className="ml-2 text-amber-600">
                                                        Test not found
                                                    </span>
                                                )}
                                                {test?.result?.status ===
                                                    'failed' && (
                                                    <span className="ml-2 text-red-600">
                                                        Failing
                                                    </span>
                                                )}
                                            </p>
                                        </div>
                                        <div className="flex shrink-0">
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                className="size-8"
                                                aria-label="Move up"
                                                disabled={index === 0}
                                                onClick={() => move(index, -1)}
                                            >
                                                <ArrowUp />
                                            </Button>
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                className="size-8"
                                                aria-label="Move down"
                                                disabled={
                                                    index ===
                                                    draft.scenes.length - 1
                                                }
                                                onClick={() => move(index, 1)}
                                            >
                                                <ArrowDown />
                                            </Button>
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                className="size-8"
                                                aria-label="Remove scene"
                                                onClick={() =>
                                                    changeScene(index, null)
                                                }
                                                data-test={`demo-remove-${index}`}
                                            >
                                                <X />
                                            </Button>
                                        </div>
                                    </li>
                                );
                            })}
                        </ol>
                    )}

                    <div className="flex flex-wrap items-center gap-2">
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={unused.length === 0}
                                    data-test="demo-add-scene"
                                >
                                    <Plus />
                                    Add scene
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                align="start"
                                className="max-h-80 max-w-md overflow-y-auto"
                            >
                                {unused.map((test) => (
                                    <DropdownMenuItem
                                        key={test.id}
                                        onSelect={() =>
                                            change({
                                                scenes: [
                                                    ...draft.scenes,
                                                    {
                                                        file: test.file,
                                                        title: test.title,
                                                        caption: captionFor(
                                                            test.title,
                                                        ),
                                                    },
                                                ],
                                            })
                                        }
                                        data-test={`demo-add-${test.id}`}
                                    >
                                        <span className="truncate">
                                            {test.title}
                                        </span>
                                    </DropdownMenuItem>
                                ))}
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <div className="flex-1" />
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={save}
                            disabled={!dirty || busy}
                            data-test="demo-save"
                        >
                            Save
                        </Button>
                        <Button
                            size="sm"
                            onClick={render}
                            disabled={
                                busy ||
                                demo.running ||
                                draft.scenes.length === 0
                            }
                            data-test="demo-render"
                        >
                            {busy ? <Spinner /> : <Clapperboard />}
                            {demo.video ? 'Render again' : 'Render'}
                        </Button>
                    </div>
                </section>
            )}

            {notice && (
                <p
                    className={cn(
                        'text-sm',
                        notice.error ? 'text-red-600' : 'text-muted-foreground',
                    )}
                    data-test="demo-notice"
                >
                    {notice.text}
                </p>
            )}

            <p className="text-xs text-muted-foreground">
                Rendering runs the scenes' tests again, slowed down, on the
                app's own dev server and database, then makes a 1080p video with
                a title card, captions and the cursor. The storyboard is saved
                in <code className="font-mono">.onedrop/demo.json</code>; only
                the latest video is kept.
            </p>
        </div>
    );
}

/** The latest video, the render going, or why the last one failed. */
function VideoCard({
    projectId,
    demo,
}: {
    projectId: number;
    demo: DemoState;
}) {
    if (demo.running) {
        const percent = Math.round(demo.progress * 100);

        return (
            <div
                className="space-y-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                data-test="demo-progress"
            >
                <p className="flex items-center gap-2 text-sm">
                    <Spinner />
                    {demo.phase === 'rendering'
                        ? `Rendering ${percent}%`
                        : 'Recording the scenes…'}
                </p>
                <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                    <div
                        className="h-full bg-primary transition-[width]"
                        style={{
                            width: `${demo.phase === 'rendering' ? 50 + percent / 2 : percent / 2}%`,
                        }}
                    />
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-3">
            {demo.error && (
                <pre
                    className="max-h-48 overflow-auto rounded-md border border-red-500/30 bg-red-500/5 p-3 text-xs whitespace-pre-wrap text-red-600"
                    data-test="demo-error"
                >
                    {demo.error}
                </pre>
            )}
            {demo.video ? (
                <>
                    <DemoVideo
                        projectId={projectId}
                        version={
                            demo.video.rendered_at ?? String(demo.video.size)
                        }
                    />
                    <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                        <span data-test="demo-video-info">
                            {demo.video.duration_ms !== undefined &&
                                duration(demo.video.duration_ms)}
                            {demo.video.scenes !== undefined &&
                                ` · ${demo.video.scenes} scene${demo.video.scenes === 1 ? '' : 's'}`}
                            {demo.video.rendered_at &&
                                ` · rendered ${new Date(demo.video.rendered_at).toLocaleString()}`}
                        </span>
                        <div className="flex-1" />
                        <Button variant="outline" size="sm" asChild>
                            <a
                                href={ProjectDemoController.video.url(
                                    projectId,
                                    {
                                        query: { download: 1 },
                                    },
                                )}
                                download
                                data-test="demo-download"
                            >
                                <Download />
                                Download MP4
                            </a>
                        </Button>
                    </div>
                </>
            ) : (
                <div
                    className="flex aspect-video w-full items-center justify-center rounded-xl border border-dashed border-sidebar-border p-6 text-center text-sm text-muted-foreground"
                    data-test="demo-empty"
                >
                    No demo yet. Pick the scenes below and click Render.
                </div>
            )}
        </div>
    );
}

/** The video, fetched whole (it comes out of the sandbox) so it can be scrubbed through. */
function DemoVideo({
    projectId,
    version,
}: {
    projectId: number;
    version: string;
}) {
    const [url, setUrl] = useState<string | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let cancelled = false;
        let objectUrl: string | null = null;

        setUrl(null);
        setFailed(false);

        void (async () => {
            const response = await fetch(
                ProjectDemoController.video.url(projectId, {
                    query: { v: version },
                }),
                { credentials: 'same-origin' },
            ).catch(() => null);

            if (cancelled) {
                return;
            }

            if (!response?.ok) {
                setFailed(true);

                return;
            }

            objectUrl = URL.createObjectURL(await response.blob());

            if (cancelled) {
                URL.revokeObjectURL(objectUrl);

                return;
            }

            setUrl(objectUrl);
        })();

        return () => {
            cancelled = true;

            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
            }
        };
    }, [projectId, version]);

    if (failed) {
        return (
            <p className="text-sm text-muted-foreground">
                Couldn't load the video.
            </p>
        );
    }

    return url ? (
        <video
            src={url}
            controls
            className="w-full rounded-xl border bg-black"
            data-test="demo-video"
        />
    ) : (
        <div className="aspect-video w-full animate-pulse rounded-xl bg-muted" />
    );
}

function Empty({ children, tone }: { children: ReactNode; tone?: 'error' }) {
    return (
        <div
            className={cn(
                'max-w-xl rounded-xl border border-dashed border-sidebar-border p-6 text-sm',
                tone === 'error' ? 'text-red-600' : 'text-muted-foreground',
            )}
        >
            {children}
        </div>
    );
}
