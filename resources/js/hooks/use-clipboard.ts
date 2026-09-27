// Credit: https://usehooks-ts.com/
import { useState } from 'react';

export type CopiedValue = string | null;
export type CopyFn = (text: string) => Promise<boolean>;
export type UseClipboardReturn = [CopiedValue, CopyFn];

export function useClipboard(): UseClipboardReturn {
    const [copiedText, setCopiedText] = useState<CopiedValue>(null);

    const copy: CopyFn = async (text) => {
        try {
            if (!navigator?.clipboard) {
                throw new Error('Clipboard API unavailable');
            }

            await navigator.clipboard.writeText(text);
            setCopiedText(text);

            return true;
        } catch {
            // Blocked (e.g. plain http, or permission denied): fall back to the legacy copy command.
            const copied = legacyCopy(text);
            setCopiedText(copied ? text : null);

            return copied;
        }
    };

    return [copiedText, copy];
}

function legacyCopy(text: string): boolean {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();

    try {
        return document.execCommand('copy');
    } catch {
        return false;
    } finally {
        textarea.remove();
    }
}
