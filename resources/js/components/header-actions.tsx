import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { HEADER_ACTIONS_ID } from '@/components/app-sidebar-header';

/**
 * Renders page-specific buttons on the right of the app header.
 */
export default function HeaderActions({
    children,
}: {
    children: React.ReactNode;
}) {
    const [target, setTarget] = useState<HTMLElement | null>(null);

    useEffect(() => {
        setTarget(document.getElementById(HEADER_ACTIONS_ID));
    }, []);

    return target ? createPortal(children, target) : null;
}
