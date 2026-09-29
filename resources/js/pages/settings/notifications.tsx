import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Switch } from '@/components/ui/switch';
import { useDesktopNotifications } from '@/hooks/use-desktop-notifications';
import { edit as editNotifications } from '@/routes/notifications';

export default function Notifications() {
    const { status, enable, disable } = useDesktopNotifications();

    return (
        <>
            <Head title="Notification settings" />

            <h1 className="sr-only">Notification settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Notifications"
                    description="Get a desktop notification when a project's agent finishes and it's ready for your review. This is set per browser."
                />

                <div className="flex items-start justify-between gap-4 rounded-lg border p-4">
                    <div className="space-y-1">
                        <p className="text-sm font-medium">
                            Desktop notifications
                        </p>
                        <p
                            className="text-sm text-muted-foreground"
                            data-test="notifications-status"
                        >
                            {status === 'unsupported' &&
                                "This browser can't show desktop notifications."}
                            {status === 'denied' &&
                                'Notifications are blocked for this site. Allow them in your browser’s site settings (the icon next to the address), then come back here.'}
                            {status === 'off' &&
                                'Off. Turning them on asks your browser for permission.'}
                            {status === 'on' &&
                                'On. You’ll be notified when a project is ready for review.'}
                        </p>
                    </div>
                    <Switch
                        checked={status === 'on'}
                        onChange={(checked) =>
                            checked ? void enable() : disable()
                        }
                        label="Desktop notifications"
                        testId="notifications-toggle"
                        disabled={
                            status === 'unsupported' || status === 'denied'
                        }
                    />
                </div>
            </div>
        </>
    );
}

Notifications.layout = {
    breadcrumbs: [
        {
            title: 'Notification settings',
            href: editNotifications(),
        },
    ],
};
