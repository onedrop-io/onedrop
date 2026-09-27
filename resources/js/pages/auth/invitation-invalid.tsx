import { Head } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { login, register } from '@/routes';

export default function InvitationInvalid({ reason }: { reason: string }) {
    return (
        <>
            <Head title="Invite unavailable" />

            <div
                className="space-y-4 text-center"
                data-test="invitation-invalid"
            >
                <p>{reason}</p>
                <p className="text-sm text-muted-foreground">
                    You can still <TextLink href={register()}>sign up</TextLink>{' '}
                    or <TextLink href={login()}>log in</TextLink>.
                </p>
            </div>
        </>
    );
}

InvitationInvalid.layout = {
    title: 'Invite unavailable',
    description: '',
};
