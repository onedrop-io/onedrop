import { Form, Head, router, setLayoutProps, usePage } from '@inertiajs/react';
import { useRef } from 'react';
import OrganizationController from '@/actions/App/Http/Controllers/OrganizationController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { OrganizationMark } from '@/components/organization-mark';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useOrganization } from '@/hooks/use-organization';
import { edit } from '@/routes/organizations';

/** An organization's general settings: name, address, logo, computers and task copy limit (ORG-005, CMP-003, TASK-003). */
export default function OrganizationEdit({
    details,
    computers,
    taskCopies,
}: {
    details: { name: string; slug: string; logo_url: string | null };
    computers: { enabled: boolean } | null;
    taskCopies: { limit: number | null; install_limit: number | null };
}) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const organization = useOrganization();
    const fileInput = useRef<HTMLInputElement>(null);

    const uploadLogo = (file: File | undefined) => {
        if (!file) {
            return;
        }

        router.post(
            OrganizationController.storeLogo.url(organization.slug),
            { logo: file },
            {
                forceFormData: true,
                preserveScroll: true,
                onFinish: () => {
                    if (fileInput.current) {
                        fileInput.current.value = '';
                    }
                },
            },
        );
    };

    setLayoutProps({
        breadcrumbs: [{ title: 'Organization', href: edit(organization.slug) }],
    });

    return (
        <>
            <Head title="Organization" />

            <div className="flex max-w-3xl flex-1 flex-col gap-10">
                <Heading
                    title={details.name}
                    description="Its projects, groups, invites and shared skills are only seen by the people in it"
                />

                <section className="space-y-4">
                    <Heading variant="small" title="Details" />
                    <Form
                        {...OrganizationController.update.form(
                            organization.slug,
                        )}
                        options={{ preserveScroll: true }}
                        className="grid max-w-xl gap-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="organization-name">
                                        Name
                                    </Label>
                                    <Input
                                        id="organization-name"
                                        name="name"
                                        defaultValue={details.name}
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="organization-slug">
                                        Address
                                    </Label>
                                    <div className="flex items-center gap-1 text-sm text-muted-foreground">
                                        <span>/o/</span>
                                        <Input
                                            id="organization-slug"
                                            name="slug"
                                            defaultValue={details.slug}
                                            required
                                        />
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        Links to the old address stop working.
                                    </p>
                                    <InputError message={errors.slug} />
                                </div>
                                <div>
                                    <Button
                                        disabled={processing}
                                        data-test="save-organization"
                                    >
                                        Save
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </section>

                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Logo"
                        description="Shown in the organization menu at the top of the sidebar. PNG, JPEG, WebP or SVG, up to 1 MB; square works best."
                    />
                    <div className="flex items-center gap-4">
                        <OrganizationMark
                            organization={{
                                name: details.name,
                                logo_url: details.logo_url,
                            }}
                            className="size-16 text-2xl"
                        />
                        <input
                            ref={fileInput}
                            type="file"
                            accept="image/png,image/jpeg,image/webp,image/svg+xml"
                            className="hidden"
                            data-test="organization-logo-input"
                            onChange={(event) =>
                                uploadLogo(event.target.files?.[0])
                            }
                        />
                        <Button
                            variant="outline"
                            onClick={() => fileInput.current?.click()}
                        >
                            {details.logo_url ? 'Replace logo' : 'Upload logo'}
                        </Button>
                        {details.logo_url && (
                            <Button
                                variant="ghost"
                                data-test="remove-organization-logo"
                                onClick={() =>
                                    router.delete(
                                        OrganizationController.destroyLogo.url(
                                            organization.slug,
                                        ),
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Remove
                            </Button>
                        )}
                    </div>
                    <InputError message={errors.logo} />
                </section>

                {computers && (
                    <section className="space-y-4">
                        <Heading
                            variant="small"
                            title="Computers"
                            description="Each person gets their own cloud computer: a desktop with a browser, which they and the AI use. Turning computers off puts running ones to sleep; turning them on again gives everyone theirs back."
                        />
                        <div className="flex items-center gap-3 text-sm">
                            <Switch
                                checked={computers.enabled}
                                label={`Give everyone in ${details.name} a computer`}
                                testId="organization-computers"
                                onChange={(enabled) =>
                                    router.put(
                                        OrganizationController.updateComputers.url(
                                            organization.slug,
                                        ),
                                        { enabled },
                                        { preserveScroll: true },
                                    )
                                }
                            />
                            <span>
                                Give everyone in {details.name} a computer
                            </span>
                        </div>
                    </section>
                )}

                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Task copies"
                        description="Each task works in its own copy of its project's sandbox, and each copy is a sandbox that costs money to run. Limit how many one project runs at once."
                    />
                    <Form
                        {...OrganizationController.updateTaskCopies.form(
                            organization.slug,
                        )}
                        options={{ preserveScroll: true }}
                        className="grid max-w-sm gap-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="organization-max-task-copies">
                                        Most at once per project
                                    </Label>
                                    <Input
                                        id="organization-max-task-copies"
                                        name="max_task_copies"
                                        type="number"
                                        min={1}
                                        max={1000}
                                        defaultValue={taskCopies.limit ?? ''}
                                        placeholder="No limit"
                                        data-test="organization-max-task-copies"
                                    />
                                    <p className="text-sm text-muted-foreground">
                                        {taskCopies.install_limit === null
                                            ? 'Leave empty for no limit.'
                                            : `Leave empty for this install's limit of ${taskCopies.install_limit}, which also caps a higher one.`}
                                    </p>
                                    <InputError
                                        message={errors.max_task_copies}
                                    />
                                </div>
                                <div>
                                    <Button
                                        disabled={processing}
                                        data-test="save-organization-task-copies"
                                    >
                                        Save
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </section>
            </div>
        </>
    );
}
