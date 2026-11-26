import { Form, Head, setLayoutProps } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { login } from '@/routes';
import { chosen } from '@/routes/social';

type Account = {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
    organizations: string[];
};

type Props = {
    provider: string;
    accounts: Account[];
};

/**
 * Which login to continue as, when the provider account someone signed in with is connected to several (AUTH-003).
 */
export default function ChooseAccount({ provider, accounts }: Props) {
    const getInitials = useInitials();

    setLayoutProps({
        title: 'Choose an account',
        description: `Your ${provider} account is connected to more than one login.`,
    });

    return (
        <>
            <Head title="Choose an account" />

            <div className="grid gap-2" data-test="choose-account">
                {accounts.map((account, index) => (
                    <Form key={account.id} {...chosen.form()}>
                        {({ processing }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="user"
                                    value={account.id}
                                />
                                <button
                                    type="submit"
                                    disabled={processing}
                                    autoFocus={index === 0}
                                    className="flex w-full items-center gap-3 rounded-lg border p-3 text-left transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50"
                                >
                                    <Avatar className="h-9 w-9 overflow-hidden rounded-full">
                                        <AvatarImage
                                            src={account.avatar ?? undefined}
                                            alt={account.name}
                                        />
                                        <AvatarFallback className="rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">
                                            {getInitials(account.name)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <div className="grid min-w-0 flex-1 text-sm leading-tight">
                                        <span className="truncate font-medium">
                                            {account.email}
                                        </span>
                                        <span className="truncate text-xs text-muted-foreground">
                                            {account.organizations.length > 0
                                                ? account.organizations.join(
                                                      ', ',
                                                  )
                                                : account.name}
                                        </span>
                                    </div>
                                </button>
                            </>
                        )}
                    </Form>
                ))}
            </div>

            <p className="text-center text-sm text-muted-foreground">
                Not you? <TextLink href={login()}>Back to log in</TextLink>
            </p>
        </>
    );
}
