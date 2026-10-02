import * as PopoverPrimitive from '@radix-ui/react-popover';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

export const Popover = PopoverPrimitive.Root;
export const PopoverTrigger = PopoverPrimitive.Trigger;
export const PopoverAnchor = PopoverPrimitive.Anchor;
export const PopoverClose = PopoverPrimitive.Close;

export function PopoverContent({
    className,
    align = 'start',
    sideOffset = 4,
    ...props
}: ComponentProps<typeof PopoverPrimitive.Content>) {
    return (
        <PopoverPrimitive.Portal>
            <PopoverPrimitive.Content
                align={align}
                sideOffset={sideOffset}
                className={cn(
                    'z-50 rounded-lg border border-neutral-200 bg-white p-3 text-sm text-neutral-900 shadow-lg outline-none dark:border-neutral-800 dark:bg-neutral-950 dark:text-neutral-100',
                    className,
                )}
                {...props}
            />
        </PopoverPrimitive.Portal>
    );
}
