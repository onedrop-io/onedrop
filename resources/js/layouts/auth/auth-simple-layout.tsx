import { Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { PendingStartPanel } from '@/components/pending-start';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { component, props } = usePage();
    // Signing up or logging in to start what they picked on the home page (HOME-004): it's shown beside the form.
    const pending =
        component === 'auth/register' || component === 'auth/login'
            ? props.pendingStart
            : null;

    const form = (
        <div className="w-full max-w-sm">
            <div className="flex flex-col gap-8">
                <div className="flex flex-col items-center gap-4">
                    <Link
                        href={home()}
                        className="flex flex-col items-center gap-2 font-medium"
                    >
                        <div className="mb-1 flex h-9 w-9 items-center justify-center rounded-md">
                            <AppLogoIcon className="size-9 fill-current text-[var(--foreground)] dark:text-white" />
                        </div>
                        <span className="sr-only">{title}</span>
                    </Link>

                    <div className="space-y-2 text-center">
                        <h1 className="text-xl font-medium">{title}</h1>
                        <p className="text-center text-sm text-muted-foreground">
                            {description}
                        </p>
                    </div>
                </div>
                {children}
            </div>
        </div>
    );

    if (pending) {
        return (
            <div className="grid min-h-svh items-center gap-10 bg-background p-6 md:p-10 lg:grid-cols-2 lg:gap-16">
                <div className="flex justify-center lg:justify-end">
                    <PendingStartPanel
                        start={pending}
                        signingUp={component === 'auth/register'}
                    />
                </div>
                <div className="flex justify-center lg:justify-start">
                    {form}
                </div>
            </div>
        );
    }

    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            {form}
        </div>
    );
}
