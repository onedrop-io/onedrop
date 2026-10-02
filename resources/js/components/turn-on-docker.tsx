import { usePage } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { index as sandboxesIndex } from '@/routes/admin/sandboxes';

/**
 * Where Docker inside sandboxes is turned on (SBX-008): a link to Settings → Sandboxes for an admin, who can do it,
 * and who to ask for everyone else.
 */
export default function TurnOnDocker() {
    const { auth } = usePage().props;

    if (!auth.user?.is_admin) {
        return (
            <>
                An admin can turn on Docker inside sandboxes in Settings →
                Sandboxes.
            </>
        );
    }

    return (
        <>
            Turn on Docker inside sandboxes in{' '}
            <TextLink href={sandboxesIndex()} data-test="turn-on-docker-link">
                Settings → Sandboxes
            </TextLink>
            .
        </>
    );
}
