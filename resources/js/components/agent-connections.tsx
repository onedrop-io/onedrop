import { Form, Link, router } from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
import { useEffect, useState } from 'react';
import ChatGptAuthController from '@/actions/App/Http/Controllers/ChatGptAuthController';
import AgentConnectionController from '@/actions/App/Http/Controllers/Settings/AgentConnectionController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useClipboard } from '@/hooks/use-clipboard';
import { jsonRequest } from '@/lib/json-request';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { redirect as openRouterRedirect } from '@/routes/openrouter';
import type { AgentConnection, AgentProvider } from '@/types';

/** A way to connect: paste a credential, sign in on the provider's site, sign in with ChatGPT (a one-time code), or point at your own Ollama server. */
type Method =
    | {
          kind: 'paste';
          label: string;
          placeholder: string;
          help: React.ReactNode;
      }
    | { kind: 'signin'; label: string; href: string }
    | { kind: 'chatgpt'; label: string; help: React.ReactNode }
    | { kind: 'claude-login'; label: string; help: React.ReactNode }
    | { kind: 'ollama-server'; label: string; help: React.ReactNode };

export type ProviderInfo = {
    id: AgentProvider;
    name: string;
    /** A one-colour mark in /images/logos, drawn white on the provider's colour. */
    logo: string;
    tile: string;
    tagline: string;
    /** How you connect it, in a few words, under its icon in the onboarding picker. */
    hint: string;
    primary: Method;
    alternate?: Method & { switchLabel: string; backLabel: string };
};

export const providers: ProviderInfo[] = [
    {
        id: 'claude',
        name: 'Claude',
        logo: '/images/logos/claude.svg',
        tile: 'bg-[#D97757]',
        tagline: "Anthropic's models, used by the agent in your sandboxes.",
        hint: 'Claude Pro or Max plan',
        primary: {
            kind: 'claude-login',
            label: 'Use my Claude subscription',
            help: (
                <>
                    Builds on your Claude Pro or Max plan with the Claude Code
                    agent. You sign in to Claude from your project, in Claude
                    Code's own sign-in: OneDrop never sees your Claude login.
                </>
            ),
        },
        alternate: {
            kind: 'paste',
            switchLabel: 'Use an Anthropic API key instead',
            backLabel: 'Use my Claude subscription instead',
            label: 'Anthropic API key',
            placeholder: 'sk-ant-api…',
            help: (
                <>
                    Billed to your Anthropic Console account. Create one at{' '}
                    <ExternalLink href="https://console.anthropic.com/settings/keys">
                        console.anthropic.com
                    </ExternalLink>
                    .
                </>
            ),
        },
    },
    {
        id: 'codex',
        name: 'Codex',
        logo: '/images/logos/openai.svg',
        tile: 'bg-neutral-900 dark:bg-neutral-700',
        tagline: "OpenAI's models, used by the agent in your sandboxes.",
        hint: 'ChatGPT Plus or Pro plan',
        primary: {
            kind: 'chatgpt',
            label: 'Sign in with ChatGPT',
            help: 'Uses your ChatGPT Plus or Pro plan instead of API billing.',
        },
        alternate: {
            kind: 'paste',
            switchLabel: 'Paste an OpenAI API key instead',
            backLabel: 'Sign in with ChatGPT instead',
            label: 'OpenAI API key',
            placeholder: 'sk-…',
            help: (
                <>
                    Billed to your OpenAI Platform account. Create one at{' '}
                    <ExternalLink href="https://platform.openai.com/api-keys">
                        platform.openai.com
                    </ExternalLink>
                    .
                </>
            ),
        },
    },
    {
        id: 'openrouter',
        name: 'OpenRouter',
        logo: '/images/logos/openrouter.svg',
        tile: 'bg-[#6467F2]',
        tagline: 'One account for hundreds of models.',
        hint: 'Hundreds of models',
        primary: {
            kind: 'signin',
            label: 'Sign in with OpenRouter',
            href: openRouterRedirect().url,
        },
        alternate: {
            kind: 'paste',
            switchLabel: 'Paste an API key instead',
            backLabel: 'Sign in with OpenRouter instead',
            label: 'OpenRouter API key',
            placeholder: 'sk-or-…',
            help: (
                <>
                    Create one at{' '}
                    <ExternalLink href="https://openrouter.ai/settings/keys">
                        openrouter.ai
                    </ExternalLink>
                    .
                </>
            ),
        },
    },
    {
        id: 'gemini',
        name: 'Gemini',
        logo: '/images/logos/gemini.svg',
        tile: 'bg-linear-to-br from-[#4285F4] to-[#9B72CB]',
        tagline: "Google's models, used by the agent in your sandboxes.",
        hint: 'API key, free tier',
        primary: {
            kind: 'paste',
            label: 'Gemini API key',
            placeholder: 'AIza…',
            help: (
                <>
                    Billed to your Google AI Studio account, which has a free
                    tier. Create one at{' '}
                    <ExternalLink href="https://aistudio.google.com/app/apikey">
                        aistudio.google.com
                    </ExternalLink>
                    . Google doesn't let other apps use a Gemini subscription.
                </>
            ),
        },
    },
    {
        id: 'ollama',
        name: 'Ollama',
        logo: '/images/logos/ollama.svg',
        tile: 'bg-neutral-900 dark:bg-neutral-700',
        tagline: 'Open models on Ollama Cloud or your own server.',
        hint: 'Cloud key or your server',
        primary: {
            kind: 'paste',
            label: 'Ollama API key',
            placeholder: 'Your ollama.com key',
            help: (
                <>
                    Runs on Ollama Cloud, billed to your Ollama account (which
                    has a free plan). Create one at{' '}
                    <ExternalLink href="https://ollama.com/settings/keys">
                        ollama.com
                    </ExternalLink>
                    .
                </>
            ),
        },
        alternate: {
            kind: 'ollama-server',
            switchLabel: 'Use my own Ollama server instead',
            backLabel: 'Use an Ollama Cloud key instead',
            label: 'Ollama server URL',
            help: (
                <>
                    Your sandboxes run on a server, so it needs a public address
                    (for example behind a reverse proxy, with a key). The agent
                    can use any model you've pulled on it.
                </>
            ),
        },
    },
];

