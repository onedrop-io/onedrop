import { languages } from '@codemirror/language-data';
import { oneDark } from '@codemirror/theme-one-dark';
import type { Extension } from '@codemirror/state';
import { keymap } from '@codemirror/view';
import CodeMirror from '@uiw/react-codemirror';
import { LanguageDescription } from '@codemirror/language';
import { RotateCw, Save } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import ProjectServiceController from '@/actions/App/Http/Controllers/ProjectServiceController';
import { Button } from '@/components/ui/button';
import FileIcon from '@/components/workspace/file-icon';
import { useAppearance } from '@/hooks/use-appearance';
import { saveWorkspaceFile } from '@/hooks/use-workspace-files';
import { jsonRequest } from '@/lib/json-request';
import { cn } from '@/lib/utils';
import type { WorkspaceFile } from '@/types';

/**
 * Whether the app only reads this file when it starts (FILE-007): `.env` files (not examples),
 * `.onedrop/dev` and Docker Compose files, so a saved change needs a preview restart.
 */
export function readAtStartup(path: string): boolean {
    const name = path.split('/').pop() ?? path;

    if (/^\.env(\..+)?$/.test(name)) {
        return !/\.(example|sample|dist|template)$/.test(name);
    }

    return (
        path === '.onedrop/dev' ||
        /^(docker-)?compose(\..+)?\.ya?ml$/.test(name)
    );
}

/**
 * An open file: shows notices for binary/large files, otherwise an editor
 * with syntax highlighting that saves back into the sandbox (Cmd/Ctrl+S).
 * Render with `key={path}` so each file gets fresh editor state.
 */
export default function FileViewer({
    projectId,
    file,
    error,
    onDirtyChange,
}: {
    projectId: number;
    file: WorkspaceFile | null;
    error: string | null;
    onDirtyChange?: (dirty: boolean) => void;
}) {
    if (error) {
        return <p className="p-4 text-sm text-red-600">{error}</p>;
    }

    if (!file) {
        return <p className="p-4 text-sm text-muted-foreground">Loading…</p>;
    }

    if (file.notice) {
        return (
            <p
                className="p-4 text-sm text-muted-foreground"
                data-test="file-notice"
            >
                {file.notice}
            </p>
        );
    }

    return (
        <FileEditor
            projectId={projectId}
            path={file.path}
            content={file.content ?? ''}
            onDirtyChange={onDirtyChange}
        />
    );
}

