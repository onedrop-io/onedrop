import { Check, Copy, Sparkles } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import { storageApi } from '@/lib/storage-api';
import { cn } from '@/lib/utils';

type Language = 'node' | 'laravel';

type Snippet = { title: string; code: string };

function nodeSnippets(bucket: string): Snippet[] {
    return [
        {
            title: 'Open the bucket (src/server/storage.js)',
            code: `import { mkdir, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';

// APP_STORAGE_DIR is set in the sandbox; buckets are folders inside it.
const bucket = path.join(process.env.APP_STORAGE_DIR ?? '.storage', '${bucket}');

function objectPath(key) {
    const file = path.resolve(bucket, key);
    if (!file.startsWith(bucket + path.sep)) throw new Error('Invalid key');
    return file;
}`,
        },
        {
            title: 'Upload an object (text, JSON or bytes)',
            code: `export async function put(key, bytes) {
    const file = objectPath(key);
    await mkdir(path.dirname(file), { recursive: true });
    await writeFile(file, bytes);
}

await put('avatars/42.png', imageBuffer);`,
        },
        {
            title: 'Download an object',
            code: `const json = JSON.parse(await readFile(objectPath('settings.json'), 'utf8'));
const bytes = await readFile(objectPath('avatars/42.png'));`,
        },
        {
            title: 'List the objects in a folder',
            code: `const names = await readdir(objectPath('avatars'));`,
        },
        {
            title: 'Delete an object',
            code: `await rm(objectPath('avatars/42.png'), { force: true });`,
        },
    ];
}

function laravelSnippets(bucket: string): Snippet[] {
    return [
        {
            title: 'Add a disk (config/filesystems.php)',
            code: `'${bucket}' => [
    'driver' => 'local',
    // APP_STORAGE_DIR is set in the sandbox; buckets are folders inside it.
    'root' => rtrim(env('APP_STORAGE_DIR', storage_path('app/zap-storage')), '/').'/${bucket}',
    'throw' => true,
],`,
        },
        {
            title: 'Upload an object',
            code: `use Illuminate\\Support\\Facades\\Storage;

$key = $request->file('photo')->store('avatars', '${bucket}');
Storage::disk('${bucket}')->put('settings.json', json_encode($settings));`,
        },
        {
            title: 'Download or serve an object',
            code: `$json = Storage::disk('${bucket}')->get('settings.json');

// In a route or controller: stream it to the browser.
return Storage::disk('${bucket}')->response($key);`,
        },
        {
            title: 'List the objects in a folder',
            code: `$keys = Storage::disk('${bucket}')->files('avatars');`,
        },
        {
            title: 'Delete an object',
            code: `Storage::disk('${bucket}')->delete($key);`,
        },
    ];
}

/**
 * How the app reads and writes a bucket, per stack, and a shortcut to have the agent wire it up.
 */
export default function CommandsView({
    projectId,
    bucket,
}: {
    projectId: number;
    bucket: string;
}) {
    const [language, setLanguage] = useState<Language>('node');
    const snippets =
        language === 'node' ? nodeSnippets(bucket) : laravelSnippets(bucket);

    return (
        <div className="max-w-4xl space-y-6" data-test="storage-commands">
            <AskAgent projectId={projectId} bucket={bucket} />

            <div className="space-y-4">
                <div
                    role="tablist"
                    aria-label="Language"
                    className="inline-flex rounded-lg bg-muted p-1 text-sm"
                >
                    {(
                        [
                            ['node', 'Node.js'],
                            ['laravel', 'Laravel / PHP'],
                        ] as const
                    ).map(([id, label]) => (
                        <button
                            key={id}
                            type="button"
                            role="tab"
                            aria-selected={language === id}
                            onClick={() => setLanguage(id)}
                            className={cn(
                                'rounded-md px-3 py-1',
                                language === id
                                    ? 'bg-background font-medium shadow-xs'
                                    : 'text-muted-foreground',
                            )}
                            data-test={`storage-language-${id}`}
                        >
                            {label}
                        </button>
                    ))}
                </div>

                <p className="text-sm text-muted-foreground">
                    Buckets are folders on the sandbox’s disk, so the app uses
                    its stack’s normal file tools; no SDK needed. The same files
                    are used by the preview and the published app.
                </p>

                {snippets.map((snippet) => (
                    <CodeBlock key={snippet.title} {...snippet} />
                ))}
            </div>
        </div>
    );
}

function CodeBlock({ title, code }: Snippet) {
    const [copied, copy] = useClipboard();

    return (
        <div className="space-y-1.5">
            <p className="text-sm">{title}</p>
            <div className="group relative">
                <pre className="overflow-x-auto rounded-lg bg-muted p-4 font-mono text-xs leading-relaxed">
                    {code}
                </pre>
                <Button
                    size="icon"
                    variant="ghost"
                    className="absolute top-2 right-2 size-7 opacity-0 group-hover:opacity-100 focus-visible:opacity-100"
                    aria-label={`Copy: ${title}`}
                    onClick={() => void copy(code)}
                >
                    {copied === code ? <Check /> : <Copy />}
                </Button>
            </div>
        </div>
    );
}

/** Describe what the app should store and ask the agent to set it up with this bucket. */
function AskAgent({
    projectId,
    bucket,
}: {
    projectId: number;
    bucket: string;
}) {
    const [uses, setUses] = useState('');
    const [sending, setSending] = useState(false);
    const [notice, setNotice] = useState<{
        text: string;
        error?: boolean;
    } | null>(null);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setSending(true);

        storageApi
            .askAgent(projectId, bucket, uses)
            .then(({ queued }) => {
                setUses('');
                setNotice({
                    text: queued
                        ? 'Asked the agent. It runs after the current task; follow along in the chat.'
                        : 'The agent is setting it up. Follow along in the chat.',
                });
            })
            .catch((e: Error) => setNotice({ text: e.message, error: true }))
            .finally(() => setSending(false));
    };

    return (
        <form
            onSubmit={submit}
            className="space-y-2 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <label
                htmlFor="storage-uses"
                className="flex items-start gap-2 text-sm font-medium"
            >
                <Sparkles className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                <span>
                    Have the agent use <code>{bucket}</code> in your app
                </span>
            </label>
            <textarea
                id="storage-uses"
                value={uses}
                onChange={(event) => setUses(event.target.value)}
                rows={2}
                maxLength={2000}
                placeholder="What should people be able to upload? e.g. Profile photos, or PDF receipts on each expense"
                className="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                data-test="storage-uses"
            />
            <div className="flex items-center gap-3">
                <Button
                    type="submit"
                    size="sm"
                    disabled={sending}
                    data-test="storage-ask-agent"
                >
                    Set up with agent
                </Button>
                {notice && (
                    <p
                        className={cn(
                            'text-sm',
                            notice.error
                                ? 'text-red-600'
                                : 'text-muted-foreground',
                        )}
                        data-test="storage-agent-notice"
                    >
                        {notice.text}
                    </p>
                )}
            </div>
        </form>
    );
}