const credentialLabels: Record<AgentConnection['credential_type'], string> = {
    api_key: 'API key',
    claude_login: 'Claude subscription',
    chatgpt: 'ChatGPT sign-in',
    ollama_server: 'Your server',
};

export default function AgentConnections({
    connections,
    onboarding = false,
}: {
    connections: AgentConnection[];
    /** Send the user on to the new-project prompt after connecting. */
    onboarding?: boolean;
}) {
    return (
        <div className="grid gap-4">
            {providers.map((provider) => (
                <ProviderCard
                    key={provider.id}
                    provider={provider}
                    connection={connections.find(
                        (connection) => connection.provider === provider.id,
                    )}
                    showDefaultToggle={connections.length > 1}
                    onboarding={onboarding}
                />
            ))}
        </div>
    );
}

export function ProviderCard({
    provider,
    connection,
    showDefaultToggle,
    onboarding,
}: {
    provider: ProviderInfo;
    connection?: AgentConnection;
    showDefaultToggle: boolean;
    onboarding: boolean;
}) {
    const [replacing, setReplacing] = useState(false);
    const [useAlternate, setUseAlternate] = useState(false);
    /** The form was revealed by a click (not on page load), so focus it. */
    const [focusForm, setFocusForm] = useState(false);
    const method =
        useAlternate && provider.alternate
            ? provider.alternate
            : provider.primary;

    return (
        <section
            className="space-y-4 rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border"
            data-test={`provider-${provider.id}`}
        >
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex items-center gap-3">
                    <ProviderLogo provider={provider} />
                    <div>
                        <h3 className="font-medium">{provider.name}</h3>
                        <p className="text-sm text-muted-foreground">
                            {provider.tagline}
                        </p>
                    </div>
                </div>
                {connection && (
                    <div className="flex items-center gap-2">
                        {connection.is_default && <Badge>default</Badge>}
                        <Badge variant="secondary">
                            {credentialLabels[connection.credential_type]}
                            {connection.hint &&
                                (connection.credential_type === 'ollama_server'
                                    ? ` ${connection.hint}`
                                    : ` ••••${connection.hint}`)}
                        </Badge>
                        {!connection.verified &&
                            connection.credential_type !== 'claude_login' && (
                                <Badge variant="outline">not verified</Badge>
                            )}
                    </div>
                )}
            </header>

            {connection && !replacing ? (
                <div className="flex flex-wrap items-center gap-4 text-sm">
                    <button
                        type="button"
                        onClick={() => {
                            setReplacing(true);
                            setFocusForm(true);
                        }}
                        className="text-muted-foreground underline-offset-4 hover:underline"
                    >
                        Reconnect
                    </button>
                    {showDefaultToggle && !connection.is_default && (
                        <Link
                            href={AgentConnectionController.update(
                                connection.id,
                            )}
                            as="button"
                            preserveScroll
                            className="text-muted-foreground underline-offset-4 hover:underline"
                        >
                            Make default
                        </Link>
                    )}
                    <Link
                        href={AgentConnectionController.destroy(connection.id)}
                        as="button"
                        preserveScroll
                        className="text-red-600 underline-offset-4 hover:underline"
                    >
                        Disconnect
                    </Link>
                </div>
            ) : (
                <div className="space-y-3">
                    {method.kind === 'signin' ? (
                        <Button asChild>
                            <a
                                href={method.href}
                                data-test={`signin-${provider.id}`}
                            >
                                {method.label}
                            </a>
                        </Button>
                    ) : method.kind === 'chatgpt' ? (
                        <ChatGptSignIn
                            method={method}
                            onboarding={onboarding}
                        />
                    ) : method.kind === 'ollama-server' ? (
                        <OllamaServerForm
                            method={method}
                            onboarding={onboarding}
                            autoFocus={focusForm}
                            onConnected={() => {
                                setReplacing(false);
                                setUseAlternate(false);
                            }}
                        />
                    ) : method.kind === 'claude-login' ? (
                        <ClaudeSubscription
                            method={method}
                            onboarding={onboarding}
                        />
                    ) : (
                        <PasteForm
                            key={method.label}
                            provider={provider.id}
                            method={method}
                            onboarding={onboarding}
                            autoFocus={focusForm}
                            onConnected={() => {
                                setReplacing(false);
                                setUseAlternate(false);
                            }}
                        />
                    )}

                    <div className="flex flex-wrap gap-4 text-sm">
                        {provider.alternate && (
                            <button
                                type="button"
                                onClick={() => {
                                    setUseAlternate(!useAlternate);
                                    setFocusForm(true);
                                }}
                                className="text-muted-foreground underline-offset-4 hover:underline"
                                data-test={`switch-method-${provider.id}`}
                            >
                                {useAlternate
                                    ? provider.alternate.backLabel
                                    : provider.alternate.switchLabel}
                            </button>
                        )}
                        {replacing && (
                            <button
                                type="button"
                                onClick={() => setReplacing(false)}
                                className="text-muted-foreground underline-offset-4 hover:underline"
                            >
                                Cancel
                            </button>
                        )}
                    </div>
                </div>
            )}
        </section>
    );
}

