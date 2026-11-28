import { useEffect, useRef, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import ProjectSkillController from '@/actions/App/Http/Controllers/ProjectSkillController';
import SkillController from '@/actions/App/Http/Controllers/SkillController';
import Markdown from '@/components/markdown';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { askAgent } from '@/lib/ask-agent';
import { jsonRequest } from '@/lib/json-request';

/** One of the user's skills, or a shared one (SKILL-001). */
export type LibrarySkill = {
    id: number;
    name: string;
    description: string;
    shared: boolean;
    source: 'written' | 'github' | 'upload' | 'project' | 'skills.sh';
    source_url: string | null;
    owner: string | null;
    mine: boolean;
    can_edit: boolean;
    enabled: boolean;
    updated_at: string | null;
};

/** A skill in the project's repository (SKILL-003). */
export type ProjectSkill = {
    name: string;
    description: string | null;
    path: string;
};

type Detail = { content: string; files: string[] };

const textarea =
    'w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

/** The skills list after a change, from any of the add endpoints. */
type Changed = { skills: LibrarySkill[] };

/**
 * Write a new skill, or edit one of the user's (name, description, instructions, sharing).
 */
export function SkillFormDialog({
    projectId,
    skill,
    onSaved,
}: {
    projectId: number;
    /** The skill to edit; a new one when missing. */
    skill?: LibrarySkill;
    onSaved: (changed: Changed | null) => void;
}) {
    const [name, setName] = useState(skill?.name ?? '');
    const [description, setDescription] = useState(skill?.description ?? '');
    const [instructions, setInstructions] = useState<string | null>(
        skill ? null : '',
    );
    const [shared, setShared] = useState(skill?.shared ?? false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!skill) {
            return;
        }

        jsonRequest<{ skill: Detail }>(SkillController.show.url(skill.id))
            .then(({ skill }) => setInstructions(skill.content))
            .catch((e: Error) => setError(e.message));
    }, [skill]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        setError(null);

        const body = { name, description, instructions, shared };
        const request = skill
            ? jsonRequest(
                  SkillController.update.url(skill.id),
                  body,
                  'PATCH',
              ).then(() => null)
            : jsonRequest<Changed>(
                  ProjectSkillController.store.url(projectId),
                  body,
              );

        request
            .then(onSaved)
            .catch((e: Error) => setError(e.message))
            .finally(() => setSaving(false));
    };

    return (
        <DialogContent className="sm:max-w-2xl" data-test="skill-form">
            <form onSubmit={submit} className="space-y-4">
                <DialogHeader>
                    <DialogTitle>
                        {skill ? `Edit ${skill.name}` : 'Write a skill'}
                    </DialogTitle>
                    <DialogDescription>
                        The agent reads the description to decide when to use
                        the skill, then follows its instructions.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-1.5">
                    <Label htmlFor="skill-name">Name</Label>
                    <Input
                        id="skill-name"
                        autoFocus
                        value={name}
                        onChange={(event) =>
                            setName(
                                event.target.value
                                    .toLowerCase()
                                    .replace(/[\s_]+/g, '-'),
                            )
                        }
                        maxLength={64}
                        placeholder="release-notes"
                        className="font-mono"
                        data-test="skill-name"
                    />
                    <p className="text-xs text-muted-foreground">
                        Lowercase letters, digits and hyphens.
                    </p>
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="skill-description">Description</Label>
                    <textarea
                        id="skill-description"
                        value={description}
                        onChange={(event) => setDescription(event.target.value)}
                        rows={2}
                        maxLength={1024}
                        placeholder="Writes release notes in our format. Use when asked for release notes or a changelog."
                        className={textarea}
                        data-test="skill-description"
                    />
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="skill-instructions">Instructions</Label>
                    <textarea
                        id="skill-instructions"
                        value={instructions ?? ''}
                        disabled={instructions === null}
                        onChange={(event) =>
                            setInstructions(event.target.value)
                        }
                        rows={12}
                        placeholder={
                            instructions === null
                                ? 'Loading…'
                                : '# Release notes\n\n1. Read the git log since the last tag.\n2. …'
                        }
                        className={`${textarea} font-mono`}
                        data-test="skill-instructions"
                    />
                    <p className="text-xs text-muted-foreground">
                        Markdown. This is the body of the skill’s SKILL.md.
                    </p>
                </div>

                <label className="flex items-center gap-2 text-sm">
                    <Checkbox
                        checked={shared}
                        onCheckedChange={(checked) =>
                            setShared(checked === true)
                        }
                        data-test="skill-shared"
                    />
                    Share with everyone on this server
                </label>

                {error && (
                    <p className="text-sm text-red-600" data-test="skill-error">
                        {error}
                    </p>
                )}

                <DialogFooter>
                    <Button
                        type="submit"
                        disabled={
                            saving ||
                            instructions === null ||
                            name === '' ||
                            description.trim() === '' ||
                            instructions.trim() === ''
                        }
                        data-test="skill-save"
                    >
                        {saving ? 'Saving…' : skill ? 'Save' : 'Add skill'}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}

