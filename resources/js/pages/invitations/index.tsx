import { Form, Head, Link } from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
import InvitationController from '@/actions/App/Http/Controllers/InvitationController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useClipboard } from '@/hooks/use-clipboard';
import { index } from '@/routes/invitations';

type InvitationRow = {
    id: number;
    email: string | null;
    status: 'waiting' | 'accepted' | 'expired' | 'revoked';
    url: string | null;
    invited_by: string | null;
    accepted_by: string | null;
    created_at: string | null;
    expires_at: string;
};

const STATUS_LABEL: Record<InvitationRow['status'], string> = {
    waiting: 'Waiting',
    accepted: 'Accepted',
    expired: 'Expired',
    revoked: 'Revoked',
};

function formatDate(iso: string | null): string {
    return iso
        ? new Date(iso).toLocaleDateString(undefined, {
              month: 'short',
              day: 'numeric',
          })
        : '';
}

export default function Invitations({
    invitations,
}: {
    invitations: InvitationRow[];
}) {
    const [copiedText, copy] = useClipboard();

    return (
        <>
            <Head title="Invite people" />

            <div className="flex max-w-3xl flex-1 flex-col gap-8">
                <Heading
                    title="Invite people"
                    description="Create a link, copy it, and send it to someone. It works once and expires in 7 days."
                />

                <Form
                    {...InvitationController.store.form()}
                    resetOnSuccess
                    className="flex flex-wrap items-end gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid min-w-64 flex-1 gap-2">
                                <Label htmlFor="email">
                                    Their email (optional)
                                </Label>
                                <Input
                                    id="email"
                                    name="email"
                                    type="email"
                                    placeholder="person@example.com"
                                />
                                <InputError message={errors.email} />
                            </div>
                            <Button
                                disabled={processing}
                                data-test="create-invite"
                            >
                                Create invite link
                            </Button>
                        </>
                    )}
                </Form>

                {invitations.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No invites yet.
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        {invitations.map((invitation) => (
                            <li
                                key={invitation.id}
                                className="flex flex-wrap items-center justify-between gap-3 p-4"
                                data-test={`invitation-${invitation.id}`}
                            >
                                <div className="min-w-0">
                                    <p className="font-medium">
                                        {invitation.email ??
                                            'Anyone with the link'}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {invitation.status === 'accepted'
                                            ? `Joined as ${invitation.accepted_by ?? 'a deleted user'}`
                                            : invitation.status === 'waiting'
                                              ? `Expires ${formatDate(invitation.expires_at)}`
                                              : `Created ${formatDate(invitation.created_at)}`}
                                        {invitation.invited_by &&
                                            ` · invited by ${invitation.invited_by}`}
                                    </p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Badge
                                        variant={
                                            invitation.status === 'waiting'
                                                ? 'default'
                                                : 'secondary'
                                        }
                                    >
                                        {STATUS_LABEL[invitation.status]}
                                    </Badge>
                                    {invitation.url && (
                                        <>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    void copy(invitation.url!)
                                                }
                                                data-test={`copy-invite-${invitation.id}`}
                                            >
                                                {copiedText ===
                                                invitation.url ? (
                                                    <>
                                                        <Check className="size-4" />
                                                        Copied
                                                    </>
                                                ) : (
                                                    <>
                                                        <Copy className="size-4" />
                                                        Copy link
                                                    </>
                                                )}
                                            </Button>
                                            <Link
                                                href={InvitationController.destroy(
                                                    invitation.id,
                                                )}
                                                as="button"
                                                preserveScroll
                                                className="text-sm text-red-600 underline-offset-4 hover:underline"
                                            >
                                                Revoke
                                            </Link>
                                        </>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

Invitations.layout = {
    breadcrumbs: [{ title: 'Invite people', href: index() }],
};
