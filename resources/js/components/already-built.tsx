import { X } from 'lucide-react';
import { TemplateLogo } from '@/components/app-gallery';
import type { AppTemplate, CatalogTemplate } from '@/types';

/** A built-in template in the shape of a registry one, to match against what they type. */
export const builtIn = (template: AppTemplate): CatalogTemplate => ({
    ...template,
    registry: null,
    logo: null,
    tags: ['business'],
    compose: false,
    version: null,
    links: { website: null, github: null, docs: null },
});

/** Templates and free apps that sound like what they typed, offered before they start from scratch (PRJ-001). */
export default function AlreadyBuilt({
    suggestions,
    onPick,
    onDismiss,
}: {
    suggestions: CatalogTemplate[];
    onPick: (template: CatalogTemplate) => void;
    onDismiss: () => void;
}) {
    return (
        <div
            className="space-y-2 rounded-xl border border-amber-500/40 bg-amber-500/10 p-3"
            data-test="already-built"
        >
            <div className="flex items-start justify-between gap-3">
                <p className="text-sm">
                    <span className="font-medium">
                        This might already exist.
                    </span>{' '}
                    <span className="text-muted-foreground">
                        Start from one of these to save time, or just send yours
                        to start from scratch.
                    </span>
                </p>
                <button
                    type="button"
                    onClick={onDismiss}
                    className="inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                    data-test="already-built-dismiss"
                >
                    <X className="size-3.5" />
                    No thanks
                </button>
            </div>
            <div className="flex flex-wrap gap-2">
                {suggestions.map((suggestion) => (
                    <button
                        key={suggestion.value}
                        type="button"
                        onClick={() => onPick(suggestion)}
                        className="inline-flex items-center gap-2 rounded-lg border border-input bg-background py-1.5 pr-3 pl-1.5 text-sm hover:border-amber-500/60"
                        data-test="already-built-option"
                    >
                        <TemplateLogo
                            template={suggestion}
                            className="size-6"
                        />
                        <span className="font-medium">{suggestion.label}</span>
                        <span className="text-xs text-muted-foreground">
                            {suggestion.compose ? 'Free app' : 'Template'}
                        </span>
                    </button>
                ))}
            </div>
        </div>
    );
}
