import { useForm } from '@inertiajs/react';
import type { RouteDefinition } from '@/wayfinder';
import { ArrowUp } from 'lucide-react';
import type { KeyboardEvent } from 'react';
import InputError from '@/components/input-error';
import { cn } from '@/lib/utils';

/**
 * A chat-style textarea: Enter sends, Shift+Enter adds a line.
 */
export default function PromptComposer({
    action,
    field,
    placeholder,
    value,
    onValueChange,
    footer,
    autoFocus = false,
    size = 'default',
    disabled = false,
    disabledPlaceholder,
}: {
    action: RouteDefinition<'post'>;
    field: string;
    placeholder: string;
    /** Control the text from outside (e.g. suggestion chips). */
    value?: string;
    onValueChange?: (value: string) => void;
    footer?: React.ReactNode;
    autoFocus?: boolean;
    size?: 'default' | 'large';
    /** Block sending (typing is still allowed). */
    disabled?: boolean;
    disabledPlaceholder?: string;
}) {
    const form = useForm<Record<string, string>>({ [field]: '' });
    const text = value ?? form.data[field];

    const setText = (next: string) => {
        form.setData(field, next);
        onValueChange?.(next);
    };

    const submit = () => {
        if (!text.trim() || form.processing || disabled) {
            return;
        }

        form.transform(() => ({ [field]: text }));
        form.submit(action, {
            preserveScroll: true,
            onSuccess: () => setText(''),
        });
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            submit();
        }
    };

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                submit();
            }}
            className="rounded-2xl border border-input bg-card shadow-xs focus-within:ring-2 focus-within:ring-ring/30"
        >
            <label htmlFor={`composer-${field}`} className="sr-only">
                {placeholder}
            </label>
            <textarea
                id={`composer-${field}`}
                name={field}
                value={text}
                onChange={(event) => setText(event.target.value)}
                onKeyDown={onKeyDown}
                placeholder={
                    disabled
                        ? (disabledPlaceholder ?? placeholder)
                        : placeholder
                }
                autoFocus={autoFocus}
                rows={size === 'large' ? 3 : 2}
                className={cn(
                    'block w-full resize-none bg-transparent px-4 pt-4 outline-none placeholder:text-muted-foreground',
                    size === 'large' ? 'text-lg' : 'text-sm',
                )}
            />
            <div className="flex items-center justify-between gap-2 px-3 pb-3">
                <div className="text-xs text-muted-foreground">{footer}</div>
                <button
                    type="submit"
                    disabled={!text.trim() || form.processing || disabled}
                    aria-label="Send"
                    data-test="composer-send"
                    className="flex size-8 items-center justify-center rounded-full bg-primary text-primary-foreground transition-opacity disabled:opacity-30"
                >
                    <ArrowUp className="size-4" />
                </button>
            </div>
            {form.errors[field] && (
                <InputError
                    message={form.errors[field]}
                    className="px-4 pb-3"
                />
            )}
        </form>
    );
}
