import { router, useForm } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import OrganizationSecretController from '@/actions/App/Http/Controllers/OrganizationSecretController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type OrganizationSecret = {
    id: number;
    name: string;
    all_projects: boolean;
    projects: number[];
    updated_at: string | null;
};

export type OrganizationSecrets = {
    items: OrganizationSecret[];
    projects: { id: number; name: string }[];
};

type SecretForm = {
    name: string;
    value: string;
    all_projects: boolean;
    projects: number[];
};

/**
 * Secrets (SECRET-003): environment variables the organization shares with its projects' sandboxes, like a
 * Codespaces organization secret. Values are write-only: they're never sent back once saved.
 */
export default function OrganizationSecretsSection({
    organization,
    secrets,
}: {
    organization: string;
    secrets: OrganizationSecrets;
}) {
    const [editing, setEditing] = useState<number | 'new' | null>(null);

    return (
        <section className="space-y-4" data-test="organization-secrets">
            <Heading
                variant="small"
                title="Secrets"
                description="Environment variables for your projects' sandboxes, like an AWS profile for everyone: the Shell, the running app and the agent all get them. A project's own secret with the same name wins. Values can't be shown again once saved, but anyone who can open a project can read the ones it gets."
            />

            {secrets.items.length > 0 && (
                <ul className="divide-y rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    {secrets.items.map((secret) =>
                        editing === secret.id ? (
                            <li key={secret.id} className="p-4">
                                <SecretEditor
                                    organization={organization}
                                    projects={secrets.projects}
                                    secret={secret}
                                    onDone={() => setEditing(null)}
                                />
                            </li>
                        ) : (
                            <SecretRow
                                key={secret.id}
                                organization={organization}
                                secret={secret}
                                projects={secrets.projects}
                                onEdit={() => setEditing(secret.id)}
                            />
                        ),
                    )}
                </ul>
            )}

            {editing === 'new' ? (
                <div className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <SecretEditor
                        organization={organization}
                        projects={secrets.projects}
                        onDone={() => setEditing(null)}
                    />
                </div>
            ) : (
                <Button
                    variant="outline"
                    onClick={() => setEditing('new')}
                    data-test="organization-secret-new"
                >
                    <KeyRound /> New secret
                </Button>
            )}
        </section>
    );
}

function SecretRow({
    organization,
    secret,
    projects,
    onEdit,
}: {
    organization: string;
    secret: OrganizationSecret;
    projects: { id: number; name: string }[];
    onEdit: () => void;
}) {
    const reaches = secret.all_projects
        ? 'All projects'
        : secret.projects.length === 1
          ? (projects.find((project) => project.id === secret.projects[0])
                ?.name ?? '1 project')
          : `${secret.projects.length} projects`;

    const remove = () => {
        if (
            confirm(
                `Delete ${secret.name}? It's taken out of every project's sandbox, and running apps restart without it.`,
            )
        ) {
            router.delete(
                OrganizationSecretController.destroy.url({
                    organization,
                    secret: secret.id,
                }),
                { preserveScroll: true },
            );
        }
    };

    return (
        <li
            className="flex flex-wrap items-center justify-between gap-3 p-4"
            data-test={`organization-secret-${secret.name}`}
        >
            <div className="min-w-0">
                <p className="truncate font-mono text-sm font-medium">
                    {secret.name}
                </p>
                <p className="text-sm text-muted-foreground">
                    {reaches}
                    {secret.updated_at &&
                        ` · updated ${new Date(secret.updated_at).toLocaleDateString()}`}
                </p>
            </div>
            <div className="flex items-center gap-2">
                <Button
                    variant="outline"
                    onClick={onEdit}
                    data-test={`organization-secret-${secret.name}-edit`}
                >
                    Change
                </Button>
                <Button
                    variant="ghost"
                    onClick={remove}
                    data-test={`organization-secret-${secret.name}-delete`}
                >
                    Delete
                </Button>
            </div>
        </li>
    );
}

/** Add a secret, or change one's value (left empty to keep it) and the projects it reaches. */
function SecretEditor({
    organization,
    projects,
    secret,
    onDone,
}: {
    organization: string;
    projects: { id: number; name: string }[];
    secret?: OrganizationSecret;
    onDone: () => void;
}) {
    const form = useForm<SecretForm>({
        name: secret?.name ?? '',
        value: '',
        all_projects: secret?.all_projects ?? true,
        projects: secret?.projects ?? [],
    });

    const save = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };

        if (secret) {
            form.put(
                OrganizationSecretController.update.url({
                    organization,
                    secret: secret.id,
                }),
                options,
            );
        } else {
            form.post(
                OrganizationSecretController.store.url(organization),
                options,
            );
        }
    };

    const toggleProject = (id: number, checked: boolean) =>
        form.setData(
            'projects',
            checked
                ? [...form.data.projects, id]
                : form.data.projects.filter((project) => project !== id),
        );

    return (
        <form
            onSubmit={save}
            className="grid max-w-xl gap-4"
            data-test="organization-secret-form"
        >
            {secret ? (
                <p className="font-mono text-sm font-medium">{secret.name}</p>
            ) : (
                <div className="grid gap-2">
                    <Label htmlFor="organization-secret-name">Name</Label>
                    <Input
                        id="organization-secret-name"
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        placeholder="AWS_ACCESS_KEY_ID"
                        autoComplete="off"
                        spellCheck={false}
                        autoFocus
                        className="font-mono"
                        data-test="organization-secret-name"
                    />
                    <InputError message={form.errors.name} />
                </div>
            )}

            <div className="grid gap-2">
                <Label htmlFor="organization-secret-value">Value</Label>
                <textarea
                    id="organization-secret-value"
                    value={form.data.value}
                    onChange={(event) =>
                        form.setData('value', event.target.value)
                    }
                    placeholder={
                        secret ? 'Saved (leave empty to keep)' : undefined
                    }
                    rows={2}
                    autoComplete="off"
                    spellCheck={false}
                    autoFocus={!!secret}
                    className="min-h-9 rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    data-test="organization-secret-value"
                />
                <InputError message={form.errors.value} />
            </div>

            <fieldset className="grid gap-2">
                <legend className="mb-2 text-sm font-medium">Projects</legend>
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="radio"
                        name="organization-secret-reach"
                        checked={form.data.all_projects}
                        onChange={() => form.setData('all_projects', true)}
                        data-test="organization-secret-all-projects"
                    />
                    All projects, including new ones
                </label>
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="radio"
                        name="organization-secret-reach"
                        checked={!form.data.all_projects}
                        onChange={() => form.setData('all_projects', false)}
                        data-test="organization-secret-selected-projects"
                    />
                    Selected projects
                </label>
                {!form.data.all_projects && (
                    <ul className="ml-6 max-h-60 space-y-2 overflow-y-auto rounded-md border p-3">
                        {projects.length === 0 && (
                            <li className="text-sm text-muted-foreground">
                                No projects yet.
                            </li>
                        )}
                        {projects.map((project) => (
                            <li key={project.id}>
                                <label className="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={form.data.projects.includes(
                                            project.id,
                                        )}
                                        onCheckedChange={(checked) =>
                                            toggleProject(
                                                project.id,
                                                checked === true,
                                            )
                                        }
                                        data-test={`organization-secret-project-${project.id}`}
                                    />
                                    {project.name}
                                </label>
                            </li>
                        ))}
                    </ul>
                )}
                <InputError message={form.errors.projects} />
            </fieldset>

            <div className="flex gap-2">
                <Button
                    disabled={form.processing}
                    data-test="organization-secret-save"
                >
                    {secret ? 'Save' : 'Add secret'}
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}
