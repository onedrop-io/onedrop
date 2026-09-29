import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { useClipboard } from '@/hooks/use-clipboard';
import { INSTALL_COMMAND, INSTALL_DOCS_URL } from '@/lib/links';

/** The one-line install for a Mac or Linux laptop, with a copy button and a link to the install docs. */
export function InstallCommand() {
    const [, copyText] = useClipboard();
    const [copied, setCopied] = useState(false);

    const copy = () => {
        void copyText(INSTALL_COMMAND).then((ok) => {
            if (ok) {
                setCopied(true);
                setTimeout(() => setCopied(false), 1500);
            }
        });
    };

    return (
        <div className="mt-8">
            <div className="flex items-center gap-3 rounded-xl bg-black/40 py-2 pr-2 pl-4 ring-1 ring-white/10">
                <code
                    className="min-w-0 flex-1 overflow-x-auto font-mono text-sm whitespace-nowrap text-[#F5EFEA]"
                    data-test="install-command"
                >
                    <span aria-hidden="true" className="text-[#7D7068]">
                        ${' '}
                    </span>
                    {INSTALL_COMMAND}
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
                    {copied ? 'Copied' : 'Copy'}
                </button>
            </div>
            <p className="mt-3 text-sm text-[#7D7068]">
                macOS or Linux, with Docker.{' '}
                <a
                    href={INSTALL_DOCS_URL}
                    className="text-[#FF9A5C] underline-offset-4 hover:underline"
                >
                    Install guide
                </a>
            </p>
        </div>
    );
}
