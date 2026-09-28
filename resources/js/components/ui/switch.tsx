import { cn } from "@/lib/utils";

/** An on/off switch button. */
export function Switch({
    checked,
    onChange,
    label,
    className,
    testId,
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
    label: string;
    className?: string;
    testId?: string;
}) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            onClick={() => onChange(!checked)}
            data-test={testId}
            className={cn(
                "relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors",
                checked ? "bg-primary" : "bg-muted-foreground/30",
                className,
            )}
        >
            <span
                className={cn(
                    "inline-block size-4 rounded-full bg-background shadow transition-transform",
                    checked ? "translate-x-4.5" : "translate-x-0.5",
                )}
            />
        </button>
    );
}
