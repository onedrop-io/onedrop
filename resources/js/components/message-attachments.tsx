import { FileText, X } from 'lucide-react';
import { cn } from '@/lib/utils';

/** A file to show: sent (from the server) or picked and not sent yet. */
export type AttachmentPreview = {
    key: string | number;
    name: string;
    /** Thumbnail source for images; null for other files. */
    imageUrl: string | null;
    /** Where clicking takes you (sent attachments only). */
    href?: string;
};

/**
 * A row of attachments: image thumbnails and file chips. Pass onRemove to show remove buttons
 * (the composer); sent attachments link to the file instead.
 */
export default function MessageAttachments({
    attachments,
    onRemove,
    className,
}: {
    attachments: AttachmentPreview[];
    onRemove?: (key: AttachmentPreview['key']) => void;
    className?: string;
}) {
    if (attachments.length === 0) {
        return null;
    }

    return (
        <ul className={cn('flex flex-wrap gap-2', className)}>
            {attachments.map((attachment) => {
                const body = attachment.imageUrl ? (
                    <img
                        src={attachment.imageUrl}
                        alt={attachment.name}
                        className="size-16 rounded-lg border object-cover"
                    />
                ) : (
                    <span className="flex h-16 max-w-44 items-center gap-2 rounded-lg border bg-card px-3 text-xs">
                        <FileText className="size-4 shrink-0 text-muted-foreground" />
                        <span className="truncate">{attachment.name}</span>
                    </span>
                );

                return (
                    <li
                        key={attachment.key}
                        className="relative"
                        title={attachment.name}
                        data-test="attachment"
                    >
                        {attachment.href ? (
                            <a
                                href={attachment.href}
                                target="_blank"
                                rel="noreferrer"
                                className="block rounded-lg hover:opacity-90"
                            >
                                {body}
                            </a>
                        ) : (
                            body
                        )}
                        {onRemove && (
                            <button
                                type="button"
                                onClick={() => onRemove(attachment.key)}
                                aria-label={`Remove ${attachment.name}`}
                                data-test="remove-attachment"
                                className="absolute -top-1.5 -right-1.5 flex size-5 items-center justify-center rounded-full border bg-background text-foreground shadow-xs hover:bg-muted"
                            >
                                <X className="size-3" />
                            </button>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}
