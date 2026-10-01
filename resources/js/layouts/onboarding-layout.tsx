import type { ReactNode } from 'react';
import ImpersonationBanner from '@/components/impersonation-banner';

/** Onboarding pages draw their own screen; this only adds the impersonation banner (USR-003). */
export default function OnboardingLayout({
    children,
}: {
    children: ReactNode;
}) {
    return (
        <>
            {children}
            <ImpersonationBanner />
        </>
    );
}