/** Describe a skill and ask the agent to write it as a project skill. */
export function CreateWithAgentDialog({
    projectId,
    onSent,
}: {
    projectId: number;
    onSent: (queued: boolean) => void;
}) {
    const [description, setDescription] = useState('');
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setSending(true);
        setError(null);

        askAgent(ProjectSkillController.create.url(projectId), { description })
            .then(({ queued }) => onSent(queued))
            .catch((e: Error) => setError(e.message))
            .finally(() => setSending(false));
    };

    return (
        <DialogContent data-test="skill-agent-dialog">
            <form onSubmit={submit} className="space-y-4">
                <DialogHeader>
                    <DialogTitle>Create a skill with the agent</DialogTitle>
                    <DialogDescription>
                        Say what the skill is for and when to use it. The agent
                        writes it in the project, under Project skills.
                    </DialogDescription>
                </DialogHeader>
                <textarea
                    autoFocus
                    value={description}
                    onChange={(event) => setDescription(event.target.value)}
                    rows={4}
                    maxLength={2000}
                    placeholder="e.g. How we write database migrations: always reversible, one table per migration, named like …"
                    className={textarea}
                    data-test="skill-agent-description"
                />
                {error && <p className="text-sm text-red-600">{error}</p>}
                <DialogFooter>
                    <Button
                        type="submit"
                        disabled={sending || description.trim() === ''}
                        data-test="skill-agent-send"
                    >
                        Create with agent
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}

