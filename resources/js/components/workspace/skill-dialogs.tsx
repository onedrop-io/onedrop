import { useEffect, useState } from 'react';
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
    source: 'written' | 'github' | 'upload' | 'project';
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
        <DialogContent className="sm:max-w-3xl" data-test="skill-view">
            <DialogHeader>
                <DialogTitle className="font-mono">{title}</DialogTitle>
                <DialogDescription>{subtitle}</DialogDescription>
            </DialogHeader>
            <div className="max-h-[60vh] overflow-y-auto rounded-lg border p-4 text-sm">
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
                        className="font-mono text-xs"
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
