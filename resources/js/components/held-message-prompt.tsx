import { KeyRound, Scale } from 'lucide-react';
import { useState } from 'react';
import type { HeldActions, HeldMessage } from '@/components/prompt-composer';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/**
 * The question shown above the chat box when the server held a message: it looks like it has a secret in it
 * (SECRET-002), or it changes an earlier decision (REQ-003). The message stays in the box until it's sent.
 */
export default function HeldMessagePrompt({
    held,
    actions,
}: {
    held: HeldMessage;
    actions: HeldActions;
}) {
    if (held.kind === 'secret') {
        return <SecretPrompt key={held.check} held={held} actions={actions} />;
    }

    return (
        <div
            className="mb-2 rounded-xl border border-amber-500/40 bg-amber-500/5 p-3 text-sm"
            role="alertdialog"
            aria-label="This changes an earlier decision"
            data-test="held-decision"
        >
            <p className="flex gap-2">
                <Scale className="mt-0.5 size-4 shrink-0 text-amber-600" />
                <span>
                    This changes an earlier decision:{' '}
                    <strong>
                        {String(held.decision).replace(/[.!?]+$/, '')}
                    </strong>
                    . Go ahead?
                </span>
            </p>
            <div className="mt-2 flex justify-end gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={actions.dismiss}
                    data-test="held-cancel"
                >
                    Cancel
                </Button>
                <Button
                    type="button"
                    size="sm"
                    autoFocus
                    disabled={actions.processing}
                    onClick={() => actions.resend({ confirm_decision: '1' })}
                    data-test="held-go-ahead"
                >
                    Go ahead
                </Button>
            </div>
        </div>
    );
}

function SecretPrompt({
    held,
    actions,
}: {
    held: HeldMessage;
    actions: HeldActions;
}) {
    const [name, setName] = useState(
        typeof held.name === 'string' ? held.name : 'SECRET',
    );
    const [value, setValue] = useState('');
    const preview = typeof held.preview === 'string' ? held.preview : null;
    const save = () =>
        actions.resend({
            secret_name: name.trim(),
            ...(preview === null ? { secret_value: value } : {}),
        });

    return (
        <form
            className="mb-2 rounded-xl border border-amber-500/40 bg-amber-500/5 p-3 text-sm"
            aria-label="This looks like a secret"
            data-test="held-secret"
            onSubmit={(event) => {
                event.preventDefault();
                save();
            }}
        >
            <p className="flex gap-2">
                <KeyRound className="mt-0.5 size-4 shrink-0 text-amber-600" />
                <span>
                    This looks like a secret
                    {preview && (
                        <>
                            {' '}
                            (<code className="text-xs">{preview}</code>)
                        </>
                    )}
                    . Save it in Secrets instead? Your message is sent with a
                    reference to it, and the agent reads it from the app's
                    environment.
                </span>
            </p>
            <div className="mt-2 flex flex-wrap items-center gap-2">
                <label className="sr-only" htmlFor="held-secret-name">
                    Secret name
                </label>
                <Input
                    id="held-secret-name"
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                    autoFocus
                    onFocus={(event) => event.currentTarget.select()}
                    className="h-8 min-w-0 flex-1 font-mono text-xs"
                    data-test="held-secret-name"
                />
                {preview === null && (
                    <>
                        <label className="sr-only" htmlFor="held-secret-value">
                            Secret value (the part of your message to save)
                        </label>
                        <Input
                            id="held-secret-value"
                            value={value}
                            onChange={(event) => setValue(event.target.value)}
                            placeholder="Paste the secret from your message"
                            className="h-8 min-w-0 flex-1 font-mono text-xs"
                            data-test="held-secret-value"
                        />
                    </>
                )}
            </div>
            <div className="mt-2 flex flex-wrap justify-end gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={actions.dismiss}
                    data-test="held-cancel"
                >
                    Cancel
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={actions.processing}
                    onClick={() => actions.resend({ send_secret: '1' })}
                    data-test="held-send-anyway"
                >
                    Send anyway
                </Button>
                <Button
                    type="submit"
                    size="sm"
                    disabled={
                        actions.processing ||
                        name.trim() === '' ||
                        (preview === null && value === '')
                    }
                    data-test="held-save-secret"
                >
                    Save in Secrets
                </Button>
            </div>
        </form>
    );
}
