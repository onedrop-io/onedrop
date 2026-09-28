import { usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import SocialProviderIcon from '@/components/social-provider-icon';
import { Button } from '@/components/ui/button';
import { redirect } from '@/routes/social';
import type { SocialProviderOption } from '@/types/auth';

type Props = {
    providers: SocialProviderOption[];
};

/**
 * "Continue with …" buttons for each login provider the server has set up.
 * These are full-page links: the provider's sign-in page isn't an Inertia page.
 */
export default function SocialLoginButtons({ providers }: Props) {
    const { errors } = usePage().props;

    if (providers.length === 0) {
        return null;
    }

    return (
        <div className="mb-6 grid gap-4">
            <div className="grid gap-2">
                {providers.map((provider) => (
                    <Button
                        key={provider.id}
                        variant="outline"
                        className="w-full"
                        asChild
                    >
                        <a
                            href={redirect.url(provider.id)}
                            data-test={`social-login-${provider.id}`}
                        >
                            <SocialProviderIcon
                                provider={provider.id}
                                className="size-4"
                            />
                            Continue with {provider.label}
                        </a>
                    </Button>
                ))}
            </div>

            <InputError message={errors.social} className="text-center" />

            <div className="flex items-center gap-3 text-xs text-muted-foreground uppercase">
                <span className="h-px flex-1 bg-border" />
                or
                <span className="h-px flex-1 bg-border" />
            </div>
        </div>
    );
}
