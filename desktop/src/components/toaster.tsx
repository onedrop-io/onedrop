import type { CSSProperties } from 'react';
import { Toaster as Sonner } from 'sonner';
import { useAppearance } from '@/hooks/use-appearance';

/** The web app's toasts, without its Inertia flash messages. */
export default function Toaster() {
    const { appearance } = useAppearance();

    return (
        <Sonner
            theme={appearance}
            className="toaster group"
            position="bottom-right"
            style={
                {
                    '--normal-bg': 'var(--popover)',
                    '--normal-text': 'var(--popover-foreground)',
                    '--normal-border': 'var(--border)',
                } as CSSProperties
            }
        />
    );
}
