import { Form, Head } from '@inertiajs/react';
import { Monitor } from 'lucide-react';
import { store } from '@/actions/App/Http/Controllers/DesktopSignInController';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

/** The desktop app opened this page to sign in (DESK-001); allowing it sends a one-time code back to the app. */
export default function DesktopAuthorize({
    device,
    email,
    request,
}: {
    device: string;
    email: string;
    /** The app's sign-in request, sent back as it came. */
    request: Record<string, string>;
}) {
    // Back to the app without a code (the address was checked before this page showed).
    const cancelUrl = `${request.redirect_uri}?${new URLSearchParams({ error: 'access_denied', state: request.state })}`;

    return (
        <>
            <Head title="Sign in to the desktop app" />

            <div className="space-y-6">
                <div className="flex items-center gap-3 rounded-lg border p-4">
                    <Monitor className="size-5 shrink-0 text-muted-foreground" />
                    <div className="min-w-0 text-sm">
                        <p
                            className="truncate font-medium"
                            data-test="desktop-device"
                        >
                            {device}
                        </p>
                        <p className="text-muted-foreground">
                            Signs in as {email}
                        </p>
                    </div>
                </div>

                <p className="text-sm text-muted-foreground">
                    Only allow this if you just started signing in from the
                    OneDrop desktop app. It will be able to see and work on your
                    projects. You can sign it out any time in Settings → Desktop
                    app.
                </p>

                <Form {...store.form()}>
                    {({ processing }) => (
                        <div className="flex flex-col gap-2">
                            {Object.entries(request).map(([name, value]) => (
                                <input
                                    key={name}
                                    type="hidden"
                                    name={name}
                                    value={value}
                                />
                            ))}
                            <input type="hidden" name="allow" value="1" />
                            <Button
                                disabled={processing}
                                data-test="desktop-allow"
                            >
                                {processing && <Spinner />}
                                Allow
                            </Button>
                            <Button variant="ghost" asChild>
                                <a href={cancelUrl} data-test="desktop-cancel">
                                    Cancel
                                </a>
                            </Button>
                        </div>
                    )}
                </Form>
            </div>
        </>
    );
}

DesktopAuthorize.layout = {
    title: 'Sign in to the desktop app',
    description: 'The OneDrop desktop app wants to sign in to your account.',
};
