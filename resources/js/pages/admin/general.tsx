import { Form, Head, router, usePage } from '@inertiajs/react';
import { useRef } from 'react';
import BrandingController from '@/actions/App/Http/Controllers/Admin/BrandingController';
import AppLogoIcon from '@/components/app-logo-icon';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/** The install's name and logo (ADMIN-001). */
export default function General({
    defaultName,
    customName,
}: {
    defaultName: string;
    customName: string | null;
}) {
    const { logo, errors } = usePage().props as {
        logo: string | null;
        errors: Record<string, string>;
    };
    const fileInput = useRef<HTMLInputElement>(null);

    const upload = (file: File | undefined) => {
        if (!file) {
            return;
        }

        router.post(
            BrandingController.storeLogo.url(),
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

    return (
        <>
            <Head title="General" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Name"
                    description="What this install is called in the sidebar, the browser tab and emails"
                />

                <Form
                    {...BrandingController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    defaultValue={customName ?? ''}
                                    placeholder={defaultName}
                                    maxLength={60}
                                    data-test="app-name-input"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Leave empty to use {defaultName}.
                                </p>
                                <InputError message={errors.name} />
                            </div>

                            <Button
                                disabled={processing}
                                data-test="save-name-button"
                            >
                                Save
                            </Button>
                        </>
                    )}
                </Form>
            </div>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Logo"
                    description="Replaces the droplet in the sidebar, sign-in pages and browser tab. PNG, JPEG, WebP or SVG, up to 1 MB; square works best."
                />

                <div className="flex items-center gap-4">
                    <div className="flex size-16 items-center justify-center rounded-lg border bg-muted/40">
                        <AppLogoIcon className="size-10 fill-current" />
                    </div>

                    <input
                        ref={fileInput}
                        type="file"
                        accept="image/png,image/jpeg,image/webp,image/svg+xml"
                        className="hidden"
                        data-test="logo-input"
                        onChange={(event) => upload(event.target.files?.[0])}
                    />
                    <Button
                        variant="outline"
                        onClick={() => fileInput.current?.click()}
                    >
                        {logo ? 'Replace logo' : 'Upload logo'}
                    </Button>
                    {logo && (
                        <Button
                            variant="ghost"
                            data-test="remove-logo-button"
                            onClick={() =>
                                router.delete(
                                    BrandingController.destroyLogo.url(),
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Remove
                        </Button>
                    )}
                </div>
                <InputError message={errors.logo} />
            </div>
        </>
    );
}
