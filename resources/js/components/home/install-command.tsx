import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { useClipboard } from '@/hooks/use-clipboard';

/** A shell command in a terminal-style box, with a copy button. */
export function InstallCommand({ command }: { command: string }) {
    const [, copyText] = useClipboard();
    const [copied, setCopied] = useState(false);

    const copy = () => {
        void copyText(command).then((ok) => {
            if (ok) {
                setCopied(true);
                setTimeout(() => setCopied(false), 1500);
            }
        });
    };

    return (
        <div className="flex items-start gap-3 rounded-xl bg-black/40 py-2 pr-2 pl-4 ring-1 ring-white/10">
            <code
                className="min-w-0 flex-1 py-2 font-mono text-sm leading-relaxed text-[#F5EFEA]"
                data-test="install-command"
            >
                <span aria-hidden="true" className="text-[#7D7068]">
                    ${' '}
                </span>
                {/* Breaks only between words, so `--domain` or the URL never splits across lines. */}
                {command.split(' ').map((word, index) => (
                    <span key={index}>
                        {index > 0 && ' '}
                        <span className="whitespace-nowrap">{word}</span>
                    </span>
                ))}
            </code>
            <button
                type="button"
                onClick={copy}
                className="inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-[#B3A69C] transition-colors hover:bg-white/10 hover:text-white focus-visible:ring-2 focus-visible:ring-[#FF9A5C] focus-visible:outline-none"
                data-test="copy-install-command"
            >
                {copied ? (
                    <Check aria-hidden="true" className="size-4" />
                ) : (
                    <Copy aria-hidden="true" className="size-4" />
                )}
                <span className="sr-only sm:not-sr-only">
                    {copied ? 'Copied' : 'Copy'}
                </span>
            </button>
        </div>
    );
}