function FileEditor({
    projectId,
    path,
    content,
    onDirtyChange,
}: {
    projectId: number;
    path: string;
    content: string;
    onDirtyChange?: (dirty: boolean) => void;
}) {
    const { resolvedAppearance } = useAppearance();
    const [saved, setSaved] = useState(content);
    const [draft, setDraft] = useState(content);
    const [saving, setSaving] = useState(false);
    const [saveError, setSaveError] = useState<string | null>(null);
    const [restart, setRestart] = useState<
        'ask' | 'restarting' | 'done' | null
    >(null);
    const [restartError, setRestartError] = useState<string | null>(null);
    const [language, setLanguage] = useState<Extension | null>(null);
    const [lastContent, setLastContent] = useState(content);
    const dirty = draft !== saved;

    // The agent may change the file: take the new text unless there are unsaved edits.
    if (content !== lastContent) {
        setLastContent(content);

        if (!dirty) {
            setSaved(content);
            setDraft(content);
        }
    }

    useEffect(() => {
        onDirtyChange?.(dirty);
    }, [dirty, onDirtyChange]);

    useEffect(() => () => onDirtyChange?.(false), [onDirtyChange]);

    useEffect(() => {
        let cancelled = false;
        const description = LanguageDescription.matchFilename(
            languages,
            path.split('/').pop() ?? path,
        );

        void description?.load().then((support) => {
            if (!cancelled) {
                setLanguage(support);
            }
        });

        return () => {
            cancelled = true;
        };
    }, [path]);

    const save = async () => {
        if (saving || !dirty) {
            return;
        }

        const text = draft;
        setSaving(true);
        setSaveError(null);

        try {
            await saveWorkspaceFile(projectId, path, text);
            setSaved(text);

            if (readAtStartup(path)) {
                setRestart('ask');
                setRestartError(null);
            }
        } catch (e) {
            setSaveError((e as Error).message);
        } finally {
            setSaving(false);
        }
    };

    const restartPreview = async () => {
        setRestart('restarting');
        setRestartError(null);

        try {
            await jsonRequest(
                ProjectServiceController.restartPreview.url(projectId),
                {},
            );
            setRestart('done');
        } catch (e) {
            setRestart('ask');
            setRestartError((e as Error).message);
        }
    };

    // The keymap is built once per language, so it calls the latest save through a ref.
    const saveRef = useRef(save);
    useEffect(() => {
        saveRef.current = save;
    });

    const extensions = useMemo(
        () => [
            keymap.of([
                {
                    key: 'Mod-s',
                    preventDefault: true,
                    run: () => {
                        void saveRef.current();

                        return true;
                    },
                },
            ]),
            ...(language ? [language] : []),
        ],
        [language],
    );

    return (
        <div className="flex min-h-0 flex-1 flex-col" data-test="file-viewer">
            <div className="flex items-center gap-2 border-b border-sidebar-border/70 px-3 py-1 text-xs text-muted-foreground dark:border-sidebar-border">
                <FileIcon name={path.split('/').pop() ?? path} />
                <span className="min-w-0 flex-1 truncate">
                    {path.split('/').join(' › ')}
                </span>
                {saveError ? (
                    <span className="text-red-600" data-test="file-save-error">
                        {saveError}
                    </span>
                ) : (
                    <span data-test="file-save-state">
                        {saving ? 'Saving…' : dirty ? 'Unsaved changes' : ''}
                    </span>
                )}
                <button
                    type="button"
                    onClick={() => void save()}
                    disabled={!dirty || saving}
                    title="Save (⌘S / Ctrl+S)"
                    data-test="file-save"
                    className={cn(
                        'flex items-center gap-1 rounded px-2 py-0.5',
                        dirty
                            ? 'bg-primary text-primary-foreground hover:bg-primary/90'
                            : 'opacity-50',
                    )}
                >
                    <Save className="size-3.5" />
                    Save
                </button>
            </div>
            {restart && (
                <div
                    className="flex items-center gap-2 border-b border-sidebar-border/70 bg-muted/50 px-3 py-1.5 text-xs dark:border-sidebar-border"
                    data-test="file-restart-prompt"
                >
                    <span className="min-w-0 flex-1">
                        {restartError ? (
                            <span className="text-red-600" role="alert">
                                {restartError}
                            </span>
                        ) : restart === 'done' ? (
                            'The preview is restarting with your change.'
                        ) : (
                            'Your app reads this file when it starts. Restart the preview to use the change?'
                        )}
                    </span>
                    {restart !== 'done' && (
                        <Button
                            size="sm"
                            className="h-6 px-2 text-xs"
                            disabled={restart === 'restarting'}
                            onClick={() => void restartPreview()}
                            data-test="file-restart"
                        >
                            <RotateCw className="size-3" />
                            {restart === 'restarting'
                                ? 'Restarting…'
                                : 'Restart'}
                        </Button>
                    )}
                    <Button
                        size="sm"
                        variant="ghost"
                        className="h-6 px-2 text-xs"
                        onClick={() => setRestart(null)}
                        data-test="file-restart-dismiss"
                    >
                        {restart === 'done' ? 'Close' : 'Not now'}
                    </Button>
                </div>
            )}
            <CodeMirror
                value={draft}
                onChange={setDraft}
                extensions={extensions}
                theme={resolvedAppearance === 'dark' ? oneDark : 'light'}
                height="100%"
                className="min-h-0 flex-1 overflow-hidden text-xs [&_.cm-editor]:h-full [&_.cm-editor]:bg-transparent! [&_.cm-gutters]:bg-transparent!"
                basicSetup={{ foldGutter: true, highlightActiveLine: true }}
            />
        </div>
    );
}