function PasteForm({
    provider,
    method,
    onboarding,
    autoFocus,
    onConnected,
}: {
    provider: AgentProvider;
    method: Extract<Method, { kind: 'paste' }>;
    onboarding: boolean;
    autoFocus: boolean;
    onConnected: () => void;
}) {
    return (
        <Form
            {...AgentConnectionController.store.form()}
            options={{ preserveScroll: true }}
            onSuccess={onConnected}
            resetOnSuccess
            className="grid gap-2"
        >
            {({ processing, errors }) => (
                <>
                    <input type="hidden" name="provider" value={provider} />
                    {onboarding && (
                        <input type="hidden" name="onboarding" value="1" />
                    )}
                    <Label htmlFor={`credential-${provider}`}>
                        {method.label}
                    </Label>
                    <div className="flex flex-wrap gap-2">
                        <Input
                            id={`credential-${provider}`}
                            name="credential"
                            type="password"
                            autoComplete="off"
                            required
                            placeholder={method.placeholder}
                            className="min-w-64 flex-1"
                            autoFocus={autoFocus}
                        />
                        <Button
                            disabled={processing}
                            data-test={`connect-${provider}`}
                        >
                            {processing ? 'Checking…' : 'Connect'}
                        </Button>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {method.help}
                    </p>
                    <InputError message={errors.credential} />
                </>
            )}
        </Form>
    );
}

