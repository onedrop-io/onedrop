import { KeyRound } from 'lucide-react';

type Props = {
    provider: string;
    className?: string;
};

/**
 * Brand mark for a login provider; generic key for OIDC single sign-on.
 */
export default function SocialProviderIcon({ provider, className }: Props) {
    switch (provider) {
        case 'google':
            return (
                <svg viewBox="0 0 24 24" className={className} aria-hidden>
                    <path
                        fill="#4285F4"
                        d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.27-4.74 3.27-8.1z"
                    />
                    <path
                        fill="#34A853"
                        d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23z"
                    />
                    <path
                        fill="#FBBC05"
                        d="M5.84 14.1A6.6 6.6 0 0 1 5.5 12c0-.73.13-1.44.34-2.1V7.06H2.18A11 11 0 0 0 1 12c0 1.78.43 3.45 1.18 4.94l3.66-2.84z"
                    />
                    <path
                        fill="#EA4335"
                        d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15A10.96 10.96 0 0 0 12 1 11 11 0 0 0 2.18 7.06l3.66 2.84C6.71 7.3 9.14 5.38 12 5.38z"
                    />
                </svg>
            );
        case 'microsoft':
            return (
                <svg viewBox="0 0 24 24" className={className} aria-hidden>
                    <path fill="#F25022" d="M1 1h10.5v10.5H1z" />
                    <path fill="#7FBA00" d="M12.5 1H23v10.5H12.5z" />
                    <path fill="#00A4EF" d="M1 12.5h10.5V23H1z" />
                    <path fill="#FFB900" d="M12.5 12.5H23V23H12.5z" />
                </svg>
            );
        case 'github':
            return (
                <svg
                    viewBox="0 0 24 24"
                    className={className}
                    fill="currentColor"
                    aria-hidden
                >
                    <path d="M12 .5a11.5 11.5 0 0 0-3.64 22.41c.58.1.79-.25.79-.56v-2c-3.2.7-3.87-1.37-3.87-1.37-.53-1.33-1.29-1.69-1.29-1.69-1.05-.72.08-.7.08-.7 1.16.08 1.77 1.19 1.77 1.19 1.03 1.77 2.7 1.26 3.36.96.1-.75.4-1.26.73-1.55-2.55-.29-5.24-1.28-5.24-5.69 0-1.26.45-2.28 1.19-3.09-.12-.29-.52-1.46.11-3.05 0 0 .97-.31 3.17 1.18a11 11 0 0 1 5.77 0c2.2-1.49 3.17-1.18 3.17-1.18.63 1.59.23 2.76.11 3.05.74.81 1.19 1.83 1.19 3.09 0 4.42-2.7 5.39-5.26 5.68.41.36.78 1.06.78 2.13v3.16c0 .31.21.67.8.56A11.5 11.5 0 0 0 12 .5z" />
                </svg>
            );
        case 'gitlab':
            return (
                <svg viewBox="0 0 24 24" className={className} aria-hidden>
                    <path
                        fill="#E24329"
                        d="m23.6 9.6-.03-.09-3.26-8.5a.85.85 0 0 0-1.62.06l-2.2 6.74H7.5L5.3 1.07a.85.85 0 0 0-1.61-.06L.43 9.51l-.03.09a6.05 6.05 0 0 0 2 7l.02.01 4.97 3.72 2.46 1.86 1.5 1.13a1 1 0 0 0 1.22 0l1.5-1.13 2.46-1.86 5-3.74a6.06 6.06 0 0 0 2.07-6.99z"
                    />
                </svg>
            );
        default:
            return <KeyRound className={className} aria-hidden />;
    }
}
