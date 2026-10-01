import { Link, usePage } from '@inertiajs/react';
import { UserRoundCog } from 'lucide-react';
import ImpersonationController from '@/actions/App/Http/Controllers/ImpersonationController';

/** Shown on every page while an admin is signed in as someone else (USR-003). */
export default function ImpersonationBanner() {
    const { auth, impersonator } = usePage().props;

    if (!impersonator || !auth?.user) {
        return null;
    }

    return (
        <div
            role="status"
            className="pointer-events-auto fixed bottom-4 left-1/2 z-[100] flex -translate-x-1/2 items-center gap-3 rounded-full bg-amber-500 px-4 py-2 text-sm text-amber-950 shadow-lg"
            data-test="impersonation-banner"
        >
            <UserRoundCog className="size-4" />
            <span>
                You ({impersonator.name}) are signed in as{' '}
                <strong>{auth.user.name}</strong>.
            </span>
            <Link
                href={ImpersonationController.destroy()}
                as="button"
                className="font-medium underline underline-offset-4"
                data-test="stop-impersonating"
            >
                Stop impersonating
            </Link>
        </div>
    );
}
