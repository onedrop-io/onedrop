import { Form, Link } from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import AgentConnectionController from '@/actions/App/Http/Controllers/Settings/AgentConnectionController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useClipboard } from '@/hooks/use-clipboard';
import { redirect as openRouterRedirect } from '@/routes/openrouter';
import type { AgentConnection, AgentProvider } from '@/types';

/** A way to connect: paste a credential, or sign in on the provider's site. */
type Method =
    | {
          kind: 'paste';
          label: string;
          placeholder: string;
          help: React.ReactNode;
      }
    | { kind: 'signin'; label: string; href: string };

type ProviderInfo = {
    id: AgentProvider;
    name: string;
    tagline: string;
    primary: Method;
    alternate?: Method & { switchLabel: string; backLabel: string };
};

const providers: ProviderInfo[] = [
    {
        id: 'claude',
        name: 'Claude',
        tagline: "Anthropic's models, used by the agent in your sandboxes.",
        primary: {
            kind: 'paste',
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
        alternate: {
            kind: 'paste',
            switchLabel: 'Use a Claude subscription token instead',
            backLabel: 'Use an API key instead',
            label: 'Claude Code token',
            placeholder: 'sk-ant-oat…',
            help: (
                <>
                    From <CopyCommand command="claude setup-token" />. Only
                    works with the Claude Code agent, which isn't available yet;
                    the current agent needs an API key or OpenRouter.
                </>
            ),
        },
    },
    {
        id: 'codex',
        name: 'Codex',
        tagline: "OpenAI's models, used by the agent in your sandboxes.",
        primary: {
            kind: 'paste',
            label: 'OpenAI API key',
            placeholder: 'sk-…',
            help: (
                <>
                    Create one at{' '}
                    <ExternalLink href="https://platform.openai.com/api-keys">
                        platform.openai.com
                    </ExternalLink>
                    . ChatGPT sign-in will come with sandboxes.
                </>
            ),
        },
    },
    {
        id: 'openrouter',
        name: 'OpenRouter',
        tagline: 'One account for hundreds of models.',
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
];

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

function ProviderCard({
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
                <div>
                    <h3 className="font-medium">{provider.name}</h3>
                    <p className="text-sm text-muted-foreground">
                        {provider.tagline}
                    </p>
                </div>
                {connection && (
                    <div className="flex items-center gap-2">
                        {connection.is_default && <Badge>default</Badge>}
                        <Badge variant="secondary">
                            {connection.credential_type === 'oauth_token'
                                ? 'subscription token'
                                : 'API key'}{' '}
                            ••••{connection.hint}
                        </Badge>
                        {!connection.verified && (
                            <Badge variant="outline">not verified</Badge>
                        )}
                    </div>
                )}
            </header>

            {connection && !replacing ? (
                <div className="flex flex-wrap items-center gap-4 text-sm">
                    <button
                        type="button"
                        onClick={() => setReplacing(true)}
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
                    ) : (
                        <PasteForm
                            provider={provider.id}
                            method={method}
                            onboarding={onboarding}
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
                                onClick={() => setUseAlternate(!useAlternate)}
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
    onConnected,
}: {
    provider: AgentProvider;
    method: Extract<Method, { kind: 'paste' }>;
    onboarding: boolean;
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

function CopyCommand({ command }: { command: string }) {
    const [copiedText, copy] = useClipboard();
    const copied = copiedText === command;

    return (
        <button
            type="button"
            onClick={() => void copy(command)}
            className="inline-flex items-center gap-1 rounded bg-muted px-1.5 py-0.5 font-mono text-xs text-foreground hover:bg-muted/70"
            title="Copy command"
            data-test="copy-setup-token"
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
