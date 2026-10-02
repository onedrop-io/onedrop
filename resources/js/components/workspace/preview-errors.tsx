import { TriangleAlert, X } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import type { RefObject } from 'react';
import { Button } from '@/components/ui/button';

/** An error the preview's page reported (docker/sandbox/host-proxy.mjs adds the reporter to preview pages). */
export type PreviewError = {
    type: 'server' | 'error' | 'rejection' | 'resource';
    message: string;
    page?: string;
};

const TYPES = ['server', 'error', 'rejection', 'resource'];
const MAX_ERRORS = 10;

const LABELS: Record<PreviewError['type'], string> = {
    server: 'Server error',
    error: 'Error',
    rejection: 'Unhandled promise rejection',
    resource: 'Failed to load',
};

function parse(data: unknown): PreviewError | null {
    const { onedrop, error } = (data ?? {}) as {
        onedrop?: unknown;
        error?: Partial<PreviewError>;
    };

    if (
        onedrop !== 'error' ||
        !error ||
        !TYPES.includes(String(error.type)) ||
        typeof error.message !== 'string' ||
        error.message === ''
    ) {
        return null;
    }

    return {
        type: error.type as PreviewError['type'],
        message: error.message.slice(0, 1000),
        page: typeof error.page === 'string' ? error.page : undefined,
    };
}

/** Errors reported by the page in the preview frame, until cleared. */
export function usePreviewErrors(frame: RefObject<HTMLIFrameElement | null>) {
    const [errors, setErrors] = useState<PreviewError[]>([]);

    useEffect(() => {
        const onMessage = (event: MessageEvent) => {
            if (
                !frame.current ||
                event.source !== frame.current.contentWindow
            ) {
                return;
            }

            const error = parse(event.data);

            if (error) {
                setErrors((list) =>
                    list.length >= MAX_ERRORS ||
                    list.some(
                        (e) =>
                            e.type === error.type &&
                            e.message === error.message,
                    )
                        ? list
                        : [...list, error],
                );
            }
        };

        window.addEventListener('message', onMessage);

        return () => window.removeEventListener('message', onMessage);
    }, [frame]);

    const clear = useCallback(() => setErrors([]), []);

    return { errors, clear };
}

/** The message asking the agent to fix the errors. */
export function fixRequest(errors: PreviewError[]): string {
    const lines = errors
        .slice(0, 5)
        .map(
            (error) =>
                `${LABELS[error.type]}${error.page ? ` on ${error.page}` : ''}: ${error.message}`,
        )
        .join('\n');

    return `The preview shows ${errors.length === 1 ? 'this error' : 'these errors'}:\n\n${lines}\n\nFind the cause (details are in /workspace/.onedrop/errors.log) and fix it.`;
}

export function PreviewErrorBar({
    errors,
    onFix,
    onDismiss,
}: {
    errors: PreviewError[];
    onFix: () => void;
    onDismiss: () => void;
}) {
    const [first] = errors;

    return (
        <div
            role="alert"
            data-test="preview-error"
            className="absolute inset-x-3 bottom-3 flex items-center gap-3 rounded-lg border border-red-500/30 bg-background/95 px-3 py-2 text-sm shadow-lg backdrop-blur"
        >
            <TriangleAlert className="size-4 shrink-0 text-red-500" />
            <div className="min-w-0 flex-1">
                <p className="font-medium">
                    {errors.length === 1
                        ? 'This page hit an error'
                        : `This page hit ${errors.length} errors`}
                </p>
                <p
                    className="truncate text-muted-foreground"
                    title={first.message}
                >
                    {LABELS[first.type]}: {first.message}
                </p>
            </div>
            <Button size="sm" onClick={onFix} data-test="preview-error-fix">
                Fix it
            </Button>
            <button
                type="button"
                onClick={onDismiss}
                aria-label="Dismiss"
                data-test="preview-error-dismiss"
                title="Dismiss"
                className="rounded p-1 text-muted-foreground hover:bg-muted"
            >
                <X className="size-4" />
            </button>
        </div>
    );
}