/**
 * Point the agent at the user's own Ollama server: its URL, and a key if it needs one (AI-006).
 */
function OllamaServerForm({
    method,
    onboarding,
    autoFocus,
    onConnected,
}: {
    method: Extract<Method, { kind: 'ollama-server' }>;
    onboarding: boolean;
    autoFocus: boolean;
    onConnected: () => void;
}) {
    return (
        <Form
            {...AgentConnectionController.ollamaServer.form()}
            options={{ preserveScroll: true }}
            onSuccess={onConnected}
            resetOnSuccess
            className="grid gap-3"
        >
            {({ processing, errors }) => (
                <>
                    {onboarding && (
                        <input type="hidden" name="onboarding" value="1" />
                    )}
                    <div className="grid gap-2">
                        <Label htmlFor="ollama-url">{method.label}</Label>
                        <Input
                            id="ollama-url"
                            name="url"
                            type="url"
                            required
                            placeholder="https://ollama.example.com"
                            autoComplete="off"
                            autoFocus={autoFocus}
                        />
                        <InputError message={errors.url} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="ollama-key">
                            Key{' '}
                            <span className="font-normal text-muted-foreground">
                                (if your server needs one)
                            </span>
                        </Label>
                        <div className="flex flex-wrap gap-2">
                            <Input
                                id="ollama-key"
                                name="credential"
                                type="password"
                                autoComplete="off"
                                placeholder="Sent as a Bearer token"
                                className="min-w-64 flex-1"
                            />
                            <Button
                                disabled={processing}
                                data-test="connect-ollama-server"
                            >
                                {processing ? 'Checking…' : 'Connect'}
                            </Button>
                        </div>
                        <InputError message={errors.credential} />
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {method.help}
                    </p>
                </>
            )}
        </Form>
    );
}

/**
 * Use the Claude subscription: nothing to paste. Claude Code signs in inside the sandbox (AI-005).
 */
function ClaudeSubscription({
    method,
    onboarding,
}: {
    method: Extract<Method, { kind: 'claude-login' }>;
    onboarding: boolean;
}) {
    const [processing, setProcessing] = useState(false);

    return (
        <div className="grid gap-2">
            <div>
                <Button
                    type="button"
                    disabled={processing}
                    onClick={() =>
                        router.post(
                            AgentConnectionController.claudeLogin.url(),
                            onboarding ? { onboarding: 1 } : {},
                            {
                                preserveScroll: true,
                                onStart: () => setProcessing(true),
                                onFinish: () => setProcessing(false),
                            },
                        )
                    }
                    data-test="use-claude-subscription"
                >
                    {method.label}
                </Button>
            </div>
            <p className="text-sm text-muted-foreground">{method.help}</p>
        </div>
    );
}

type DeviceCode = {
    user_code: string;
    verification_url: string;
    interval: number;
};

/**
 * Sign in with ChatGPT: show OpenAI's one-time code and poll until the user approves it.
 */
