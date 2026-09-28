import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import SocialProviderIcon from '@/components/social-provider-icon';
import { Button } from '@/components/ui/button';
import { destroy } from '@/routes/social-accounts';
import { redirect } from '@/routes/social';
import type { SocialAccountRow } from '@/types/auth';

type Props = {
    socialAccounts: SocialAccountRow[];
    loginMethodCount: number;
};

export default function ManageSocialAccounts({
    socialAccounts,
    loginMethodCount,
}: Props) {
    const { errors } = usePage().props;
    const [disconnecting, setDisconnecting] = useState<number | null>(null);

    if (socialAccounts.length === 0) {
        return null;
    }

    const disconnect = (id: number) => {
        setDisconnecting(id);
        router.delete(destroy.url(id), {
            preserveScroll: true,
            onFinish: () => setDisconnecting(null),
        });
    };

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title="Connected accounts"
                description="Log in with an account you already have"
            />

            <div className="overflow-hidden rounded-lg border border-border">
                {socialAccounts.map(({ provider, label, account }) => (
                    <div
                        key={provider}
                        className="flex items-center justify-between border-b p-4 last:border-b-0"
                        data-test={`social-account-${provider}`}
                    >
                        <div className="flex items-center gap-4">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-muted">
                                <SocialProviderIcon
                                    provider={provider}
                                    className="h-5 w-5"
                                />
                            </div>
                            <div className="space-y-1">
                                <p className="font-medium tracking-tight">
                                    {label}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {account
                                        ? (account.email ?? 'Connected')
                                        : 'Not connected'}
                                </p>
                            </div>
                        </div>

                        {account ? (
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={
                                    loginMethodCount <= 1 ||
                                    disconnecting === account.id
                                }
                                title={
                                    loginMethodCount <= 1
                                        ? 'Set a password or connect another account first'
                                        : undefined
                                }
                                onClick={() => disconnect(account.id)}
                            >
                                Disconnect
                            </Button>
                        ) : (
                            <Button variant="outline" size="sm" asChild>
                                <a href={redirect.url(provider)}>Connect</a>
                            </Button>
                        )}
                    </div>
                ))}
            </div>

            <InputError message={errors.social} />
        </div>
    );
}
