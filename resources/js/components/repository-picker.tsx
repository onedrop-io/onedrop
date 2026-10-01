import { Lock, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import GitHubAppController from '@/actions/App/Http/Controllers/GitHubAppController';
import SocialProviderIcon from '@/components/social-provider-icon';
import { jsonRequest } from '@/lib/json-request';
import { cn } from '@/lib/utils';

export type ImportGitHub = {
    configured: boolean;
    signed_in: boolean;
    connect_url: string | null;
    /** Back from connecting GitHub on this page, with its error if it failed. */
    returned: { error: string | null } | null;
};

type Repository = {
    full_name: string;
    private: boolean;
    html_url: string;
    pushed_at: string | null;
};

/**
 * The toggle next to the agent picker that starts the project from a repository instead of a description (PRJ-009).
 */
export function RepositoryToggle({
    pressed,
    onPressedChange,
}: {
    pressed: boolean;
    onPressedChange: (pressed: boolean) => void;
}) {
    return (
        <button
            type="button"
            aria-pressed={pressed}
            onClick={() => onPressedChange(!pressed)}
            title="Start from a GitHub repository"
            data-test="repository-toggle"
            className={cn(
                'inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs text-foreground hover:bg-muted',
                pressed && 'bg-muted',
            )}
        >
            <SocialProviderIcon provider="github" className="size-3.5" />
            Repository
        </button>
    );
}

/**
 * Where the repository to import is typed or picked: any "owner/name" or HTTPS git URL, with the user's own
 * GitHub repositories suggested when they've connected GitHub.
 */
export default function RepositoryPicker({
    value,
    onChange,
    onClose,
    github,
}: {
    value: string;
    onChange: (value: string) => void;
    onClose: () => void;
    github: ImportGitHub;
}) {
    const [repositories, setRepositories] = useState<Repository[] | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [open, setOpen] = useState(false);

    useEffect(() => {
        if (!github.signed_in) {
            return;
        }

        let cancelled = false;

        jsonRequest<{ repositories: Repository[] }>(
            GitHubAppController.importable.url(),
        )
            .then(
                ({ repositories }) =>
                    !cancelled && setRepositories(repositories),
            )
            .catch((error: Error) => !cancelled && setLoadError(error.message));

        return () => {
            cancelled = true;
        };
    }, [github.signed_in]);

    const matches = useMemo(() => {
        const query = value.trim().toLowerCase();

        return (repositories ?? [])
            .filter((repository) =>
                repository.full_name.toLowerCase().includes(query),
            )
            .slice(0, 8);
    }, [repositories, value]);

    const error = github.returned?.error ?? loadError;

    return (
        <div className="border-b border-input px-4 pt-3 pb-2">
            <div className="relative flex items-center gap-2">
                <SocialProviderIcon
                    provider="github"
                    className="size-4 shrink-0 text-muted-foreground"
                />
                <label htmlFor="composer-repository" className="sr-only">
                    Repository
                </label>
                <input
                    id="composer-repository"
                    value={value}
                    onChange={(event) => {
                        onChange(event.target.value);
                        setOpen(true);
                    }}
                    onFocus={() => setOpen(true)}
                    onBlur={() => setOpen(false)}
                    onKeyDown={(event) => {
                        if (event.key === 'Escape') {
                            setOpen(false);
                        } else if (event.key === 'Enter') {
                            // Enter here picks the repository; it moves on to the prompt instead of sending.
                            event.preventDefault();
                            setOpen(false);
                            document.getElementById('composer-prompt')?.focus();
                        }
                    }}
                    placeholder="github.com/owner/repository"
                    autoFocus
                    autoComplete="off"
                    spellCheck={false}
                    data-test="repository-input"
                    className="min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                />
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Don't import a repository"
                    className="flex size-6 shrink-0 items-center justify-center rounded-full text-muted-foreground hover:bg-muted hover:text-foreground"
                >
                    <X className="size-3.5" />
                </button>

                {open && matches.length > 0 && (
                    <ul
                        className="absolute top-full right-0 left-0 z-20 mt-2 max-h-72 overflow-y-auto rounded-xl border border-input bg-popover p-1 shadow-md"
                        data-test="repository-options"
                    >
                        {matches.map((repository) => (
                            <li key={repository.full_name}>
                                <button
                                    type="button"
                                    // Before the input's blur closes the list.
                                    onMouseDown={(event) => {
                                        event.preventDefault();
                                        onChange(repository.full_name);
                                        setOpen(false);
                                        document
                                            .getElementById('composer-prompt')
                                            ?.focus();
                                    }}
                                    className="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm hover:bg-muted"
                                >
                                    <span className="truncate">
                                        {repository.full_name}
                                    </span>
                                    {repository.private && (
                                        <Lock
                                            className="size-3 shrink-0 text-muted-foreground"
                                            aria-label="Private"
                                        />
                                    )}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <p className="mt-1.5 pl-6 text-xs text-muted-foreground">
                {github.configured && !github.signed_in ? (
                    <>
                        Public repositories work as they are.{' '}
                        <a
                            href={github.connect_url ?? undefined}
                            className="text-foreground underline underline-offset-2"
                            data-test="repository-connect-github"
                        >
                            Connect GitHub
                        </a>{' '}
                        to import your private ones.
                    </>
                ) : github.signed_in ? (
                    'Pick one of your repositories, or paste any public repository’s link.'
                ) : (
                    'Paste a public repository’s link.'
                )}
            </p>
            {error && (
                <p className="mt-1 pl-6 text-xs text-destructive">{error}</p>
            )}
        </div>
    );
}