function ChatGptSignIn({
    method,
    onboarding,
}: {
    method: Extract<Method, { kind: 'chatgpt' }>;
    onboarding: boolean;
}) {
    const [device, setDevice] = useState<DeviceCode | null>(null);
    const [starting, setStarting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const start = async () => {
        setStarting(true);
        setError(null);

        try {
            setDevice(
                await jsonRequest<DeviceCode>(
                    ChatGptAuthController.store().url,
                    {},
                ),
            );
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setStarting(false);
        }
    };

    useEffect(() => {
        if (!device) {
            return;
        }

        let timer: ReturnType<typeof setTimeout>;
        let cancelled = false;

        const poll = async () => {
            try {
                const result = await jsonRequest<{
                    status: 'pending' | 'connected';
                }>(ChatGptAuthController.poll().url, {});

                if (cancelled) {
                    return;
                }

                if (result.status === 'connected') {
                    if (onboarding) {
                        router.visit(dashboard());
                    } else {
                        router.reload();
                    }

                    return;
                }

                timer = setTimeout(poll, device.interval * 1000);
            } catch (e) {
                if (!cancelled) {
                    setDevice(null);
                    setError((e as Error).message);
                }
            }
        };

        timer = setTimeout(poll, device.interval * 1000);

        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
    }, [device, onboarding]);

    if (!device) {
        return (
            <div className="grid gap-2">
                <div>
                    <Button
                        type="button"
                        onClick={() => void start()}
                        disabled={starting}
                        data-test="signin-codex"
                    >
                        {starting ? 'Starting…' : method.label}
                    </Button>
                </div>
                <p className="text-sm text-muted-foreground">{method.help}</p>
                <InputError message={error ?? undefined} />
            </div>
        );
    }

    return (
        <div className="grid gap-3" data-test="chatgpt-device-code">
            <ol className="list-decimal space-y-2 pl-5 text-sm">
                <li>
                    Open{' '}
                    <ExternalLink href={device.verification_url}>
                        {device.verification_url.replace(/^https:\/\//, '')}
                    </ExternalLink>{' '}
                    and sign in to ChatGPT.
                </li>
                <li>
                    Enter this code:{' '}
                    <CopyCommand
                        command={device.user_code}
                        test="copy-chatgpt-code"
                    />
                </li>
            </ol>
            <div className="flex flex-wrap items-center gap-4 text-sm">
                <span className="text-muted-foreground">
                    Waiting for you to approve… The code expires in 15 minutes.
                </span>
                <button
                    type="button"
                    onClick={() => void start()}
                    disabled={starting}
                    className="text-muted-foreground underline-offset-4 hover:underline"
                    data-test="chatgpt-new-code"
                >
                    Get a new code
                </button>
                <button
                    type="button"
                    onClick={() => setDevice(null)}
                    className="text-muted-foreground underline-offset-4 hover:underline"
                >
                    Cancel
                </button>
            </div>
            <p className="text-sm text-muted-foreground">
                If OpenAI asks you to enable device code sign-in, turn it on in{' '}
                <ExternalLink href="https://chatgpt.com/#settings/Security">
                    ChatGPT → Settings → Security
                </ExternalLink>
                , then get a new code here. On a ChatGPT Business or Enterprise
                workspace, an admin may need to allow it first.
            </p>
        </div>
    );
}

export function ProviderLogo({
    provider,
    className,
}: {
    provider: ProviderInfo;
    className?: string;
}) {
    const mask = `url(${provider.logo}) center / contain no-repeat`;

    return (
        <span
            className={cn(
                'flex size-10 shrink-0 items-center justify-center rounded-lg shadow-sm',
                provider.tile,
                className,
            )}
            aria-hidden
        >
            <span
                className="size-1/2 bg-white"
                style={{ mask, WebkitMask: mask }}
            />
        </span>
    );
}

function CopyCommand({
    command,
    test = 'copy-command',
}: {
    command: string;
    test?: string;
}) {
    const [copiedText, copy] = useClipboard();
    const copied = copiedText === command;

    return (
        <button
            type="button"
            onClick={() => void copy(command)}
            className="inline-flex items-center gap-1 rounded bg-muted px-1.5 py-0.5 font-mono text-xs text-foreground hover:bg-muted/70"
            title="Copy"
            data-test={test}
        >
            {command}
            {copied ? (
                <Check className="size-3" />
            ) : (
                <Copy className="size-3" />
            )}
        </button>
    );
}

function ExternalLink({
    href,
    children,
}: {
    href: string;
    children: React.ReactNode;
}) {
    return (
        <a
            href={href}
            target="_blank"
            rel="noreferrer"
            className="underline underline-offset-4"
        >
            {children}
        </a>
    );
}
