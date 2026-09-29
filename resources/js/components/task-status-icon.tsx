import { Circle, CircleCheck, CircleDashed, CircleDot } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { TaskStage } from '@/types';

const STAGE_ICONS = {
    todo: CircleDashed,
    in_progress: Circle,
    review: CircleDot,
    done: CircleCheck,
} satisfies Record<TaskStage, unknown>;

/** A task's column as an icon; a pulsing dot while its agent works. */
export function TaskStatusIcon({
    stage,
    working,
    className,
}: {
    stage: TaskStage;
    working: boolean;
    className?: string;
}) {
    if (working) {
        return (
            <span
                className={cn(
                    'flex size-4 shrink-0 items-center justify-center',
                    className,
                )}
                aria-label="Agent working"
            >
                <span className="size-2 animate-pulse rounded-full bg-emerald-500" />
            </span>
        );
    }

    const Icon = STAGE_ICONS[stage];

    return (
        <Icon
            className={cn(
                'size-4 shrink-0',
                stage === 'done' ? 'text-emerald-600' : 'text-muted-foreground',
                stage === 'review' && 'text-sky-600',
                className,
            )}
            aria-hidden
        />
    );
}
