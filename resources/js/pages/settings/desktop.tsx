import { Head, router } from '@inertiajs/react';
import { Monitor } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { destroy, index } from '@/routes/desktop-devices';

type Device = {
    id: number;
    name: string;
    created_at: string | null;
    last_used_at: string | null;
};

function formatDate(value: string | null): string {
    return value
        ? new Date(value).toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : 'Never';
}

/** Where the desktop app is signed in (DESK-001), each with a way to sign it out. */
export default function DesktopSettings({ devices }: { devices: Device[] }) {
    const [signingOut, setSigningOut] = useState<number | null>(null);

    const signOut = (id: number) => {
        setSigningOut(id);
        router.delete(destroy.url(id), {
            preserveScroll: true,
            onFinish: () => setSigningOut(null),
        });
    };

    return (
        <>
            <Head title="Desktop app" />

            <h1 className="sr-only">Desktop app</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Desktop app"
                    description="The computers the OneDrop desktop app is signed in on. Sign one out if you no longer use it or it's lost."
                />

                {devices.length === 0 ? (
                    <p
                        className="rounded-lg border p-4 text-sm text-muted-foreground"
                        data-test="desktop-devices-empty"
                    >
                        The desktop app isn't signed in anywhere.
                    </p>
                ) : (
                    <div className="overflow-hidden rounded-lg border">
                        {devices.map((device) => (
                            <div
                                key={device.id}
                                className="flex items-center justify-between gap-4 border-b p-4 last:border-b-0"
                                data-test="desktop-device"
                            >
                                <div className="flex min-w-0 items-center gap-4">
                                    <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted">
                                        <Monitor className="size-5" />
                                    </div>
                                    <div className="min-w-0 space-y-1">
                                        <p className="truncate font-medium tracking-tight">
                                            {device.name}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            Last used{' '}
                                            {formatDate(device.last_used_at)}
                                            {' · '}
                                            Signed in{' '}
                                            {formatDate(device.created_at)}
                                        </p>
                                    </div>
                                </div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={signingOut === device.id}
                                    onClick={() => signOut(device.id)}
                                    data-test="desktop-device-sign-out"
                                >
                                    Sign out
                                </Button>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

DesktopSettings.layout = {
    breadcrumbs: [{ title: 'Desktop app', href: index() }],
};