/** Import a skill from a GitHub link. */
export function ImportSkillDialog({
    projectId,
    onAdded,
}: {
    projectId: number;
    onAdded: (changed: Changed) => void;
}) {
    const [url, setUrl] = useState('');
    const [importing, setImporting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setImporting(true);
        setError(null);

        jsonRequest<Changed>(ProjectSkillController.import.url(projectId), {
            url,
        })
            .then(onAdded)
            .catch((e: Error) => setError(e.message))
            .finally(() => setImporting(false));
    };

    return (
        <DialogContent data-test="skill-import-dialog">
            <form onSubmit={submit} className="space-y-4">
                <DialogHeader>
                    <DialogTitle>Import from GitHub</DialogTitle>
                    <DialogDescription>
                        Paste a link to a skill’s folder or its SKILL.md.
                        Private repositories work once you’ve connected GitHub
                        in Source Control.
                    </DialogDescription>
                </DialogHeader>
                <Input
                    autoFocus
                    value={url}
                    onChange={(event) => setUrl(event.target.value)}
                    placeholder="https://github.com/anthropics/skills/tree/main/skills/pdf"
                    data-test="skill-import-url"
                />
                {error && (
                    <p className="text-sm text-red-600" data-test="skill-error">
                        {error}
                    </p>
                )}
                <DialogFooter>
                    <Button
                        type="submit"
                        disabled={importing || url.trim() === ''}
                        data-test="skill-import-submit"
                    >
                        {importing ? 'Importing…' : 'Import'}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}

/** A search result from skills.sh (SKILL-005). */
type DirectorySkill = {
    id: string;
    name: string;
    source: string;
    installs: number;
    url: string;
    added: boolean;
};

type DirectoryDetail = {
    name: string;
    description: string;
    content: string;
    files: string[];
    url: string;
    added: boolean;
};

const installs = new Intl.NumberFormat(undefined, {
    notation: 'compact',
    maximumFractionDigits: 1,
});

/**
 * Search skills.sh as you type, read a skill, and add it (SKILL-005).
 */
export function BrowseSkillsDialog({
    projectId,
    onAdded,
}: {
    projectId: number;
    onAdded: (changed: Changed) => void;
}) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<DirectorySkill[] | null>(null);
    const [searching, setSearching] = useState(false);
    const [selected, setSelected] = useState<DirectorySkill | null>(null);
    const [detail, setDetail] = useState<DirectoryDetail | null>(null);
    const [adding, setAdding] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const searchCount = useRef(0);

    useEffect(() => {
        const q = query.trim();
        const count = ++searchCount.current;

        if (q.length < 2) {
            setResults(null);
            setSearching(false);

            return;
        }

        setSearching(true);
        const timer = setTimeout(() => {
            jsonRequest<{ skills: DirectorySkill[] }>(
                ProjectSkillController.browse.url(projectId, { query: { q } }),
            )
                .then(({ skills }) => {
                    if (count === searchCount.current) {
                        setResults(skills);
                        setError(null);
                    }
                })
                .catch((e: Error) => {
                    if (count === searchCount.current) {
                        setError(e.message);
                    }
                })
                .finally(() => {
                    if (count === searchCount.current) {
                        setSearching(false);
                    }
                });
        }, 300);

        return () => clearTimeout(timer);
    }, [projectId, query]);

    useEffect(() => {
        setDetail(null);

        if (!selected) {
            return;
        }

        let current = true;
        jsonRequest<{ skill: DirectoryDetail }>(
            ProjectSkillController.browseShow.url(projectId, {
                query: { id: selected.id },
            }),
        )
            .then(({ skill }) => current && setDetail(skill))
            .catch((e: Error) => current && setError(e.message));

        return () => {
            current = false;
        };
    }, [projectId, selected]);

    const add = (skill: DirectorySkill) => {
        setAdding(skill.id);
        setError(null);

        jsonRequest<Changed>(ProjectSkillController.browseAdd.url(projectId), {
            id: skill.id,
        })
            .then(onAdded)
            .catch((e: Error) => setError(e.message))
            .finally(() => setAdding(null));
    };

    const addButton = (skill: DirectorySkill, added: boolean) =>
        added ? (
            <span className="shrink-0 text-xs text-muted-foreground">
                Added
            </span>
        ) : (
            <Button
                size="sm"
                variant="outline"
                className="shrink-0"
                disabled={adding !== null}
                onClick={(event) => {
                    event.stopPropagation();
                    add(skill);
                }}
                data-test="skill-browse-add"
            >
                {adding === skill.id ? 'Adding…' : 'Add'}
            </Button>
        );

    return (
        <DialogContent
            className="flex max-h-[calc(100dvh-2rem)] flex-col sm:max-w-2xl"
            data-test="skill-browse-dialog"
        >
            <DialogHeader>
                <DialogTitle>
                    {selected ? (
                        <span className="font-mono">{selected.name}</span>
                    ) : (
                        'Browse skills.sh'
                    )}
                </DialogTitle>
                <DialogDescription>
                    {selected ? (
                        <>
                            From {selected.source} ·{' '}
                            <a
                                href={selected.url}
                                target="_blank"
                                rel="noreferrer"
                                className="underline underline-offset-2"
                            >
                                View on skills.sh
                            </a>
                        </>
                    ) : (
                        'Public skills from GitHub, ranked by installs. Read one before you add it: the agent follows its instructions.'
                    )}
                </DialogDescription>
            </DialogHeader>

            {selected ? (
                <>
                    <div className="min-h-0 flex-1 space-y-3 overflow-y-auto rounded-lg border p-4 text-sm">
                        {detail ? (
                            <>
                                <p
                                    className="text-muted-foreground"
                                    data-test="skill-browse-description"
                                >
                                    {detail.description}
                                </p>
                                <Markdown content={detail.content} />
                            </>
                        ) : error ? null : (
                            <p className="text-muted-foreground">Loading…</p>
                        )}
                    </div>
                    {detail && detail.files.length > 0 && (
                        <div className="text-sm">
                            <p className="mb-1 text-muted-foreground">
                                Other files
                            </p>
                            <ul className="max-h-32 overflow-y-auto font-mono text-xs">
                                {detail.files.map((file) => (
                                    <li key={file}>{file}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {error && (
                        <p
                            className="text-sm text-red-600"
                            data-test="skill-error"
                        >
                            {error}
                        </p>
                    )}
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => {
                                setSelected(null);
                                setError(null);
                            }}
                        >
                            Back
                        </Button>
                        {detail &&
                            (detail.added ? (
                                <Button disabled>Already added</Button>
                            ) : (
                                <Button
                                    disabled={adding !== null}
                                    onClick={() => add(selected)}
                                    data-test="skill-browse-add-detail"
                                >
                                    {adding ? 'Adding…' : 'Add skill'}
                                </Button>
                            ))}
                    </DialogFooter>
                </>
            ) : (
                <>
                    <Input
                        autoFocus
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Search: pdf, stripe, testing…"
                        maxLength={100}
                        data-test="skill-browse-search"
                    />
                    {error && (
                        <p
                            className="text-sm text-red-600"
                            data-test="skill-error"
                        >
                            {error}
                        </p>
                    )}
                    <div className="min-h-32 flex-1 overflow-y-auto">
                        {results === null ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                {searching
                                    ? 'Searching…'
                                    : 'Type at least two characters to search.'}
                            </p>
                        ) : results.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                {searching
                                    ? 'Searching…'
                                    : `No skills match “${query.trim()}”.`}
                            </p>
                        ) : (
                            <ul
                                className="divide-y"
                                data-test="skill-browse-results"
                            >
                                {results.map((skill) => (
                                    <li
                                        key={skill.id}
                                        className="flex cursor-pointer items-center gap-3 px-1 py-2 hover:bg-muted/50"
                                        onClick={() => {
                                            setError(null);
                                            setSelected(skill);
                                        }}
                                        data-test="skill-browse-result"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate font-mono text-sm">
                                                {skill.name}
                                            </p>
                                            <p className="truncate text-xs text-muted-foreground">
                                                {skill.source} ·{' '}
                                                {installs.format(
                                                    skill.installs,
                                                )}{' '}
                                                installs
                                            </p>
                                        </div>
                                        {addButton(skill, skill.added)}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </>
            )}
        </DialogContent>
    );
}

/** Upload a SKILL.md or a zip of a skill folder. */
export function UploadSkillDialog({
    projectId,
    onAdded,
}: {
    projectId: number;
    onAdded: (changed: Changed) => void;
}) {
    const [file, setFile] = useState<File | null>(null);
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!file) {
            return;
        }

        const body = new FormData();
        body.append('file', file);
        setUploading(true);
        setError(null);

        jsonRequest<Changed>(ProjectSkillController.upload.url(projectId), body)
            .then(onAdded)
            .catch((e: Error) => setError(e.message))
            .finally(() => setUploading(false));
    };

    return (
        <DialogContent data-test="skill-upload-dialog">
            <form onSubmit={submit} className="space-y-4">
                <DialogHeader>
                    <DialogTitle>Upload a skill</DialogTitle>
                    <DialogDescription>
                        A SKILL.md, or a .zip of a skill folder with its
                        SKILL.md and other files (up to 50 files and 1 MB).
                    </DialogDescription>
                </DialogHeader>
                <Input
                    type="file"
                    accept=".md,.zip,text/markdown,application/zip"
                    onChange={(event) =>
                        setFile(event.target.files?.[0] ?? null)
                    }
                    data-test="skill-upload-file"
                />
                {error && (
                    <p className="text-sm text-red-600" data-test="skill-error">
                        {error}
                    </p>
                )}
                <DialogFooter>
                    <Button
                        type="submit"
                        disabled={uploading || !file}
                        data-test="skill-upload-submit"
                    >
                        {uploading ? 'Uploading…' : 'Upload'}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}

/** A skill's instructions and other files. */
export function ViewSkillDialog({
    projectId,
    title,
    subtitle,
    source,
    actions,
}: {
    projectId: number;
    title: string;
    subtitle: ReactNode;
    /** One of the user's skills (by id), or a project skill (by path). */
    source: { id: number } | { path: string };
    actions?: ReactNode;
}) {
    const [detail, setDetail] = useState<Detail | null>(null);
    const [error, setError] = useState<string | null>(null);
    const key = 'id' in source ? `id:${source.id}` : `path:${source.path}`;

    useEffect(() => {
        const url =
            'id' in source
                ? SkillController.show.url(source.id)
                : ProjectSkillController.projectShow.url(projectId, {
                      query: { path: source.path },
                  });

        jsonRequest<{ skill: Detail }>(url)
            .then(({ skill }) => setDetail(skill))
            .catch((e: Error) => setError(e.message));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [projectId, key]);

    return (
        <DialogContent
            className="flex max-h-[calc(100dvh-2rem)] flex-col sm:max-w-3xl"
            data-test="skill-view"
        >
            <DialogHeader>
                <DialogTitle className="font-mono">{title}</DialogTitle>
                <DialogDescription>{subtitle}</DialogDescription>
            </DialogHeader>
            <div className="min-h-0 flex-1 overflow-y-auto rounded-lg border p-4 text-sm">
                {error ? (
                    <p className="text-red-600">{error}</p>
                ) : detail ? (
                    <Markdown
                        content={detail.content}
                        data-test="skill-view-content"
                    />
                ) : (
                    <p className="text-muted-foreground">Loading…</p>
                )}
            </div>
            {detail && detail.files.length > 0 && (
                <div className="text-sm">
                    <p className="mb-1 text-muted-foreground">Other files</p>
                    <ul
                        className="max-h-32 overflow-y-auto font-mono text-xs"
                        data-test="skill-view-files"
                    >
                        {detail.files.map((file) => (
                            <li key={file}>{file}</li>
                        ))}
                    </ul>
                </div>
            )}
            {actions && <DialogFooter>{actions}</DialogFooter>}
        </DialogContent>
    );
}

/** Confirm deleting one of the user's skills. */
export function DeleteSkillDialog({
    skill,
    onClose,
    onDeleted,
}: {
    skill: LibrarySkill;
    onClose: () => void;
    onDeleted: () => void;
}) {
    const [deleting, setDeleting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const remove = () => {
        setDeleting(true);
        jsonRequest(SkillController.destroy.url(skill.id), {}, 'DELETE')
            .then(onDeleted)
            .catch((e: Error) => setError(e.message))
            .finally(() => setDeleting(false));
    };

    return (
        <DialogContent data-test="skill-delete-dialog">
            <DialogHeader>
                <DialogTitle>Delete {skill.name}?</DialogTitle>
                <DialogDescription>
                    It’s turned off in every project that uses it
                    {skill.shared ? ', including other people’s' : ''}. This
                    can’t be undone.
                </DialogDescription>
            </DialogHeader>
            {error && <p className="text-sm text-red-600">{error}</p>}
            <DialogFooter>
                <Button variant="ghost" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    variant="destructive"
                    autoFocus
                    disabled={deleting}
                    onClick={remove}
                    data-test="skill-delete-confirm"
                >
                    Delete
                </Button>
            </DialogFooter>
        </DialogContent>
    );
}
