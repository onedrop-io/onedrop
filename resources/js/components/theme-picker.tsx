import { Check } from 'lucide-react';
import { useAppearance } from '@/hooks/use-appearance';
import type { Theme, ThemeKind } from '@/lib/themes';
import { THEMES } from '@/lib/themes';
import { cn } from '@/lib/utils';

/**
 * The color themes as cards, dark ones then light ones (SET-002). Each card draws a little editor in its theme:
 * the card carries the theme's data-theme, and only reads its own variables, so it shows the theme whatever is
 * showing now. Picking one applies it straight away.
 */
export default function ThemePicker() {
    const { appearance, resolvedAppearance, themes, updateTheme } =
        useAppearance();

    const groups: { kind: ThemeKind; title: string }[] = [
        { kind: 'dark', title: 'Dark themes' },
        { kind: 'light', title: 'Light themes' },
    ];

    return (
        <div className="space-y-6">
            {groups.map(({ kind, title }) => (
                <section key={kind} className="space-y-3">
                    <div>
                        <h3 className="text-sm font-medium">{title}</h3>
                        {appearance === 'system' && (
                            <p className="text-xs text-muted-foreground">
                                {kind === resolvedAppearance
                                    ? `Showing now: your system is ${kind}.`
                                    : `Used when your system switches to ${kind}.`}
                            </p>
                        )}
                    </div>
                    <div
                        className="grid grid-cols-2 gap-3 sm:grid-cols-3"
                        role="radiogroup"
                        aria-label={title}
                    >
                        {THEMES.filter((theme) => theme.kind === kind).map(
                            (theme) => (
                                <ThemeCard
                                    key={theme.id}
                                    theme={theme}
                                    selected={themes[kind] === theme.id}
                                    onSelect={() => updateTheme(theme.id)}
                                />
                            ),
                        )}
                    </div>
                </section>
            ))}
        </div>
    );
}

function ThemeCard({
    theme,
    selected,
    onSelect,
}: {
    theme: Theme;
    selected: boolean;
    onSelect: () => void;
}) {
    const line = (color: string, width: string) => (
        <span
            className="block h-1.5 rounded-full"
            style={{ backgroundColor: `var(${color})`, width }}
        />
    );

    return (
        <button
            type="button"
            role="radio"
            aria-checked={selected}
            onClick={onSelect}
            className={cn(
                'group overflow-hidden rounded-lg border text-left transition-shadow focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                selected
                    ? 'border-primary ring-1 ring-primary'
                    : 'hover:border-muted-foreground/40',
            )}
            data-test={`theme-${theme.id}`}
        >
            <div
                data-theme={theme.id}
                className="flex h-20"
                style={{ backgroundColor: 'var(--t-bg)' }}
                aria-hidden
            >
                <div
                    className="flex w-1/4 flex-col gap-1.5 p-2"
                    style={{ backgroundColor: 'var(--t-sidebar)' }}
                >
                    <span
                        className="block h-1.5 w-full rounded-full opacity-40"
                        style={{ backgroundColor: 'var(--t-fg)' }}
                    />
                    <span
                        className="block h-1.5 w-3/4 rounded-full opacity-40"
                        style={{ backgroundColor: 'var(--t-fg)' }}
                    />
                    <span
                        className="mt-auto block h-2.5 w-full rounded-sm"
                        style={{ backgroundColor: 'var(--t-primary)' }}
                    />
                </div>
                <div className="flex flex-1 flex-col gap-1.5 p-2">
                    <span className="flex gap-1">
                        {line('--syntax-keyword', '22%')}
                        {line('--syntax-function', '35%')}
                    </span>
                    <span className="flex gap-1 pl-3">
                        {line('--syntax-property', '25%')}
                        {line('--syntax-string', '45%')}
                    </span>
                    <span className="flex gap-1 pl-3">
                        {line('--syntax-type', '30%')}
                        {line('--syntax-number', '15%')}
                    </span>
                    {line('--syntax-comment', '55%')}
                </div>
            </div>
            <div className="flex items-center justify-between gap-2 border-t px-2.5 py-1.5 text-xs">
                <span className="truncate">{theme.name}</span>
                {selected && <Check className="size-3.5 shrink-0" />}
            </div>
        </button>
    );
}
